import { useState, type FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Button,
  Card,
  CardContent,
  FormControlLabel,
  MenuItem,
  Stack,
  Switch,
  TextField,
  Typography,
} from "@mui/material";
import axios from "axios";
import { api } from "../api/client";
import type { User } from "../api/types";
import { useAuth } from "../auth/useAuth";

type Mapping = { userId: number; subject: string };
type Protocol = "OIDC" | "SAML";
type Configuration = {
  issuer: string;
  clientId: string;
  ssoUrl?: string;
  idpCertificate?: string;
  spEntityId?: string;
  sloUrl?: string;
  encryptAssertions?: boolean;
  linkScimExternalId?: boolean;
  accountMappings: Mapping[];
  hostedDomain?: string;
  roleClaim?: string;
  roleMappings?: Array<{ group: string; role: string }>;
};
type Integration = {
  id: number;
  type: string;
  provider: string;
  name: string;
  enabled: boolean;
  credentialConfigured: boolean;
  configuration: Configuration;
};
const presets: Record<string, string> = {
  MICROSOFT_ENTRA:
    "https://login.microsoftonline.com/00000000-0000-0000-0000-000000000000/v2.0",
  GOOGLE_WORKSPACE: "https://accounts.google.com",
  KEYCLOAK: "https://sso.example.com/realms/riskpilot",
  AUTHENTIK: "https://sso.example.com/application/o/riskpilot/",
  GENERIC: "https://sso.example.com",
};
const names: Record<string, string> = {
  MICROSOFT_ENTRA: "Microsoft Entra ID",
  GOOGLE_WORKSPACE: "Google Workspace",
  KEYCLOAK: "Keycloak",
  AUTHENTIK: "Authentik",
  GENERIC: "Autre fournisseur OIDC",
};
const initial = {
  protocol: "OIDC" as Protocol,
  ssoUrl: "",
  idpCertificate: "",
  spEntityId: "",
  sloUrl: "",
  encryptAssertions: false,
  linkScimExternalId: false,
  name: "",
  provider: "MICROSOFT_ENTRA",
  issuer: presets.MICROSOFT_ENTRA,
  clientId: "",
  credential: "",
  hostedDomain: "",
  enabled: false,
  accountMappings: [] as Mapping[],
  roleClaim: "",
  roleMappingsText: "",
};

const roleMappingsToText = (
  mappings: Array<{ group: string; role: string }> | undefined,
) => (mappings ?? []).map((item) => `${item.group}=${item.role}`).join("\n");
const textToRoleMappings = (text: string) =>
  text
    .split("\n")
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => {
      const cut = line.lastIndexOf("=");
      return {
        group: line.slice(0, cut).trim(),
        role: line.slice(cut + 1).trim(),
      };
    });

