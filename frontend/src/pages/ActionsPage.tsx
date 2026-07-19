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
  DeleteOutline,
  EditOutlined,
  TableRowsOutlined,
  ViewKanbanOutlined,
} from "@mui/icons-material";
import { useState, type FormEvent } from "react";
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
  actualCost: number | null;
  expectedRiskReduction: number | null;
  evidence: string[];
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
  actualCost: null,
  expectedRiskReduction: null,
  evidence: [],
};
function apiMessage(error: unknown) {
  return axios.isAxiosError<{ message?: string }>(error)
    ? (error.response?.data?.message ?? "L’opération a échoué.")
    : "L’opération a échoué.";
}

function ActionCard({ action }: { action: ActionPlan }) {
  return (
    <Card variant="outlined">
      <CardContent>
        <Stack spacing={1.2}>
          <Stack direction="row" justifyContent="space-between" gap={1}>
            <Typography fontWeight={700}>{action.title}</Typography>
            <Chip
              size="small"
              label={action.priority}
              color={priorityColors[action.priority]}
            />
          </Stack>
          <Typography variant="caption" color="text.secondary">
            {action.relatedRisk.title}
          </Typography>
          <LinearProgress variant="determinate" value={action.progress} />
          <Typography variant="caption">
            {action.progress}% · échéance{" "}
            {new Date(`${action.dueDate}T00:00:00`).toLocaleDateString("fr-FR")}
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
                {actions.map((action) => (
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
            display: "grid",
            gridTemplateColumns: {
              xs: "1fr",
              md: "repeat(3, 1fr)",
              xl: "repeat(6, 1fr)",
            },
            gap: 2,
          }}
        >
          {statuses.map((status) => (
            <Stack
              key={status}
              spacing={1.5}
              sx={{
                bgcolor: "#eef2f7",
                p: 1.5,
                borderRadius: 2,
                minHeight: 180,
              }}
            >
              <Typography fontWeight={750}>
                {statusLabels[status]} (
                {actions.filter((item) => item.status === status).length})
              </Typography>
              {actions
                .filter((item) => item.status === status)
                .map((action) => (
                  <ActionCard key={action.id} action={action} />
                ))}
            </Stack>
          ))}
        </Box>
      )}
      {view === "calendar" && (
        <Card variant="outlined">
          <CardContent>
            <Typography variant="h6" fontWeight={700} mb={2}>
              Échéancier
            </Typography>
            <Stack spacing={2}>
              {actions.length === 0 ? (
                <Typography color="text.secondary">
                  Aucune échéance planifiée.
                </Typography>
              ) : (
                actions.map((action) => (
                  <Stack
                    key={action.id}
                    direction="row"
                    spacing={2}
                    alignItems="center"
                  >
                    <Box
                      sx={{
                        width: 68,
                        textAlign: "center",
                        bgcolor:
                          action.status === "OVERDUE"
                            ? "error.main"
                            : "primary.main",
                        color: "white",
                        borderRadius: 1,
                        p: 1,
                      }}
                    >
                      <Typography variant="caption">
                        {new Date(
                          `${action.dueDate}T00:00:00`,
                        ).toLocaleDateString("fr-FR", { month: "short" })}
                      </Typography>
                      <Typography variant="h6">
                        {new Date(`${action.dueDate}T00:00:00`).getDate()}
                      </Typography>
                    </Box>
                    <Stack>
                      <Typography fontWeight={700}>{action.title}</Typography>
                      <Typography variant="caption" color="text.secondary">
                        {action.owner.firstName} {action.owner.lastName} ·{" "}
                        {statusLabels[action.status]}
                      </Typography>
                    </Stack>
                  </Stack>
                ))
              )}
            </Stack>
          </CardContent>
        </Card>
      )}
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
