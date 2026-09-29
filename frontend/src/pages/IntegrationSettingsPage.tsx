import { useState, type FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Button,
  Card,
  CardContent,
  Checkbox,
  Chip,
  FormControlLabel,
  FormGroup,
  MenuItem,
  Stack,
  Switch,
  TextField,
  Typography,
} from "@mui/material";
import axios from "axios";
import { useNavigate } from "react-router-dom";
import { api } from "../api/client";

type Integration = {
  id: number;
  type: string;
  provider: string;
  name: string;
  configuration: Record<string, unknown>;
  credentialPrefix: string | null;
  credentialConfigured?: boolean;
  enabled: boolean;
};
type IntegrationType = "API_KEY" | "DIRECTORY" | "OIDC_DIAGNOSTIC";
type OidcResult = {
  validated: boolean;
  issuer: string;
  authorizationCode: boolean;
  pkceS256: boolean;
  signingAlgorithms: string[];
};
type EmailSummary = {
  provider: string;
  enabled: boolean;
  ready: boolean;
  oauthConnected: boolean;
  connectedEmail: string | null;
};
type Form = {
  type: IntegrationType;
  name: string;
  scopes: string[];
  host: string;
  port: string;
  baseDn: string;
  bindDn: string;
  credential: string;
  userFilter: string;
  testUsername: string;
  groupDn: string;
  groupRole: string;
  caCertificate: string;
  oidcProvider: "GOOGLE_WORKSPACE" | "MICROSOFT_ENTRA";
  issuer: string;
  enabled: boolean;
};

const initial: Form = {
  type: "API_KEY",
  name: "",
  scopes: ["risks:read"],
  host: "ldaps://",
  port: "636",
  baseDn: "",
  bindDn: "",
  credential: "",
  userFilter: "(&(objectClass=user)(sAMAccountName={username}))",
  testUsername: "",
  groupDn: "",
  groupRole: "ROLE_RISK_MANAGER",
  caCertificate: "",
  oidcProvider: "GOOGLE_WORKSPACE",
  issuer: "https://accounts.google.com",
  enabled: false,
};
const scopes = [
  ["risks:read", "Lire les risques"],
  ["controls:read", "Lire les mesures de sécurité"],
  ["actions:read", "Lire les plans d’action"],
  ["events:write", "Écrire des événements"],
] as const;
const emailProviders: Record<string, string> = {
  SMTP2GO: "SMTP2GO",
  GOOGLE_WORKSPACE: "API Gmail",
  MICROSOFT_365: "Microsoft Graph",
  CUSTOM: "SMTP personnalisé",
};
const typeLabels: Record<string, string> = {
  API_KEY: "Accès API",
  DIRECTORY: "Diagnostic LDAPS",
  OIDC: "Configuration OIDC",
  SAML: "Configuration SAML",
  SCIM: "Configuration SCIM",
  WEBHOOK: "Configuration webhook",
  CONNECTOR: "Connecteur métier",
};

function errorMessage(error: unknown): string {
  return axios.isAxiosError<{ message?: string }>(error)
    ? (error.response?.data?.message ?? "L’opération a échoué.")
    : "L’opération a échoué.";
}

