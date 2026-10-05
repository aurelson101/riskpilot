import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
  InputLabel,
  Link,
  MenuItem,
  Select,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { useId, useRef, useState, type FormEvent } from "react";
import { api } from "../api/client";
import type { User } from "../api/types";
import { useAuth } from "../auth/useAuth";
import { useInterfaceLocale } from "../i18n/InterfaceLocaleContext";

type Props = {
  thirdParty: { id: number; name: string };
  onClose: () => void;
};
type Question = { id: string; label: string; weight: string };
type CampaignPayload = {
  reviewerId: number;
  title: string;
  version: number;
  expiresAt: string;
  questions: Array<{ id: string; label: string; weight: number }>;
};

const managerRoles = [
  "ROLE_SUPER_ADMIN",
  "ROLE_ADMIN",
  "ROLE_RISK_MANAGER",
  "ROLE_AUDITOR",
];
const directoryRoles = ["ROLE_SUPER_ADMIN", "ROLE_ADMIN", "ROLE_RISK_MANAGER"];

function localDate(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

function expiryAtEndOfDay(value: string): Date | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
  const date = new Date(`${value}T23:59:59`);
  return !Number.isNaN(date.getTime()) && localDate(date) === value
    ? date
    : null;
}

export function SupplierCampaignDialog({ thirdParty, onClose }: Props) {
  const locale = useInterfaceLocale();
  const t = (fr: string, en: string) => (locale === "en" ? en : fr);
  const { user } = useAuth();
  const canManage = Boolean(
    user?.roles.some((role) => managerRoles.includes(role)),
  );
  const canListUsers = Boolean(
    user?.roles.some((role) => directoryRoles.includes(role)),
  );
  const cache = useQueryClient();
  const id = useId();
  const questionCounter = useRef(4);
  const [title, setTitle] = useState(() =>
    `${t("Évaluation fournisseur", "Supplier assessment")} — ${thirdParty.name}`.slice(
      0,
      200,
    ),
  );
  const [version, setVersion] = useState("1");
  const [today] = useState(() => localDate(new Date()));
  const [reviewerId, setReviewerId] = useState(String(user?.id ?? ""));
  const [expiresAt, setExpiresAt] = useState(() => {
    const date = new Date();
    date.setDate(date.getDate() + 30);
    return localDate(date);
  });
  const [questions, setQuestions] = useState<Question[]>(() => [
    {
      id: "q1",
      label: t(
        "Comment protégez-vous les accès sensibles avec l’authentification multifacteur ?",
        "How do you protect sensitive access with multifactor authentication?",
      ),
      weight: "1",
    },
    {
      id: "q2",
      label: t(
        "Comment protégez-vous les données confiées par vos clients ?",
        "How do you protect data entrusted to you by your customers?",
      ),
      weight: "1",
    },
    {
      id: "q3",
      label: t(
        "Comment assurez-vous la continuité du service et la restauration des données ?",
        "How do you ensure service continuity and data recovery?",
      ),
      weight: "1",
    },
  ]);
  const [link, setLink] = useState<string | null>(null);
  const [created, setCreated] = useState(false);
  const [copyState, setCopyState] = useState<"idle" | "copied" | "failed">(
    "idle",
  );
  const users = useQuery({
    queryKey: ["users"],
    enabled: canManage && canListUsers && !created,
    queryFn: async ({ signal }) =>
      (await api.get<User[]>("/users", { signal })).data,
  });
  const reviewers = new Map<number, string>();
  for (const item of users.data ?? []) {
    reviewers.set(
      item.id,
      `${item.firstName} ${item.lastName}`.trim() || item.email,
    );
  }
  if (user && !reviewers.has(user.id)) {
    reviewers.set(
      user.id,
      `${user.firstName} ${user.lastName}`.trim() || user.email,
    );
  }
  const create = useMutation({
    mutationFn: async (payload: CampaignPayload) =>
      (
        await api.post<{ publicToken?: unknown }>(
          `/third-parties/${thirdParty.id}/assessments`,
          payload,
        )
      ).data,
    onSuccess: async (result) => {
      setCreated(true);
      if (
        typeof result.publicToken === "string" &&
        /^[a-f0-9]{64}$/i.test(result.publicToken)
      ) {
        setLink(
          `${window.location.origin}/supplier-assessments/${result.publicToken}`,
        );
      }
      await cache.invalidateQueries({ queryKey: ["third-parties"] });
    },
  });
  const expiry = expiryAtEndOfDay(expiresAt);
  const valid =
    canManage &&
    !created &&
    title.trim().length > 0 &&
    title.trim().length <= 200 &&
    /^\d+$/.test(version) &&
    Number(version) >= 1 &&
    Number(version) <= 2147483647 &&
    reviewers.has(Number(reviewerId)) &&
    expiry !== null &&
    expiresAt >= today &&
    questions.length > 0 &&
    questions.length <= 100 &&
    questions.every(
      (question) =>
        question.label.trim().length > 0 &&
        question.label.trim().length <= 2000 &&
        /^\d+$/.test(question.weight) &&
        Number(question.weight) >= 1 &&
        Number(question.weight) <= 100,
    );
  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!valid || !expiry || expiry.getTime() <= Date.now() || create.isPending)
      return;
    create.mutate({
      reviewerId: Number(reviewerId),
      title: title.trim(),
      version: Number(version),
      expiresAt: expiry.toISOString(),
      questions: questions.map((question) => ({
        id: question.id,
        label: question.label.trim(),
        weight: Number(question.weight),
      })),
    });
  };
  const copy = async () => {
    if (!link) return;
    try {
      await navigator.clipboard.writeText(link);
      setCopyState("copied");
    } catch {
      setCopyState("failed");
    }
  };
  const updateQuestion = (questionId: string, patch: Partial<Question>) => {
    setQuestions((current) =>
      current.map((question) =>
        question.id === questionId ? { ...question, ...patch } : question,
      ),
    );
  };

  if (!canManage) return null;

  return (
    <Dialog
      open
      fullWidth
      maxWidth="md"
      aria-labelledby={`${id}-title`}
      disableEscapeKeyDown={create.isPending}
      onClose={() => {
        if (!create.isPending) onClose();
      }}
    >
      <DialogTitle id={`${id}-title`}>
        {t("Nouvelle campagne fournisseur", "New supplier campaign")}
      </DialogTitle>
      <DialogContent sx={{ overflowWrap: "anywhere" }}>
        {created ? (
          <Stack spacing={2} sx={{ pt: 1, minWidth: 0 }}>
            <Alert severity="success">
              {t(
                "Campagne créée. Aucun email n’a été envoyé.",
                "Campaign created. No email has been sent.",
              )}
            </Alert>
            <Typography>
              {thirdParty.name} — {t("Échéance", "Due date")} : {expiresAt}
            </Typography>
            <Alert severity="warning">
              {t(
                "Ce lien confidentiel permet de répondre sans connexion. Copiez-le avant de fermer cette fenêtre, puis transmettez-le uniquement au fournisseur concerné.",
                "This confidential link allows a response without signing in. Copy it before closing this window, then share it only with the intended supplier.",
              )}
            </Alert>
            {link ? (
              <>
                <TextField
                  id={`${id}-link`}
                  label={t("Lien fournisseur", "Supplier link")}
                  value={link}
                  fullWidth
                  slotProps={{ input: { readOnly: true } }}
                  onFocus={(event) => event.target.select()}
                />
                <Stack
                  direction="row"
                  spacing={1}
                  useFlexGap
                  flexWrap="wrap"
                  alignItems="center"
                >
                  <Button onClick={() => void copy()}>
                    {t("Copier le lien", "Copy link")}
                  </Button>
                  <Link
                    href={link}
                    target="_blank"
                    rel="noopener noreferrer"
                    referrerPolicy="no-referrer"
                  >
                    {t("Ouvrir le questionnaire", "Open questionnaire")}
                  </Link>
                </Stack>
                {copyState === "copied" && (
                  <Alert severity="success">
                    {t("Lien copié.", "Link copied.")}
                  </Alert>
                )}
                {copyState === "failed" && (
                  <Alert severity="info">
                    {t(
                      "La copie automatique est indisponible. Sélectionnez le lien et copiez-le manuellement.",
                      "Automatic copying is unavailable. Select the link and copy it manually.",
                    )}
                  </Alert>
                )}
              </>
            ) : (
              <Alert severity="error">
                {t(
                  "La campagne existe, mais aucun lien valide n’a été reçu. Ne recréez pas la campagne ; contactez votre administrateur.",
                  "The campaign exists, but no valid link was received. Do not create a duplicate campaign; contact your administrator.",
                )}
              </Alert>
            )}
          </Stack>
        ) : (
          <Stack
            component="form"
            id={`${id}-form`}
            onSubmit={submit}
            spacing={2}
            sx={{ pt: 1, minWidth: 0 }}
          >
            <Typography>{thirdParty.name}</Typography>
            <Alert severity="info">
              {t(
                "Personnalisez ces questions générales selon les services du fournisseur. Elles ne constituent pas un audit ou une certification.",
                "Adapt these general questions to the supplier’s services. They do not constitute an audit or certification.",
              )}
            </Alert>
            {create.isError && (
              <Alert severity="error">
                {t(
                  "La création a échoué. Vos saisies sont conservées ; réessayez.",
                  "Creation failed. Your entries are preserved; please retry.",
                )}
              </Alert>
            )}
            {users.isError && (
              <Alert severity="warning">
                {t(
                  "Annuaire indisponible. Vous pouvez vous désigner comme évaluateur.",
                  "The directory is unavailable. You can assign yourself as reviewer.",
                )}
              </Alert>
            )}
            <TextField
              id={`${id}-name`}
              label={t("Titre de la campagne", "Campaign title")}
              value={title}
              onChange={(event) => setTitle(event.target.value)}
              required
              disabled={create.isPending}
              slotProps={{ htmlInput: { maxLength: 200 } }}
            />
            <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
              <TextField
                id={`${id}-version`}
                label={t("Version", "Version")}
                type="number"
                value={version}
                onChange={(event) => setVersion(event.target.value)}
                required
                disabled={create.isPending}
                slotProps={{ htmlInput: { min: 1, max: 2147483647, step: 1 } }}
                sx={{ flex: 1 }}
              />
              <TextField
                id={`${id}-expires`}
                label={t("Date limite de réponse", "Response deadline")}
                type="date"
                value={expiresAt}
                onChange={(event) => setExpiresAt(event.target.value)}
                required
                disabled={create.isPending}
                slotProps={{
                  inputLabel: { shrink: true },
                  htmlInput: { min: today },
                }}
                sx={{ flex: 1 }}
              />
            </Stack>
            <FormControl disabled={create.isPending} required fullWidth>
              <InputLabel id={`${id}-reviewer-label`}>
                {t("Évaluateur", "Reviewer")}
              </InputLabel>
              <Select
                id={`${id}-reviewer`}
                labelId={`${id}-reviewer-label`}
                label={t("Évaluateur", "Reviewer")}
                value={reviewerId}
                onChange={(event) => setReviewerId(event.target.value)}
              >
                {[...reviewers].map(([reviewer, name]) => (
                  <MenuItem key={reviewer} value={String(reviewer)}>
                    {name}
                  </MenuItem>
                ))}
              </Select>
            </FormControl>
            <Typography variant="subtitle1">
              {t("Questions", "Questions")} ({questions.length}/100)
            </Typography>
            {questions.map((question, index) => (
              <Stack key={question.id} spacing={1} sx={{ minWidth: 0 }}>
                <TextField
                  id={`${id}-${question.id}-label`}
                  label={`${t("Question", "Question")} ${index + 1}`}
                  value={question.label}
                  onChange={(event) =>
                    updateQuestion(question.id, { label: event.target.value })
                  }
                  multiline
                  minRows={2}
                  slotProps={{ htmlInput: { maxLength: 2000 } }}
                  required
                  disabled={create.isPending}
                />
                <Stack direction={{ xs: "column", sm: "row" }} spacing={1}>
                  <TextField
                    id={`${id}-${question.id}-weight`}
                    label={`${t("Poids de la question", "Question weight")} ${index + 1}`}
                    type="number"
                    value={question.weight}
                    onChange={(event) =>
                      updateQuestion(question.id, {
                        weight: event.target.value,
                      })
                    }
                    required
                    disabled={create.isPending}
                    slotProps={{ htmlInput: { min: 1, max: 100, step: 1 } }}
                    sx={{ flex: 1 }}
                  />
                  <Button
                    color="error"
                    disabled={create.isPending || questions.length <= 1}
                    onClick={() =>
                      setQuestions((current) =>
                        current.filter((item) => item.id !== question.id),
                      )
                    }
                    aria-label={`${t("Supprimer la question", "Remove question")} ${index + 1}`}
                  >
                    {t("Supprimer", "Remove")}
                  </Button>
                </Stack>
              </Stack>
            ))}
            <Button
              disabled={create.isPending || questions.length >= 100}
              onClick={() => {
                const questionId = `q${questionCounter.current++}`;
                setQuestions((current) => [
                  ...current,
                  { id: questionId, label: "", weight: "1" },
                ]);
              }}
            >
              {t("Ajouter une question", "Add question")}
            </Button>
          </Stack>
        )}
      </DialogContent>
      <DialogActions sx={{ flexWrap: "wrap" }}>
        <Button onClick={onClose} disabled={create.isPending}>
          {t("Fermer", "Close")}
        </Button>
        {!created && (
          <Button
            type="submit"
            form={`${id}-form`}
            variant="contained"
            disabled={!valid || create.isPending}
          >
            {create.isPending
              ? t("Création…", "Creating…")
              : t("Créer la campagne", "Create campaign")}
          </Button>
        )}
      </DialogActions>
    </Dialog>
  );
}
