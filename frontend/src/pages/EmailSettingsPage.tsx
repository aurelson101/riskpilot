import { useEffect, useState, type FormEvent } from "react";
import { useQuery } from "@tanstack/react-query";
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

type EmailSettings = {
  provider: string;
  host: string;
  port: number;
  encryption: string;
  username: string;
  passwordConfigured: boolean;
  senderEmail: string;
  senderName: string;
  replyTo: string | null;
  enabled: boolean;
  oauthClientId: string | null;
  oauthClientSecretConfigured: boolean;
  oauthTenant: string | null;
  oauthConnected: boolean;
  connectedEmail: string | null;
};
type Form = EmailSettings & { password: string; oauthClientSecret: string };
const oauthProviders = ["GOOGLE_WORKSPACE", "MICROSOFT_365"];
const labels: Record<string, string> = {
  SMTP2GO: "SMTP2GO",
  GOOGLE_WORKSPACE: "Google Workspace — OAuth 2.0",
  MICROSOFT_365: "Microsoft 365 — OAuth 2.0",
  CUSTOM: "Serveur SMTP personnalisé",
};
const empty: Form = {
  provider: "SMTP2GO",
  host: "mail.smtp2go.com",
  port: 587,
  encryption: "tls",
  username: "",
  password: "",
  passwordConfigured: false,
  senderEmail: "",
  senderName: "RiskPilot",
  replyTo: "",
  enabled: false,
  oauthClientId: "",
  oauthClientSecret: "",
  oauthClientSecretConfigured: false,
  oauthTenant: "common",
  oauthConnected: false,
  connectedEmail: null,
};