export function IntegrationSettingsPage() {
  const navigate = useNavigate();
  const cache = useQueryClient();
  const integrations = useQuery({
    queryKey: ["platform-integrations"],
    queryFn: async () =>
      (await api.get<{ items: Integration[] }>("/v1/integrations")).data.items,
  });
  const email = useQuery({
    queryKey: ["email-settings"],
    queryFn: async () => (await api.get<EmailSummary>("/settings/email")).data,
  });
  const [form, setForm] = useState<Form>(initial);
  const [secret, setSecret] = useState<string | null>(null);
  const [directoryResult, setDirectoryResult] = useState<string | null>(null);
  const [oidcResult, setOidcResult] = useState<OidcResult | null>(null);
  const [operationError, setOperationError] = useState<string | null>(null);
  const create = useMutation({
    mutationFn: async () => {
      if (form.type === "OIDC_DIAGNOSTIC") {
        const response = await api.post<OidcResult>(
          "/v1/integrations/oidc-discovery-test",
          { provider: form.oidcProvider, issuer: form.issuer },
        );

        return { oidc: response.data };
      }
      const directory = form.type === "DIRECTORY";
      const configuration = directory
        ? {
            host: form.host,
            port: Number(form.port),
            baseDn: form.baseDn,
            bindDn: form.bindDn,
            userFilter: form.userFilter,
            testUsername: form.testUsername,
            groupMappings: { [form.groupDn]: form.groupRole },
            ...(form.caCertificate.trim()
              ? { caCertificate: form.caCertificate }
              : {}),
          }
        : { scopes: form.scopes };
      const created = (
        await api.post<Integration & { secret: string | null }>(
          "/v1/integrations",
          {
            type: form.type,
            provider: directory ? "ACTIVE_DIRECTORY" : "GENERIC",
            name: form.name,
            configuration,
            ...(directory ? { credential: form.credential } : {}),
            enabled: directory ? false : form.enabled,
          },
        )
      ).data;

      return { created };
    },
    onSuccess: async (data) => {
      setSecret(data.created?.secret ?? null);
      setOidcResult(data.oidc ?? null);
      if (data.created) setForm(initial);
      setOperationError(null);
      await cache.invalidateQueries({ queryKey: ["platform-integrations"] });
    },
    onError: (error) => setOperationError(errorMessage(error)),
  });
  const supported = integrations.data?.filter((item) =>
    ["API_KEY", "DIRECTORY"].includes(item.type),
  );
  const legacy = integrations.data?.filter(
    (item) => !["API_KEY", "DIRECTORY", "CONNECTOR"].includes(item.type),
  );

  function submit(event: FormEvent) {
    event.preventDefault();
    setSecret(null);
    setOidcResult(null);
    setOperationError(null);
    create.mutate();
  }

  async function remove(item: Integration) {
    if (!window.confirm(`Supprimer « ${item.name} » ?`)) return;
    try {
      await api.delete(`/v1/integrations/${item.id}`);
      await cache.invalidateQueries({ queryKey: ["platform-integrations"] });
    } catch (error) {
      setOperationError(errorMessage(error));
    }
  }

  async function toggle(item: Integration) {
    try {
      await api.put(`/v1/integrations/${item.id}`, {
        name: item.name,
        configuration: item.configuration,
        enabled: !item.enabled,
      });
      await cache.invalidateQueries({ queryKey: ["platform-integrations"] });
    } catch (error) {
      setOperationError(errorMessage(error));
    }
  }

  return (
    <Stack spacing={3} maxWidth={1000}>
      <div>
        <Typography variant="h4" fontWeight={750}>
          Intégrations
        </Typography>
        <Typography color="text.secondary">
          Configurez chaque service dans son espace, avec ses propres droits et
          secrets.
        </Typography>
      </div>
      {operationError && <Alert severity="error">{operationError}</Alert>}
      {secret && (
        <Alert severity="warning">
          Copiez cette clé maintenant, elle ne sera plus affichée :{" "}
          <strong>{secret}</strong>
        </Alert>
      )}

      <Card variant="outlined">
        <CardContent>
          <Stack
            direction={{ xs: "column", sm: "row" }}
            alignItems={{ sm: "center" }}
            spacing={2}
          >
            <div style={{ flex: 1 }}>
              <Typography variant="h6">Messagerie</Typography>
              <Typography color="text.secondary">
                SMTP, API Gmail ou Microsoft Graph pour les notifications.
              </Typography>
            </div>
            {email.data && (
              <Chip
                color={
                  email.data.ready && email.data.enabled ? "success" : "default"
                }
                label={`${emailProviders[email.data.provider] ?? email.data.provider} · ${
                  email.data.ready && email.data.enabled
                    ? "opérationnel"
                    : "à configurer"
                }`}
              />
            )}
            <Button
              variant="outlined"
              onClick={() => navigate("/administration/email-settings")}
            >
              Configurer
            </Button>
          </Stack>
        </CardContent>
      </Card>

      <Alert severity="info">
        La découverte OIDC Google/Entra peut être vérifiée ici. L’activation du
        SSO OIDC/SAML et du provisioning SCIM reste masquée tant que le parcours
        complet de connexion n’est pas disponible.
      </Alert>

      <Card>
        <CardContent component="form" onSubmit={submit}>
          <Stack spacing={2}>
            <div>
              <Typography variant="h6">Nouvel accès ou diagnostic</Typography>
              <Typography color="text.secondary">
                Créez une clé API, vérifiez un annuaire LDAPS ou la découverte
                OIDC d’un fournisseur d’identité.
              </Typography>
            </div>
            <TextField
              select
              label="Usage"
              value={form.type}
              onChange={(event) =>
                setForm({
                  ...initial,
                  type: event.target.value as IntegrationType,
                })
              }
            >
              <MenuItem value="API_KEY">Accès API RiskPilot</MenuItem>
              <MenuItem value="DIRECTORY">
                Diagnostic annuaire Microsoft AD (LDAPS)
              </MenuItem>
              <MenuItem value="OIDC_DIAGNOSTIC">
                Diagnostic SSO — découverte OIDC
              </MenuItem>
            </TextField>
            {form.type !== "OIDC_DIAGNOSTIC" && (
              <TextField
                required
                label={
                  form.type === "DIRECTORY"
                    ? "Nom de l’annuaire"
                    : "Nom de l’application"
                }
                value={form.name}
                onChange={(event) =>
                  setForm({ ...form, name: event.target.value })
                }
              />
            )}
            {form.type === "API_KEY" ? (
              <>
                <Typography fontWeight={700}>Droits accordés</Typography>
                <FormGroup>
                  {scopes.map(([value, label]) => (
                    <FormControlLabel
                      key={value}
                      control={
                        <Checkbox
                          checked={form.scopes.includes(value)}
                          onChange={(event) =>
                            setForm({
                              ...form,
                              scopes: event.target.checked
                                ? [...form.scopes, value]
                                : form.scopes.filter(
                                    (scope) => scope !== value,
                                  ),
                            })
                          }
                        />
                      }
                      label={label}
                    />
                  ))}
                </FormGroup>
                <FormControlLabel
                  control={
                    <Switch
                      checked={form.enabled}
                      onChange={(event) =>
                        setForm({ ...form, enabled: event.target.checked })
                      }
                    />
                  }
                  label="Activer la clé dès sa création"
                />
              </>
            ) : form.type === "DIRECTORY" ? (
              <Stack spacing={2}>
                <Alert severity="info">
                  Ce diagnostic vérifie le chiffrement, le bind et la recherche.
                  Il n’active pas la connexion des utilisateurs.
                </Alert>
                <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                  <TextField
                    required
                    fullWidth
                    label="Serveur LDAPS"
                    value={form.host}
                    onChange={(event) =>
                      setForm({ ...form, host: event.target.value })
                    }
                    helperText="Exemple : ldaps://ad.example.com"
                  />
                  <TextField
                    required
                    label="Port"
                    type="number"
                    value={form.port}
                    onChange={(event) =>
                      setForm({ ...form, port: event.target.value })
                    }
                    inputProps={{ min: 636, max: 636 }}
                  />
                </Stack>
                <TextField
                  required
                  label="Base DN"
                  value={form.baseDn}
                  onChange={(event) =>
                    setForm({ ...form, baseDn: event.target.value })
                  }
                />
                <TextField
                  required
                  label="Bind DN"
                  value={form.bindDn}
                  onChange={(event) =>
                    setForm({ ...form, bindDn: event.target.value })
                  }
                />
                <TextField
                  required
                  type="password"
                  label="Mot de passe du compte de service"
                  value={form.credential}
                  onChange={(event) =>
                    setForm({ ...form, credential: event.target.value })
                  }
                  autoComplete="new-password"
                />
                <TextField
                  required
                  label="Filtre utilisateur"
                  value={form.userFilter}
                  onChange={(event) =>
                    setForm({ ...form, userFilter: event.target.value })
                  }
                  helperText="Le filtre doit contenir {username}."
                />
                <TextField
                  required
                  label="Utilisateur de test"
                  value={form.testUsername}
                  onChange={(event) =>
                    setForm({ ...form, testUsername: event.target.value })
                  }
                  helperText="Compte non privilégié utilisé uniquement pour vérifier la recherche."
                />
                <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                  <TextField
                    required
                    fullWidth
                    label="Groupe Microsoft AD"
                    value={form.groupDn}
                    onChange={(event) =>
                      setForm({ ...form, groupDn: event.target.value })
                    }
                  />
                  <TextField
                    select
                    fullWidth
                    label="Rôle RiskPilot"
                    value={form.groupRole}
                    onChange={(event) =>
                      setForm({ ...form, groupRole: event.target.value })
                    }
                  >
                    <MenuItem value="ROLE_VIEWER">Lecteur</MenuItem>
                    <MenuItem value="ROLE_RISK_MANAGER">
                      Gestionnaire des risques
                    </MenuItem>
                    <MenuItem value="ROLE_ADMIN">Administrateur</MenuItem>
                  </TextField>
                </Stack>
                <TextField
                  multiline
                  minRows={4}
                  label="Autorité de certification PEM (facultatif)"
                  value={form.caCertificate}
                  onChange={(event) =>
                    setForm({ ...form, caCertificate: event.target.value })
                  }
                />
              </Stack>
            ) : (
              <Stack spacing={2}>
                <Alert severity="info">
                  Ce diagnostic contrôle la découverte, le flux Authorization
                  Code et les algorithmes de signature. Il n’active pas la
                  connexion des utilisateurs et ne demande aucun secret.
                </Alert>
                <TextField
                  select
                  label="Fournisseur d’identité"
                  value={form.oidcProvider}
                  onChange={(event) => {
                    const provider = event.target.value as Form["oidcProvider"];
                    setForm({
                      ...form,
                      oidcProvider: provider,
                      issuer:
                        provider === "GOOGLE_WORKSPACE"
                          ? "https://accounts.google.com"
                          : "https://login.microsoftonline.com/00000000-0000-0000-0000-000000000000/v2.0",
                    });
                  }}
                >
                  <MenuItem value="GOOGLE_WORKSPACE">Google Workspace</MenuItem>
                  <MenuItem value="MICROSOFT_ENTRA">
                    Microsoft Entra ID
                  </MenuItem>
                </TextField>
                <TextField
                  required
                  label="Émetteur OIDC"
                  value={form.issuer}
                  onChange={(event) =>
                    setForm({ ...form, issuer: event.target.value })
                  }
                  helperText={
                    form.oidcProvider === "MICROSOFT_ENTRA"
                      ? "Remplacez les zéros par l’identifiant UUID du tenant Entra ID."
                      : "Émetteur officiel Google, sans chemin supplémentaire."
                  }
                />
              </Stack>
            )}
            <Button
              type="submit"
              variant="contained"
              disabled={
                create.isPending ||
                (form.type === "API_KEY" && form.scopes.length === 0)
              }
            >
              {form.type === "DIRECTORY"
                ? "Enregistrer le diagnostic"
                : form.type === "OIDC_DIAGNOSTIC"
                  ? "Vérifier la découverte OIDC"
                  : "Créer la clé API"}
            </Button>
          </Stack>
        </CardContent>
      </Card>

      <Stack spacing={1}>
        <Typography variant="h6">Accès configurés</Typography>
        {supported?.length === 0 && (
          <Alert severity="info">Aucun accès technique configuré.</Alert>
        )}
        {supported?.map((item) => (
          <Card key={item.id} variant="outlined">
            <CardContent>
              <Stack
                direction={{ xs: "column", sm: "row" }}
                spacing={1}
                alignItems={{ sm: "center" }}
              >
                <div style={{ flex: 1 }}>
                  <Typography fontWeight={700}>{item.name}</Typography>
                  <Typography variant="body2" color="text.secondary">
                    {typeLabels[item.type] ?? item.type}
                    {item.credentialPrefix
                      ? ` · ${item.credentialPrefix}…`
                      : ""}
                  </Typography>
                </div>
                <Chip
                  color={item.enabled ? "success" : "default"}
                  label={item.enabled ? "Actif" : "Inactif"}
                />
                {item.type === "API_KEY" && (
                  <Button onClick={() => toggle(item)}>
                    {item.enabled ? "Désactiver" : "Activer"}
                  </Button>
                )}
                {item.type === "DIRECTORY" && (
                  <Button
                    onClick={async () => {
                      setDirectoryResult(null);
                      try {
                        const response = await api.post<{
                          matchedEntries: number;
                        }>(`/v1/integrations/${item.id}/directory-test`);
                        setDirectoryResult(
                          `LDAPS validé — ${response.data.matchedEntries} entrée(s) trouvée(s).`,
                        );
                      } catch (error) {
                        setDirectoryResult(errorMessage(error));
                      }
                    }}
                  >
                    Tester LDAPS
                  </Button>
                )}
                <Button color="error" onClick={() => remove(item)}>
                  Supprimer
                </Button>
              </Stack>
            </CardContent>
          </Card>
        ))}
      </Stack>

      {directoryResult && (
        <Alert
          severity={
            directoryResult.startsWith("LDAPS validé") ? "success" : "error"
          }
        >
          {directoryResult}
        </Alert>
      )}

      {oidcResult && (
        <Alert severity="success">
          Découverte OIDC validée pour {oidcResult.issuer}. Authorization Code :
          oui · PKCE S256 annoncé : {oidcResult.pkceS256 ? "oui" : "non"} ·
          signatures : {oidcResult.signingAlgorithms.join(", ")}.
        </Alert>
      )}

      {legacy && legacy.length > 0 && (
        <Card variant="outlined">
          <CardContent>
            <Stack spacing={1}>
              <Typography variant="h6">
                Configurations non raccordées
              </Typography>
              <Typography color="text.secondary">
                Ces anciennes fiches restent visibles pour pouvoir être
                supprimées, mais elles ne sont pas présentées comme
                opérationnelles.
              </Typography>
              {legacy.map((item) => (
                <Stack
                  key={item.id}
                  direction={{ xs: "column", sm: "row" }}
                  spacing={1}
                  alignItems={{ sm: "center" }}
                >
                  <Typography flex={1}>{item.name}</Typography>
                  <Chip label={typeLabels[item.type] ?? item.type} />
                  <Chip label="Non raccordé" />
                  <Button color="error" onClick={() => remove(item)}>
                    Supprimer
                  </Button>
                </Stack>
              ))}
            </Stack>
          </CardContent>
        </Card>
      )}
    </Stack>
  );
}