export function SsoSettingsCard() {
  const { user: actor } = useAuth();
  const cache = useQueryClient();
  const [form, setForm] = useState(initial);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/v1/integrations/${id}`),
    onSuccess: async () => {
      setForm(initial);
      setEditingId(null);
      setError(null);
      setSaved(false);
      await cache.invalidateQueries({ queryKey: ["platform-integrations"] });
      await cache.invalidateQueries({ queryKey: ["sso-providers"] });
    },
    onError: () => setError("Suppression de la configuration SSO impossible."),
  });
  const integrations = useQuery({
    queryKey: ["platform-integrations"],
    queryFn: async () =>
      (await api.get<{ items: Integration[] }>("/v1/integrations")).data.items,
  });
  const users = useQuery({
    queryKey: ["sso-users", actor?.organization.id],
    queryFn: async () =>
      (
        await api.get<Pick<User, "id" | "email" | "status" | "organization">[]>(
          "/users",
        )
      ).data,
    enabled: Boolean(actor?.organization.id),
  });
  const organizationUsers =
    users.data?.filter(
      (user) => user.organization?.id === actor?.organization.id,
    ) ?? [];
  const editing = integrations.data?.find((item) => item.id === editingId);
  const saml = form.protocol === "SAML";
  const needsNewSecret =
    !saml &&
    (editingId === null ||
      Boolean(
        editing &&
        (editing.configuration.issuer !== form.issuer.trim() ||
          editing.configuration.clientId !== form.clientId.trim()),
      ));
  const duplicateSubject = (subject: string, index: number) =>
    Boolean(subject.trim()) &&
    form.accountMappings.some(
      (mapping, i) => i !== index && mapping.subject.trim() === subject.trim(),
    );
  const invalidMappings = form.accountMappings.some(
    (mapping, index) =>
      !mapping.subject.trim() ||
      !organizationUsers.some(
        (user) =>
          user.id === mapping.userId &&
          (!form.enabled || user.status === "ACTIVE"),
      ) ||
      duplicateSubject(mapping.subject, index) ||
      form.accountMappings.some(
        (other, i) => i !== index && other.userId === mapping.userId,
      ),
  );
  const save = useMutation({
    mutationFn: async () => {
      const body = {
        type: form.protocol,
        provider: form.provider,
        name: form.name,
        enabled: form.enabled,
        ...(!saml && form.credential ? { credential: form.credential } : {}),
        configuration: {
          issuer: form.issuer.trim(),
          ...(saml
            ? {
                ssoUrl: form.ssoUrl.trim(),
                idpCertificate: form.idpCertificate.trim(),
                ...(form.spEntityId.trim()
                  ? { spEntityId: form.spEntityId.trim() }
                  : {}),
                ...(form.sloUrl.trim() ? { sloUrl: form.sloUrl.trim() } : {}),
                ...(form.encryptAssertions ? { encryptAssertions: true } : {}),
              }
            : { clientId: form.clientId.trim() }),
          ...(form.linkScimExternalId ? { linkScimExternalId: true } : {}),
          accountMappings: form.accountMappings,
          ...(form.roleMappingsText.trim()
            ? {
                roleClaim: form.roleClaim.trim(),
                roleMappings: textToRoleMappings(form.roleMappingsText),
              }
            : {}),
          ...(form.provider === "GOOGLE_WORKSPACE" && form.hostedDomain.trim()
            ? { hostedDomain: form.hostedDomain.trim().toLowerCase() }
            : {}),
        },
      };
      return editingId
        ? api.put(`/v1/integrations/${editingId}`, body)
        : api.post("/v1/integrations", body);
    },
    onSuccess: async () => {
      setForm(initial);
      setEditingId(null);
      setError(null);
      setSaved(true);
      await cache.invalidateQueries({ queryKey: ["platform-integrations"] });
      await cache.invalidateQueries({ queryKey: ["sso-providers"] });
    },
    onError: (reason) =>
      setError(
        axios.isAxiosError<{ message?: string }>(reason)
          ? (reason.response?.data?.message ?? "Configuration SSO impossible.")
          : "Configuration SSO impossible.",
      ),
  });
  function submit(event: FormEvent) {
    event.preventDefault();
    setSaved(false);
    setError(null);
    if (!users.isSuccess || invalidMappings) {
      setError(
        "Vérifiez les comptes : utilisateur de votre organisation et identifiant fournisseur uniques.",
      );
      return;
    }
    if (needsNewSecret && !form.credential.trim()) {
      setError(
        "Saisissez le secret client correspondant à cette configuration SSO.",
      );
      return;
    }
    if (!save.isPending) save.mutate();
  }

  return (
    <Card>
      <CardContent>
        <Stack spacing={2}>
          <Typography variant="h6">Connexion SSO</Typography>
          <Typography color="text.secondary">
            Connectez Microsoft, Google, Keycloak, Authentik ou tout fournisseur
            OIDC ou SAML 2.0. Les comptes et les droits restent gérés dans
            RiskPilot ; le MFA RiskPilot reste obligatoire lorsqu’il est activé.
          </Typography>
          {error && <Alert severity="error">{error}</Alert>}
          {saved && (
            <Alert severity="success">Configuration SSO enregistrée.</Alert>
          )}
          {(integrations.isError || users.isError) && (
            <Alert severity="error">
              Impossible de charger les configurations ou les utilisateurs.
            </Alert>
          )}
          {integrations.data
            ?.filter((item) => item.type === "OIDC" || item.type === "SAML")
            .map((item) => (
              <Stack
                key={item.id}
                direction={{ xs: "column", sm: "row" }}
                gap={1}
              >
                <Typography sx={{ flex: 1 }}>
                  {item.name} · {item.type} ·{" "}
                  {item.enabled ? "Actif" : "Inactif"}
                </Typography>
                <Button
                  disabled={save.isPending || remove.isPending}
                  onClick={() => {
                    setEditingId(item.id);
                    setSaved(false);
                    setError(null);
                    setForm({
                      ...initial,
                      ...item.configuration,
                      protocol: item.type as Protocol,
                      ssoUrl: item.configuration.ssoUrl ?? "",
                      idpCertificate: item.configuration.idpCertificate ?? "",
                      spEntityId: item.configuration.spEntityId ?? "",
                      sloUrl: item.configuration.sloUrl ?? "",
                      encryptAssertions:
                        item.configuration.encryptAssertions ?? false,
                      linkScimExternalId:
                        item.configuration.linkScimExternalId ?? false,
                      name: item.name,
                      provider: item.provider,
                      enabled: item.enabled,
                      credential: "",
                      hostedDomain: item.configuration.hostedDomain ?? "",
                      accountMappings: item.configuration.accountMappings ?? [],
                      roleClaim: item.configuration.roleClaim ?? "",
                      roleMappingsText: roleMappingsToText(
                        item.configuration.roleMappings,
                      ),
                    });
                  }}
                >
                  Configurer
                </Button>
                <Button
                  color="error"
                  disabled={save.isPending || remove.isPending}
                  onClick={() => {
                    if (window.confirm(`Supprimer le SSO « ${item.name} » ?`))
                      remove.mutate(item.id);
                  }}
                >
                  Supprimer
                </Button>
              </Stack>
            ))}
          <Stack component="form" onSubmit={submit} spacing={2}>
            <Stack
              component="fieldset"
              disabled={save.isPending || remove.isPending}
              spacing={2}
              sx={{ border: 0, p: 0, m: 0, minWidth: 0 }}
            >
              <TextField
                required
                label="Nom affiché à la connexion"
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
              />
              <TextField
                select
                label="Protocole"
                value={form.protocol}
                disabled={editingId !== null}
                helperText="OIDC (recommandé) ou SAML 2.0 pour les fournisseurs qui n’exposent pas OIDC."
                onChange={(e) =>
                  setForm({
                    ...initial,
                    protocol: e.target.value as Protocol,
                    name: form.name,
                    provider:
                      e.target.value === "SAML" ? "KEYCLOAK" : initial.provider,
                    issuer:
                      e.target.value === "SAML"
                        ? ""
                        : presets[initial.provider],
                  })
                }
              >
                <MenuItem value="OIDC">OpenID Connect</MenuItem>
                <MenuItem value="SAML">SAML 2.0</MenuItem>
              </TextField>
              <TextField
                select
                label="Fournisseur SSO"
                value={form.provider}
                disabled={editingId !== null}
                helperText="Changer de fournisseur efface les identifiants et associations saisis."
                onChange={(e) =>
                  setForm({
                    ...form,
                    provider: e.target.value,
                    issuer: saml ? "" : presets[e.target.value],
                    clientId: "",
                    credential: "",
                    hostedDomain: "",
                    enabled: false,
                    accountMappings: [],
                  })
                }
              >
                {Object.entries(names)
                  .filter(
                    ([value]) =>
                      !saml ||
                      [
                        "KEYCLOAK",
                        "AUTHENTIK",
                        "MICROSOFT_ENTRA",
                        "GENERIC",
                      ].includes(value),
                  )
                  .map(([value, label]) => (
                    <MenuItem key={value} value={value}>
                      {saml && value === "GENERIC"
                        ? "Autre fournisseur SAML"
                        : label}
                    </MenuItem>
                  ))}
              </TextField>
              <TextField
                required
                label={
                  saml
                    ? "Identifiant du fournisseur (entityID)"
                    : "URL de l’émetteur SSO"
                }
                value={form.issuer}
                onChange={(e) => setForm({ ...form, issuer: e.target.value })}
                helperText={
                  saml
                    ? "Valeur entityID des métadonnées du fournisseur, par exemple https://sso.example.com/realms/riskpilot."
                    : form.provider === "MICROSOFT_ENTRA"
                      ? "Remplacez les zéros par l’identifiant du tenant Entra ID. Application de type Web, mono-tenant."
                      : form.provider === "GOOGLE_WORKSPACE"
                        ? "Créez un client OAuth de type application Web dans Google Cloud."
                        : "Autorisez cet émetteur dans OIDC_DIAGNOSTIC_ISSUERS côté serveur. Signature RS256 requise."
                }
              />
              {saml ? (
                <>
                  <TextField
                    required
                    label="Adresse de connexion SAML (SingleSignOnService)"
                    value={form.ssoUrl}
                    onChange={(e) =>
                      setForm({ ...form, ssoUrl: e.target.value })
                    }
                    helperText="URL HTTPS de la liaison HTTP-Redirect du fournisseur."
                  />
                  <TextField
                    required
                    multiline
                    minRows={4}
                    label="Certificat de signature du fournisseur (PEM)"
                    value={form.idpCertificate}
                    onChange={(e) =>
                      setForm({ ...form, idpCertificate: e.target.value })
                    }
                    helperText="Certificat X.509 public (-----BEGIN CERTIFICATE-----). Les réponses ou assertions non signées par ce certificat sont refusées ; SHA-1 est refusé."
                  />
                  <TextField
                    label="Identifiant RiskPilot (entityID du service, facultatif)"
                    value={form.spEntityId}
                    onChange={(e) =>
                      setForm({ ...form, spEntityId: e.target.value })
                    }
                    helperText="Par défaut, l’adresse des métadonnées RiskPilot."
                  />
                  <TextField
                    label="Adresse de déconnexion SAML (SingleLogoutService, facultative)"
                    value={form.sloUrl}
                    onChange={(e) =>
                      setForm({ ...form, sloUrl: e.target.value })
                    }
                    helperText="URL HTTPS de la liaison HTTP-Redirect : la déconnexion RiskPilot ferme aussi la session chez le fournisseur, et une déconnexion signée par le fournisseur ferme les sessions RiskPilot."
                  />
                  <FormControlLabel
                    label="Exiger des assertions chiffrées"
                    control={
                      <Switch
                        checked={form.encryptAssertions}
                        onChange={(e) =>
                          setForm({
                            ...form,
                            encryptAssertions: e.target.checked,
                          })
                        }
                      />
                    }
                  />
                  <Typography variant="body2" color="text.secondary">
                    RiskPilot génère la clé de chiffrement (la clé privée reste
                    chiffrée sur le serveur). Importez ensuite les métadonnées
                    chez le fournisseur pour qu’il chiffre les assertions avec
                    le certificat publié.
                  </Typography>
                  <TextField
                    label="Adresse de retour à enregistrer chez le fournisseur (ACS)"
                    value={
                      editingId === null
                        ? "Disponible après l’enregistrement"
                        : `${window.location.origin}/api/auth/sso/saml/${editingId}/acs`
                    }
                    slotProps={{ input: { readOnly: true } }}
                  />
                  <TextField
                    label="Métadonnées RiskPilot à importer chez le fournisseur"
                    value={
                      editingId === null
                        ? "Disponible après l’enregistrement"
                        : `${window.location.origin}/api/auth/sso/saml/${editingId}/metadata`
                    }
                    slotProps={{ input: { readOnly: true } }}
                  />
                </>
              ) : (
                <>
                  <TextField
                    required
                    label="Identifiant client"
                    value={form.clientId}
                    onChange={(e) =>
                      setForm({ ...form, clientId: e.target.value })
                    }
                    autoComplete="off"
                  />
                  <TextField
                    required={needsNewSecret}
                    type="password"
                    label="Secret client"
                    value={form.credential}
                    onChange={(e) =>
                      setForm({ ...form, credential: e.target.value })
                    }
                    autoComplete="new-password"
                    helperText={
                      editingId && !needsNewSecret
                        ? "Laissez vide pour conserver le secret chiffré."
                        : editingId
                          ? "Identité SSO modifiée : saisissez le nouveau secret client."
                          : "Stocké chiffré et jamais renvoyé par l’API."
                    }
                  />
                  <TextField
                    label="URL de retour à enregistrer chez le fournisseur"
                    value={`${window.location.origin}/api/auth/sso/callback`}
                    slotProps={{ input: { readOnly: true } }}
                  />
                </>
              )}
              {form.provider === "GOOGLE_WORKSPACE" && (
                <TextField
                  label="Domaine Google Workspace autorisé (facultatif)"
                  value={form.hostedDomain}
                  onChange={(e) =>
                    setForm({ ...form, hostedDomain: e.target.value })
                  }
                  helperText="Contrôlé sur la propriété hd du jeton Google."
                />
              )}
              <Typography fontWeight={700}>
                Rôles issus des groupes (facultatif)
              </Typography>
              <Typography variant="body2">
                Les rôles de risque, action, audit et lecture peuvent suivre les
                groupes du fournisseur à chaque connexion. Les rôles
                d’administration restent gérés dans RiskPilot et ne sont jamais
                attribués ni retirés par le fournisseur.
              </Typography>
              <TextField
                label={
                  saml ? "Attribut des groupes" : "Revendication des groupes"
                }
                value={form.roleClaim}
                onChange={(e) =>
                  setForm({ ...form, roleClaim: e.target.value })
                }
                helperText={
                  saml
                    ? "Nom de l’attribut SAML qui porte les groupes, par exemple Role ou http://schemas.xmlsoap.org/claims/Group."
                    : "Exemples : groups (Authentik), realm_access.roles (Keycloak), roles (Entra)."
                }
              />
              <TextField
                label="Correspondances groupe = rôle"
                multiline
                minRows={3}
                value={form.roleMappingsText}
                onChange={(e) =>
                  setForm({ ...form, roleMappingsText: e.target.value })
                }
                helperText="Une ligne par correspondance, par exemple : riskpilot-managers=ROLE_RISK_MANAGER. Rôles autorisés : ROLE_RISK_MANAGER, ROLE_ACTION_OWNER, ROLE_AUDITOR, ROLE_VIEWER."
              />
              <Typography fontWeight={700}>Comptes autorisés</Typography>
              <Typography variant="body2">
                Associez un utilisateur à son identifiant{" "}
                {form.provider === "MICROSOFT_ENTRA"
                  ? "d’objet Microsoft (oid)"
                  : "de sujet OIDC (sub)"}
                . Aucun compte n’est créé automatiquement.
              </Typography>
              <FormControlLabel
                label="Rattacher aussi les comptes créés par SCIM (identifiant externe)"
                control={
                  <Switch
                    checked={form.linkScimExternalId}
                    onChange={(e) =>
                      setForm({ ...form, linkScimExternalId: e.target.checked })
                    }
                  />
                }
              />
              <Typography variant="body2" color="text.secondary">
                Un compte provisionné par SCIM dont l’externalId est égal à{" "}
                {saml ? "l’identifiant NameID" : "l’identifiant sub"} peut se
                connecter sans association manuelle. Il reste Lecteur tant que
                les groupes ne lui donnent pas d’autre rôle.
              </Typography>
              {form.accountMappings.map((mapping, index) => (
                <Stack
                  key={index}
                  direction={{ xs: "column", sm: "row" }}
                  gap={1}
                >
                  <TextField
                    select
                    required
                    fullWidth
                    label="Utilisateur RiskPilot"
                    helperText="Compte de votre organisation ; les comptes désactivés ne peuvent pas se connecter."
                    value={mapping.userId || ""}
                    onChange={(e) =>
                      setForm({
                        ...form,
                        accountMappings: form.accountMappings.map((m, i) =>
                          i === index
                            ? { ...m, userId: Number(e.target.value) }
                            : m,
                        ),
                      })
                    }
                  >
                    <MenuItem value="" disabled>
                      Choisir un utilisateur
                    </MenuItem>
                    {organizationUsers
                      .filter(
                        (user) =>
                          user.status === "ACTIVE" ||
                          user.id === mapping.userId,
                      )
                      .map((user) => (
                        <MenuItem
                          key={user.id}
                          value={user.id}
                          disabled={
                            user.status !== "ACTIVE" ||
                            form.accountMappings.some(
                              (other, i) =>
                                i !== index && other.userId === user.id,
                            )
                          }
                        >
                          {user.email}
                          {user.status !== "ACTIVE" ? " · Désactivé" : ""}
                        </MenuItem>
                      ))}
                  </TextField>
                  <TextField
                    required
                    fullWidth
                    label={
                      form.provider === "MICROSOFT_ENTRA"
                        ? "Identifiant Microsoft (oid)"
                        : "Identifiant OIDC (sub)"
                    }
                    value={mapping.subject}
                    error={duplicateSubject(mapping.subject, index)}
                    helperText={
                      duplicateSubject(mapping.subject, index)
                        ? "Cet identifiant est déjà associé à un autre compte."
                        : undefined
                    }
                    onChange={(e) =>
                      setForm({
                        ...form,
                        accountMappings: form.accountMappings.map((m, i) =>
                          i === index
                            ? { ...m, subject: e.target.value.trim() }
                            : m,
                        ),
                      })
                    }
                  />
                  <Button
                    aria-label={`Retirer le compte ${index + 1}`}
                    onClick={() =>
                      setForm({
                        ...form,
                        accountMappings: form.accountMappings.filter(
                          (_, i) => i !== index,
                        ),
                      })
                    }
                  >
                    Retirer
                  </Button>
                </Stack>
              ))}
              <Button
                disabled={
                  !users.isSuccess ||
                  !organizationUsers.some(
                    (user) =>
                      user.status === "ACTIVE" &&
                      !form.accountMappings.some(
                        (mapping) => mapping.userId === user.id,
                      ),
                  ) ||
                  form.accountMappings.some((mapping) => !mapping.userId)
                }
                onClick={() =>
                  setForm({
                    ...form,
                    accountMappings: [
                      ...form.accountMappings,
                      { userId: 0, subject: "" },
                    ],
                  })
                }
              >
                Associer un compte
              </Button>
              <FormControlLabel
                label="Activer la connexion SSO"
                control={
                  <Switch
                    checked={form.enabled}
                    onChange={(e) =>
                      setForm({ ...form, enabled: e.target.checked })
                    }
                  />
                }
              />
              <Button
                type="submit"
                variant="contained"
                disabled={
                  save.isPending ||
                  !users.isSuccess ||
                  (form.enabled &&
                    !form.accountMappings.length &&
                    !form.linkScimExternalId)
                }
              >
                {save.isPending ? "Vérification…" : "Enregistrer le SSO"}
              </Button>
              {editingId !== null && (
                <Button
                  onClick={() => {
                    setEditingId(null);
                    setForm(initial);
                  }}
                >
                  Nouvelle configuration
                </Button>
              )}
            </Stack>
          </Stack>
        </Stack>
      </CardContent>
    </Card>
  );
}