export function EmailSettingsPage() {
  const query = useQuery({
    queryKey: ["email-settings"],
    queryFn: async () => (await api.get<EmailSettings>("/settings/email")).data,
  });
  const [form, setForm] = useState<Form>(empty);
  const [recipient, setRecipient] = useState("");
  const [message, setMessage] = useState<{
    type: "success" | "error";
    text: string;
  } | null>(null);
  const [saving, setSaving] = useState(false);
  const oauth = oauthProviders.includes(form.provider);
  useEffect(() => {
    if (query.data)
      setForm({ ...query.data, password: "", oauthClientSecret: "" });
  }, [query.data]);
  useEffect(() => {
    const result = new URLSearchParams(window.location.search).get("oauth");
    if (result)
      setMessage({
        type: result === "success" ? "success" : "error",
        text:
          result === "success"
            ? "Compte OAuth connecté. Les notifications utiliseront désormais ce compte."
            : "La connexion OAuth a échoué ou a expiré.",
      });
  }, []);

  function selectProvider(provider: string) {
    if (provider === "SMTP2GO")
      setForm({
        ...form,
        provider,
        host: "mail.smtp2go.com",
        port: 587,
        encryption: "tls",
      });
    else setForm({ ...form, provider });
  }
  async function save(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setMessage(null);
    try {
      const { data } = await api.put<EmailSettings>("/settings/email", form);
      setForm({ ...data, password: "", oauthClientSecret: "" });
      setMessage({
        type: "success",
        text: oauth
          ? "Identifiants OAuth enregistrés. Vous pouvez maintenant connecter le compte."
          : "Configuration SMTP enregistrée.",
      });
    } catch (caught) {
      setMessage({
        type: "error",
        text: axios.isAxiosError<{ message?: string }>(caught)
          ? (caught.response?.data?.message ?? "Enregistrement impossible.")
          : "Enregistrement impossible.",
      });
    } finally {
      setSaving(false);
    }
  }
  async function connectOauth() {
    setMessage(null);
    try {
      const { data } = await api.post<{ authorizationUrl: string }>(
        `/settings/email/oauth/${form.provider.toLowerCase()}/authorize`,
      );
      window.location.assign(data.authorizationUrl);
    } catch (caught) {
      setMessage({
        type: "error",
        text: axios.isAxiosError<{ message?: string }>(caught)
          ? (caught.response?.data?.message ?? "Connexion OAuth impossible.")
          : "Connexion OAuth impossible.",
      });
    }
  }
  async function disconnectOauth() {
    await api.post("/settings/email/oauth/disconnect");
    setForm({
      ...form,
      oauthConnected: false,
      connectedEmail: null,
      enabled: false,
    });
    setMessage({ type: "success", text: "Compte OAuth déconnecté." });
  }
  async function sendTest() {
    setMessage(null);
    try {
      const { data } = await api.post<{ message: string }>(
        "/settings/email/test",
        { recipient },
      );
      setMessage({ type: "success", text: data.message });
    } catch (caught) {
      setMessage({
        type: "error",
        text: axios.isAxiosError<{ message?: string }>(caught)
          ? (caught.response?.data?.message ?? "Test impossible.")
          : "Test impossible.",
      });
    }
  }

  const callback = `${window.location.origin}/api/settings/email/oauth/${form.provider.toLowerCase()}/callback`;
  return (
    <Stack spacing={3} maxWidth={850}>
      <Stack>
        <Typography variant="h4" fontWeight={750}>
          Paramètres de messagerie
        </Typography>
        <Typography color="text.secondary">
          SMTP2GO/SMTP ou connexion OAuth 2.0 Google Workspace et Microsoft 365.
        </Typography>
      </Stack>
      {message && <Alert severity={message.type}>{message.text}</Alert>}
      <Card variant="outlined">
        <CardContent>
          <Stack component="form" spacing={2.5} onSubmit={save}>
            <TextField
              select
              label="Fournisseur"
              value={form.provider}
              onChange={(e) => selectProvider(e.target.value)}
            >
              {Object.entries(labels).map(([value, label]) => (
                <MenuItem key={value} value={value}>
                  {label}
                </MenuItem>
              ))}
            </TextField>
            {oauth ? (
              <>
                <Alert severity="info">
                  Créez une application Web chez le fournisseur et déclarez
                  exactement cette URI de redirection :<br />
                  <strong>{callback}</strong>
                </Alert>
                <TextField
                  label="Client ID OAuth"
                  required
                  value={form.oauthClientId ?? ""}
                  onChange={(e) =>
                    setForm({ ...form, oauthClientId: e.target.value })
                  }
                />
                <TextField
                  label={
                    form.oauthClientSecretConfigured
                      ? "Nouveau secret client (vide pour conserver)"
                      : "Secret client OAuth"
                  }
                  type="password"
                  required={!form.oauthClientSecretConfigured}
                  value={form.oauthClientSecret}
                  onChange={(e) =>
                    setForm({ ...form, oauthClientSecret: e.target.value })
                  }
                />
                {form.provider === "MICROSOFT_365" && (
                  <TextField
                    label="Tenant Microsoft"
                    helperText="Identifiant du tenant, domaine vérifié ou common pour le multitenant."
                    value={form.oauthTenant ?? "common"}
                    onChange={(e) =>
                      setForm({ ...form, oauthTenant: e.target.value })
                    }
                  />
                )}
                <TextField
                  label="Nom d’expéditeur"
                  required
                  value={form.senderName}
                  onChange={(e) =>
                    setForm({ ...form, senderName: e.target.value })
                  }
                />
                <TextField
                  label="Adresse de réponse (facultatif)"
                  type="email"
                  value={form.replyTo ?? ""}
                  onChange={(e) =>
                    setForm({ ...form, replyTo: e.target.value })
                  }
                />
                {form.oauthConnected && (
                  <Alert severity="success">
                    Compte connecté : {form.connectedEmail}
                  </Alert>
                )}
                <Button type="submit" variant="contained" disabled={saving}>
                  {saving
                    ? "Enregistrement…"
                    : "Enregistrer les identifiants OAuth"}
                </Button>
                <Button
                  variant="outlined"
                  onClick={connectOauth}
                  disabled={
                    !form.oauthClientSecretConfigured && !form.oauthClientSecret
                  }
                >
                  {form.oauthConnected
                    ? "Reconnecter le compte"
                    : "Connecter le compte"}
                </Button>
                {form.oauthConnected && (
                  <Button color="error" onClick={disconnectOauth}>
                    Déconnecter
                  </Button>
                )}
              </>
            ) : (
              <>
                <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                  <TextField
                    label="Serveur SMTP"
                    fullWidth
                    value={form.host}
                    disabled={form.provider !== "CUSTOM"}
                    onChange={(e) => setForm({ ...form, host: e.target.value })}
                  />
                  <TextField
                    label="Port"
                    type="number"
                    value={form.port}
                    disabled={form.provider !== "CUSTOM"}
                    onChange={(e) =>
                      setForm({ ...form, port: Number(e.target.value) })
                    }
                  />
                </Stack>
                <TextField
                  select
                  label="Chiffrement"
                  value={form.encryption}
                  disabled={form.provider !== "CUSTOM"}
                  onChange={(e) =>
                    setForm({ ...form, encryption: e.target.value })
                  }
                >
                  <MenuItem value="tls">STARTTLS</MenuItem>
                  <MenuItem value="ssl">TLS implicite</MenuItem>
                  <MenuItem value="none">Aucun</MenuItem>
                </TextField>
                <TextField
                  label="Identifiant SMTP"
                  required
                  value={form.username}
                  onChange={(e) =>
                    setForm({ ...form, username: e.target.value })
                  }
                />
                <TextField
                  label={
                    form.passwordConfigured
                      ? "Nouveau mot de passe SMTP (vide pour conserver)"
                      : "Mot de passe SMTP"
                  }
                  type="password"
                  required={!form.passwordConfigured}
                  value={form.password}
                  onChange={(e) =>
                    setForm({ ...form, password: e.target.value })
                  }
                />
                <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                  <TextField
                    label="Email d’envoi"
                    type="email"
                    required
                    fullWidth
                    value={form.senderEmail}
                    onChange={(e) =>
                      setForm({ ...form, senderEmail: e.target.value })
                    }
                  />
                  <TextField
                    label="Nom d’expéditeur"
                    required
                    fullWidth
                    value={form.senderName}
                    onChange={(e) =>
                      setForm({ ...form, senderName: e.target.value })
                    }
                  />
                </Stack>
                <TextField
                  label="Adresse de réponse (facultatif)"
                  type="email"
                  value={form.replyTo ?? ""}
                  onChange={(e) =>
                    setForm({ ...form, replyTo: e.target.value })
                  }
                />
                <FormControlLabel
                  control={
                    <Switch
                      checked={form.enabled}
                      onChange={(e) =>
                        setForm({ ...form, enabled: e.target.checked })
                      }
                    />
                  }
                  label="Utiliser cette configuration pour les notifications"
                />
                <Button type="submit" variant="contained" disabled={saving}>
                  {saving ? "Enregistrement…" : "Enregistrer"}
                </Button>
              </>
            )}
          </Stack>
        </CardContent>
      </Card>
      <Card variant="outlined">
        <CardContent>
          <Stack spacing={2}>
            <Typography variant="h6">Tester l’envoi</Typography>
            <Typography color="text.secondary">
              Le destinataire n’est utilisé que pour ce test. Les notifications
              réelles vont à l’utilisateur concerné.
            </Typography>
            <TextField
              label="Destinataire du test"
              type="email"
              value={recipient}
              onChange={(e) => setRecipient(e.target.value)}
            />
            <Button
              variant="outlined"
              onClick={sendTest}
              disabled={!recipient || (oauth && !form.oauthConnected)}
            >
              Envoyer un email de test
            </Button>
          </Stack>
        </CardContent>
      </Card>
    </Stack>
  );
}
