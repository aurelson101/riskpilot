import { useState } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import {
  Alert,
  Box,
  Button,
  Checkbox,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { SendOutlined } from "@mui/icons-material";
import { api } from "../../api/client";
import type { ComplianceResult } from "../../api/types";

type Source = { type: string; id: number; label: string };
type ContextPreview = {
  enabled: boolean;
  provider: string | null;
  model: string | null;
  dataPolicy: "MINIMAL" | "CONTEXTUAL";
  context: Record<string, unknown>;
  sources: Source[];
  notice: string;
};
type Message = { role: "user" | "assistant"; content: string };
type CopilotAnswer = {
  answer: string;
  provider: string;
  model: string;
  sources: Source[];
  automaticWrite: false;
  notice: string;
};

export function ComplianceCopilotDialog({
  result,
  onClose,
}: {
  result: ComplianceResult;
  onClose: () => void;
}) {
  const [question, setQuestion] = useState("");
  const [consent, setConsent] = useState(false);
  const [messages, setMessages] = useState<Message[]>([]);
  const preview = useQuery({
    queryKey: ["compliance-copilot-context", result.id],
    queryFn: async () =>
      (
        await api.get<ContextPreview>(
          `/compliance-results/${result.id}/copilot/context`,
        )
      ).data,
  });
  const ask = useMutation({
    mutationFn: async (currentQuestion: string) =>
      (
        await api.post<CopilotAnswer>(
          `/compliance-results/${result.id}/copilot`,
          {
            question: currentQuestion,
            consent,
            history: messages.slice(-8),
          },
        )
      ).data,
    onSuccess: (answer, currentQuestion) => {
      setMessages((current) => [
        ...current,
        { role: "user", content: currentQuestion },
        { role: "assistant", content: answer.answer },
      ]);
      setQuestion("");
      setConsent(false);
    },
  });
  const contextRows = preview.data
    ? [
        ["Référentiel", preview.data.context.framework],
        [
          "Exigence",
          [
            preview.data.context.requirementReference,
            preview.data.context.requirementTitle,
          ]
            .filter(Boolean)
            .join(" — "),
        ],
        ["Catégorie", preview.data.context.category],
        ["Niveau de maturité", preview.data.context.maturityLevel],
        ["Statut de conformité", preview.data.context.complianceStatus],
        ["Périmètre", preview.data.context.assessmentScope],
        ["Commentaire actuel", preview.data.context.currentComment],
        ["Action corrective", preview.data.context.remediationAction],
        [
          "Preuves partagées",
          Array.isArray(preview.data.context.evidenceReferences)
            ? `${preview.data.context.evidenceReferences.length} preuve(s)`
            : null,
        ],
      ].filter(
        (row) => row[1] !== null && row[1] !== undefined && row[1] !== "",
      )
    : [];

  return (
    <Dialog open onClose={onClose} fullWidth maxWidth="md">
      <DialogTitle>
        Copilote conformité · {result.requirement.reference}
      </DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          {preview.isLoading && (
            <CircularProgress aria-label="Chargement du contexte IA" />
          )}
          {preview.isError && (
            <Alert severity="error">
              Impossible de préparer le contexte du copilote.
            </Alert>
          )}
          {preview.data && !preview.data.enabled && (
            <Alert severity="warning">
              Le copilote IA est désactivé. Un administrateur doit configurer et
              activer Mistral, OpenAI ou Gemini dans Paramètres → Intégrations.
            </Alert>
          )}
          {preview.data?.enabled && (
            <>
              <Alert severity="info">
                Vérifiez les données ci-dessous avant chaque envoi. La réponse
                conseille et aide à rédiger, mais ne modifie aucun champ.
              </Alert>
              <Stack direction="row" spacing={1} flexWrap="wrap">
                <Chip
                  label={`${preview.data.provider} · ${preview.data.model}`}
                />
                <Chip
                  variant="outlined"
                  label={`Politique ${preview.data.dataPolicy}`}
                />
              </Stack>
              <Box
                aria-label="Données envoyées au fournisseur IA"
                sx={{ p: 2, borderRadius: 1, bgcolor: "grey.100" }}
              >
                <Typography fontWeight={700} mb={1}>
                  Informations partagées avec l’IA
                </Typography>
                <Stack spacing={0.75}>
                  {contextRows.map(([label, value]) => (
                    <Typography key={String(label)} variant="body2">
                      <strong>{String(label)} :</strong>{" "}
                      {String(value).replaceAll("_", " ")}
                    </Typography>
                  ))}
                </Stack>
              </Box>
              <Stack spacing={1} aria-label="Conversation avec le copilote">
                {messages.map((message, index) => (
                  <Box
                    key={`${message.role}-${index}`}
                    sx={{
                      alignSelf:
                        message.role === "user" ? "flex-end" : "flex-start",
                      maxWidth: "90%",
                      p: 1.5,
                      borderRadius: 2,
                      bgcolor:
                        message.role === "user" ? "primary.main" : "grey.100",
                      color:
                        message.role === "user"
                          ? "primary.contrastText"
                          : "text.primary",
                      whiteSpace: "pre-wrap",
                    }}
                  >
                    {message.content}
                  </Box>
                ))}
              </Stack>
              {ask.isError && (
                <Alert severity="error">
                  Le fournisseur IA n’a pas pu répondre. Vérifiez la
                  configuration, le quota et le modèle.
                </Alert>
              )}
              <TextField
                multiline
                minRows={3}
                label="Question ou aide demandée"
                placeholder="Exemple : quelles preuves dois-je réunir pour évaluer ce point ?"
                value={question}
                inputProps={{ maxLength: 2000 }}
                onChange={(event) => setQuestion(event.target.value)}
              />
              <FormControlLabel
                control={
                  <Checkbox
                    checked={consent}
                    onChange={(event) => setConsent(event.target.checked)}
                  />
                }
                label="J’ai vérifié les données affichées et j’autorise cet envoi au fournisseur IA."
              />
              <Button
                variant="contained"
                startIcon={<SendOutlined />}
                disabled={
                  !consent || question.trim().length < 3 || ask.isPending
                }
                onClick={() => ask.mutate(question.trim())}
                sx={{ alignSelf: "flex-start" }}
              >
                Envoyer au copilote
              </Button>
              {preview.data.sources.map((source) => (
                <Typography
                  key={`${source.type}-${source.id}`}
                  variant="caption"
                  color="text.secondary"
                >
                  [1] {source.label}
                </Typography>
              ))}
            </>
          )}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Fermer</Button>
      </DialogActions>
    </Dialog>
  );
}
