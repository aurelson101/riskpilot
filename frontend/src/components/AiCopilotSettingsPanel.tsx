import { useEffect, useState, type FormEvent } from "react";
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
import { api } from "../api/client";

type Provider = "MISTRAL" | "OPENAI" | "GEMINI" | "CUSTOM";
type AiSettings = {
  provider: Provider;
  baseUrl: string;
  model: string;
  apiKeyConfigured: boolean;
  dataPolicy: "MINIMAL" | "CONTEXTUAL";
  systemPrompt: string;
  enabled: boolean;
  updatedAt: string | null;
};
type Form = AiSettings & { apiKey: string };

const presets: Record<
  Exclude<Provider, "CUSTOM">,
  Pick<Form, "baseUrl" | "model">
> = {
  MISTRAL: {
    baseUrl: "https://api.mistral.ai/v1",
    model: "mistral-large-latest",
  },
  OPENAI: { baseUrl: "https://api.openai.com/v1", model: "gpt-5-mini" },
  GEMINI: {
    baseUrl: "https://generativelanguage.googleapis.com/v1beta",
    model: "gemini-2.5-flash",
  },
};

export function AiCopilotSettingsPanel() {
  const cache = useQueryClient();
  const settings = useQuery({
    queryKey: ["ai-settings"],
    queryFn: async () => (await api.get<AiSettings>("/settings/ai")).data,
  });
  const [form, setForm] = useState<Form | null>(null);
  useEffect(() => {
    if (settings.data) setForm({ ...settings.data, apiKey: "" });
  }, [settings.data]);
  const save = useMutation({
    mutationFn: async () =>
      (await api.put<AiSettings>("/settings/ai", form)).data,
    onSuccess: async (data) => {
      setForm({ ...data, apiKey: "" });
      await cache.invalidateQueries({ queryKey: ["ai-settings"] });
    },
  });
  const test = useMutation({
    mutationFn: () => api.post<{ message: string }>("/settings/ai/test"),
  });

  if (settings.isLoading || !form) return null;
  if (settings.isError) {
    return (
      <Alert severity="error">Impossible de charger la configuration IA.</Alert>
    );
  }

  return (
    <Card variant="outlined">
      <CardContent
        component="form"
        onSubmit={(event: FormEvent) => {
          event.preventDefault();
          save.mutate();
        }}
      >
        <Stack spacing={2}>
          <div>
            <Typography variant="h6" fontWeight={750}>
              Copilote IA
            </Typography>
            <Typography color="text.secondary">
              Assistance à l’analyse et à la rédaction. Les suggestions restent
              soumises à validation humaine et ne prennent aucune décision
              automatiquement.
            </Typography>
          </div>
          <Alert severity="info">
            La clé est chiffrée et n’est jamais réaffichée. Utilisez une clé
            dédiée à RiskPilot avec quotas et restrictions côté fournisseur.
          </Alert>
          {save.isSuccess && (
            <Alert severity="success">Configuration IA enregistrée.</Alert>
          )}
          {save.isError && (
            <Alert severity="error">
              Échec de l’enregistrement de la configuration IA.
            </Alert>
          )}
          {test.isSuccess && (
            <Alert severity="success">
              Connexion au fournisseur IA validée.
            </Alert>
          )}
          {test.isError && (
            <Alert severity="error">
              Le test de connexion au fournisseur IA a échoué.
            </Alert>
          )}
          <Stack direction={{ xs: "column", md: "row" }} spacing={2}>
            <TextField
              select
              fullWidth
              label="Fournisseur IA"
              value={form.provider}
              onChange={(event) => {
                const provider = event.target.value as Provider;
                setForm({
                  ...form,
                  provider,
                  ...(provider === "CUSTOM" ? {} : presets[provider]),
                });
              }}
            >
              <MenuItem value="MISTRAL">Mistral AI</MenuItem>
              <MenuItem value="OPENAI">OpenAI</MenuItem>
              <MenuItem value="GEMINI">Google Gemini</MenuItem>
              <MenuItem value="CUSTOM">Endpoint compatible</MenuItem>
            </TextField>
            <TextField
              fullWidth
              required
              label="Modèle"
              value={form.model}
              onChange={(event) =>
                setForm({ ...form, model: event.target.value })
              }
            />
          </Stack>
          <TextField
            required
            label="URL de l’API"
            value={form.baseUrl}
            disabled={form.provider !== "CUSTOM"}
            onChange={(event) =>
              setForm({ ...form, baseUrl: event.target.value })
            }
            helperText="HTTPS obligatoire. Le test automatique des endpoints personnalisés est désactivé."
          />
          <TextField
            type="password"
            label={
              form.apiKeyConfigured
                ? "Nouvelle clé API (vide pour conserver)"
                : "Clé API"
            }
            required={!form.apiKeyConfigured}
            value={form.apiKey}
            onChange={(event) =>
              setForm({ ...form, apiKey: event.target.value })
            }
            autoComplete="new-password"
          />
          <TextField
            select
            label="Politique d’envoi des données"
            value={form.dataPolicy}
            onChange={(event) =>
              setForm({
                ...form,
                dataPolicy: event.target.value as Form["dataPolicy"],
              })
            }
          >
            <MenuItem value="MINIMAL">
              Minimale — extraits strictement nécessaires
            </MenuItem>
            <MenuItem value="CONTEXTUAL">
              Contextuelle — contexte GRC sélectionné
            </MenuItem>
          </TextField>
          <TextField
            multiline
            minRows={3}
            label="Instructions système complémentaires"
            value={form.systemPrompt}
            inputProps={{ maxLength: 4000 }}
            onChange={(event) =>
              setForm({ ...form, systemPrompt: event.target.value })
            }
          />
          <FormControlLabel
            control={
              <Switch
                checked={form.enabled}
                onChange={(event) =>
                  setForm({ ...form, enabled: event.target.checked })
                }
              />
            }
            label="Activer le copilote IA pour l’organisation"
          />
          <Stack direction={{ xs: "column", sm: "row" }} spacing={1}>
            <Button type="submit" variant="contained" disabled={save.isPending}>
              Enregistrer la configuration IA
            </Button>
            <Button
              variant="outlined"
              disabled={
                !form.apiKeyConfigured ||
                form.provider === "CUSTOM" ||
                test.isPending
              }
              onClick={() => test.mutate()}
            >
              Tester la connexion
            </Button>
          </Stack>
        </Stack>
      </CardContent>
    </Card>
  );
}
