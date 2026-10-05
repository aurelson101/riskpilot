import { useMutation, useQuery } from "@tanstack/react-query";
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  CircularProgress,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import axios from "axios";
import { useId, useState, type FormEvent } from "react";
import { useParams, useSearchParams } from "react-router-dom";
import { api } from "../api/client";
import { useInterfaceLocale } from "../i18n/InterfaceLocaleContext";

type PublicAssessment = {
  thirdParty: string;
  title: string;
  version: number;
  questions: Array<{ id: string; label: string; weight: number }>;
  expiresAt: string;
  status: string;
};

function isTransient(error: unknown) {
  if (!axios.isAxiosError(error)) return false;
  const status = error.response?.status;
  return (
    status === undefined || status === 408 || status === 429 || status >= 500
  );
}

export function PublicSupplierAssessmentPage() {
  const { token = "" } = useParams<{ token: string }>();
  return <PublicAssessmentForm key={token} token={token} />;
}

function PublicAssessmentForm({ token }: { token: string }) {
  const locale = useInterfaceLocale();
  const t = (fr: string, en: string) => (locale === "en" ? en : fr);
  const [params, setParams] = useSearchParams();
  const id = useId();
  const validToken = /^[a-fA-F0-9]{64}$/.test(token);
  const [responses, setResponses] = useState<Record<string, string>>({});
  const [references, setReferences] = useState("");
  const [submitted, setSubmitted] = useState(false);
  const [expiredOnSubmit, setExpiredOnSubmit] = useState(false);
  const [invalidForm, setInvalidForm] = useState(false);
  const detail = useQuery({
    queryKey: ["public-supplier-assessment", token],
    enabled: validToken,
    retry: false,
    gcTime: 0,
    queryFn: async ({ signal }) =>
      (
        await api.get<PublicAssessment>(
          `/public/supplier-assessments/${token}`,
          { signal },
        )
      ).data,
  });
  const assessment = detail.data;
  const answered =
    submitted ||
    assessment?.status === "SUBMITTED" ||
    assessment?.status === "REVIEWED";
  const expired =
    assessment !== undefined &&
    (expiredOnSubmit ||
      assessment.status === "EXPIRED" ||
      !Number.isFinite(Date.parse(assessment.expiresAt)) ||
      Date.parse(assessment.expiresAt) <= detail.dataUpdatedAt);
  const unavailable =
    !validToken || expired || (detail.isError && !isTransient(detail.error));
  const canAnswer =
    assessment !== undefined &&
    !unavailable &&
    !answered &&
    !detail.isError &&
    ["DRAFT", "SENT", "IN_PROGRESS"].includes(assessment.status) &&
    assessment.questions.length > 0;
  const answer = (questionId: string) =>
    Object.hasOwn(responses, questionId) ? responses[questionId] : "";
  const evidence = references
    .split(/\r?\n/)
    .map((reference) => reference.trim())
    .filter(Boolean);
  const validEvidence =
    evidence.length <= 10 &&
    evidence.every(
      (reference) => reference.length <= 500 && !/[<>]/.test(reference),
    );
  const validAnswers =
    Boolean(assessment?.questions.length) &&
    assessment!.questions.every(
      (question) =>
        answer(question.id).trim().length > 0 &&
        answer(question.id).length <= 4000,
    );
  const send = useMutation({
    gcTime: 0,
    mutationFn: async (payload: {
      responses: Record<string, string>;
      evidence: string[];
    }) =>
      (
        await api.post<{ status: string; submittedAt: string }>(
          `/public/supplier-assessments/${token}`,
          payload,
        )
      ).data,
    onSuccess: () => {
      setSubmitted(true);
      setResponses({});
      setReferences("");
    },
    onError: (error) => {
      if (
        axios.isAxiosError(error) &&
        [404, 410].includes(error.response?.status ?? 0)
      ) {
        setExpiredOnSubmit(true);
      }
    },
  });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!canAnswer || send.isPending) return;
    if (Date.parse(assessment!.expiresAt) <= Date.now()) {
      setExpiredOnSubmit(true);
      return;
    }
    if (!validAnswers || !validEvidence) {
      setInvalidForm(true);
      return;
    }
    setInvalidForm(false);
    send.mutate({
      responses: Object.fromEntries(
        assessment!.questions.map((question) => [
          question.id,
          answer(question.id).trim(),
        ]),
      ),
      evidence,
    });
  };
  const changeLanguage = (language: "fr" | "en") => {
    const next = new URLSearchParams(params);
    next.set("lang", language);
    setParams(next, { replace: true });
  };

  return (
    <Box
      component="main"
      lang={locale}
      sx={{
        minHeight: "100vh",
        bgcolor: "#eef4fb",
        p: { xs: 2, sm: 3 },
        overflowWrap: "anywhere",
      }}
    >
      <Card sx={{ maxWidth: 800, width: "100%", mx: "auto", borderRadius: 3 }}>
        <CardContent sx={{ p: { xs: 2, sm: 4 } }}>
          <Stack spacing={2.5}>
            <Stack
              direction="row"
              justifyContent="space-between"
              alignItems="center"
              gap={1}
              flexWrap="wrap"
            >
              <Typography fontWeight={700}>RiskPilot</Typography>
              <Stack direction="row" aria-label={t("Langue", "Language")}>
                <Button
                  aria-pressed={locale === "fr"}
                  onClick={() => changeLanguage("fr")}
                  disabled={send.isPending}
                >
                  FR
                </Button>
                <Button
                  aria-pressed={locale === "en"}
                  onClick={() => changeLanguage("en")}
                  disabled={send.isPending}
                >
                  EN
                </Button>
              </Stack>
            </Stack>
            <Typography component="h1" variant="h5">
              {t("Questionnaire fournisseur", "Supplier questionnaire")}
            </Typography>
            {validToken && detail.isLoading && (
              <CircularProgress
                aria-label={t(
                  "Chargement du questionnaire",
                  "Loading questionnaire",
                )}
              />
            )}
            {unavailable && !answered && (
              <Alert severity="warning">
                {t(
                  "Ce lien est invalide ou le questionnaire a expiré. Demandez un nouveau lien à votre contact.",
                  "This link is invalid or the questionnaire has expired. Ask your contact for a new link.",
                )}
              </Alert>
            )}
            {detail.isError && isTransient(detail.error) && !answered && (
              <Alert
                severity="error"
                action={
                  <Button
                    color="inherit"
                    disabled={detail.isFetching}
                    onClick={() => void detail.refetch()}
                  >
                    {t("Réessayer", "Retry")}
                  </Button>
                }
              >
                {t(
                  "Le questionnaire est temporairement indisponible. Réessayez dans quelques instants.",
                  "The questionnaire is temporarily unavailable. Please try again shortly.",
                )}
              </Alert>
            )}
            {assessment && !unavailable && (
              <Stack spacing={0.5}>
                <Typography component="h2" variant="h6">
                  {assessment.title}
                </Typography>
                <Typography color="text.secondary">
                  {assessment.thirdParty}
                </Typography>
                <Typography variant="body2">
                  {t("Version", "Version")} {assessment.version} ·{" "}
                  {t("Échéance", "Due date")} :{" "}
                  {new Date(assessment.expiresAt).toLocaleDateString(
                    locale === "en" ? "en-GB" : "fr-FR",
                  )}
                </Typography>
              </Stack>
            )}
            {answered && (
              <Alert severity="success">
                {submitted
                  ? t(
                      "Merci. Vos réponses ont été envoyées pour revue humaine. Vous n’avez rien d’autre à faire.",
                      "Thank you. Your answers have been submitted for human review. No further action is needed.",
                    )
                  : t(
                      "Ce questionnaire a déjà reçu une réponse. Aucune nouvelle soumission n’est nécessaire.",
                      "This questionnaire has already been answered. No further submission is needed.",
                    )}
              </Alert>
            )}
            {assessment && !unavailable && !answered && !canAnswer && (
              <Alert severity="warning">
                {t(
                  "Ce questionnaire ne peut pas recevoir de réponses. Contactez votre interlocuteur.",
                  "This questionnaire cannot receive answers. Please contact your representative.",
                )}
              </Alert>
            )}
            {canAnswer && (
              <Stack component="form" spacing={2.5} onSubmit={submit}>
                <Alert severity="info">
                  {t(
                    "Toutes les questions sont obligatoires. Les réponses seront examinées par une personne ; aucun score de conformité n’est calculé automatiquement.",
                    "All questions are required. Answers will be reviewed by a person; no compliance score is calculated automatically.",
                  )}
                </Alert>
                {assessment.questions.map((question, index) => (
                  <Stack key={`${question.id}-${index}`} spacing={1}>
                    <Typography
                      component="h3"
                      variant="subtitle1"
                      fontWeight={600}
                    >
                      {index + 1}. {question.label}
                    </Typography>
                    <TextField
                      id={`${id}-answer-${index}`}
                      label={`${t("Votre réponse", "Your answer")} ${index + 1}`}
                      value={answer(question.id)}
                      onChange={(event) =>
                        setResponses((current) => ({
                          ...current,
                          [question.id]: event.target.value,
                        }))
                      }
                      required
                      multiline
                      minRows={3}
                      fullWidth
                      disabled={send.isPending}
                      slotProps={{ htmlInput: { maxLength: 4000 } }}
                      helperText={t(
                        "4 000 caractères maximum.",
                        "Maximum 4,000 characters.",
                      )}
                    />
                  </Stack>
                ))}
                <TextField
                  id={`${id}-references`}
                  label={t(
                    "Références complémentaires (facultatif)",
                    "Additional references (optional)",
                  )}
                  value={references}
                  onChange={(event) => setReferences(event.target.value)}
                  multiline
                  minRows={3}
                  fullWidth
                  disabled={send.isPending}
                  error={!validEvidence}
                  helperText={t(
                    "Une référence par ligne, 10 maximum, 500 caractères par référence, sans HTML. Aucun fichier n’est téléversé ; ces déclarations ne sont pas des preuves vérifiées.",
                    "One reference per line, maximum 10, 500 characters each, without HTML. No files are uploaded; these declarations are not verified evidence.",
                  )}
                />
                {invalidForm && (
                  <Alert severity="warning">
                    {t(
                      "Complétez toutes les réponses et respectez les limites des références.",
                      "Complete every answer and respect the reference limits.",
                    )}
                  </Alert>
                )}
                {send.isError && (
                  <Alert severity="error">
                    {t(
                      "L’envoi a échoué. Vos réponses sont conservées sur cette page. Vérifiez le lien et réessayez, ou contactez votre interlocuteur.",
                      "Submission failed. Your answers are preserved on this page. Check the link and try again, or contact your representative.",
                    )}
                  </Alert>
                )}
                <Typography variant="body2" color="text.secondary">
                  {t(
                    "Après envoi, les réponses ne pourront plus être modifiées.",
                    "After submission, answers cannot be changed.",
                  )}
                </Typography>
                <Button
                  type="submit"
                  variant="contained"
                  disabled={send.isPending || !validAnswers || !validEvidence}
                >
                  {send.isPending
                    ? t("Envoi…", "Submitting…")
                    : t("Envoyer mes réponses", "Submit my answers")}
                </Button>
              </Stack>
            )}
          </Stack>
        </CardContent>
      </Card>
    </Box>
  );
}
