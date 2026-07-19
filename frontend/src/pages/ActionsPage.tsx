import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Box,
  Card,
  CardContent,
  Chip,
  CircularProgress,
  LinearProgress,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  ToggleButton,
  ToggleButtonGroup,
  Typography,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
  IconButton,
  InputLabel,
  MenuItem,
  Select,
  TextField,
} from "@mui/material";
import {
  Add,
  CalendarMonthOutlined,
  ChevronLeft,
  ChevronRight,
  ContentCopyOutlined,
  DeleteOutline,
  EditOutlined,
  PersonOutline,
  TodayOutlined,
  SyncOutlined,
  TableRowsOutlined,
  ViewKanbanOutlined,
} from "@mui/icons-material";
import { useState, type DragEvent, type FormEvent } from "react";
import { api } from "../api/client";
import type {
  ActionPlan,
  RiskScenario,
  SecurityControl,
  User,
} from "../api/types";
import { useAuth } from "../auth/useAuth";
import axios from "axios";

const statuses = [
  "OPEN",
  "PLANNED",
  "IN_PROGRESS",
  "BLOCKED",
  "OVERDUE",
  "COMPLETED",
] as const;
const statusLabels: Record<string, string> = {
  OPEN: "Ouvert",
  PLANNED: "Planifié",
  IN_PROGRESS: "En cours",
  BLOCKED: "Bloqué",
  OVERDUE: "En retard",
  COMPLETED: "Terminé",
  CANCELLED: "Annulé",
};
const priorityColors: Record<
  ActionPlan["priority"],
  "default" | "info" | "warning" | "error"
> = { LOW: "default", MEDIUM: "info", HIGH: "warning", CRITICAL: "error" };
const priorityLabels: Record<ActionPlan["priority"], string> = {
  LOW: "Faible",
  MEDIUM: "Modérée",
  HIGH: "Haute",
  CRITICAL: "Critique",
};
const kanbanColors: Record<(typeof statuses)[number], string> = {
  OPEN: "#64748b",
  PLANNED: "#2563eb",
  IN_PROGRESS: "#f59e0b",
  BLOCKED: "#7c3aed",
  OVERDUE: "#dc2626",
  COMPLETED: "#16a34a",
};
const weekDays = ["Lun", "Mar", "Mer", "Jeu", "Ven", "Sam", "Dim"];

function toLocalDate(value: string) {
  return new Date(`${value}T00:00:00`);
}

