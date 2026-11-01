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

type Provider = "MISTRAL" | "OPENAI" | "GEMINI";
type AiSettings = {
  provider: Provider;
  baseUrl: string;
  model: string;
  apiKeyConfigured: boolean;
  dataPolicy: "MINIMAL" | "CONTEXTUAL";
  systemPrompt: string;
  enabled: boolean;
  monthlyTokenBudget: number;
  dailyCallLimit: number;
  termsAcknowledged: boolean;
  termsVersion: string;
  termsAcknowledgedAt: string | null;
  models: string[];
  updatedAt: string | null;
};
type Usage = {
  usage: {
    calls: number;
    succeeded: number;
    failed: number;
    refused: number;
    inputTokens: number;
    outputTokens: number;
  };
  monthlyTokenBudget: number;
  dailyCallLimit: number;
  dailyCalls: number;
  promptVersion: string;
};
type Form = AiSettings & { apiKey: string; acknowledgeTerms: boolean };

const presets: Record<Provider, Pick<Form, "baseUrl" | "model">> = {
  MISTRAL: {
    baseUrl: "https://api.mistral.ai/v1",
    model: "mistral-large-latest",
  },
  OPENAI: { baseUrl: "https://api.openai.com/v1", model: "gpt-5-mini" },
  GEMINI: {
    baseUrl: "https://generativelanguage.googleapis.com/v1beta",
    model: "gemini-3.5-flash-lite",
  },
};

/** Doit rester identique à AiSettingsController::MODELS : le serveur refuse tout autre modèle. */
const providerModels: Record<Provider, readonly string[]> = {
  MISTRAL: [
    "mistral-large-latest",
    "mistral-medium-latest",
    "mistral-medium-3-5",
    "mistral-small-latest",
    "mistral-small-2603",
    "ministral-14b-2512",
    "codestral-latest",
  ],
  OPENAI: ["gpt-5-mini", "gpt-4.1-mini", "gpt-4.1"],
  GEMINI: [
    "gemini-3.5-flash-lite",
    "gemini-3.1-flash-lite",
    "gemini-2.5-flash",
    "gemini-2.5-flash-lite",
    "gemini-2.5-pro",
  ],
};

