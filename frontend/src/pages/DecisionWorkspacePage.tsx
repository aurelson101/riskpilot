import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  AddOutlined,
  DownloadOutlined,
  PlayArrowOutlined,
} from "@mui/icons-material";
import {
  Alert,
  Button,
  Card,
  CardContent,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  MenuItem,
  Stack,
  Tab,
  Tabs,
  TextField,
  Typography,
} from "@mui/material";
import { useState, type FormEvent } from "react";
import { api } from "../api/client";
import { useAuth } from "../auth/useAuth";
import { hasAnyRole } from "../auth/roles";
import { RecordDetails } from "../components/RecordDetails";

type Section =
  | "SECURITY_PROJECT"
  | "FINANCIAL_SCENARIO"
  | "SAVED_VIEW"
  | "REPORT_TEMPLATE"
  | "CONNECTOR_SYNC"
  | "TPRM_PROGRAM";
type Item = {
  id: number;
  type: string;
  title: string;
  status: string;
  details: Record<string, unknown>;
  dueAt?: string | null;
};
type Portfolio = {
  summary: { total: number; averageCyberScore: number; critical: number };
  items: Array<{
    id: number;
    name: string;
    segment: string;
    criticality: string;
    cyberScore: number;
    alerts: string[];
  }>;
};

const sections: Array<{ type: Section; label: string }> = [
  { type: "SECURITY_PROJECT", label: "Security by Design" },
  { type: "FINANCIAL_SCENARIO", label: "Quantification financière" },
  { type: "SAVED_VIEW", label: "Vues 360°" },
  { type: "REPORT_TEMPLATE", label: "Rapports gouvernés" },
  { type: "CONNECTOR_SYNC", label: "Connecteurs" },
  { type: "TPRM_PROGRAM", label: "Portefeuille TPRM" },
];

const defaults: Record<Section, Record<string, unknown>> = {
  SECURITY_PROJECT: {
    version: "1.0",
    criticality: "MEDIUM",
    qualification: {},
    assetIds: [],
    dataCategories: [],
    requirementIds: [],
    riskIds: [],
    actionIds: [],
    deviations: [],
    milestones: [],
    securityOpinion: "",
    productionDecision: "",
  },
  FINANCIAL_SCENARIO: {
    frequencyMin: 0.2,
    frequencyMax: 1,
    lossMin: 10000,
    lossMostLikely: 50000,
    lossMax: 250000,
    indirectLossFactor: 0.2,
    currency: "EUR",
    modelVersion: "1.0",
    financeApproval: { approved: false },
  },
  SAVED_VIEW: {
    version: "1.0",
    shared: false,
    filters: {},
    columns: [],
    groupBy: null,
    period: null,
    comparison: null,
  },
  REPORT_TEMPLATE: {
    version: "1.0",
    reportType: "MANAGEMENT_COMMITTEE",
    blocks: ["risks", "actions", "compliance"],
    approved: false,
  },
  CONNECTOR_SYNC: {
    provider: "JIRA",
    direction: "BIDIRECTIONAL",
    conflictStrategy: "MANUAL",
    fieldOwnership: {},
  },
  TPRM_PROGRAM: {
    version: "1.0",
    segments: {
      LIGHT: ["LOW", "MEDIUM"],
      STANDARD: ["HIGH"],
      DEEP: ["CRITICAL"],
    },
    reassessmentMonths: { LIGHT: 24, STANDARD: 12, DEEP: 6 },
    reminders: [30, 14, 7],
  },
};

