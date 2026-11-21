#!/usr/bin/env python3
"""Recette complémentaire : assertions chiffrées et déconnexion unique SAML avec Keycloak (après up.sh)."""
import base64, html, http.client, json, os, re, subprocess, sys, time, urllib.parse

KC_IP, APP_IP = os.environ["KC_IP"], os.environ["APP_IP"]
KC_HOST, APP_HOST = "keycloak.recette.test", "rp.recette.test"
results = []

def check(name, ok, detail=""):
    results.append(ok)
    print(("PASS " if ok else "FAIL ") + name + ((" — " + detail) if detail and not ok else ""))

class Client:
    def __init__(self, ip, host):
        self.ip, self.host, self.jar = ip, host, {}
    def request(self, method, path, body=None, headers=None, form=None):
        h = {"Host": self.host, "X-Forwarded-Proto": "https", "X-Forwarded-Host": self.host, "X-Forwarded-Port": "443"}
        h.update(headers or {})
        if self.jar: h["Cookie"] = "; ".join(f"{k}={v}" for k, v in self.jar.items())
        data = None
        if form is not None:
            data = urllib.parse.urlencode(form); h["Content-Type"] = "application/x-www-form-urlencoded"
        elif body is not None:
            data = json.dumps(body); h["Content-Type"] = "application/json"
        c = http.client.HTTPConnection(self.ip, 8080, timeout=60)
        c.request(method, path, body=data, headers=h)
        r = c.getresponse(); raw = r.read().decode("utf-8", "replace")
        for v in r.headers.get_all("Set-Cookie") or []:
            name, _, rest = v.partition("=")
            value = rest.split(";")[0]
            if value in ("", "deleted") or "Max-Age=0" in v:
                self.jar.pop(name, None)
            else:
                self.jar[name] = value
        return r.status, r.headers, raw

def path_of(url):
    p = urllib.parse.urlsplit(url)
    return p.path + (("?" + p.query) if p.query else "")

def kcadm(*args):
    return subprocess.run(["docker", "exec", "rpdev-kc", "/opt/keycloak/bin/kcadm.sh", *args], capture_output=True, text=True)

kc = Client(KC_IP, KC_HOST)
app = Client(APP_IP, APP_HOST)
s, _, raw = app.request("POST", "/api/auth/login", {"email": "admin@riskpilot.test", "password": "Recette-Admin-12345"})
admin = {"Authorization": "Bearer " + json.loads(raw)["token"]}
s, _, desc = kc.request("GET", "/realms/riskpilot/protocol/saml/descriptor")
entity = re.search(r'entityID="([^"]+)"', desc).group(1)
cert = re.search(r'<(?:md:)?KeyDescriptor use="signing">.*?<(?:ds:)?X509Certificate>([^<]+)<', desc, re.S).group(1).strip()
sso = re.search(r'SingleSignOnService[^>]*Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"[^>]*Location="([^"]+)"', desc).group(1)
slo = re.search(r'SingleLogoutService[^>]*Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"[^>]*Location="([^"]+)"', desc).group(1)
pem = "-----BEGIN CERTIFICATE-----\n" + "\n".join(cert[i:i+64] for i in range(0, len(cert), 64)) + "\n-----END CERTIFICATE-----"
config = {"issuer": entity, "ssoUrl": sso, "sloUrl": slo, "idpCertificate": pem, "accountMappings": [], "linkScimExternalId": True, "encryptAssertions": True,
          "roleClaim": "Role", "roleMappings": [{"group": "riskpilot-auditors", "role": "ROLE_AUDITOR"}]}
s, _, raw = app.request("POST", "/api/v1/integrations", {"type": "SAML", "provider": "KEYCLOAK", "name": "Keycloak chiffré", "configuration": config, "enabled": True}, admin)
check("intégration SAML avec chiffrement et SLO", s == 201, raw[:300])
integration = json.loads(raw); sid = integration["id"]
sp_cert = integration["configuration"].get("spCertificate", "")
check("certificat de chiffrement généré, clé privée jamais renvoyée", "BEGIN CERTIFICATE" in sp_cert and "PRIVATE KEY" not in raw)
s, _, meta = app.request("GET", f"/api/auth/sso/saml/{sid}/metadata")
check("métadonnées publient le certificat de chiffrement et le SLS", "KeyDescriptor" in meta and f"/saml/{sid}/sls" in meta, meta[:300])

