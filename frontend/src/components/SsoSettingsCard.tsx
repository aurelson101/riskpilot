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

type Mapping = { userId: number; subject: string };
type Configuration = {
  issuer: string;
  clientId: string;
  accountMappings: Mapping[];
  hostedDomain?: string;
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
  name: "",
  provider: "MICROSOFT_ENTRA",
  issuer: presets.MICROSOFT_ENTRA,
  clientId: "",
  credential: "",
  hostedDomain: "",
  enabled: false,
  accountMappings: [] as Mapping[],
};

export function SsoSettingsCard() {
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
    queryKey: ["sso-users"],
    queryFn: async () =>
      (await api.get<{ id: number; email: string }[]>("/users")).data,
  });
  const save = useMutation({
    mutationFn: async () => {
      const body = {
        type: "OIDC",
        provider: form.provider,
        name: form.name,
        enabled: form.enabled,
        ...(form.credential ? { credential: form.credential } : {}),
        configuration: {
          issuer: form.issuer.trim(),
          clientId: form.clientId.trim(),
          accountMappings: form.accountMappings,
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
    if (!save.isPending) save.mutate();
  }

  return (
    <Card>
      <CardContent>
        <Stack spacing={2}>
          <Typography variant="h6">Connexion SSO</Typography>
          <Typography color="text.secondary">
            Connectez Microsoft, Google ou un fournisseur OIDC. Les comptes et
            les droits restent gérés dans RiskPilot ; le MFA RiskPilot reste
            obligatoire lorsqu’il est activé.
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
            ?.filter((item) => item.type === "OIDC")
            .map((item) => (
              <Stack
                key={item.id}
                direction={{ xs: "column", sm: "row" }}
                gap={1}
              >
                <Typography sx={{ flex: 1 }}>
                  {item.name} · {item.enabled ? "Actif" : "Inactif"}
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
                      name: item.name,
                      provider: item.provider,
                      enabled: item.enabled,
                      credential: "",
                      hostedDomain: item.configuration.hostedDomain ?? "",
                      accountMappings: item.configuration.accountMappings ?? [],
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
                label="Fournisseur SSO"
                value={form.provider}
                disabled={editingId !== null}
                onChange={(e) =>
                  setForm({
                    ...form,
                    provider: e.target.value,
                    issuer: presets[e.target.value],
                    accountMappings: [],
                  })
                }
              >
                {Object.entries(names).map(([value, label]) => (
                  <MenuItem key={value} value={value}>
                    {label}
                  </MenuItem>
                ))}
              </TextField>
              <TextField
                required
                label="URL de l’émetteur SSO"
                value={form.issuer}
                onChange={(e) => setForm({ ...form, issuer: e.target.value })}
                helperText={
                  form.provider === "MICROSOFT_ENTRA"
                    ? "Remplacez les zéros par l’identifiant du tenant Entra ID. Application de type Web, mono-tenant."
                    : form.provider === "GOOGLE_WORKSPACE"
                      ? "Créez un client OAuth de type application Web dans Google Cloud."
                      : "Autorisez cet émetteur dans OIDC_DIAGNOSTIC_ISSUERS côté serveur. Signature RS256 requise."
                }
              />
              <TextField
                required
                label="Identifiant client"
                value={form.clientId}
                onChange={(e) => setForm({ ...form, clientId: e.target.value })}
                autoComplete="off"
              />
              <TextField
                required={editingId === null}
                type="password"
                label="Secret client"
                value={form.credential}
                onChange={(e) =>
                  setForm({ ...form, credential: e.target.value })
                }
                autoComplete="new-password"
                helperText={
                  editingId
                    ? "Laissez vide pour conserver le secret chiffré."
                    : "Stocké chiffré et jamais renvoyé par l’API."
                }
              />
              <TextField
                label="URL de retour à enregistrer chez le fournisseur"
                value={`${window.location.origin}/api/auth/sso/callback`}
                slotProps={{ input: { readOnly: true } }}
              />
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
              <Typography fontWeight={700}>Comptes autorisés</Typography>
              <Typography variant="body2">
                Associez un utilisateur à son identifiant{" "}
                {form.provider === "MICROSOFT_ENTRA"
                  ? "d’objet Microsoft (oid)"
                  : "de sujet OIDC (sub)"}
                . Aucun compte n’est créé automatiquement.
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
                    {users.data?.map((user) => (
                      <MenuItem key={user.id} value={user.id}>
                        {user.email}
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
                  users.isError ||
                  (form.enabled && !form.accountMappings.length)
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
