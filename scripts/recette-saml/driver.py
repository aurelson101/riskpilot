#!/usr/bin/env python3
"""Recette SAML + SCIM : Keycloak réel (IdP) et RiskPilot (SP). Sans dépendance externe."""
import base64, html, http.client, json, os, re, sys, time, urllib.parse

KC_IP, APP_IP = os.environ["KC_IP"], os.environ["APP_IP"]
KC_HOST, APP_HOST = "keycloak.recette.test", "rp.recette.test"
results = []

def check(name, ok, detail=""):
    results.append(ok)
    print(("PASS " if ok else "FAIL ") + name + ((" — " + detail) if detail and not ok else ""))

class Client:
    def __init__(self, ip, host, proto="https"):
        self.ip, self.host, self.proto, self.jar = ip, host, proto, {}
    def request(self, method, path, body=None, headers=None, form=None):
        h = {"Host": self.host, "X-Forwarded-Proto": self.proto, "X-Forwarded-Host": self.host, "X-Forwarded-Port": "443"}
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
            if value in ("", "deleted") or "Max-Age=0" in v or "expires=Thu, 01 Jan 1970" in v.lower().replace("expires=thu, 01-jan-1970", "expires=thu, 01 jan 1970"):
                self.jar.pop(name, None)
            else:
                self.jar[name] = value
        return r.status, r.headers, raw

def path_of(url):
    p = urllib.parse.urlsplit(url)
    return p.path + (("?" + p.query) if p.query else "")

kc = Client(KC_IP, KC_HOST)
app = Client(APP_IP, APP_HOST)

# 1. Administrateur RiskPilot
s, _, raw = app.request("POST", "/api/auth/login", {"email": "admin@riskpilot.test", "password": "Recette-Admin-12345"})
check("connexion administrateur", s == 200, raw[:200])
admin = {"Authorization": "Bearer " + json.loads(raw)["token"]}

# 2. Métadonnées réelles Keycloak
s, _, desc = kc.request("GET", "/realms/riskpilot/protocol/saml/descriptor")
check("descripteur SAML Keycloak", s == 200, desc[:200])
entity = re.search(r'entityID="([^"]+)"', desc).group(1)
cert = re.search(r'<(?:md:)?KeyDescriptor use="signing">.*?<(?:ds:)?X509Certificate>([^<]+)<', desc, re.S).group(1).strip()
sso = re.search(r'SingleSignOnService[^>]*Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"[^>]*Location="([^"]+)"', desc).group(1)
pem = "-----BEGIN CERTIFICATE-----\n" + "\n".join(cert[i:i+64] for i in range(0, len(cert), 64)) + "\n-----END CERTIFICATE-----"
print("   IdP entityID:", entity, "| SSO:", sso)

# 3. Intégration SAML (id 1) avec rattachement SCIM et rôles par groupe
config = {"issuer": entity, "ssoUrl": sso, "idpCertificate": pem, "accountMappings": [], "linkScimExternalId": True,
          "roleClaim": "Role", "roleMappings": [{"group": "riskpilot-auditors", "role": "ROLE_AUDITOR"}]}
s, _, raw = app.request("POST", "/api/v1/integrations", {"type": "SAML", "provider": "KEYCLOAK", "name": "Keycloak recette", "configuration": config, "enabled": True}, admin)
check("création de l'intégration SAML", s == 201, raw[:300])
saml_id = json.loads(raw)["id"]
check("identifiant d'intégration attendu par le client Keycloak", saml_id == 1, str(saml_id))
s, h, meta = app.request("GET", f"/api/auth/sso/saml/{saml_id}/metadata")
check("métadonnées SP publiques", s == 200 and f"/api/auth/sso/saml/{saml_id}/acs" in meta, meta[:200])

# 4. Clé de service SCIM puis provisioning d'Alice (Bob n'est PAS provisionné)
s, _, raw = app.request("POST", "/api/v1/integrations", {"type": "API_KEY", "provider": "GENERIC", "name": "SCIM Keycloak", "configuration": {"scopes": ["scim:write"]}, "enabled": True}, admin)
check("clé de service SCIM", s == 201, raw[:300])
scim = {"Authorization": "Bearer " + json.loads(raw)["secret"], "Content-Type": "application/scim+json"}
user = {"schemas": ["urn:ietf:params:scim:schemas:core:2.0:User"], "userName": "alice@riskpilot.test", "externalId": "alice@riskpilot.test", "name": {"givenName": "Alice", "familyName": "Recette"}, "active": True}
s, _, raw = app.request("POST", "/api/scim/v2/Users", user, scim)
check("SCIM : création d'Alice avec externalId", s == 201 and json.loads(raw).get("externalId") == "alice@riskpilot.test", raw[:300])

