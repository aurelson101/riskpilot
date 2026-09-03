import { useState, type FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Button,
  Card,
  CardContent,
  Chip,
  FormControlLabel,
  MenuItem,
  Stack,
  Switch,
  TextField,
  Typography,
} from "@mui/material";
import { api } from "../api/client";
import { AiCopilotSettingsPanel } from "../components/AiCopilotSettingsPanel";

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
const initial = {
  type: "OIDC",
  provider: "GOOGLE_WORKSPACE",
  name: "",
  issuer: "",
  scopes: "risks:read",
  host: "ldaps://",
  port: "636",
  baseDn: "",
  bindDn: "",
  credential: "",
  userFilter: "(&(objectClass=user)(sAMAccountName={username}))",
  groupDn: "",
  groupRole: "ROLE_RISK_MANAGER",
  caCertificate: "",
  enabled: false,
};

export function IntegrationSettingsPage() {
  const cache = useQueryClient();
  const query = useQuery({
    queryKey: ["platform-integrations"],
    queryFn: async () =>
      (await api.get<{ items: Integration[] }>("/v1/integrations")).data.items,
  });
  const [form, setForm] = useState(initial);
  const [secret, setSecret] = useState<string | null>(null);
  const [directoryResult, setDirectoryResult] = useState<string | null>(null);
  const create = useMutation({
    mutationFn: async () => {
      const configuration =
        form.type === "API_KEY"
          ? {
              scopes: form.scopes
                .split(",")
                .map((value) => value.trim())
                .filter(Boolean),
            }
          : form.type === "WEBHOOK"
            ? { url: form.issuer, events: ["risk.updated", "action.overdue"] }
            : form.type === "DIRECTORY"
              ? {
                  host: form.host,
                  port: Number(form.port),
                  baseDn: form.baseDn,
                  bindDn: form.bindDn,
                  userFilter: form.userFilter,
                  groupMappings: { [form.groupDn]: form.groupRole },
                  ...(form.caCertificate.trim()
                    ? { caCertificate: form.caCertificate }
                    : {}),
                }
              : {
                  issuer: form.issuer,
                  groupRoleMappings: { "riskpilot-admins": "ROLE_ADMIN" },
                };
      return (
        await api.post<Integration & { secret: string | null }>(
          "/v1/integrations",
          {
            type: form.type,
            provider: form.provider,
            name: form.name,
            configuration,
            ...(form.type === "DIRECTORY"
              ? { credential: form.credential }
              : {}),
            enabled: form.enabled,
          },
        )
      ).data;
    },
    onSuccess: async (data) => {
      setSecret(data.secret);
      setForm(initial);
      await cache.invalidateQueries({ queryKey: ["platform-integrations"] });
    },
  });
  function submit(event: FormEvent) {
    event.preventDefault();
    create.mutate();
  }
  return (
    <Stack spacing={2}>
      <div>
        <Typography variant="h5" fontWeight={700}>
          Identité et intégrations
        </Typography>
        <Typography color="text.secondary">
          OIDC/SAML, AD en LDAPS, provisioning SCIM, clés API limitées et
          webhooks signés.
        </Typography>
      </div>
      <AiCopilotSettingsPanel />
      {secret && (
        <Alert severity="warning">
          Copiez ce secret maintenant, il ne sera plus affiché :{" "}
          <strong>{secret}</strong>
        </Alert>
      )}
      <Card>
        <CardContent component="form" onSubmit={submit}>
          <Stack spacing={2}>
            <Typography variant="h6">Nouvelle intégration</Typography>
            <Stack direction={{ xs: "column", md: "row" }} spacing={2}>
              <TextField
                select
                label="Type"
                value={form.type}
                onChange={(e) => setForm({ ...form, type: e.target.value })}
                fullWidth
              >
                {[
                  "OIDC",
                  "SAML",
                  "DIRECTORY",
                  "SCIM",
                  "API_KEY",
                  "WEBHOOK",
                ].map((item) => (
                  <MenuItem key={item} value={item}>
                    {item}
                  </MenuItem>
                ))}
              </TextField>
              <TextField
                select
                label="Fournisseur"
                value={form.provider}
                onChange={(e) => setForm({ ...form, provider: e.target.value })}
                fullWidth
              >
                {[
                  "GOOGLE_WORKSPACE",
                  "MICROSOFT_ENTRA",
                  "ACTIVE_DIRECTORY",
                  "GENERIC",
                ].map((item) => (
                  <MenuItem key={item} value={item}>
                    {item}
                  </MenuItem>
                ))}
              </TextField>
            </Stack>
            <TextField
              required
              label="Nom"
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
            />
            {form.type === "DIRECTORY" ? (
              <Stack spacing={2}>
                <TextField
                  required
                  label="Hôte LDAPS"
                  value={form.host}
                  onChange={(e) => setForm({ ...form, host: e.target.value })}
                  helperText="Format obligatoire : ldaps://ad.exemple.fr"
                />
                <TextField
                  required
                  label="Port"
                  type="number"
                  value={form.port}
                  onChange={(e) => setForm({ ...form, port: e.target.value })}
                  helperText="636 uniquement"
                />
                <TextField
                  required
                  label="Base DN"
                  value={form.baseDn}
                  onChange={(e) => setForm({ ...form, baseDn: e.target.value })}
                />
                <TextField
                  required
                  label="Bind DN"
                  value={form.bindDn}
                  onChange={(e) => setForm({ ...form, bindDn: e.target.value })}
                />
                <TextField
                  required
                  type="password"
                  label="Mot de passe du compte de service"
                  value={form.credential}
                  onChange={(e) =>
                    setForm({ ...form, credential: e.target.value })
                  }
                  autoComplete="new-password"
                />
                <TextField
                  required
                  label="Filtre utilisateur"
                  value={form.userFilter}
                  onChange={(e) =>
                    setForm({ ...form, userFilter: e.target.value })
                  }
                  helperText="Doit contenir {username}"
                />
                <Stack direction={{ xs: "column", md: "row" }} spacing={2}>
                  <TextField
                    required
                    fullWidth
                    label="DN du groupe AD"
                    value={form.groupDn}
                    onChange={(e) =>
                      setForm({ ...form, groupDn: e.target.value })
                    }
                  />
                  <TextField
                    select
                    fullWidth
                    label="Rôle RiskPilot"
                    value={form.groupRole}
                    onChange={(e) =>
                      setForm({ ...form, groupRole: e.target.value })
                    }
                  >
                    {["ROLE_VIEWER", "ROLE_RISK_MANAGER", "ROLE_ADMIN"].map(
                      (role) => (
                        <MenuItem key={role} value={role}>
                          {role}
                        </MenuItem>
                      ),
                    )}
                  </TextField>
                </Stack>
                <TextField
                  multiline
                  minRows={4}
                  label="CA PEM (recommandée)"
                  value={form.caCertificate}
                  onChange={(e) =>
                    setForm({ ...form, caCertificate: e.target.value })
                  }
                  helperText="La validation TLS est explicitement attestée lorsque la CA est fournie."
                />
              </Stack>
            ) : form.type === "API_KEY" ? (
              <TextField
                label="Portées (séparées par des virgules)"
                value={form.scopes}
                onChange={(e) => setForm({ ...form, scopes: e.target.value })}
                helperText="risks:read, controls:read, actions:read, events:write, scim:write"
              />
            ) : (
              form.type !== "SCIM" && (
                <TextField
                  required
                  label={
                    form.type === "WEBHOOK"
                      ? "URL HTTPS"
                      : "Issuer / Metadata URL"
                  }
                  value={form.issuer}
                  onChange={(e) => setForm({ ...form, issuer: e.target.value })}
                />
              )
            )}
            <FormControlLabel
              control={
                <Switch
                  checked={form.enabled}
                  onChange={(e) =>
                    setForm({ ...form, enabled: e.target.checked })
                  }
                />
              }
              label="Activer après enregistrement"
            />
            <Button
              type="submit"
              variant="contained"
              disabled={create.isPending}
            >
              Créer
            </Button>
          </Stack>
        </CardContent>
      </Card>
      <Stack spacing={1}>
        {query.data?.map((item) => (
          <Card key={item.id}>
            <CardContent>
              <Stack
                direction={{ xs: "column", sm: "row" }}
                spacing={1}
                alignItems={{ sm: "center" }}
              >
                <Typography fontWeight={700} flex={1}>
                  {item.name}
                </Typography>
                <Chip label={item.type} />
                <Chip
                  color={item.enabled ? "success" : "default"}
                  label={item.enabled ? "Actif" : "Inactif"}
                />
                {item.credentialPrefix && (
                  <Typography variant="body2">
                    {item.credentialPrefix}…
                  </Typography>
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
                      } catch {
                        setDirectoryResult(
                          "Échec de validation LDAPS. Vérifiez la CA, le bind, le filtre et le réseau.",
                        );
                      }
                    }}
                  >
                    Tester LDAPS
                  </Button>
                )}
                <Button
                  color="error"
                  onClick={async () => {
                    await api.delete(`/v1/integrations/${item.id}`);
                    await cache.invalidateQueries({
                      queryKey: ["platform-integrations"],
                    });
                  }}
                >
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
    </Stack>
  );
}
