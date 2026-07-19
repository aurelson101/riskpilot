import { useQuery } from "@tanstack/react-query";
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  CircularProgress,
  LinearProgress,
  Stack,
  Typography,
} from "@mui/material";
import { DownloadOutlined } from "@mui/icons-material";
import {
  Cell,
  Legend,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
} from "recharts";
import { api } from "../api/client";
import type { Dashboard, RiskLevel } from "../api/types";

const levelColors: Record<RiskLevel, string> = {
  LOW: "#43a047",
  MODERATE: "#fbc02d",
  HIGH: "#fb8c00",
  CRITICAL: "#e53935",
};
const levelLabels: Record<RiskLevel, string> = {
  LOW: "Faible",
  MODERATE: "Modéré",
  HIGH: "Élevé",
  CRITICAL: "Critique",
};

function download(path: string) {
  const token = sessionStorage.getItem("riskpilot.accessToken");
  fetch(`/api${path}`, {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  }).then(async (response) => {
    if (!response.ok) throw new Error("Export impossible");
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download =
      response.headers
        .get("Content-Disposition")
        ?.match(/filename="(.+)"/)?.[1] ?? "export.csv";
    link.click();
    URL.revokeObjectURL(url);
  });
}

export function DashboardPage() {
  const query = useQuery({
    queryKey: ["dashboard"],
    queryFn: async () => (await api.get<Dashboard>("/dashboard")).data,
  });
  if (query.isLoading) return <CircularProgress />;
  if (query.isError || !query.data)
    return (
      <Alert severity="error">Impossible de charger le tableau de bord.</Alert>
    );
  const data = query.data;
  const cards = [
    ["Risques totaux", data.summary.totalRisks, "primary.main"],
    ["Risques critiques", data.summary.criticalRisks, "error.main"],
    ["Risques élevés", data.summary.highRisks, "warning.main"],
    ["Actions en retard", data.summary.overdueActions, "error.dark"],
    ["Échéances à 30 jours", data.summary.dueActions, "secondary.main"],
    ["Conformité globale", `${data.summary.globalCompliance}%`, "success.main"],
  ];
  const pieData = (
    Object.entries(data.riskLevels) as Array<[RiskLevel, number]>
  ).map(([name, value]) => ({ name: levelLabels[name], value, level: name }));
  return (
    <Stack spacing={3}>
      <Stack
        direction={{ xs: "column", sm: "row" }}
        justifyContent="space-between"
        gap={2}
      >
        <Stack>
          <Typography variant="h4" fontWeight={750}>
            Tableau de bord
          </Typography>
          <Typography color="text.secondary">
            Vue consolidée des risques, actions et conformité
          </Typography>
        </Stack>
        <Stack direction="row" spacing={1}>
          <Button
            variant="outlined"
            startIcon={<DownloadOutlined />}
            onClick={() => download("/exports/risks.csv")}
          >
            Risques CSV
          </Button>
          <Button
            variant="outlined"
            startIcon={<DownloadOutlined />}
            onClick={() => download("/exports/actions.csv")}
          >
            Actions CSV
          </Button>
        </Stack>
      </Stack>
      <Box
        sx={{
          display: "grid",
          gridTemplateColumns: {
            xs: "1fr",
            sm: "repeat(2, 1fr)",
            lg: "repeat(6, 1fr)",
          },
          gap: 2,
        }}
      >
        {cards.map(([label, value, color]) => (
          <Card key={String(label)} variant="outlined">
            <CardContent>
              <Box
                sx={{
                  width: 34,
                  height: 5,
                  bgcolor: color,
                  borderRadius: 2,
                  mb: 1.5,
                }}
              />
              <Typography variant="caption" color="text.secondary">
                {label}
              </Typography>
              <Typography variant="h4" fontWeight={800}>
                {value}
              </Typography>
            </CardContent>
          </Card>
        ))}
      </Box>
      <Box
        sx={{
          display: "grid",
          gridTemplateColumns: { xs: "1fr", lg: "1fr 1.4fr" },
          gap: 2,
        }}
      >
        <Card variant="outlined">
          <CardContent>
            <Typography variant="h6" fontWeight={700}>
              Répartition des risques
            </Typography>
            <Box sx={{ height: 300 }}>
              <ResponsiveContainer>
                <PieChart>
                  <Pie
                    data={pieData}
                    dataKey="value"
                    nameKey="name"
                    innerRadius={65}
                    outerRadius={100}
                    paddingAngle={2}
                  >
                    {pieData.map((item) => (
                      <Cell key={item.level} fill={levelColors[item.level]} />
                    ))}
                  </Pie>
                  <Tooltip />
                  <Legend />
                </PieChart>
              </ResponsiveContainer>
            </Box>
          </CardContent>
        </Card>
        <Card variant="outlined">
          <CardContent>
            <Typography variant="h6" fontWeight={700} mb={2}>
              Top 10 des risques
            </Typography>
            <Stack spacing={1.3}>
              {data.topRisks.length === 0 ? (
                <Typography color="text.secondary">
                  Aucun risque évalué.
                </Typography>
              ) : (
                data.topRisks.map((risk) => (
                  <Stack
                    key={risk.id}
                    direction="row"
                    justifyContent="space-between"
                    alignItems="center"
                  >
                    <Typography>{risk.title}</Typography>
                    <Chip
                      size="small"
                      label={risk.score}
                      color={
                        risk.score >= 17
                          ? "error"
                          : risk.score >= 10
                            ? "warning"
                            : "default"
                      }
                    />
                  </Stack>
                ))
              )}
            </Stack>
          </CardContent>
        </Card>
      </Box>
      <Box
        sx={{
          display: "grid",
          gridTemplateColumns: { xs: "1fr", lg: "1fr 1fr" },
          gap: 2,
        }}
      >
        <Card variant="outlined">
          <CardContent>
            <Typography variant="h6" fontWeight={700} mb={2}>
              Actions à échéance
            </Typography>
            <Stack spacing={1.5}>
              {data.dueActions.length === 0 ? (
                <Typography color="text.secondary">
                  Aucune échéance dans les 30 jours.
                </Typography>
              ) : (
                data.dueActions.map((action) => (
                  <Stack
                    key={action.id}
                    direction="row"
                    justifyContent="space-between"
                  >
                    <Stack>
                      <Typography fontWeight={650}>{action.title}</Typography>
                      <Typography variant="caption">
                        {new Date(
                          `${action.dueDate}T00:00:00`,
                        ).toLocaleDateString("fr-FR")}
                      </Typography>
                    </Stack>
                    <Chip
                      size="small"
                      label={action.priority}
                      color={action.status === "OVERDUE" ? "error" : "warning"}
                    />
                  </Stack>
                ))
              )}
            </Stack>
          </CardContent>
        </Card>
        <Card variant="outlined">
          <CardContent>
            <Typography variant="h6" fontWeight={700} mb={2}>
              Conformité par référentiel
            </Typography>
            <Stack spacing={2}>
              {Object.keys(data.complianceByFramework).length === 0 ? (
                <Typography color="text.secondary">
                  Aucune évaluation disponible.
                </Typography>
              ) : (
                Object.entries(data.complianceByFramework).map(
                  ([framework, score]) => (
                    <Stack key={framework}>
                      <Stack direction="row" justifyContent="space-between">
                        <Typography>{framework}</Typography>
                        <Typography fontWeight={700}>{score}%</Typography>
                      </Stack>
                      <LinearProgress
                        variant="determinate"
                        value={score}
                        color={
                          score >= 75
                            ? "success"
                            : score >= 50
                              ? "warning"
                              : "error"
                        }
                      />
                    </Stack>
                  ),
                )
              )}
            </Stack>
          </CardContent>
        </Card>
      </Box>
    </Stack>
  );
}