# Keycloak : chiffrer les assertions pour le certificat publié par RiskPilot
kcadm("config", "credentials", "--server", "http://localhost:8080", "--realm", "master", "--user", "admin", "--password", "Recette-Admin-1")
listing = kcadm("get", "clients", "-r", "riskpilot", "-q", f"clientId=https://rp.recette.test/api/auth/sso/saml/{sid}/metadata", "--fields", "id")
client_id = re.search(r'"id"\s*:\s*"([^"]+)"', listing.stdout).group(1)
b64 = "".join(sp_cert.splitlines()[1:-1])
done = kcadm("update", f"clients/{client_id}", "-r", "riskpilot", "-s", 'attributes."saml.encrypt"=true', "-s", f'attributes."saml.encryption.certificate"={b64}')
check("Keycloak configuré pour chiffrer avec le certificat RiskPilot", done.returncode == 0, done.stderr[:300])

# Provisioning Alice
s, _, raw = app.request("POST", "/api/v1/integrations", {"type": "API_KEY", "provider": "GENERIC", "name": "SCIM", "configuration": {"scopes": ["scim:write"]}, "enabled": True}, admin)
scim = {"Authorization": "Bearer " + json.loads(raw)["secret"], "Content-Type": "application/scim+json"}
app.request("POST", "/api/scim/v2/Users", {"schemas": ["urn:ietf:params:scim:schemas:core:2.0:User"], "userName": "alice@riskpilot.test", "externalId": "alice@riskpilot.test", "name": {"givenName": "Alice", "familyName": "Recette"}, "active": True}, scim)

time.sleep(1)
s, h, _ = app.request("GET", f"/api/auth/sso/{sid}/start")
kc_c = Client(KC_IP, KC_HOST)
s, h2, page = kc_c.request("GET", path_of(h["Location"]))
action = html.unescape(re.search(r'<form[^>]+id="kc-form-login"[^>]+action="([^"]+)"', page).group(1))
s, _, page = kc_c.request("POST", path_of(action), form={"username": "alice@riskpilot.test", "password": "Recette-Only-1", "credentialId": ""})
fields = {k: html.unescape(v) for k, v in re.findall(r'name="([^"]+)"\s+value="([^"]*)"', page)}
xml = base64.b64decode(fields.get("SAMLResponse", "")).decode("utf-8", "replace")
check("Keycloak renvoie une assertion chiffrée", "EncryptedAssertion" in xml and "AttributeStatement" not in xml, xml[:200])
s, h, _ = app.request("POST", f"/api/auth/sso/saml/{sid}/acs", form=fields)
check("RiskPilot déchiffre et accepte l'assertion", s in (302, 303) and h["Location"].endswith("sso=complete"), f"{s} {h.get('Location')}")
s, _, raw = app.request("POST", "/api/auth/sso/complete", {}, {"Origin": "https://" + APP_HOST})
check("session ouverte", s == 200 and "token" in raw, raw[:200])
logout = json.loads(raw).get("sso", {}).get("logoutUrl", "") if s == 200 else ""
check("adresse de déconnexion SAML fournie", logout.startswith(slo + "?SAMLRequest="), logout[:120])

if logout:
    s, h, body = kc_c.request("GET", path_of(logout))
    where = h.get("Location", "")
    redirected_back = s in (302, 303) and "/api/auth/sso/saml/" in where
    if redirected_back:
        s2, h2, _ = app.request("GET", path_of(where))
        check("Keycloak ferme la session puis renvoie vers le SLS RiskPilot (retour à /login)", s2 in (302, 303) and h2.get("Location", "").endswith("/login"), f"{s2} {h2.get('Location')}")
    else:
        check("Keycloak ferme la session (réponse directe)", s in (200, 302, 303), f"{s} {where} {body[:200]}")
    # La session Keycloak est fermée : la page d'authentification redemande les identifiants
    s, h, _ = app.request("GET", f"/api/auth/sso/{sid}/start")
    s, _, page = kc_c.request("GET", path_of(h["Location"]))
    check("session Keycloak réellement terminée (formulaire de connexion de nouveau demandé)", 'id="kc-form-login"' in page, page[:200])

print(f"\n{sum(results)}/{len(results)} vérifications réussies")
sys.exit(0 if all(results) else 1)
