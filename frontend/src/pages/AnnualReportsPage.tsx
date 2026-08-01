import { DownloadOutlined, HistoryOutlined } from "@mui/icons-material";
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  CircularProgress,
  FormControl,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useMemo, useState } from "react";
import { api } from "../api/client";
import { hasAnyRole } from "../auth/roles";
import { useAuth } from "../auth/useAuth";

type SavedReport = {
  id: number;
  year: number;
  version: number;
  title: string;
  generatedAt: string;
  activities: number;
};
type AnnualReport = {
  year: number;
  period: { from: string; until: string };
  generatedAt: string;
  totals: { activities: number; contributors: number; domains: number };
  byMonth: Array<{ month: number; count: number }>;
  byAction: Record<string, number>;
  byDomain: Record<string, number>;
  contributors: Record<string, number>;
  activities: Array<{
    id: number;
    occurredAt: string;
    domain: string;
    action: string;
    entityType: string;
    entityId: string | null;
    actor: string;
    sealed: boolean;
  }>;
  methodology: string;
};

const domainLabels: Record<string, string> = {
  RISKS: "Risques",
  ACTIONS: "Actions et projets",
  COMPLIANCE: "Conformité",
  EVIDENCE: "Preuves et audits",
  THIRD_PARTIES: "Tiers",
  RESILIENCE: "Résilience",
  ADMINISTRATION: "Administration",
  GOVERNANCE: "Gouvernance",
};
const monthNames = [
  "Janvier",
  "Février",
  "Mars",
  "Avril",
  "Mai",
  "Juin",
  "Juillet",
  "Août",
  "Septembre",
  "Octobre",
  "Novembre",
  "Décembre",
];

