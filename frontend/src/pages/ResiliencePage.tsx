import { useQuery } from "@tanstack/react-query";
import {
  Alert,
  Card,
  CardContent,
  Chip,
  Stack,
  Typography,
} from "@mui/material";
import { api } from "../api/client";
type Incident = {
  id: number;
  title: string;
  severity: string;
  status: string;
  owner: { name: string };
  timeline: unknown[];
  evidence: string[];
};
type Process = {
  id: number;
  name: string;
  criticality: string;
  scope: { name: string };
  mtpdHours: number;
  rtoHours: number;
  rpoHours: number;
  dependencies: string[];
  exercises: unknown[];
};
export function ResiliencePage() {
  const incidents = useQuery({
    queryKey: ["incidents"],
    queryFn: async () =>
      (await api.get<Incident[]>("/resilience/incidents")).data,
  });
  const processes = useQuery({
    queryKey: ["continuity-processes"],
    queryFn: async () =>
      (await api.get<Process[]>("/resilience/continuity-processes")).data,
  });
  if (incidents.isError || processes.isError)
    return (
      <Alert severity="error">
        Impossible de charger le module résilience.
      </Alert>
    );
  return (
    <Stack spacing={3}>
      <Stack>
        <Typography variant="h4" fontWeight={750}>
          Incidents et continuité
        </Typography>
        <Typography color="text.secondary">
          Chronologie de crise, BIA, PCA/PRA et exercices
        </Typography>
      </Stack>
      <Stack
        sx={{
          display: "grid",
          gridTemplateColumns: { xs: "1fr", lg: "repeat(2, 1fr)" },
          gap: 2,
        }}
      >
        <Stack spacing={1.5}>
          <Typography variant="h6" fontWeight={750}>
            Incidents
          </Typography>
          {incidents.data?.length === 0 && (
            <Alert severity="info">Aucun incident déclaré.</Alert>
          )}
          {incidents.data?.map((item) => (
            <Card variant="outlined" key={item.id}>
              <CardContent>
                <Stack spacing={1}>
                  <Stack direction="row" justifyContent="space-between">
                    <Typography fontWeight={700}>{item.title}</Typography>
                    <Chip
                      size="small"
                      label={item.severity}
                      color={item.severity === "CRITICAL" ? "error" : "warning"}
                    />
                  </Stack>
                  <Typography variant="body2">
                    {item.status} · {item.owner.name}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {item.timeline.length} événement(s) · {item.evidence.length}{" "}
                    preuve(s)
                  </Typography>
                </Stack>
              </CardContent>
            </Card>
          ))}
        </Stack>
        <Stack spacing={1.5}>
          <Typography variant="h6" fontWeight={750}>
            BIA et continuité
          </Typography>
          {processes.data?.length === 0 && (
            <Alert severity="info">Aucun processus métier analysé.</Alert>
          )}
          {processes.data?.map((item) => (
            <Card variant="outlined" key={item.id}>
              <CardContent>
                <Stack spacing={1}>
                  <Stack direction="row" justifyContent="space-between">
                    <Typography fontWeight={700}>{item.name}</Typography>
                    <Chip size="small" label={item.criticality} />
                  </Stack>
                  <Typography variant="body2">
                    {item.scope.name} · MTPD {item.mtpdHours}h · RTO{" "}
                    {item.rtoHours}h · RPO {item.rpoHours}h
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {item.dependencies.join(", ")} · {item.exercises.length}{" "}
                    exercice(s)
                  </Typography>
                </Stack>
              </CardContent>
            </Card>
          ))}
        </Stack>
      </Stack>
    </Stack>
  );
}