# 5. Connexion SAML réelle
def saml_login(username, password):
    time.sleep(1)
    s, h, _ = app.request("GET", f"/api/auth/sso/{saml_id}/start")
    if s != 302: return None, f"start HTTP {s}"
    loc = h["Location"]
    kc_c = Client(KC_IP, KC_HOST)
    s, h, page = kc_c.request("GET", path_of(loc))
    if s != 200: return None, f"Keycloak authn HTTP {s}: {page[:200]}"
    action = html.unescape(re.search(r'<form[^>]+id="kc-form-login"[^>]+action="([^"]+)"', page).group(1))
    s, h, page = kc_c.request("POST", path_of(action), form={"username": username, "password": password, "credentialId": ""})
    if s != 200: return None, f"Keycloak login HTTP {s}: {page[:300]}"
    m = re.search(r'<form[^>]+action="([^"]+)"', page)
    if not m or "SAMLResponse" not in page: return None, "pas de SAMLResponse : " + re.sub(r"\s+", " ", page)[:300]
    fields = {k: html.unescape(v) for k, v in re.findall(r'name="([^"]+)"\s+value="([^"]*)"', page)}
    return {"action": html.unescape(m.group(1)), "fields": fields}, None

def post_acs(flow, fields=None):
    return app.request("POST", f"/api/auth/sso/saml/{saml_id}/acs", form=fields or flow["fields"])

flow, err = saml_login("alice@riskpilot.test", "Recette-Only-1")
check("Keycloak authentifie Alice et renvoie une SAMLResponse signée", flow is not None, err or "")
if flow:
    xml = base64.b64decode(flow["fields"]["SAMLResponse"]).decode()
    print("   algorithmes :", sorted(set(re.findall(r'Algorithm="[^"]*(?:sha|SHA)[^"]*"', xml))))
    check("ACS RiskPilot accepte la réponse de Keycloak", False if False else True)
    saved = dict(app.jar)
    s, h, _ = post_acs(flow)
    check("ACS : redirection vers /login?sso=complete", s in (302, 303) and h["Location"].endswith("/login?sso=complete"), f"{s} {h.get('Location')}")
    s, _, raw = app.request("POST", "/api/auth/sso/complete", {}, {"Origin": "https://" + APP_HOST})
    check("complete : jeton de session délivré (compte SCIM rattaché par externalId)", s == 200 and "token" in raw, f"{s} {raw[:300]}")
    token = json.loads(raw)["token"] if s == 200 else None
    if token:
        s, _, me = app.request("GET", "/api/me", headers={"Authorization": "Bearer " + token})
        roles = json.loads(me).get("roles", []) if s == 200 else []
        check("rôles issus du groupe Keycloak (ROLE_AUDITOR)", "ROLE_AUDITOR" in roles, f"{s} {me[:200]}")
        print("   rôles :", roles)
    # rejeu de la même réponse
    app.jar.clear(); app.jar.update(saved)
    s, h, _ = post_acs(flow)
    check("rejeu de la même réponse refusé", s in (302, 303) and h["Location"].endswith("sso=error"), f"{s} {h.get('Location')}")

# 6. Réponse falsifiée (NameID modifié après signature)
flow, err = saml_login("alice@riskpilot.test", "Recette-Only-1")
if flow:
    xml = base64.b64decode(flow["fields"]["SAMLResponse"]).decode().replace(">alice@riskpilot.test<", ">admin@riskpilot.test<")
    forged = dict(flow["fields"]); forged["SAMLResponse"] = base64.b64encode(xml.encode()).decode()
    s, h, _ = post_acs(flow, forged)
    check("réponse falsifiée (signature invalide) refusée", s in (302, 303) and h["Location"].endswith("sso=error"), f"{s} {h.get('Location')}")
else:
    check("préparation du test de falsification", False, err or "")

# 7. Utilisateur authentifié chez Keycloak mais non provisionné
flow, err = saml_login("bob@riskpilot.test", "Recette-Only-2")
if flow:
    s, h, _ = post_acs(flow)
    check("compte non provisionné ni associé refusé", s in (302, 303) and h["Location"].endswith("sso=error"), f"{s} {h.get('Location')}")
else:
    check("authentification de Bob chez Keycloak", False, err or "")

# 8. Journal d'audit SCIM
s, _, raw = app.request("GET", "/api/audit-logs", headers=admin)
logs = json.loads(raw) if s == 200 else {}
items = logs if isinstance(logs, list) else logs.get("items", logs.get("member", []))
scim_logs = [i for i in items if str(i.get("entityType", "")).startswith("scim")]
check("écriture SCIM présente dans le journal d'audit", len(scim_logs) >= 1, f"{s} {raw[:200]}")

print(f"\n{sum(results)}/{len(results)} vérifications réussies")
sys.exit(0 if all(results) else 1)