export function DecisionWorkspacePage() {
  const { user } = useAuth();
  const isAdmin = hasAnyRole(user?.roles, ["ROLE_SUPER_ADMIN", "ROLE_ADMIN"]);
  const canContribute = hasAnyRole(user?.roles, [
    "ROLE_SUPER_ADMIN",
    "ROLE_ADMIN",
    "ROLE_RISK_MANAGER",
  ]);
  const client = useQueryClient();
  const [section, setSection] = useState<Section>("SECURITY_PROJECT");
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [simulation, setSimulation] = useState<Record<string, unknown> | null>(
    null,
  );
  const [form, setForm] = useState({
    title: "",
    dueAt: "",
    details: JSON.stringify(defaults.SECURITY_PROJECT, null, 2),
  });
  const isFinancial = section === "FINANCIAL_SCENARIO";
  const isConnector = section === "CONNECTOR_SYNC";
  const canCreate = isConnector ? isAdmin : canContribute;
  const portfolio = useQuery({
    queryKey: ["decision-tprm"],
    enabled: section === "TPRM_PROGRAM",
    queryFn: async () =>
      (await api.get<Portfolio>("/decision/tprm/portfolio")).data,
  });
  const records = useQuery({
    queryKey: ["decision-records", section],
    enabled: !isFinancial && !isConnector,
    queryFn: async () =>
      (await api.get<Item[]>(`/operations/records?type=${section}`)).data,
  });
  const connectors = useQuery({
    queryKey: ["decision-connectors"],
    enabled: isConnector,
    queryFn: async () => {
      const response = await api.get<{
        items: Array<{
          id: number;
          type: string;
          provider: string;
          name: string;
          enabled: boolean;
          configuration: Record<string, unknown>;
        }>;
      }>("/v1/integrations");
      return response.data.items
        .filter((item) => item.type === "CONNECTOR")
        .map((item) => ({
          id: item.id,
          type: item.type,
          title: item.name,
          status: item.enabled ? "ACTIVE" : "DRAFT",
          details: {
            provider: item.provider,
            ...item.configuration,
          } as Record<string, unknown>,
        }));
    },
  });
  const finance = useQuery({
    queryKey: ["decision-finance"],
    enabled: isFinancial,
    queryFn: async () =>
      (await api.get<Item[]>("/executive-governance/records")).data.filter(
        (item) => item.type === "FINANCIAL_SCENARIO",
      ),
  });
  const create = useMutation({
    mutationFn: (details: Record<string, unknown>) =>
      isFinancial
        ? api.post("/executive-governance/records", {
            type: section,
            title: form.title,
            ownerId: user?.id,
            status: "DRAFT",
            details,
          })
        : isConnector
          ? api.post("/v1/integrations", {
              type: "CONNECTOR",
              provider: details.provider,
              name: form.title,
              enabled: true,
              configuration: details,
            })
          : api.post("/operations/records", {
              type: section,
              title: form.title,
              ownerId: user?.id,
              status: "ACTIVE",
              dueAt: form.dueAt || null,
              details,
            }),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["decision-records"] });
      await client.invalidateQueries({ queryKey: ["decision-finance"] });
      await client.invalidateQueries({ queryKey: ["decision-connectors"] });
      setOpen(false);
    },
  });
  const runReport = useMutation({
    mutationFn: async (id: number) =>
      (await api.post<Item>(`/decision/reports/${id}/run`)).data,
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["decision-records"] });
    },
  });
  const downloadReport = useMutation({
    mutationFn: async ({
      id,
      format,
    }: {
      id: number;
      format: "json" | "pdf";
    }) => {
      const response = await api.get<Blob>(
        `/decision/reports/${id}/export?format=${format}`,
        { responseType: "blob" },
      );
      const url = URL.createObjectURL(response.data);
      const link = document.createElement("a");
      link.href = url;
      link.download = `riskpilot-report-${id}.${format}`;
      link.click();
      URL.revokeObjectURL(url);
    },
  });
  const approveFinance = useMutation({
    mutationFn: (item: Item) =>
      api.put(`/executive-governance/records/${item.id}`, {
        status: "APPROVED",
        details: {
          ...item.details,
          modelVersion: item.details.modelVersion || "1.0",
          financeApproval: {
            approved: true,
            approvedAt: new Date().toISOString(),
            approvedBy: user?.email,
          },
        },
      }),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: ["decision-finance"] }),
  });
  const simulate = useMutation({
    mutationFn: async (id: number) =>
      (
        await api.post<Record<string, unknown>>(
          `/decision/financial-scenarios/${id}/simulate`,
        )
      ).data,
    onSuccess: (data) => setSimulation(data),
  });
  const reconcile = useMutation({
    mutationFn: async (id: number) =>
      (
        await api.post<Record<string, unknown>>(
          `/decision/connectors/${id}/reconcile`,
          {
            dryRun: true,
            idempotencyKey: crypto.randomUUID(),
            items: [],
          },
        )
      ).data,
    onSuccess: (data) => setSimulation(data),
  });
  const transitionProject = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) =>
      api.post(`/decision/projects/${id}/transition`, {
        status,
        comment: "Transition validée depuis le workspace P2",
      }),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: ["decision-records"] }),
  });
  const approveReport = useMutation({
    mutationFn: (item: Item) =>
      api.put(`/operations/records/${item.id}`, {
        details: {
          ...item.details,
          approved: true,
          approvedAt: new Date().toISOString(),
          approvedBy: user?.email,
        },
      }),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: ["decision-records"] }),
  });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    try {
      const details = JSON.parse(form.details) as Record<string, unknown>;
      setError(null);
      create.mutate(details);
    } catch {
      setError("La configuration JSON n’est pas valide.");
    }
  };
  const items = isFinancial
    ? finance.data
    : isConnector
      ? connectors.data
      : records.data;

  return (
    <Stack spacing={3}>
      <div>
        <Typography variant="h4" fontWeight={800}>
          Décision et différenciation
        </Typography>
        <Typography color="text.secondary">
          Security by Design, quantification, vues, rapports, connecteurs et
          portefeuille tiers
        </Typography>
      </div>
      <Card>
        <Tabs
          value={section}
          onChange={(_, value: Section) => {
            setSection(value);
            setForm({
              title: "",
              dueAt: "",
              details: JSON.stringify(defaults[value], null, 2),
            });
          }}
          variant="scrollable"
          scrollButtons="auto"
          aria-label="Décision et différenciation"
        >
          {sections.map((item) => (
            <Tab key={item.type} value={item.type} label={item.label} />
          ))}
        </Tabs>
      </Card>
      {(records.isError ||
        finance.isError ||
        connectors.isError ||
        portfolio.isError ||
        create.isError ||
        runReport.isError ||
        downloadReport.isError ||
        approveFinance.isError ||
        simulate.isError ||
        reconcile.isError ||
        transitionProject.isError ||
        approveReport.isError ||
        error) && (
        <Alert severity="error">
          {error ?? "L’opération n’a pas pu être terminée."}
        </Alert>
      )}
      {runReport.data && (
        <Alert
          severity="success"
          action={
            <Stack direction="row" spacing={1}>
              <Button
                color="inherit"
                size="small"
                startIcon={<DownloadOutlined />}
                disabled={downloadReport.isPending}
                onClick={() =>
                  downloadReport.mutate({
                    id: runReport.data.id,
                    format: "json",
                  })
                }
              >
                Télécharger JSON
              </Button>
              <Button
                color="inherit"
                size="small"
                startIcon={<DownloadOutlined />}
                disabled={downloadReport.isPending}
                onClick={() =>
                  downloadReport.mutate({
                    id: runReport.data.id,
                    format: "pdf",
                  })
                }
              >
                Télécharger PDF
              </Button>
            </Stack>
          }
        >
          Rapport généré : {runReport.data.title}
        </Alert>
      )}
      {(records.isLoading ||
        finance.isLoading ||
        connectors.isLoading ||
        portfolio.isLoading) && (
        <Stack alignItems="center" py={4}>
          <CircularProgress aria-label="Chargement de l’espace décision" />
        </Stack>
      )}
      {section === "TPRM_PROGRAM" && portfolio.data && (
        <Stack spacing={2}>
          <Alert severity="info">
            {portfolio.data.summary.total} tiers · cyberscore moyen{" "}
            {portfolio.data.summary.averageCyberScore}% ·{" "}
            {portfolio.data.summary.critical} critiques
          </Alert>
          {portfolio.data.items.map((item) => (
            <Card key={item.id}>
              <CardContent>
                <Stack
                  direction={{ xs: "column", sm: "row" }}
                  justifyContent="space-between"
                  gap={1}
                >
                  <div>
                    <Typography fontWeight={750}>{item.name}</Typography>
                    <Typography variant="body2">
                      Segment {item.segment} · score {item.cyberScore}%
                    </Typography>
                  </div>
                  <Stack direction="row" gap={1}>
                    <Chip label={item.criticality} />
                    {item.alerts.map((alert) => (
                      <Chip key={alert} color="warning" label={alert} />
                    ))}
                  </Stack>
                </Stack>
              </CardContent>
            </Card>
          ))}
        </Stack>
      )}
      {canCreate && (
        <Button
          variant="contained"
          startIcon={<AddOutlined />}
          sx={{ alignSelf: "flex-start" }}
          onClick={() => setOpen(true)}
        >
          Créer
        </Button>
      )}
      <Stack spacing={2}>
        {items?.map((item) => (
          <Card key={item.id}>
            <CardContent>
              <Stack spacing={1}>
                <Stack direction="row" justifyContent="space-between">
                  <Typography fontWeight={750}>{item.title}</Typography>
                  <Chip label={item.status} />
                </Stack>
                <RecordDetails details={item.details} />
                {section === "REPORT_TEMPLATE" &&
                  item.details.approved !== true &&
                  isAdmin && (
                    <Button onClick={() => approveReport.mutate(item)}>
                      Approuver le modèle de rapport
                    </Button>
                  )}
                {section === "REPORT_TEMPLATE" &&
                  item.status === "ACTIVE" &&
                  item.details.approved === true &&
                  canContribute && (
                    <Button
                      startIcon={<PlayArrowOutlined />}
                      disabled={runReport.isPending}
                      onClick={() => runReport.mutate(item.id)}
                    >
                      {runReport.isPending
                        ? "Génération du rapport…"
                        : "Générer le rapport"}
                    </Button>
                  )}
                {section === "FINANCIAL_SCENARIO" &&
                  item.status !== "APPROVED" &&
                  isAdmin && (
                    <Button onClick={() => approveFinance.mutate(item)}>
                      Approuver le modèle financier
                    </Button>
                  )}
                {section === "CONNECTOR_SYNC" &&
                  item.status === "ACTIVE" &&
                  isAdmin && (
                    <Button onClick={() => reconcile.mutate(item.id)}>
                      Tester le rapprochement
                    </Button>
                  )}
                {section === "SECURITY_PROJECT" &&
                  item.status === "ACTIVE" &&
                  canContribute && (
                    <Button
                      onClick={() =>
                        transitionProject.mutate({
                          id: item.id,
                          status: "IN_PROGRESS",
                        })
                      }
                    >
                      Démarrer la revue sécurité
                    </Button>
                  )}
                {section === "SECURITY_PROJECT" &&
                  ["IN_PROGRESS", "AT_RISK"].includes(item.status) &&
                  canContribute && (
                    <Button
                      onClick={() =>
                        transitionProject.mutate({
                          id: item.id,
                          status: "COMPLETED",
                        })
                      }
                    >
                      Décider la mise en production
                    </Button>
                  )}
                {section === "FINANCIAL_SCENARIO" &&
                  item.status === "APPROVED" && (
                    <Button
                      startIcon={<PlayArrowOutlined />}
                      onClick={() => simulate.mutate(item.id)}
                    >
                      Lancer la simulation
                    </Button>
                  )}
              </Stack>
            </CardContent>
          </Card>
        ))}
      </Stack>
      {simulation && (
        <Card>
          <CardContent>
            <Typography fontWeight={750}>Résultat du traitement</Typography>
            <RecordDetails details={simulation} />
          </CardContent>
        </Card>
      )}
      <Dialog
        open={canCreate && open}
        onClose={() => setOpen(false)}
        fullWidth
        maxWidth="md"
      >
        <form onSubmit={submit}>
          <DialogTitle>Créer un élément P2</DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ mt: 1 }}>
              <TextField
                required
                label="Titre"
                value={form.title}
                onChange={(event) =>
                  setForm({ ...form, title: event.target.value })
                }
              />
              {!isFinancial && (
                <TextField
                  type="date"
                  label="Échéance"
                  InputLabelProps={{ shrink: true }}
                  value={form.dueAt}
                  onChange={(event) =>
                    setForm({ ...form, dueAt: event.target.value })
                  }
                />
              )}
              <TextField
                select
                label="Statut initial"
                value={isFinancial ? "DRAFT" : "ACTIVE"}
                disabled
              >
                <MenuItem value={isFinancial ? "DRAFT" : "ACTIVE"}>
                  {isFinancial ? "DRAFT" : "ACTIVE"}
                </MenuItem>
              </TextField>
              <TextField
                required
                multiline
                minRows={14}
                label="Configuration JSON versionnée"
                value={form.details}
                onChange={(event) =>
                  setForm({ ...form, details: event.target.value })
                }
              />
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setOpen(false)}>Annuler</Button>
            <Button
              type="submit"
              variant="contained"
              disabled={create.isPending}
            >
              Créer
            </Button>
          </DialogActions>
        </form>
      </Dialog>
    </Stack>
  );
}