function dateKey(date: Date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function actionPayload(action: ActionPlan, status = action.status) {
  return {
    title: action.title,
    description: action.description ?? "",
    relatedRiskId: action.relatedRisk.id,
    relatedControlId: action.relatedControl?.id ?? null,
    ownerId: action.owner.id,
    priority: action.priority,
    status,
    startDate: action.startDate,
    dueDate: action.dueDate,
    completionDate: status === "COMPLETED" ? dateKey(new Date()) : null,
    progress: status === "COMPLETED" ? 100 : action.progress,
    estimatedCost:
      action.estimatedCost === null ? null : Number(action.estimatedCost),
    estimatedEffortDays:
      action.estimatedEffortDays === null
        ? null
        : Number(action.estimatedEffortDays),
    actualCost: action.actualCost === null ? null : Number(action.actualCost),
    expectedRiskReduction: action.expectedRiskReduction,
    evidence: action.evidence,
  };
}

type ActionForm = {
  title: string;
  description: string;
  relatedRiskId: number | "";
  relatedControlId: number | null;
  ownerId: number | "";
  priority: ActionPlan["priority"];
  status: ActionPlan["status"];
  startDate: string | null;
  dueDate: string;
  completionDate: string | null;
  progress: number;
  estimatedCost: number | null;
  estimatedEffortDays: number | null;
  actualCost: number | null;
  expectedRiskReduction: number | null;
  evidence: string[];
};
type CalendarSubscription = {
  enabled: boolean;
  createdAt: string | null;
  url?: string;
};
const emptyForm: ActionForm = {
  title: "",
  description: "",
  relatedRiskId: "",
  relatedControlId: null,
  ownerId: "",
  priority: "MEDIUM",
  status: "OPEN",
  startDate: null,
  dueDate: "",
  completionDate: null,
  progress: 0,
  estimatedCost: null,
  estimatedEffortDays: null,
  actualCost: null,
  expectedRiskReduction: null,
  evidence: [],
};
function apiMessage(error: unknown) {
  return axios.isAxiosError<{ message?: string }>(error)
    ? (error.response?.data?.message ?? "L’opération a échoué.")
    : "L’opération a échoué.";
}

function ActionCard({
  action,
  canManage,
  onEdit,
}: {
  action: ActionPlan;
  canManage: boolean;
  onEdit: (action: ActionPlan) => void;
}) {
  return (
    <Card
      variant="outlined"
      draggable={canManage}
      onDragStart={(event) => {
        event.dataTransfer.setData("text/action-id", String(action.id));
        event.dataTransfer.effectAllowed = "move";
      }}
      onClick={() => canManage && onEdit(action)}
      sx={{
        cursor: canManage ? "grab" : "default",
        transition: "transform .15s ease, box-shadow .15s ease",
        "&:hover": { transform: "translateY(-2px)", boxShadow: 3 },
      }}
    >
      <CardContent sx={{ "&:last-child": { pb: 2 } }}>
        <Stack spacing={1.2}>
          <Stack direction="row" justifyContent="space-between" gap={1}>
            <Typography fontWeight={700}>{action.title}</Typography>
            <Chip
              size="small"
              label={priorityLabels[action.priority]}
              color={priorityColors[action.priority]}
            />
          </Stack>
          <Typography variant="caption" color="text.secondary">
            {action.relatedRisk.title}
          </Typography>
          <Stack direction="row" alignItems="center" spacing={0.5}>
            <PersonOutline sx={{ fontSize: 15, color: "text.secondary" }} />
            <Typography variant="caption" color="text.secondary" noWrap>
              {action.owner.firstName} {action.owner.lastName}
            </Typography>
          </Stack>
          <LinearProgress variant="determinate" value={action.progress} />
          <Typography variant="caption">
            {action.progress}% · échéance{" "}
            {toLocalDate(action.dueDate).toLocaleDateString("fr-FR")}
          </Typography>
        </Stack>
      </CardContent>
    </Card>
  );
}

export function ActionsPage() {
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const [view, setView] = useState<"table" | "kanban" | "calendar">("table");
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<ActionPlan | null>(null);
  const [form, setForm] = useState<ActionForm>(emptyForm);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const [priorityFilter, setPriorityFilter] = useState("ALL");
  const [ownerFilter, setOwnerFilter] = useState("ALL");
  const [calendarMonth, setCalendarMonth] = useState(
    () => new Date(new Date().getFullYear(), new Date().getMonth(), 1),
  );
  const [subscriptionOpen, setSubscriptionOpen] = useState(false);
  const [subscriptionUrl, setSubscriptionUrl] = useState("");
  const [copied, setCopied] = useState(false);
  const canManage = user?.roles.some((role) =>
    ["ROLE_SUPER_ADMIN", "ROLE_ADMIN", "ROLE_RISK_MANAGER"].includes(role),
  );
  const query = useQuery({
    queryKey: ["actions"],
    queryFn: async () => (await api.get<ActionPlan[]>("/actions")).data,
  });
  const risks = useQuery({
    queryKey: ["risks"],
    queryFn: async () => (await api.get<RiskScenario[]>("/risks")).data,
  });
  const controls = useQuery({
    queryKey: ["security-controls"],
    queryFn: async () =>
      (await api.get<SecurityControl[]>("/security-controls")).data,
  });
  const users = useQuery({
    queryKey: ["users"],
    queryFn: async () => (await api.get<User[]>("/users")).data,
    enabled: Boolean(canManage),
  });
  const calendarSubscription = useQuery({
    queryKey: ["calendar-subscription"],
    queryFn: async () =>
      (await api.get<CalendarSubscription>("/me/calendar")).data,
  });
  const save = useMutation({
    mutationFn: () =>
      editing
        ? api.put(`/actions/${editing.id}`, form)
        : api.post("/actions", form),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["actions"] });
      await queryClient.invalidateQueries({ queryKey: ["dashboard"] });
      setDialogOpen(false);
    },
    onError: (caught) => setError(apiMessage(caught)),
  });
  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/actions/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["actions"] }),
    onError: (caught) => setError(apiMessage(caught)),
  });
  const moveAction = useMutation({
    mutationFn: ({
      action,
      status,
    }: {
      action: ActionPlan;
      status: ActionPlan["status"];
    }) => api.put(`/actions/${action.id}`, actionPayload(action, status)),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["actions"] });
      await queryClient.invalidateQueries({ queryKey: ["dashboard"] });
    },
    onError: (caught) => setError(apiMessage(caught)),
  });
  const createSubscription = useMutation({
    mutationFn: async () =>
      (await api.post<CalendarSubscription>("/me/calendar")).data,
    onSuccess: async (data) => {
      setSubscriptionUrl(data.url ?? "");
      setCopied(false);
      await queryClient.invalidateQueries({
        queryKey: ["calendar-subscription"],
      });
    },
    onError: (caught) => setError(apiMessage(caught)),
  });
  const revokeSubscription = useMutation({
    mutationFn: () => api.delete("/me/calendar"),
    onSuccess: async () => {
      setSubscriptionUrl("");
      setCopied(false);
      await queryClient.invalidateQueries({
        queryKey: ["calendar-subscription"],
      });
    },
    onError: (caught) => setError(apiMessage(caught)),
  });
  const update = <K extends keyof ActionForm>(key: K, value: ActionForm[K]) =>
    setForm((current) => ({ ...current, [key]: value }));
  function openCreate() {
    setEditing(null);
    setForm(emptyForm);
    setError("");
    setDialogOpen(true);
  }
  function openEdit(action: ActionPlan) {
    setEditing(action);
    setForm({
      title: action.title,
      description: action.description ?? "",
      relatedRiskId: action.relatedRisk.id,
      relatedControlId: action.relatedControl?.id ?? null,
      ownerId: action.owner.id,
      priority: action.priority,
      status: action.status,
      startDate: action.startDate,
      dueDate: action.dueDate,
      completionDate: action.completionDate,
      progress: action.progress,
      estimatedCost:
        action.estimatedCost === null ? null : Number(action.estimatedCost),
      estimatedEffortDays:
        action.estimatedEffortDays === null
          ? null
          : Number(action.estimatedEffortDays),
      actualCost: action.actualCost === null ? null : Number(action.actualCost),
      expectedRiskReduction: action.expectedRiskReduction,
      evidence: action.evidence,
    });
    setError("");
    setDialogOpen(true);
  }
  function submit(event: FormEvent) {
    event.preventDefault();
    setError("");
    save.mutate();
  }
  if (query.isLoading) return <CircularProgress />;
  if (query.isError)
    return (
      <Alert severity="error">Impossible de charger les plans d’action.</Alert>
    );
  const actions = query.data ?? [];
  const filteredActions = actions.filter((action) => {
    const term = search.trim().toLocaleLowerCase("fr");
    const matchesSearch =
      !term ||
      action.title.toLocaleLowerCase("fr").includes(term) ||
      action.relatedRisk.title.toLocaleLowerCase("fr").includes(term) ||
      `${action.owner.firstName} ${action.owner.lastName}`
        .toLocaleLowerCase("fr")
        .includes(term);
    return (
      matchesSearch &&
      (priorityFilter === "ALL" || action.priority === priorityFilter) &&
      (ownerFilter === "ALL" || String(action.owner.id) === ownerFilter)
    );
  });
  const availableOwners = Array.from(
    new Map(actions.map((action) => [action.owner.id, action.owner])).values(),
  ).sort((left, right) => left.lastName.localeCompare(right.lastName, "fr"));
  const calendarDays = (() => {
    const first = new Date(
      calendarMonth.getFullYear(),
      calendarMonth.getMonth(),
      1,
    );
    const offset = (first.getDay() + 6) % 7;
    const start = new Date(first);
    start.setDate(first.getDate() - offset);
    return Array.from({ length: 42 }, (_, index) => {
      const date = new Date(start);
      date.setDate(start.getDate() + index);
      return date;
    });
  })();
  function dropAction(event: DragEvent, status: ActionPlan["status"]) {
    event.preventDefault();
    if (!canManage || status === "OVERDUE") return;
    const id = Number(event.dataTransfer.getData("text/action-id"));
    const action = actions.find((item) => item.id === id);
    if (action && action.status !== status)
      moveAction.mutate({ action, status });
  }
  return (
    <Stack spacing={3}>
      <Stack
        direction={{ xs: "column", sm: "row" }}
        justifyContent="space-between"
        gap={2}
      >
        <Stack>
          <Typography variant="h4" fontWeight={750}>
            Plans d’action
          </Typography>
          <Typography color="text.secondary">
            Pilotage des traitements · {actions.length} action(s)
          </Typography>
        </Stack>
        <ToggleButtonGroup
          exclusive
          size="small"
          value={view}
          onChange={(_, value) => value && setView(value)}
          aria-label="Mode d’affichage"
        >
          <ToggleButton value="table">
            <TableRowsOutlined sx={{ mr: 1 }} />
            Tableau
          </ToggleButton>
          <ToggleButton value="kanban">
            <ViewKanbanOutlined sx={{ mr: 1 }} />
            Kanban
          </ToggleButton>
          <ToggleButton value="calendar">
            <CalendarMonthOutlined sx={{ mr: 1 }} />
            Calendrier
          </ToggleButton>
        </ToggleButtonGroup>
        {canManage && (
          <Button variant="contained" startIcon={<Add />} onClick={openCreate}>
            Créer une action
          </Button>
        )}
      </Stack>
      {error && !dialogOpen && <Alert severity="error">{error}</Alert>}
      <Card variant="outlined">
        <CardContent sx={{ py: 2, "&:last-child": { pb: 2 } }}>
          <Stack direction={{ xs: "column", md: "row" }} spacing={1.5}>
            <TextField
              size="small"
              fullWidth
              label="Rechercher une action, un risque ou un responsable"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
            />
            <FormControl size="small" sx={{ minWidth: 160 }}>
              <InputLabel>Priorité</InputLabel>
              <Select
                label="Priorité"
                value={priorityFilter}
                onChange={(event) => setPriorityFilter(event.target.value)}
              >
                <MenuItem value="ALL">Toutes</MenuItem>
                {Object.entries(priorityLabels).map(([value, label]) => (
                  <MenuItem key={value} value={value}>
                    {label}
                  </MenuItem>
                ))}
              </Select>
            </FormControl>
            <FormControl size="small" sx={{ minWidth: 190 }}>
              <InputLabel>Responsable</InputLabel>
              <Select
                label="Responsable"
                value={ownerFilter}
                onChange={(event) => setOwnerFilter(event.target.value)}
              >
                <MenuItem value="ALL">Tous</MenuItem>
                {availableOwners.map((owner) => (
                  <MenuItem key={owner.id} value={String(owner.id)}>
                    {owner.firstName} {owner.lastName}
                  </MenuItem>
                ))}
              </Select>
            </FormControl>
          </Stack>
        </CardContent>
      </Card>
      {view === "table" && (
        <Card variant="outlined">
          <CardContent>
            <Table aria-label="Plans d’action">
              <TableHead>
                <TableRow>
                  <TableCell>Action</TableCell>
                  <TableCell>Risque</TableCell>
                  <TableCell>Responsable</TableCell>
                  <TableCell>Priorité</TableCell>
                  <TableCell>Progression</TableCell>
                  <TableCell>Échéance</TableCell>
                  <TableCell>Statut</TableCell>
                  {canManage && <TableCell align="right">Actions</TableCell>}
                </TableRow>
              </TableHead>
              <TableBody>
                {filteredActions.map((action) => (
                  <TableRow key={action.id} hover>
                    <TableCell>
                      <Typography fontWeight={650}>{action.title}</Typography>
                    </TableCell>
                    <TableCell>{action.relatedRisk.title}</TableCell>
                    <TableCell>
                      {action.owner.firstName} {action.owner.lastName}
                    </TableCell>
                    <TableCell>
                      <Chip
                        size="small"
                        label={action.priority}
                        color={priorityColors[action.priority]}
                      />
                    </TableCell>
                    <TableCell sx={{ minWidth: 130 }}>
                      <LinearProgress
                        variant="determinate"
                        value={action.progress}
                      />
                      <Typography variant="caption">
                        {action.progress}%
                      </Typography>
                    </TableCell>
                    <TableCell>
                      {new Date(
                        `${action.dueDate}T00:00:00`,
                      ).toLocaleDateString("fr-FR")}
                    </TableCell>
                    <TableCell>
                      <Chip
                        size="small"
                        label={statusLabels[action.status]}
                        color={
                          action.status === "OVERDUE"
                            ? "error"
                            : action.status === "COMPLETED"
                              ? "success"
                              : "default"
                        }
                      />
                    </TableCell>
                    {canManage && (
                      <TableCell align="right">
                        <IconButton
                          aria-label="Modifier"
                          onClick={() => openEdit(action)}
                        >
                          <EditOutlined />
                        </IconButton>
                        <IconButton
                          aria-label="Annuler"
                          color="error"
                          disabled={action.status === "CANCELLED"}
                          onClick={() =>
                            window.confirm(`Annuler « ${action.title} » ?`) &&
                            remove.mutate(action.id)
                          }
                        >
                          <DeleteOutline />
                        </IconButton>
                      </TableCell>
                    )}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}
      {view === "kanban" && (
        <Box
          sx={{
            display: "flex",
            overflowX: "auto",
            gap: 2,
            pb: 1,
            scrollSnapType: { xs: "x mandatory", lg: "none" },
          }}
        >
          {statuses.map((status) => (
            <Stack
              key={status}
              spacing={1.5}
              onDragOver={(event) => {
                if (canManage && status !== "OVERDUE") event.preventDefault();
              }}
              onDrop={(event) => dropAction(event, status)}
              sx={{
                bgcolor: "#eef2f7",
                p: 1.5,
                borderRadius: 2,
                minHeight: 300,
                minWidth: { xs: 280, md: 300 },
                flex: "1 0 280px",
                borderTop: `4px solid ${kanbanColors[status]}`,
                scrollSnapAlign: "start",
              }}
            >
              <Stack
                direction="row"
                justifyContent="space-between"
                alignItems="center"
              >
                <Typography fontWeight={750}>{statusLabels[status]}</Typography>
                <Chip
                  size="small"
                  label={
                    filteredActions.filter((item) => item.status === status)
                      .length
                  }
                />
              </Stack>
              {filteredActions
                .filter((item) => item.status === status)
                .map((action) => (
                  <ActionCard
                    key={action.id}
                    action={action}
                    canManage={Boolean(canManage)}
                    onEdit={openEdit}
                  />
                ))}
              {!filteredActions.some((item) => item.status === status) && (
                <Typography
                  variant="body2"
                  color="text.secondary"
                  textAlign="center"
                  sx={{ py: 4 }}
                >
                  Aucune action
                </Typography>
              )}
            </Stack>
          ))}
        </Box>
      )}
      {view === "calendar" && (
        <Card variant="outlined">
          <CardContent>
            <Stack
              direction="row"
              justifyContent="space-between"
              alignItems="center"
              mb={2}
              gap={1}
            >
              <IconButton
                aria-label="Mois précédent"
                onClick={() =>
                  setCalendarMonth(
                    new Date(
                      calendarMonth.getFullYear(),
                      calendarMonth.getMonth() - 1,
                      1,
                    ),
                  )
                }
              >
                <ChevronLeft />
              </IconButton>
              <Typography
                variant="h6"
                fontWeight={750}
                textTransform="capitalize"
              >
                {calendarMonth.toLocaleDateString("fr-FR", {
                  month: "long",
                  year: "numeric",
                })}
              </Typography>
              <Stack direction="row">
                <Button
                  size="small"
                  startIcon={<SyncOutlined />}
                  onClick={() => setSubscriptionOpen(true)}
                >
                  Synchroniser
                </Button>
                <Button
                  size="small"
                  startIcon={<TodayOutlined />}
                  onClick={() =>
                    setCalendarMonth(
                      new Date(
                        new Date().getFullYear(),
                        new Date().getMonth(),
                        1,
                      ),
                    )
                  }
                  sx={{ display: { xs: "none", sm: "inline-flex" } }}
                >
                  Aujourd’hui
                </Button>
                <IconButton
                  aria-label="Mois suivant"
                  onClick={() =>
                    setCalendarMonth(
                      new Date(
                        calendarMonth.getFullYear(),
                        calendarMonth.getMonth() + 1,
                        1,
                      ),
                    )
                  }
                >
                  <ChevronRight />
                </IconButton>
              </Stack>
            </Stack>
            <Box sx={{ overflowX: "auto" }}>
              <Box
                sx={{
                  display: "grid",
                  gridTemplateColumns: "repeat(7, minmax(100px, 1fr))",
                  minWidth: 700,
                }}
              >
                {weekDays.map((day) => (
                  <Typography
                    key={day}
                    variant="caption"
                    fontWeight={750}
                    textAlign="center"
                    color="text.secondary"
                    sx={{ py: 1 }}
                  >
                    {day}
                  </Typography>
                ))}
                {calendarDays.map((day) => {
                  const key = dateKey(day);
                  const dayActions = filteredActions.filter(
                    (action) => action.dueDate === key,
                  );
                  const isToday = key === dateKey(new Date());
                  const inMonth = day.getMonth() === calendarMonth.getMonth();
                  return (
                    <Box
                      key={key}
                      sx={{
                        minHeight: 112,
                        border: "1px solid",
                        borderColor: "divider",
                        p: 0.75,
                        bgcolor: inMonth ? "background.paper" : "action.hover",
                      }}
                    >
                      <Typography
                        variant="caption"
                        fontWeight={isToday ? 800 : 500}
                        sx={{
                          display: "inline-flex",
                          alignItems: "center",
                          justifyContent: "center",
                          width: 26,
                          height: 26,
                          borderRadius: "50%",
                          bgcolor: isToday ? "primary.main" : "transparent",
                          color: isToday
                            ? "primary.contrastText"
                            : inMonth
                              ? "text.primary"
                              : "text.disabled",
                        }}
                      >
                        {day.getDate()}
                      </Typography>
                      <Stack spacing={0.5} mt={0.5}>
                        {dayActions.slice(0, 3).map((action) => (
                          <Box
                            key={action.id}
                            onClick={() => canManage && openEdit(action)}
                            title={`${action.title} — ${action.owner.firstName} ${action.owner.lastName}`}
                            sx={{
                              px: 0.75,
                              py: 0.4,
                              borderRadius: 1,
                              bgcolor:
                                action.status === "OVERDUE"
                                  ? "error.light"
                                  : action.status === "COMPLETED"
                                    ? "success.light"
                                    : "primary.light",
                              color:
                                action.status === "OVERDUE"
                                  ? "error.contrastText"
                                  : "primary.contrastText",
                              cursor: canManage ? "pointer" : "default",
                            }}
                          >
                            <Typography
                              variant="caption"
                              fontWeight={650}
                              noWrap
                              display="block"
                            >
                              {action.title}
                            </Typography>
                          </Box>
                        ))}
                        {dayActions.length > 3 && (
                          <Typography variant="caption" color="text.secondary">
                            + {dayActions.length - 3} autre(s)
                          </Typography>
                        )}
                      </Stack>
                    </Box>
                  );
                })}
              </Box>
            </Box>
          </CardContent>
        </Card>
      )}
      <Dialog
        open={subscriptionOpen}
        onClose={() => setSubscriptionOpen(false)}
        fullWidth
        maxWidth="sm"
      >
        <DialogTitle>Synchroniser mon calendrier</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            <Typography color="text.secondary">
              Abonnez Apple Calendar, Google Calendar ou Outlook aux actions qui
              vous sont affectées. Les changements seront récupérés
              automatiquement par votre application de calendrier.
            </Typography>
            <Alert severity="warning">
              Ce lien donne accès à vos échéances sans connexion. Gardez-le
              privé et révoquez-le immédiatement s’il est partagé par erreur.
            </Alert>
            {calendarSubscription.data?.enabled && !subscriptionUrl && (
              <Alert severity="info">
                Un abonnement est actif depuis le{" "}
                {calendarSubscription.data.createdAt
                  ? new Date(
                      calendarSubscription.data.createdAt,
                    ).toLocaleDateString("fr-FR")
                  : "une date inconnue"}
                . Pour des raisons de sécurité, son adresse n’est affichée qu’à
                sa création. Régénérez-la pour obtenir un nouveau lien.
              </Alert>
            )}
            {subscriptionUrl && (
              <>
                <TextField
                  label="Adresse d’abonnement iCalendar"
                  value={subscriptionUrl}
                  fullWidth
                  InputProps={{ readOnly: true }}
                />
                <Button
                  variant="contained"
                  startIcon={<ContentCopyOutlined />}
                  onClick={async () => {
                    await navigator.clipboard.writeText(subscriptionUrl);
                    setCopied(true);
                  }}
                >
                  {copied ? "Lien copié" : "Copier le lien"}
                </Button>
              </>
            )}
            <Box>
              <Typography fontWeight={700} gutterBottom>
                Ajouter l’abonnement
              </Typography>
              <Typography variant="body2" color="text.secondary">
                iPhone/iPad : Réglages → Apps → Calendrier → Comptes → Ajouter
                un calendrier avec abonnement. Android/Google : Google Agenda
                sur le web → Autres agendas → À partir de l’URL. Outlook :
                Ajouter un calendrier → S’abonner à partir du web.
              </Typography>
            </Box>
          </Stack>
        </DialogContent>
        <DialogActions sx={{ justifyContent: "space-between" }}>
          {calendarSubscription.data?.enabled ? (
            <Button
              color="error"
              onClick={() => revokeSubscription.mutate()}
              disabled={revokeSubscription.isPending}
            >
              Révoquer
            </Button>
          ) : (
            <Box />
          )}
          <Stack direction="row" spacing={1}>
            <Button onClick={() => setSubscriptionOpen(false)}>Fermer</Button>
            <Button
              variant="contained"
              onClick={() => createSubscription.mutate()}
              disabled={createSubscription.isPending}
            >
              {calendarSubscription.data?.enabled
                ? "Régénérer le lien"
                : "Créer le lien"}
            </Button>
          </Stack>
        </DialogActions>
      </Dialog>
      <Dialog
        open={dialogOpen}
        onClose={() => setDialogOpen(false)}
        fullWidth
        maxWidth="md"
      >
        <Stack component="form" onSubmit={submit}>
          <DialogTitle>
            {editing ? "Modifier l’action" : "Créer une action"}
          </DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ pt: 1 }}>
              {error && <Alert severity="error">{error}</Alert>}
              <TextField
                required
                label="Titre"
                value={form.title}
                onChange={(e) => update("title", e.target.value)}
              />
              <TextField
                multiline
                minRows={2}
                label="Description"
                value={form.description}
                onChange={(e) => update("description", e.target.value)}
              />
              <FormControl required>
                <InputLabel>Risque lié</InputLabel>
                <Select
                  label="Risque lié"
                  value={form.relatedRiskId}
                  onChange={(e) =>
                    update("relatedRiskId", Number(e.target.value))
                  }
                >
                  {risks.data
                    ?.filter((risk) => risk.status !== "ARCHIVED")
                    .map((risk) => (
                      <MenuItem key={risk.id} value={risk.id}>
                        {risk.title}
                      </MenuItem>
                    ))}
                </Select>
              </FormControl>
              <FormControl>
                <InputLabel>Mesure liée</InputLabel>
                <Select
                  label="Mesure liée"
                  value={form.relatedControlId ?? ""}
                  onChange={(e) =>
                    update(
                      "relatedControlId",
                      String(e.target.value) === ""
                        ? null
                        : Number(e.target.value),
                    )
                  }
                >
                  <MenuItem value="">Aucune</MenuItem>
                  {controls.data?.map((control) => (
                    <MenuItem key={control.id} value={control.id}>
                      {control.name}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
              <FormControl required>
                <InputLabel>Responsable</InputLabel>
                <Select
                  label="Responsable"
                  value={form.ownerId}
                  onChange={(e) => update("ownerId", Number(e.target.value))}
                >
                  {users.data?.map((owner) => (
                    <MenuItem key={owner.id} value={owner.id}>
                      {owner.firstName} {owner.lastName}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
              <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                <FormControl fullWidth>
                  <InputLabel>Priorité</InputLabel>
                  <Select
                    label="Priorité"
                    value={form.priority}
                    onChange={(e) =>
                      update(
                        "priority",
                        e.target.value as ActionPlan["priority"],
                      )
                    }
                  >
                    {["LOW", "MEDIUM", "HIGH", "CRITICAL"].map((value) => (
                      <MenuItem key={value} value={value}>
                        {value}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
                <FormControl fullWidth>
                  <InputLabel>Statut</InputLabel>
                  <Select
                    label="Statut"
                    value={form.status}
                    onChange={(e) =>
                      update("status", e.target.value as ActionPlan["status"])
                    }
                  >
                    {[
                      "OPEN",
                      "PLANNED",
                      "IN_PROGRESS",
                      "BLOCKED",
                      "COMPLETED",
                      "CANCELLED",
                    ].map((value) => (
                      <MenuItem key={value} value={value}>
                        {value}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Stack>
              <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                <TextField
                  fullWidth
                  type="date"
                  label="Début"
                  InputLabelProps={{ shrink: true }}
                  value={form.startDate ?? ""}
                  onChange={(e) => update("startDate", e.target.value || null)}
                />
                <TextField
                  required
                  fullWidth
                  type="date"
                  label="Échéance"
                  InputLabelProps={{ shrink: true }}
                  value={form.dueDate}
                  onChange={(e) => update("dueDate", e.target.value)}
                />
                <TextField
                  fullWidth
                  type="date"
                  label="Achèvement"
                  InputLabelProps={{ shrink: true }}
                  value={form.completionDate ?? ""}
                  onChange={(e) =>
                    update("completionDate", e.target.value || null)
                  }
                />
              </Stack>
              <TextField
                type="number"
                label="Progression (%)"
                inputProps={{ min: 0, max: 100 }}
                value={form.progress}
                onChange={(e) => update("progress", Number(e.target.value))}
              />
              <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                <TextField
                  fullWidth
                  type="number"
                  label="Coût estimé"
                  value={form.estimatedCost ?? ""}
                  onChange={(e) =>
                    update(
                      "estimatedCost",
                      e.target.value === "" ? null : Number(e.target.value),
                    )
                  }
                />
                <TextField
                  fullWidth
                  type="number"
                  label="Coût réel"
                  value={form.actualCost ?? ""}
                  onChange={(e) =>
                    update(
                      "actualCost",
                      e.target.value === "" ? null : Number(e.target.value),
                    )
                  }
                />
                <TextField
                  fullWidth
                  type="number"
                  label="Charge estimée (jours)"
                  inputProps={{ min: 0, step: 0.5 }}
                  value={form.estimatedEffortDays ?? ""}
                  onChange={(e) =>
                    update(
                      "estimatedEffortDays",
                      e.target.value === "" ? null : Number(e.target.value),
                    )
                  }
                />
                <TextField
                  fullWidth
                  type="number"
                  label="Réduction attendue"
                  inputProps={{ min: 0, max: 25 }}
                  value={form.expectedRiskReduction ?? ""}
                  onChange={(e) =>
                    update(
                      "expectedRiskReduction",
                      e.target.value === "" ? null : Number(e.target.value),
                    )
                  }
                />
              </Stack>
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setDialogOpen(false)}>Annuler</Button>
            <Button type="submit" variant="contained" disabled={save.isPending}>
              Enregistrer
            </Button>
          </DialogActions>
        </Stack>
      </Dialog>
    </Stack>
  );
}
