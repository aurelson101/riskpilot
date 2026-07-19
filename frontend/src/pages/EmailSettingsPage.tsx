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
};

const providers = {
  SMTP2GO: {
    label: "SMTP2GO",
    host: "mail.smtp2go.com",
    port: 587,
    encryption: "tls",
  },
  GOOGLE_WORKSPACE: {
    label: "Google Workspace",
    host: "smtp.gmail.com",
    port: 587,
    encryption: "tls",
  },
  MICROSOFT_365: {
    label: "Microsoft 365",
    host: "smtp.office365.com",
    port: 587,
    encryption: "tls",
  },
  CUSTOM: {
    label: "Serveur SMTP personnalisé",
    host: "",
    port: 587,
    encryption: "tls",
  },
} as const;

export function EmailSettingsPage() {
  const query = useQuery({
    queryKey: ["email-settings"],
    queryFn: async () => (await api.get<EmailSettings>("/settings/email")).data,
  });
  const [form, setForm] = useState<EmailSettings & { password: string }>({
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
  });
  const [recipient, setRecipient] = useState("");
  const [message, setMessage] = useState<{
    type: "success" | "error";
    text: string;
  } | null>(null);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (query.data) setForm({ ...query.data, password: "" });
  }, [query.data]);

  function selectProvider(provider: keyof typeof providers) {
    const preset = providers[provider];
    setForm({
      ...form,
      provider,
      host: preset.host,
      port: preset.port,
      encryption: preset.encryption,
    });
  }
  async function save(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setMessage(null);
    try {
      const { data } = await api.put<EmailSettings>("/settings/email", form);
      setForm({ ...data, password: "" });
      setMessage({
        type: "success",
        text: "Configuration de messagerie enregistrée.",
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

  return (
    <Stack spacing={3} maxWidth={850}>
      <Stack>
        <Typography variant="h4" fontWeight={750}>
          Paramètres de messagerie
        </Typography>
        <Typography color="text.secondary">
          Configurez l’expéditeur des notifications de votre organisation.
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
              onChange={(event) =>
                selectProvider(event.target.value as keyof typeof providers)
              }
            >
              {Object.entries(providers).map(([value, item]) => (
                <MenuItem key={value} value={value}>
                  {item.label}
                </MenuItem>
              ))}
            </TextField>
            {form.provider === "GOOGLE_WORKSPACE" && (
              <Alert severity="info">
                Utilisez l’adresse du compte et un mot de passe d’application
                Google. Le relais SMTP Workspace peut aussi être configuré via
                le mode personnalisé.
              </Alert>
            )}
            {form.provider === "MICROSOFT_365" && (
              <Alert severity="info">
                SMTP AUTH doit être autorisé pour la boîte Microsoft 365.
                Utilisez un mot de passe d’application si la politique du tenant
                le permet.
              </Alert>
            )}
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
              onChange={(e) => setForm({ ...form, encryption: e.target.value })}
            >
              <MenuItem value="tls">STARTTLS</MenuItem>
              <MenuItem value="ssl">TLS implicite</MenuItem>
              <MenuItem value="none">Aucun</MenuItem>
            </TextField>
            <TextField
              label="Identifiant SMTP"
              required
              value={form.username}
              onChange={(e) => setForm({ ...form, username: e.target.value })}
            />
            <TextField
              label={
                form.passwordConfigured
                  ? "Nouveau mot de passe SMTP (laisser vide pour conserver)"
                  : "Mot de passe SMTP"
              }
              type="password"
              required={!form.passwordConfigured}
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
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
              onChange={(e) => setForm({ ...form, replyTo: e.target.value })}
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
          </Stack>
        </CardContent>
      </Card>
      <Card variant="outlined">
        <CardContent>
          <Stack spacing={2}>
            <Typography variant="h6">Tester l’envoi</Typography>
            <Typography color="text.secondary">
              Le destinataire ci-dessous n’est utilisé que pour le test. Les
              notifications réelles sont envoyées à l’utilisateur concerné.
            </Typography>
            <TextField
              label="Destinataire du test"
              type="email"
              value={recipient}
              onChange={(e) => setRecipient(e.target.value)}
            />
            <Button variant="outlined" onClick={sendTest} disabled={!recipient}>
              Envoyer un email de test
            </Button>
          </Stack>
        </CardContent>
      </Card>
    </Stack>
  );
}