export function AnnualReportsPage() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [year, setYear] = useState(new Date().getFullYear());
  const years = useQuery({
    queryKey: ["annual-report-years"],
    queryFn: async () =>
      (
        await api.get<{
          years: number[];
          savedReports: Record<string, SavedReport[]>;
        }>("/annual-reports/years")
      ).data,
  });
  const report = useQuery({
    queryKey: ["annual-report", year],
    queryFn: async () =>
      (await api.get<AnnualReport>(`/annual-reports/${year}`)).data,
  });
  const generate = useMutation({
    mutationFn: async () =>
      (await api.post(`/annual-reports/${year}/generate`)).data,
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: ["annual-report-years"] }),
  });
  const saved = years.data?.savedReports[String(year)] ?? [];
  const canGenerate = hasAnyRole(user?.roles, [
    "ROLE_RISK_MANAGER",
    "ROLE_ADMIN",
    "ROLE_SUPER_ADMIN",
  ]);
  const domainRows = useMemo(
    () => Object.entries(report.data?.byDomain ?? {}),
    [report.data],
  );

  const download = async (item: SavedReport, format: "json" | "html") => {
    const response = await api.get(
      `/annual-reports/saved/${item.id}/export?format=${format}`,
      { responseType: "blob" },
    );
    const url = URL.createObjectURL(response.data);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = `riskpilot-rapport-annuel-${item.year}-v${item.version}.${format}`;
    anchor.click();
    URL.revokeObjectURL(url);
  };

  if (years.isLoading || report.isLoading)
    return <CircularProgress aria-label="Chargement du rapport annuel" />;
  if (years.isError || report.isError || !report.data)
    return (
      <Alert severity="error">Impossible de charger le rapport annuel.</Alert>
    );

  return (
    <Stack spacing={3}>
      <Stack
        direction={{ xs: "column", md: "row" }}
        justifyContent="space-between"
        spacing={2}
      >
        <Box>
          <Typography variant="h4" fontWeight={800}>
            Rapports annuels
          </Typography>
          <Typography color="text.secondary">
            Classification annuelle complète des travaux tracés dans RiskPilot.
          </Typography>
        </Box>
        <Stack direction="row" spacing={1} alignItems="center">
          <FormControl size="small" sx={{ minWidth: 130 }}>
            <InputLabel id="annual-year-label">Année</InputLabel>
            <Select
              labelId="annual-year-label"
              label="Année"
              value={year}
              onChange={(event) => setYear(Number(event.target.value))}
            >
              {(years.data?.years ?? [year]).map((value) => (
                <MenuItem key={value} value={value}>
                  {value}
                </MenuItem>
              ))}
            </Select>
          </FormControl>
          {canGenerate && (
            <Button
              variant="contained"
              startIcon={<HistoryOutlined />}
              disabled={generate.isPending}
              onClick={() => generate.mutate()}
            >
              Créer l’instantané
            </Button>
          )}
        </Stack>
      </Stack>
      {generate.isSuccess && (
        <Alert severity="success">
          La nouvelle version du rapport annuel a été créée.
        </Alert>
      )}
      {generate.isError && (
        <Alert severity="error">La génération du rapport a échoué.</Alert>
      )}
      <Alert severity="info">{report.data.methodology}</Alert>
      <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
        {[
          ["Activités tracées", report.data.totals.activities],
          ["Contributeurs", report.data.totals.contributors],
          ["Domaines couverts", report.data.totals.domains],
        ].map(([label, value]) => (
          <Card variant="outlined" sx={{ flex: 1 }} key={String(label)}>
            <CardContent>
              <Typography color="text.secondary">{label}</Typography>
              <Typography variant="h3" fontWeight={800}>
                {value}
              </Typography>
            </CardContent>
          </Card>
        ))}
      </Stack>
      <Card variant="outlined">
        <CardContent>
          <Typography variant="h6" fontWeight={750} gutterBottom>
            Classification par domaine
          </Typography>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Domaine</TableCell>
                <TableCell align="right">Activités</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {domainRows.length ? (
                domainRows.map(([domain, count]) => (
                  <TableRow key={domain}>
                    <TableCell>{domainLabels[domain] ?? domain}</TableCell>
                    <TableCell align="right">{count}</TableCell>
                  </TableRow>
                ))
              ) : (
                <TableRow>
                  <TableCell colSpan={2}>
                    Aucune activité tracée pour cette année.
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
      <Card variant="outlined">
        <CardContent>
          <Typography variant="h6" fontWeight={750} gutterBottom>
            Activité mensuelle
          </Typography>
          <Stack direction="row" flexWrap="wrap" gap={1.5}>
            {report.data.byMonth.map((item) => (
              <Box
                key={item.month}
                sx={{
                  minWidth: 92,
                  p: 1.5,
                  bgcolor: "action.hover",
                  borderRadius: 1,
                }}
              >
                <Typography variant="caption">
                  {monthNames[item.month - 1]}
                </Typography>
                <Typography variant="h6" fontWeight={750}>
                  {item.count}
                </Typography>
              </Box>
            ))}
          </Stack>
        </CardContent>
      </Card>
      <Card variant="outlined">
        <CardContent>
          <Typography variant="h6" fontWeight={750} gutterBottom>
            Instantanés conservés
          </Typography>
          {saved.length === 0 ? (
            <Typography color="text.secondary">
              Aucun instantané n’a encore été créé pour {year}.
            </Typography>
          ) : (
            saved.map((item) => (
              <Stack
                key={item.id}
                direction={{ xs: "column", sm: "row" }}
                justifyContent="space-between"
                alignItems={{ sm: "center" }}
                spacing={1}
                sx={{
                  py: 1,
                  borderBottom: "1px solid",
                  borderColor: "divider",
                }}
              >
                <Box>
                  <Typography fontWeight={700}>{item.title}</Typography>
                  <Typography variant="caption" color="text.secondary">
                    {new Date(item.generatedAt).toLocaleString()} ·{" "}
                    {item.activities} activités
                  </Typography>
                </Box>
                <Stack direction="row" spacing={1}>
                  <Button
                    size="small"
                    startIcon={<DownloadOutlined />}
                    onClick={() => download(item, "json")}
                  >
                    JSON
                  </Button>
                  <Button
                    size="small"
                    startIcon={<DownloadOutlined />}
                    onClick={() => download(item, "html")}
                  >
                    HTML / PDF
                  </Button>
                </Stack>
              </Stack>
            ))
          )}
        </CardContent>
      </Card>
      <Card variant="outlined">
        <CardContent>
          <Typography variant="h6" fontWeight={750} gutterBottom>
            Journal classé de {year}
          </Typography>
          <Box sx={{ overflowX: "auto", maxHeight: 520 }}>
            <Table stickyHeader size="small">
              <TableHead>
                <TableRow>
                  <TableCell>Date</TableCell>
                  <TableCell>Domaine</TableCell>
                  <TableCell>Action</TableCell>
                  <TableCell>Objet</TableCell>
                  <TableCell>Contributeur</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {report.data.activities.map((item) => (
                  <TableRow key={item.id}>
                    <TableCell>
                      {new Date(item.occurredAt).toLocaleString()}
                    </TableCell>
                    <TableCell>
                      {domainLabels[item.domain] ?? item.domain}
                    </TableCell>
                    <TableCell>{item.action}</TableCell>
                    <TableCell>
                      {item.entityType}
                      {item.entityId ? ` #${item.entityId}` : ""}
                    </TableCell>
                    <TableCell>{item.actor}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Box>
        </CardContent>
      </Card>
    </Stack>
  );
}
