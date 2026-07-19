import { useQuery } from "@tanstack/react-query";
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
} from "@mui/material";
import {
  CalendarMonthOutlined,
  TableRowsOutlined,
  ViewKanbanOutlined,
} from "@mui/icons-material";
import { useState } from "react";
import { api } from "../api/client";
import type { ActionPlan } from "../api/types";

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
  const [view, setView] = useState<"table" | "kanban" | "calendar">("table");
  const query = useQuery({
    queryKey: ["actions"],
    queryFn: async () => (await api.get<ActionPlan[]>("/actions")).data,
  });
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
      </Stack>
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
    </Stack>
  );
}