export function AiCopilotSettingsPanel() {
  const cache = useQueryClient();
  const settings = useQuery({
    queryKey: ["ai-settings"],
    queryFn: async () => (await api.get<AiSettings>("/settings/ai")).data,
  });
  const usage = useQuery({
    queryKey: ["ai-usage"],
    queryFn: async () => (await api.get<Usage>("/settings/ai/usage")).data,
  });
  const [draft, setForm] = useState<Form | null>(null);
  const form =
    draft ??
    (settings.data
      ? { ...settings.data, apiKey: "", acknowledgeTerms: false }
      : null);
  const save = useMutation({
    mutationFn: async () =>
      (await api.put<AiSettings>("/settings/ai", form)).data,
    onSuccess: async (data) => {
      test.reset();
      setForm({ ...data, apiKey: "", acknowledgeTerms: false });
      await cache.invalidateQueries({ queryKey: ["ai-settings"] });
      await cache.invalidateQueries({ queryKey: ["ai-usage"] });
    },
  });
  const test = useMutation({
    mutationFn: () => api.post<{ message: string }>("/settings/ai/test"),
  });

  if (settings.isError) {
    return (
      <Alert severity="error">Impossible de charger la configuration IA.</Alert>
    );
  }
  if (settings.isLoading || !form) return null;
  const configurationSaved =
    form.apiKey === "" &&
    !form.acknowledgeTerms &&
    (
      [
        "provider",
        "baseUrl",
        "model",
        "dataPolicy",
        "systemPrompt",
        "enabled",
        "monthlyTokenBudget",
        "dailyCallLimit",
      ] as const
    ).every((field) => form[field] === settings.data?.[field]);
  const models = providerModels[form.provider];
  const termsOk = form.termsAcknowledged || form.acknowledgeTerms;

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
          {save.isSuccess && configurationSaved && (
            <Alert severity="success">Configuration IA enregistrée.</Alert>
          )}
          {save.isError && (
            <Alert severity="error">
              {axios.isAxiosError<{ message?: string }>(save.error)
                ? (save.error.response?.data?.message ??
                  "Échec de l’enregistrement de la configuration IA.")
                : "Échec de l’enregistrement de la configuration IA."}
            </Alert>
          )}
          {test.isSuccess && configurationSaved && (
            <Alert severity="success">
              Connexion et génération IA validées.
            </Alert>
          )}
          {test.isError && configurationSaved && (
            <Alert severity="error">
              {axios.isAxiosError<{ message?: string }>(test.error)
                ? (test.error.response?.data?.message ??
                  "Le test de génération IA a échoué.")
                : "Le test de génération IA a échoué."}
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
                test.reset();
                setForm({
                  ...form,
                  provider,
                  apiKey: "",
                  apiKeyConfigured:
                    provider === settings.data?.provider &&
                    settings.data.apiKeyConfigured,
                  enabled: false,
                  ...presets[provider],
                });
              }}
            >
              <MenuItem value="MISTRAL">Mistral AI</MenuItem>
              <MenuItem value="OPENAI">OpenAI</MenuItem>
              <MenuItem value="GEMINI">Google Gemini</MenuItem>
            </TextField>
            <TextField
              select
              fullWidth
              required
              label="Modèle"
              helperText="Disponibilité selon votre compte et vos quotas fournisseur."
              value={form.model}
              onChange={(event) =>
                setForm({ ...form, model: event.target.value })
              }
            >
              {models.map((model) => (
                <MenuItem key={model} value={model}>
                  {model}
                </MenuItem>
              ))}
            </TextField>
          </Stack>
          <TextField
            required
            label="URL de l’API"
            value={form.baseUrl}
            disabled
            helperText="URL officielle du fournisseur, imposée par le serveur (protection contre les accès réseau internes)."
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
          <Stack direction={{ xs: "column", md: "row" }} spacing={2}>
            <TextField
              type="number"
              fullWidth
              label="Budget mensuel de tokens"
              value={form.monthlyTokenBudget}
              inputProps={{ min: 10000, max: 50000000, step: 10000 }}
              onChange={(event) =>
                setForm({
                  ...form,
                  monthlyTokenBudget: Number(event.target.value),
                })
              }
              helperText="Les appels sont refusés (429) une fois le budget de l’organisation épuisé."
            />
            <TextField
              type="number"
              fullWidth
              label="Plafond d’appels par jour"
              value={form.dailyCallLimit}
              inputProps={{ min: 1, max: 100000 }}
              onChange={(event) =>
                setForm({
                  ...form,
                  dailyCallLimit: Number(event.target.value),
                })
              }
            />
          </Stack>
          {usage.data && (
            <Alert severity="info">
              Ce mois-ci : {usage.data.usage.calls} appel(s) dont{" "}
              {usage.data.usage.failed} en échec et {usage.data.usage.refused}{" "}
              refusé(s) ;{" "}
              {usage.data.usage.inputTokens + usage.data.usage.outputTokens} /{" "}
              {usage.data.monthlyTokenBudget} tokens. Aujourd’hui :{" "}
              {usage.data.dailyCalls} / {usage.data.dailyCallLimit} appels.
              Version des consignes : {usage.data.promptVersion}.
            </Alert>
          )}
          <FormControlLabel
            control={
              <Switch
                checked={termsOk}
                disabled={form.termsAcknowledged}
                onChange={(event) =>
                  setForm({ ...form, acknowledgeTerms: event.target.checked })
                }
              />
            }
            label={
              form.termsAcknowledged
                ? `Avis d’usage IA ${form.termsVersion} acquitté${form.termsAcknowledgedAt ? ` le ${new Date(form.termsAcknowledgedAt).toLocaleDateString("fr-FR")}` : ""}`
                : "J’atteste que le contrat de traitement (DPA), les transferts hors UE éventuels et le sous-traitant IA ont été validés pour cette organisation"
            }
          />
          <FormControlLabel
            control={
              <Switch
                checked={form.enabled}
                disabled={!termsOk}
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
                !configurationSaved ||
                save.isPending ||
                test.isPending
              }
              onClick={() => test.mutate()}
            >
              Tester la génération IA
            </Button>
            {(!form.apiKeyConfigured || !configurationSaved) && (
              <Typography variant="caption" color="text.secondary">
                Enregistrez la configuration avant de lancer le test de
                génération.
              </Typography>
            )}
          </Stack>
        </Stack>
      </CardContent>
    </Card>
  );
}
