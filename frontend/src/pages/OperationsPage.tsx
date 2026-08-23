import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AddOutlined, EditOutlined } from "@mui/icons-material";
import {
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
  { type: "TASK", label: "Tâches opérationnelles" },
  { type: "RESPONSIBILITY_RULE", label: "Responsabilités" },
  { type: "COMPLIANCE_PROGRAM", label: "Trajectoires" },
  { type: "QUESTIONNAIRE_TEMPLATE", label: "Questionnaires" },
  { type: "QUESTIONNAIRE_CAMPAIGN", label: "Campagnes" },
  { type: "REFERENCE_PACK", label: "Packs" },
];

export function OperationsPage() {
  const { user } = useAuth();
  const canManage = hasAnyRole(user?.roles, [
    "ROLE_SUPER_ADMIN",
    "ROLE_ADMIN",
    "ROLE_RISK_MANAGER",
  ]);
  const isAdmin = hasAnyRole(user?.roles, ["ROLE_SUPER_ADMIN", "ROLE_ADMIN"]);
  const client = useQueryClient();
  const navigate = useNavigate();
  const [section, setSection] = useState<RecordType | "MY_TASKS">("MY_TASKS");
  const [open, setOpen] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState({
    title: "",
    dueAt: "",
    ownerId: "",
    details: "{}",
  });
  const [formError, setFormError] = useState<string | null>(null);
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
    setForm({ title: "", dueAt: "", ownerId: "", details: "{}" });
    setFormError(null);
  };
  const openCreateDialog = () => {
    setEditingId(null);
    setForm({ title: "", dueAt: "", ownerId: "", details: "{}" });
    setFormError(null);
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
    setFormError(null);
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
    try {
      JSON.parse(form.details);
      setFormError(null);
      if (editingId) update.mutate();
      else create.mutate();
    } catch {
      setFormError("La configuration JSON n’est pas valide.");
    }
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
      {(tasks.isError ||
        records.isError ||
        trajectory.isError ||
        create.isError ||
        update.isError) && (
        <Alert severity="error">L’opération n’a pas pu être terminée.</Alert>
      )}
      {formError && <Alert severity="error">{formError}</Alert>}
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
              {!editingId && (
                <TextField
                  select
                  label="Modèle de données"
                  value={form.details}
                  onChange={(e) =>
                    setForm({ ...form, details: e.target.value })
                  }
                >
                  <MenuItem value="{}">Vide</MenuItem>
                  {section === "COMPLIANCE_PROGRAM" && (
                    <MenuItem
                      value={
                        '{"startDate":"2026-08-01","currentScore":0,"targetScore":100,"frameworks":[]}'
                      }
                    >
                      Programme de conformité
                    </MenuItem>
                  )}
                  {section === "RESPONSIBILITY_RULE" && (
                    <MenuItem
                      value={
                        '{"domain":"governance","scopeType":"ORGANIZATION","defaultRole":"ROLE_RISK_MANAGER","requiresApproval":true}'
                      }
                    >
                      Règle de responsabilité
                    </MenuItem>
                  )}
                  {section === "QUESTIONNAIRE_TEMPLATE" && (
                    <MenuItem
                      value={
                        '{"useCase":"EVIDENCE_COLLECTION","version":1,"questions":[],"reminderDays":[7,2]}'
                      }
                    >
                      Collecte de preuves
                    </MenuItem>
                  )}
                  {section === "QUESTIONNAIRE_CAMPAIGN" && (
                    <MenuItem
                      value={
                        '{"templateId":null,"recipientIds":[],"responseStatus":"DRAFT"}'
                      }
                    >
                      Campagne interne
                    </MenuItem>
                  )}
                  {section === "REFERENCE_PACK" && (
                    <MenuItem
                      value={
                        '{"code":"STARTER","version":"1.0","license":"metadata-only","frameworks":[],"mappings":[]}'
                      }
                    >
                      Pack gouverné vide
                    </MenuItem>
                  )}
                </TextField>
              )}
              {isAdmin && (
                <TextField
                  multiline
                  minRows={7}
                  label="Configuration avancée JSON"
                  helperText="Réservée aux administrateurs. Vérifiez la structure avant l’enregistrement."
                  value={form.details}
                  onChange={(e) =>
                    setForm({ ...form, details: e.target.value })
                  }
                />
              )}
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={closeDialog}>Annuler</Button>
            <Button
              type="submit"
              variant="contained"
              disabled={create.isPending || update.isPending}
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
