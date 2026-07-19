import { useQuery } from "@tanstack/react-query";
import { PrintOutlined } from "@mui/icons-material";
import {
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  LinearProgress,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from "@mui/material";
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
import { useAuth } from "../auth/useAuth";

const riskColors: Record<RiskLevel, string> = {
  LOW: "#43a047",
  MODERATE: "#fbc02d",
  HIGH: "#fb8c00",
  CRITICAL: "#e53935",
};
const riskLabels: Record<RiskLevel, string> = {
  LOW: "Faible",
  MODERATE: "Modéré",
  HIGH: "Élevé",
  CRITICAL: "Critique",
};
const actionColors = [
  "#1976d2",
  "#7e57c2",
  "#fb8c00",
  "#e53935",
  "#43a047",
  "#78909c",
];
const actionLabels: Record<string, string> = {
  OPEN: "Ouvert",
  PLANNED: "Planifié",
  IN_PROGRESS: "En cours",
  BLOCKED: "Bloqué",
  COMPLETED: "Terminé",
  CANCELLED: "Annulé",
  OVERDUE: "En retard",
};

function ChartCard({
  title,
  data,
  colors,
}: {
  title: string;
  data: Array<{ name: string; value: number }>;
  colors: string[];
}) {
  return (
    <Card variant="outlined">
      <CardContent>
        <Typography variant="h6" fontWeight={750}>
          {title}
        </Typography>
        <Box sx={{ height: 300 }}>
          <ResponsiveContainer>
            <PieChart>
              <Pie
                data={data}
                dataKey="value"
                nameKey="name"
                innerRadius={58}
                outerRadius={100}
                paddingAngle={2}
                label={({ value }) => (value > 0 ? String(value) : "")}
              >
                {data.map((item, index) => (
                  <Cell key={item.name} fill={colors[index % colors.length]} />
                ))}
              </Pie>
              <Tooltip />
              <Legend />
            </PieChart>
          </ResponsiveContainer>
        </Box>
      </CardContent>
    </Card>
  );
}

export function ExecutiveReportPage() {
  const { user } = useAuth();
  const query = useQuery({
    queryKey: ["dashboard"],
    queryFn: async () => (await api.get<Dashboard>("/dashboard")).data,
  });
  if (!query.data) return <Typography>Préparation du rapport…</Typography>;
  const data = query.data;
  const riskData = (
    Object.entries(data.riskLevels) as Array<[RiskLevel, number]>
  ).map(([level, value]) => ({ name: riskLabels[level], value }));
  const actionData = Object.entries(data.actionStatuses).map(
    ([status, value]) => ({ name: actionLabels[status] ?? status, value }),
  );
  const indicators = [
    ["Risques", data.summary.totalRisks],
    ["Critiques", data.summary.criticalRisks],
    ["Élevés", data.summary.highRisks],
    ["Actions en retard", data.summary.overdueActions],
    ["Échéances à 30 jours", data.summary.dueActions],
    ["Conformité", `${data.summary.globalCompliance}%`],
  ];
  return (
    <Stack className="executive-report" spacing={3}>
      <Stack
        direction={{ xs: "column", sm: "row" }}
        justifyContent="space-between"
        alignItems={{ xs: "stretch", sm: "flex-start" }}
        gap={2}
      >
        <Stack>
          <Typography variant="overline" color="primary" fontWeight={800}>
            RISKpilot · Rapport exécutif
          </Typography>
          <Typography
            variant="h3"
            fontWeight={800}
            sx={{ fontSize: { xs: "2rem", sm: "3rem" } }}
          >
            {user?.organization.name}
          </Typography>
          <Typography color="text.secondary">
            Situation au{" "}
            {new Date().toLocaleDateString("fr-FR", { dateStyle: "long" })}
          </Typography>
        </Stack>
        <Button
          className="no-print"
          variant="contained"
          startIcon={<PrintOutlined />}
          onClick={() => window.print()}
        >
          Imprimer / Enregistrer en PDF
        </Button>
      </Stack>
      <Box
        sx={{
          display: "grid",
          gridTemplateColumns: { xs: "repeat(2, 1fr)", md: "repeat(6, 1fr)" },
          gap: 1.5,
        }}
      >
        {indicators.map(([label, value]) => (
          <Card key={String(label)} variant="outlined">
            <CardContent>
              <Typography variant="caption" color="text.secondary">
                {label}
              </Typography>
              <Typography variant="h4" fontWeight={850}>
                {value}
              </Typography>
            </CardContent>
          </Card>
        ))}
      </Box>
      <Box
        sx={{
          display: "grid",
          gridTemplateColumns: { xs: "1fr", md: "1fr 1fr" },
          gap: 2,
        }}
      >
        <ChartCard
          title="Répartition des risques"
          data={riskData}
          colors={Object.values(riskColors)}
        />
        <ChartCard
          title="Répartition des actions"
          data={actionData}
          colors={actionColors}
        />
      </Box>
      <Card variant="outlined">
        <CardContent>
          <Typography variant="h6" fontWeight={750} mb={2}>
            Risques prioritaires
          </Typography>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>#</TableCell>
                <TableCell>Scénario</TableCell>
                <TableCell>Statut</TableCell>
                <TableCell align="right">Score actuel</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {data.topRisks.map((risk, index) => (
                <TableRow key={risk.id}>
                  <TableCell>{index + 1}</TableCell>
                  <TableCell>
                    <Typography fontWeight={650}>{risk.title}</Typography>
                  </TableCell>
                  <TableCell>
                    <Chip size="small" label={risk.status} />
                  </TableCell>
                  <TableCell align="right">
                    <Chip
                      size="small"
                      color={
                        risk.score >= 17
                          ? "error"
                          : risk.score >= 10
                            ? "warning"
                            : "success"
                      }
                      label={risk.score}
                    />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
      <Box
        sx={{
          display: "grid",
          gridTemplateColumns: { xs: "1fr", md: "1.2fr 1fr" },
          gap: 2,
        }}
      >
        <Card variant="outlined">
          <CardContent>
            <Typography variant="h6" fontWeight={750} mb={2}>
              Actions à échéance
            </Typography>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>Action</TableCell>
                  <TableCell>Priorité</TableCell>
                  <TableCell align="right">Échéance</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {data.dueActions.map((action) => (
                  <TableRow key={action.id}>
                    <TableCell>{action.title}</TableCell>
                    <TableCell>
                      <Chip
                        size="small"
                        label={action.priority}
                        color={
                          action.status === "OVERDUE" ? "error" : "warning"
                        }
                      />
                    </TableCell>
                    <TableCell align="right">
                      {new Date(
                        `${action.dueDate}T00:00:00`,
                      ).toLocaleDateString("fr-FR")}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
        <Card variant="outlined">
          <CardContent>
            <Typography variant="h6" fontWeight={750} mb={2}>
              Conformité par référentiel
            </Typography>
            <Stack spacing={2}>
              {Object.entries(data.complianceByFramework).map(
                ([framework, score]) => (
                  <Stack key={framework}>
                    <Stack direction="row" justifyContent="space-between">
                      <Typography>{framework}</Typography>
                      <Typography fontWeight={750}>{score}%</Typography>
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
              )}
            </Stack>
          </CardContent>
        </Card>
      </Box>
      <Typography variant="caption" color="text.secondary">
        Document généré par RiskPilot. Les données sont limitées à
        l’organisation authentifiée.
      </Typography>
    </Stack>
  );
}
