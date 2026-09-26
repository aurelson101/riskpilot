import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AddOutlined, EditOutlined } from "@mui/icons-material";
import {
  Autocomplete,
  Alert,
  Button,
  Card,
  CardActionArea,
  CardContent,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Checkbox,
  FormControlLabel,
  LinearProgress,
  Pagination,
  CircularProgress,
  MenuItem,
  Stack,
  Tab,
  Tabs,
  TextField,
  Typography,
} from "@mui/material";
import { useMemo, useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { api } from "../api/client";
import type { User } from "../api/types";
import { useAuth } from "../auth/useAuth";
import { hasAnyRole } from "../auth/roles";
import { RecordDetails } from "../components/RecordDetails";

type RecordType =
  | "TASK"
  | "RESPONSIBILITY_RULE"
  | "COMPLIANCE_PROGRAM"
  | "QUESTIONNAIRE_TEMPLATE"
  | "QUESTIONNAIRE_CAMPAIGN"
  | "REFERENCE_PACK";
type RecordItem = {
  id: number;
  type: RecordType;
  title: string;
  status: string;
  details: Record<string, unknown>;
  owner: { id: number; name: string } | null;
  dueAt: string | null;
};
type Task = {
  id: number;
  title: string;
  status: string;
  source: string;
  link: string;
  dueAt: string | null;
  overdue: boolean;
  priority: string;
  quickActions: string[];
};
type Trajectory = {
  id: number;
  title: string;
  current: number;
  expected: number;
  target: number;
  atRisk: boolean;
  dueAt: string;
  gap: number;
  remainingDays: number;
  frameworkScores: Record<string, number>;
  source: "ASSESSMENTS" | "DECLARED";
};

const sections: Array<{ type: RecordType | "MY_TASKS"; label: string }> = [
  { type: "MY_TASKS", label: "Mes tâches" },
  { type: "RESPONSIBILITY_RULE", label: "Responsabilités" },
  { type: "COMPLIANCE_PROGRAM", label: "Trajectoires" },
  { type: "QUESTIONNAIRE_TEMPLATE", label: "Questionnaires" },
  { type: "QUESTIONNAIRE_CAMPAIGN", label: "Campagnes" },
];

const sectionHelp: Record<RecordType | "MY_TASKS", string> = {
  MY_TASKS:
    "Retrouvez ici vos actions, évaluations, risques, contrôles, tiers et incidents à traiter.",
  TASK: "Les tâches manuelles sont regroupées dans Mes tâches.",
  RESPONSIBILITY_RULE:
    "Définissez qui prend automatiquement en charge un domaine GRC.",
  COMPLIANCE_PROGRAM:
    "Fixez une cible et suivez la progression réelle de vos référentiels.",
  QUESTIONNAIRE_TEMPLATE:
    "Préparez un questionnaire simple pour collecter des réponses ou des preuves.",
  QUESTIONNAIRE_CAMPAIGN:
    "Choisissez un questionnaire, ses destinataires et une échéance.",
  REFERENCE_PACK: "Les packs techniques sont gérés automatiquement.",
};

const defaultDetails: Record<RecordType, Record<string, unknown>> = {
  TASK: { description: "", priority: "MEDIUM" },
  RESPONSIBILITY_RULE: {
    domain: "GOUVERNANCE",
    scopeType: "ORGANIZATION",
    defaultRole: "ROLE_RISK_MANAGER",
    requiresApproval: true,
  },
  COMPLIANCE_PROGRAM: {
    startDate: new Date().toISOString().slice(0, 10),
    currentScore: 0,
    targetScore: 100,
    frameworks: [],
  },
  QUESTIONNAIRE_TEMPLATE: {
    useCase: "EVIDENCE_COLLECTION",
    version: 1,
    questions: [
      {
        id: "Q1",
        label: "Décrivez la preuve disponible.",
        type: "EVIDENCE",
      },
    ],
    reminderDays: [7, 2],
  },
  QUESTIONNAIRE_CAMPAIGN: {
    templateId: null,
    recipientIds: [],
    responseStatus: "DRAFT",
  },
  REFERENCE_PACK: {},
};

const emptyForm = (type: RecordType) => ({
  title: "",
  dueAt: "",
  ownerId: "",
  details: JSON.stringify(defaultDetails[type], null, 2),
});

export function OperationsPage() {
  const { user } = useAuth();
  const canManage = hasAnyRole(user?.roles, [
    "ROLE_SUPER_ADMIN",
    "ROLE_ADMIN",
    "ROLE_RISK_MANAGER",
  ]);
  const client = useQueryClient();
  const navigate = useNavigate();
  const [section, setSection] = useState<RecordType | "MY_TASKS">("MY_TASKS");
  const [open, setOpen] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState(() => emptyForm("RESPONSIBILITY_RULE"));
  const [taskQuery, setTaskQuery] = useState("");
  const [taskSource, setTaskSource] = useState("");
  const [taskStatus, setTaskStatus] = useState("");
  const [taskPage, setTaskPage] = useState(1);
  const [delegation, setDelegation] = useState({
    taskId: 0,
    email: "",
    until: "",
  });
  const closeDialog = () => {
    setOpen(false);
    setEditingId(null);
    if (section !== "MY_TASKS") setForm(emptyForm(section));
  };
  const openCreateDialog = () => {
    setEditingId(null);
    if (section === "MY_TASKS") return;
    setForm(emptyForm(section));
    setOpen(true);
  };
  const openEditDialog = (item: RecordItem) => {
    setEditingId(item.id);
    setForm({
      title: item.title,
      dueAt: item.dueAt?.slice(0, 16) ?? "",
      ownerId: item.owner ? String(item.owner.id) : "",
      details: JSON.stringify(item.details, null, 2),
    });
    setOpen(true);
  };
  const tasks = useQuery({
    queryKey: ["my-tasks", taskQuery, taskSource, taskStatus, taskPage],
    queryFn: async () => {
      const data = (
        await api.get<{ items: Task[]; total: number; pages: number } | Task[]>(
          `/operations/my-tasks?q=${encodeURIComponent(taskQuery)}&source=${taskSource}&status=${taskStatus}&page=${taskPage}&limit=10`,
        )
      ).data;
      return Array.isArray(data)
        ? { items: data, total: data.length, pages: 1 }
        : {
            items: data.items,
            total: data.total ?? data.items.length,
            pages: data.pages ?? 1,
          };
    },
  });
  const records = useQuery({
    queryKey: ["operations", section],
    enabled: section !== "MY_TASKS",
    queryFn: async () =>
      (await api.get<RecordItem[]>(`/operations/records?type=${section}`)).data,
  });
  const trajectory = useQuery({
    queryKey: ["compliance-trajectory"],
    enabled: section === "COMPLIANCE_PROGRAM",
    queryFn: async () =>
      (await api.get<Trajectory[]>("/operations/compliance-trajectory")).data,
  });
  const users = useQuery({
    queryKey: ["users", "operations-owner-selector"],
    enabled: canManage && open,
    queryFn: async () => (await api.get<User[]>("/users")).data,
    staleTime: 5 * 60 * 1000,
  });
  const questionnaireTemplates = useQuery({
    queryKey: ["operations", "QUESTIONNAIRE_TEMPLATE", "campaign-selector"],
    enabled: open && section === "QUESTIONNAIRE_CAMPAIGN",
    queryFn: async () =>
      (
        await api.get<RecordItem[]>(
          "/operations/records?type=QUESTIONNAIRE_TEMPLATE",
        )
      ).data.filter((item) => item.status === "ACTIVE"),
  });
  const create = useMutation({
    mutationFn: () =>
      api.post("/operations/records", {
        type: section,
        title: form.title,
        status: "ACTIVE",
        ownerId: form.ownerId || null,
        dueAt: form.dueAt || null,
        details: JSON.parse(form.details),
      }),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["operations"] });
      await client.invalidateQueries({ queryKey: ["my-tasks"] });
      closeDialog();
    },
  });
  const update = useMutation({
    mutationFn: () =>
      api.put(`/operations/records/${editingId}`, {
        title: form.title,
        ownerId: form.ownerId || null,
        dueAt: form.dueAt || null,
        details: JSON.parse(form.details),
      }),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["operations"] });
      await client.invalidateQueries({ queryKey: ["my-tasks"] });
      closeDialog();
    },
  });
  const currentTrajectory = useMemo(
    () => new Map((trajectory.data ?? []).map((item) => [item.id, item])),
    [trajectory.data],
  );
  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (editingId) update.mutate();
    else create.mutate();
  };
  const completeTask = useMutation({
    mutationFn: (id: number) => api.post(`/operations/tasks/${id}/complete`),
    onSuccess: () => client.invalidateQueries({ queryKey: ["my-tasks"] }),
  });
  const delegateTask = useMutation({
    mutationFn: () =>
      api.post(`/operations/tasks/${delegation.taskId}/delegate`, {
        email: delegation.email,
        until: delegation.until,
      }),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["my-tasks"] });
      setDelegation({ taskId: 0, email: "", until: "" });
    },
  });
  const parsedDetails = useMemo(() => {
    try {
      return JSON.parse(form.details) as Record<string, unknown>;
    } catch {
      return {};
    }
  }, [form.details]);
  const setDetail = (key: string, value: unknown) =>
    setForm({
      ...form,
      details: JSON.stringify({ ...parsedDetails, [key]: value }, null, 2),
    });
  const questionnaireQuestions = Array.isArray(parsedDetails.questions)
    ? (parsedDetails.questions as Array<{ id?: string; label?: string }>)
    : [];
  const recordReady =
    form.title.trim().length > 0 &&
    (section !== "QUESTIONNAIRE_TEMPLATE" ||
      (questionnaireQuestions.length > 0 &&
        questionnaireQuestions.every(
          (question) =>
            Boolean(question.id?.trim()) && Boolean(question.label?.trim()),
        ))) &&
    (section !== "QUESTIONNAIRE_CAMPAIGN" ||
      (Number(parsedDetails.templateId) > 0 &&
        Array.isArray(parsedDetails.recipientIds) &&
        parsedDetails.recipientIds.length > 0));

  return (
    <Stack spacing={3}>
      <div>
        <Typography variant="h4" fontWeight={800}>
          Pilotage opérationnel
        </Typography>
        <Typography color="text.secondary">
          Responsabilités, tâches, trajectoires, questionnaires, campagnes et
          contenus de référence
        </Typography>
      </div>
      <Card>
        <Tabs
          value={section}
          onChange={(_, value) => setSection(value)}
          variant="scrollable"
          scrollButtons="auto"
          aria-label="Pilotage opérationnel"
        >
          {sections.map((item) => (
            <Tab key={item.type} value={item.type} label={item.label} />
          ))}
        </Tabs>
      </Card>
      <Alert severity="info">{sectionHelp[section]}</Alert>
      {(tasks.isError ||
        records.isError ||
        trajectory.isError ||
        create.isError ||
        update.isError) && (
        <Alert severity="error">L’opération n’a pas pu être terminée.</Alert>
      )}
      {(tasks.isLoading ||
        (section !== "MY_TASKS" && records.isLoading) ||
        (section === "COMPLIANCE_PROGRAM" && trajectory.isLoading)) && (
        <Stack alignItems="center" py={4}>
          <CircularProgress aria-label="Chargement du pilotage opérationnel" />
        </Stack>
      )}
      {section === "MY_TASKS" ? (
        <Stack spacing={2}>
          <Stack direction={{ xs: "column", md: "row" }} gap={1}>
            <TextField
              size="small"
              label="Rechercher"
              value={taskQuery}
              onChange={(e) => {
                setTaskQuery(e.target.value);
                setTaskPage(1);
              }}
            />
            <TextField
              size="small"
              select
              label="Source"
              value={taskSource}
              onChange={(e) => {
                setTaskSource(e.target.value);
                setTaskPage(1);
              }}
              sx={{ minWidth: 160 }}
            >
              <MenuItem value="">Toutes</MenuItem>
              {[
                "OPERATIONAL",
                "ACTION",
                "ASSESSMENT",
                "RISK",
                "CONTROL",
                "THIRD_PARTY",
                "INCIDENT",
              ].map((value) => (
                <MenuItem key={value} value={value}>
                  {value}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              size="small"
              select
              label="Statut"
              value={taskStatus}
              onChange={(e) => {
                setTaskStatus(e.target.value);
                setTaskPage(1);
              }}
              sx={{ minWidth: 150 }}
            >
              <MenuItem value="">Tous</MenuItem>
              {[
                "ACTIVE",
                "IN_PROGRESS",
                "DRAFT",
                "OPEN",
                "PLANNED",
                "PARTIAL",
              ].map((value) => (
                <MenuItem key={value} value={value}>
                  {value}
                </MenuItem>
              ))}
            </TextField>
          </Stack>
          {tasks.data?.items.map((item) => (
            <Card key={`${item.source}-${item.id}`}>
              <CardActionArea onClick={() => navigate(item.link)}>
                <CardContent>
                  <Stack
                    direction={{ xs: "column", sm: "row" }}
                    justifyContent="space-between"
                    gap={1}
                  >
                    <div>
                      <Typography fontWeight={750}>{item.title}</Typography>
                      <Typography variant="body2" color="text.secondary">
                        {item.source} ·{" "}
                        {item.dueAt
                          ? new Date(item.dueAt).toLocaleDateString()
                          : "Sans échéance"}
                      </Typography>
                    </div>
                    <Chip
                      color={item.overdue ? "error" : "default"}
                      label={`${item.priority} · ${item.status}`}
                    />
                  </Stack>
                </CardContent>
              </CardActionArea>
              {(item.quickActions ?? []).includes("complete") && (
                <Button
                  sx={{ m: 1 }}
                  size="small"
                  onClick={() => completeTask.mutate(item.id)}
                >
                  Terminer
                </Button>
              )}
              {(item.quickActions ?? []).includes("delegate") && (
                <Button
                  sx={{ m: 1 }}
                  size="small"
                  onClick={() =>
                    setDelegation({ taskId: item.id, email: "", until: "" })
                  }
                >
                  Déléguer
                </Button>
              )}
            </Card>
          ))}
          {tasks.data?.total === 0 && (
            <Alert severity="success">Aucune tâche ouverte.</Alert>
          )}
          {(tasks.data?.pages ?? 0) > 1 && (
            <Pagination
              page={taskPage}
              count={tasks.data?.pages ?? 1}
              onChange={(_, value) => setTaskPage(value)}
            />
          )}
        </Stack>
      ) : (
        <Stack spacing={2}>
          {canManage && (
            <Button
              sx={{ alignSelf: "flex-start" }}
              variant="contained"
              startIcon={<AddOutlined />}
              onClick={openCreateDialog}
            >
              Créer
            </Button>
          )}
          {records.data?.map((item) => {
            const progress = currentTrajectory.get(item.id);
            return (
              <Card key={item.id}>
                <CardContent>
                  <Stack spacing={1}>
                    <Stack
                      direction="row"
                      justifyContent="space-between"
                      gap={1}
                    >
                      <Typography fontWeight={750}>{item.title}</Typography>
                      <Chip
                        label={item.status}
                        color={progress?.atRisk ? "warning" : "default"}
                      />
                    </Stack>
                    {item.owner && (
                      <Typography variant="body2">
                        Responsable : {item.owner.name}
                      </Typography>
                    )}
                    {progress && (
                      <>
                        <Typography variant="body2">
                          Réel {progress.current}% · attendu {progress.expected}
                          % · cible {progress.target}% · écart {progress.gap}
                          points
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          {progress.remainingDays} jours restants · source{" "}
                          {progress.source === "ASSESSMENTS"
                            ? "dernières évaluations"
                            : "déclarative"}
                        </Typography>
                        {Object.entries(progress.frameworkScores).map(
                          ([framework, score]) => (
                            <Chip
                              key={framework}
                              size="small"
                              label={`${framework} · ${Math.round(score)}%`}
                              sx={{ alignSelf: "flex-start" }}
                            />
                          ),
                        )}
                        <LinearProgress
                          aria-label={`Progression ${item.title} : ${progress.current}%`}
                          variant="determinate"
                          value={progress.current}
                          color={progress.atRisk ? "warning" : "primary"}
                        />
                      </>
                    )}
                    <RecordDetails details={item.details} />
                    {canManage && (
                      <Button
                        startIcon={<EditOutlined />}
                        sx={{ alignSelf: "flex-start" }}
                        onClick={() => openEditDialog(item)}
                      >
                        Modifier
                      </Button>
                    )}
                  </Stack>
                </CardContent>
              </Card>
            );
          })}
          {records.data?.length === 0 && (
            <Alert severity="info">Aucun élément dans cette section.</Alert>
          )}
        </Stack>
      )}
      <Dialog
        open={canManage && open}
        onClose={closeDialog}
        fullWidth
        maxWidth="sm"
      >
        <form onSubmit={submit}>
          <DialogTitle>
            {editingId ? "Modifier l’élément" : "Nouvel élément"} —{" "}
            {sections.find((item) => item.type === section)?.label}
          </DialogTitle>
          <DialogContent>
            <Stack spacing={2} mt={1}>
              <TextField
                required
                label="Titre"
                value={form.title}
                onChange={(e) => setForm({ ...form, title: e.target.value })}
              />
              <TextField
                type="datetime-local"
                label="Échéance"
                InputLabelProps={{ shrink: true }}
                value={form.dueAt}
                onChange={(e) => setForm({ ...form, dueAt: e.target.value })}
              />
              <TextField
                select
                label="Responsable"
                value={form.ownerId}
                onChange={(e) => setForm({ ...form, ownerId: e.target.value })}
              >
                <MenuItem value="">Non attribué</MenuItem>
                {(users.data ?? []).map((item) => (
                  <MenuItem key={item.id} value={item.id}>
                    {item.firstName} {item.lastName} — {item.email}
                  </MenuItem>
                ))}
              </TextField>
              {section === "TASK" && (
                <>
                  <TextField
                    label="Description"
                    value={String(parsedDetails.description ?? "")}
                    onChange={(e) => setDetail("description", e.target.value)}
                    multiline
                    minRows={2}
                  />
                  <TextField
                    select
                    label="Priorité"
                    value={String(parsedDetails.priority ?? "MEDIUM")}
                    onChange={(e) => setDetail("priority", e.target.value)}
                  >
                    <MenuItem value="LOW">Faible</MenuItem>
                    <MenuItem value="MEDIUM">Moyenne</MenuItem>
                    <MenuItem value="HIGH">Haute</MenuItem>
                    <MenuItem value="CRITICAL">Critique</MenuItem>
                  </TextField>
                </>
              )}
              {section === "RESPONSIBILITY_RULE" && (
                <>
                  <TextField
                    label="Domaine"
                    value={String(parsedDetails.domain ?? "")}
                    onChange={(e) => setDetail("domain", e.target.value)}
                  />
                  <TextField
                    select
                    label="Rôle responsable par défaut"
                    value={String(
                      parsedDetails.defaultRole ?? "ROLE_RISK_MANAGER",
                    )}
                    onChange={(e) => setDetail("defaultRole", e.target.value)}
                  >
                    {[
                      "ROLE_RISK_MANAGER",
                      "ROLE_ACTION_OWNER",
                      "ROLE_AUDITOR",
                      "ROLE_ADMIN",
                    ].map((role) => (
                      <MenuItem key={role} value={role}>
                        {role}
                      </MenuItem>
                    ))}
                  </TextField>
                  <FormControlLabel
                    control={
                      <Checkbox
                        checked={Boolean(parsedDetails.requiresApproval)}
                        onChange={(event) =>
                          setDetail("requiresApproval", event.target.checked)
                        }
                      />
                    }
                    label="Validation obligatoire avant application"
                  />
                </>
              )}
              {section === "COMPLIANCE_PROGRAM" && (
                <>
                  <TextField
                    type="date"
                    label="Début"
                    InputLabelProps={{ shrink: true }}
                    value={String(parsedDetails.startDate ?? "")}
                    onChange={(e) => setDetail("startDate", e.target.value)}
                  />
                  <TextField
                    type="number"
                    label="Score actuel (%)"
                    value={Number(parsedDetails.currentScore ?? 0)}
                    onChange={(e) =>
                      setDetail("currentScore", Number(e.target.value))
                    }
                    inputProps={{ min: 0, max: 100 }}
                  />
                  <TextField
                    type="number"
                    label="Cible (%)"
                    value={Number(parsedDetails.targetScore ?? 100)}
                    onChange={(e) =>
                      setDetail("targetScore", Number(e.target.value))
                    }
                    inputProps={{ min: 1, max: 100 }}
                  />
                  <TextField
                    label="Référentiels (séparés par des virgules)"
                    value={
                      Array.isArray(parsedDetails.frameworks)
                        ? parsedDetails.frameworks.join(", ")
                        : ""
                    }
                    onChange={(e) =>
                      setDetail(
                        "frameworks",
                        e.target.value
                          .split(",")
                          .map((x) => x.trim())
                          .filter(Boolean),
                      )
                    }
                  />
                </>
              )}
              {section === "QUESTIONNAIRE_TEMPLATE" && (
                <>
                  <TextField
                    select
                    label="Usage du questionnaire"
                    value={String(
                      parsedDetails.useCase ?? "EVIDENCE_COLLECTION",
                    )}
                    onChange={(event) =>
                      setDetail("useCase", event.target.value)
                    }
                  >
                    <MenuItem value="EVIDENCE_COLLECTION">
                      Collecte de preuves
                    </MenuItem>
                    <MenuItem value="COMPLIANCE_SELF_ASSESSMENT">
                      Autoévaluation de conformité
                    </MenuItem>
                    <MenuItem value="CONTROL_REVIEW">
                      Revue d’un contrôle
                    </MenuItem>
                    <MenuItem value="THIRD_PARTY">
                      Évaluation d’un tiers
                    </MenuItem>
                    <MenuItem value="PROJECT_PREQUALIFICATION">
                      Qualification d’un projet
                    </MenuItem>
                    <MenuItem value="EXCEPTION_REQUEST">
                      Demande d’exception
                    </MenuItem>
                  </TextField>
                  <TextField
                    type="number"
                    label="Version"
                    inputProps={{ min: 1 }}
                    value={Number(parsedDetails.version ?? 1)}
                    onChange={(event) =>
                      setDetail("version", Number(event.target.value))
                    }
                  />
                  {(
                    (parsedDetails.questions as Array<{
                      id: string;
                      label: string;
                      type: string;
                    }>) ?? []
                  ).map((question, index, questions) => (
                    <Card key={question.id || index} variant="outlined">
                      <CardContent>
                        <Stack spacing={1}>
                          <Typography fontWeight={700}>
                            Question {index + 1}
                          </Typography>
                          <TextField
                            required
                            label="Question posée"
                            value={question.label}
                            onChange={(event) => {
                              const next = [...questions];
                              next[index] = {
                                ...question,
                                id: question.id || `Q${index + 1}`,
                                label: event.target.value,
                              };
                              setDetail("questions", next);
                            }}
                          />
                          <TextField
                            select
                            label="Type de réponse"
                            value={question.type}
                            onChange={(event) => {
                              const next = [...questions];
                              next[index] = {
                                ...question,
                                type: event.target.value,
                              };
                              setDetail("questions", next);
                            }}
                          >
                            <MenuItem value="TEXT">Texte</MenuItem>
                            <MenuItem value="BOOLEAN">Oui / Non</MenuItem>
                            <MenuItem value="DATE">Date</MenuItem>
                            <MenuItem value="EVIDENCE">
                              Preuve à joindre
                            </MenuItem>
                          </TextField>
                          {questions.length > 1 && (
                            <Button
                              color="error"
                              onClick={() =>
                                setDetail(
                                  "questions",
                                  questions.filter((_, item) => item !== index),
                                )
                              }
                            >
                              Retirer cette question
                            </Button>
                          )}
                        </Stack>
                      </CardContent>
                    </Card>
                  ))}
                  <Button
                    variant="outlined"
                    onClick={() => {
                      const questions = Array.isArray(parsedDetails.questions)
                        ? parsedDetails.questions
                        : [];
                      setDetail("questions", [
                        ...questions,
                        {
                          id: `Q${questions.length + 1}`,
                          label: "",
                          type: "TEXT",
                        },
                      ]);
                    }}
                  >
                    Ajouter une question
                  </Button>
                  <TextField
                    label="Rappels avant l’échéance (jours)"
                    helperText="Exemple : 7, 2"
                    value={
                      Array.isArray(parsedDetails.reminderDays)
                        ? parsedDetails.reminderDays.join(", ")
                        : ""
                    }
                    onChange={(event) =>
                      setDetail(
                        "reminderDays",
                        event.target.value
                          .split(",")
                          .map((value) => Number(value.trim()))
                          .filter(
                            (value) => Number.isInteger(value) && value >= 0,
                          ),
                      )
                    }
                  />
                </>
              )}
              {section === "QUESTIONNAIRE_CAMPAIGN" && (
                <>
                  <TextField
                    required
                    select
                    label="Questionnaire"
                    value={String(parsedDetails.templateId ?? "")}
                    onChange={(event) =>
                      setDetail("templateId", Number(event.target.value))
                    }
                  >
                    {(questionnaireTemplates.data ?? []).map((template) => (
                      <MenuItem key={template.id} value={template.id}>
                        {template.title}
                      </MenuItem>
                    ))}
                  </TextField>
                  {questionnaireTemplates.isSuccess &&
                    questionnaireTemplates.data.length === 0 && (
                      <Alert severity="warning">
                        Créez d’abord un questionnaire actif.
                      </Alert>
                    )}
                  <Autocomplete
                    multiple
                    options={users.data ?? []}
                    getOptionLabel={(option) =>
                      `${option.firstName} ${option.lastName} — ${option.email}`
                    }
                    value={(users.data ?? []).filter((candidate) =>
                      (
                        (parsedDetails.recipientIds as number[] | undefined) ??
                        []
                      ).includes(candidate.id),
                    )}
                    onChange={(_, recipients) =>
                      setDetail(
                        "recipientIds",
                        recipients.map((recipient) => recipient.id),
                      )
                    }
                    renderInput={(params) => (
                      <TextField {...params} label="Destinataires" required />
                    )}
                  />
                </>
              )}
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={closeDialog}>Annuler</Button>
            <Button
              type="submit"
              variant="contained"
              disabled={!recordReady || create.isPending || update.isPending}
            >
              {editingId ? "Enregistrer les modifications" : "Créer"}
            </Button>
          </DialogActions>
        </form>
      </Dialog>
      <Dialog
        open={delegation.taskId > 0}
        onClose={() => setDelegation({ taskId: 0, email: "", until: "" })}
        fullWidth
        maxWidth="xs"
      >
        <DialogTitle>Déléguer temporairement la tâche</DialogTitle>
        <DialogContent>
          <Stack spacing={2} mt={1}>
            <TextField
              required
              type="email"
              label="Email du délégataire"
              value={delegation.email}
              onChange={(e) =>
                setDelegation({ ...delegation, email: e.target.value })
              }
            />
            <TextField
              required
              type="datetime-local"
              label="Fin de délégation"
              InputLabelProps={{ shrink: true }}
              value={delegation.until}
              onChange={(e) =>
                setDelegation({ ...delegation, until: e.target.value })
              }
            />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button
            onClick={() => setDelegation({ taskId: 0, email: "", until: "" })}
          >
            Annuler
          </Button>
          <Button
            variant="contained"
            disabled={
              !delegation.email || !delegation.until || delegateTask.isPending
            }
            onClick={() => delegateTask.mutate()}
          >
            Confirmer
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
