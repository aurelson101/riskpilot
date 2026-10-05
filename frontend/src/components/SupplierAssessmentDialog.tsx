import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { useId, useState, type FormEvent } from "react";
import { api } from "../api/client";
import { useInterfaceLocale } from "../i18n/InterfaceLocaleContext";

type SupplierAssessment = {
  id: number;
  title: string;
  version: number;
  reviewer: { id: number; name: string };
  status: string;
  expiresAt: string;
  submittedAt: string | null;
  reviewedAt: string | null;
  score: number | null;
  reviewComment: string | null;
  questions: Array<{ id: string; label: string; weight: number }>;
  responses: Record<string, unknown>;
  evidence: string[];
};

type Props = {
  assessmentId: number | null;
  canManage: boolean;
  onClose: () => void;
};

export function SupplierAssessmentDialog(props: Props) {
  if (
    props.assessmentId === null ||
    !Number.isInteger(props.assessmentId) ||
    props.assessmentId <= 0
  ) {
    return null;
  }
  return (
    <AssessmentDetail
      key={props.assessmentId}
      assessmentId={props.assessmentId}
      canManage={props.canManage}
      onClose={props.onClose}
    />
  );
}

function AssessmentDetail({
  assessmentId,
  canManage,
  onClose,
}: Props & { assessmentId: number }) {
  const locale = useInterfaceLocale();
  const t = (fr: string, en: string) => (locale === "en" ? en : fr);
  const cache = useQueryClient();
  const id = useId();
  const [score, setScore] = useState("");
  const [comment, setComment] = useState("");
  const detail = useQuery({
    queryKey: ["supplier-assessment", assessmentId],
    queryFn: async ({ signal }) =>
      (
        await api.get<SupplierAssessment>(
          `/supplier-assessments/${assessmentId}`,
          { signal },
        )
      ).data,
  });
  const review = useMutation({
    mutationFn: async (payload: { score: number; comment: string }) =>
      (
        await api.post<SupplierAssessment>(
          `/supplier-assessments/${assessmentId}/review`,
          payload,
        )
      ).data,
    onSuccess: async (reviewed) => {
      cache.setQueryData<SupplierAssessment>(
        ["supplier-assessment", assessmentId],
        (current) => (current ? { ...current, ...reviewed } : reviewed),
      );
      await Promise.all([
        cache.invalidateQueries({ queryKey: ["third-parties"] }),
        cache.invalidateQueries({
          queryKey: ["supplier-assessment", assessmentId],
        }),
      ]);
    },
  });
  const assessment = detail.data;
  const canReview =
    canManage && assessment?.status === "SUBMITTED" && !detail.isError;
  const validScore = /^\d{1,3}$/.test(score) && Number(score) <= 100;
  const validReview = validScore && comment.trim().length > 0;
  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!canReview || !validReview || review.isPending) return;
    review.mutate({ score: Number(score), comment: comment.trim() });
  };
  const date = (value: string | null) => {
    if (!value) return t("Non renseignée", "Not provided");
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime())
      ? t("Date indisponible", "Date unavailable")
      : parsed.toLocaleDateString(locale === "en" ? "en-GB" : "fr-FR");
  };
  const response = (value: unknown, depth = 0): string => {
    if (value === null || value === undefined || value === "") {
      return t("Non renseignée", "Not provided");
    }
    if (typeof value === "boolean")
      return value ? t("Oui", "Yes") : t("Non", "No");
    if (typeof value === "string" || typeof value === "number") {
      return String(value);
    }
    if (depth >= 4) return t("Réponse structurée", "Structured answer");
    if (Array.isArray(value)) {
      return value.length
        ? value.map((item) => response(item, depth + 1)).join(" · ")
        : t("Non renseignée", "Not provided");
    }
    if (typeof value === "object") {
      const entries = Object.entries(value);
      return entries.length
        ? entries
            .map(([key, item]) => `${key} : ${response(item, depth + 1)}`)
            .join(" · ")
        : t("Non renseignée", "Not provided");
    }
    return t(
      "Format de réponse non pris en charge",
      "Unsupported answer format",
    );
  };
  const statusLabel = (status: string) => {
    const labels: Record<string, string> = {
      DRAFT: t("Brouillon", "Draft"),
      SENT: t("Envoyée", "Sent"),
      IN_PROGRESS: t("En cours", "In progress"),
      SUBMITTED: t("À examiner", "Awaiting review"),
      REVIEWED: t("Revue validée", "Review validated"),
      EXPIRED: t("Expirée", "Expired"),
    };
    return labels[status] ?? t("Statut inconnu", "Unknown status");
  };

  return (
    <Dialog
      open
      fullWidth
      maxWidth="md"
      aria-labelledby={`${id}-title`}
      disableEscapeKeyDown={review.isPending}
      onClose={() => {
        if (!review.isPending) onClose();
      }}
    >
      <DialogTitle id={`${id}-title`}>
        {t("Évaluation fournisseur", "Supplier assessment")}
      </DialogTitle>
      <DialogContent sx={{ overflowWrap: "anywhere" }}>
        <Stack spacing={2} sx={{ pt: 1, minWidth: 0 }}>
          {detail.isLoading && (
            <CircularProgress
              aria-label={t("Chargement de l’évaluation", "Loading assessment")}
            />
          )}
          {detail.isError && (
            <Alert
              severity="error"
              action={
                <Button
                  color="inherit"
                  onClick={() => void detail.refetch()}
                  disabled={detail.isFetching}
                >
                  {t("Réessayer", "Retry")}
                </Button>
              }
            >
              {t(
                "Impossible de charger l’évaluation fournisseur.",
                "Unable to load the supplier assessment.",
              )}
            </Alert>
          )}
          {assessment && (
            <>
              <Stack spacing={1}>
                <Typography variant="h6">{assessment.title}</Typography>
                <Stack direction="row" spacing={1} useFlexGap flexWrap="wrap">
                  <Chip label={statusLabel(assessment.status)} />
                  <Chip
                    variant="outlined"
                    label={`${t("Version", "Version")} ${assessment.version}`}
                  />
                </Stack>
                <Typography variant="body2">
                  {t("Évaluateur", "Reviewer")} : {assessment.reviewer.name}
                </Typography>
                <Typography variant="body2">
                  {t("Échéance", "Due date")} : {date(assessment.expiresAt)}
                </Typography>
                {assessment.submittedAt && (
                  <Typography variant="body2">
                    {t("Soumise le", "Submitted on")} :{" "}
                    {date(assessment.submittedAt)}
                  </Typography>
                )}
              </Stack>
              <Typography variant="subtitle1" component="h3">
                {t("Questions et réponses", "Questions and answers")}
              </Typography>
              {!assessment.questions.length && (
                <Typography color="text.secondary">
                  {t("Aucune question disponible.", "No questions available.")}
                </Typography>
              )}
              {assessment.questions.map((question, index) => (
                <Box
                  key={`${question.id}-${index}`}
                  sx={{
                    border: 1,
                    borderColor: "divider",
                    borderRadius: 1,
                    p: 2,
                    minWidth: 0,
                  }}
                >
                  <Typography fontWeight={600}>{question.label}</Typography>
                  <Typography variant="body2" color="text.secondary">
                    {t("Poids", "Weight")} : {question.weight}
                  </Typography>
                  <Typography sx={{ mt: 1, whiteSpace: "pre-wrap" }}>
                    {response(assessment.responses[question.id])}
                  </Typography>
                </Box>
              ))}
              <Typography variant="subtitle1" component="h3">
                {t("Références fournies", "Provided references")}
              </Typography>
              <Alert severity="info">
                {t(
                  "Ces références sont déclarées par le fournisseur, non vérifiées. Elles ne prouvent pas à elles seules sa conformité.",
                  "These references are supplied by the supplier and are not verified. They do not establish compliance on their own.",
                )}
              </Alert>
              {assessment.evidence.length ? (
                <Box component="ul" sx={{ m: 0, pl: 3 }}>
                  {assessment.evidence.map((reference, index) => (
                    <Typography
                      component="li"
                      key={index}
                      sx={{ whiteSpace: "pre-wrap" }}
                    >
                      {reference}
                    </Typography>
                  ))}
                </Box>
              ) : (
                <Typography color="text.secondary">
                  {t("Aucune référence fournie.", "No references provided.")}
                </Typography>
              )}
              {assessment.status === "REVIEWED" && (
                <Alert severity="success">
                  <Typography fontWeight={600}>
                    {t("Score validé", "Reviewed score")} :{" "}
                    {assessment.score ?? "—"} %
                  </Typography>
                  <Typography variant="body2">
                    {t("Revue le", "Reviewed on")} :{" "}
                    {date(assessment.reviewedAt)}
                  </Typography>
                  <Typography sx={{ whiteSpace: "pre-wrap" }}>
                    {assessment.reviewComment ||
                      t(
                        "Aucun commentaire disponible.",
                        "No comment available.",
                      )}
                  </Typography>
                </Alert>
              )}
              {canReview && (
                <Box component="form" id={`${id}-form`} onSubmit={submit}>
                  <Stack spacing={2}>
                    <Typography variant="subtitle1" component="h3">
                      {t("Revue humaine", "Human review")}
                    </Typography>
                    <TextField
                      id={`${id}-score`}
                      label={t("Score validé (%)", "Reviewed score (%)")}
                      value={score}
                      onChange={(event) => setScore(event.target.value)}
                      type="number"
                      required
                      fullWidth
                      disabled={review.isPending}
                      slotProps={{ htmlInput: { min: 0, max: 100, step: 1 } }}
                      error={score !== "" && !validScore}
                      helperText={t(
                        "Entier de 0 à 100, après examen des réponses.",
                        "Integer from 0 to 100, after reviewing the answers.",
                      )}
                    />
                    <TextField
                      id={`${id}-comment`}
                      label={t("Commentaire de revue", "Review comment")}
                      value={comment}
                      onChange={(event) => setComment(event.target.value)}
                      required
                      fullWidth
                      multiline
                      minRows={3}
                      disabled={review.isPending}
                    />
                    {review.isError && (
                      <Alert severity="error">
                        {t(
                          "Impossible de valider la revue. Vos saisies sont conservées ; réessayez.",
                          "Unable to validate the review. Your entries are preserved; please try again.",
                        )}
                      </Alert>
                    )}
                  </Stack>
                </Box>
              )}
            </>
          )}
        </Stack>
      </DialogContent>
      <DialogActions sx={{ flexWrap: "wrap", gap: 1 }}>
        <Button onClick={onClose} disabled={review.isPending}>
          {t("Fermer", "Close")}
        </Button>
        {canReview && (
          <Button
            type="submit"
            form={`${id}-form`}
            variant="contained"
            disabled={!validReview || review.isPending}
          >
            {review.isPending
              ? t("Validation…", "Validating…")
              : t("Valider la revue", "Validate review")}
          </Button>
        )}
      </DialogActions>
    </Dialog>
  );
}
