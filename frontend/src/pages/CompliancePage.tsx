import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Box,
  Card,
  CardContent,
  Chip,
  CircularProgress,
  FormControl,
  LinearProgress,
  MenuItem,
  Select,
  Stack,
  Tab,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Tabs,
  Typography,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  InputLabel,
  TextField,
} from "@mui/material";
import { Add, SmartToyOutlined } from "@mui/icons-material";
import { useMemo, useState, type FormEvent } from "react";
import {
  PolarAngleAxis,
  PolarGrid,
  PolarRadiusAxis,
  Radar,
  RadarChart,
  ResponsiveContainer,
  Tooltip,
} from "recharts";
import { api } from "../api/client";
import type {
  ComplianceAssessment,
  ComplianceResult,
  Framework,
  Scope,
  User,
} from "../api/types";
import { useAuth } from "../auth/useAuth";
import { ComplianceGovernancePanel } from "../components/compliance/ComplianceGovernancePanel";
import { ComplianceCopilotDialog } from "../components/compliance/ComplianceCopilotDialog";
import { buildComplianceSummary } from "./complianceSummary";

const complianceLabels: Record<ComplianceResult["complianceStatus"], string> = {
  COMPLIANT: "Conforme",
  PARTIAL: "Partiel",
  NON_COMPLIANT: "Non conforme",
  NOT_APPLICABLE: "Non applicable",
  NOT_ASSESSED: "Non évalué",
};
const statusColors: Record<ComplianceResult["complianceStatus"], string> = {
  COMPLIANT: "#43a047",
  PARTIAL: "#f9a825",
  NON_COMPLIANT: "#e53935",
  NOT_APPLICABLE: "#78909c",
  NOT_ASSESSED: "#90a4ae",
};
const assessmentStatusLabels: Record<ComplianceAssessment["status"], string> = {
  DRAFT: "Brouillon",
  IN_PROGRESS: "En cours",
  COMPLETED: "Terminée",
  ARCHIVED: "Archivée",
};

const assessmentStatusColors: Record<
  ComplianceAssessment["status"],
  "default" | "info" | "success"
> = {
  DRAFT: "default",
  IN_PROGRESS: "info",
  COMPLETED: "success",
  ARCHIVED: "default",
};

function summarizeReferences(items: ComplianceResult[]): string {
  const references = items.map((item) => item.requirement.reference);
  const visible = references.slice(0, 6).join(", ");

  return references.length > 6
    ? `${visible} +${references.length - 6}`
    : visible;
}

export function CompliancePage() {
  const { user } = useAuth();
  const [tab, setTab] = useState(0);
  const [selectedAssessment, setSelectedAssessment] = useState<number | null>(
    null,
  );
  const client = useQueryClient();
  const [assessmentDialog, setAssessmentDialog] = useState(false);
  const [copilotResult, setCopilotResult] = useState<ComplianceResult | null>(
    null,
  );
  const [assessmentForm, setAssessmentForm] = useState({
    frameworkId: "",
    scopeId: "",
    assessorId: "",
    assessmentDate: new Date().toISOString().slice(0, 10),
    status: "DRAFT",
  });
  const canAssess = user?.roles.some((role) =>
    [
      "ROLE_SUPER_ADMIN",
      "ROLE_ADMIN",
      "ROLE_RISK_MANAGER",
      "ROLE_AUDITOR",
    ].includes(role),
  );
  const frameworks = useQuery({
    queryKey: ["frameworks"],
    queryFn: async () => (await api.get<Framework[]>("/frameworks")).data,
  });
  const assessments = useQuery({
    queryKey: ["compliance-assessments"],
    queryFn: async () =>
      (await api.get<ComplianceAssessment[]>("/compliance-assessments")).data,
  });
  const scopes = useQuery({
    queryKey: ["scopes"],
    queryFn: async () => (await api.get<Scope[]>("/scopes")).data,
    enabled: Boolean(canAssess),
  });
  const users = useQuery({
    queryKey: ["users"],
    queryFn: async () => (await api.get<User[]>("/users")).data,
    enabled: Boolean(
      canAssess &&
      user?.roles.some((role) =>
        ["ROLE_SUPER_ADMIN", "ROLE_ADMIN", "ROLE_RISK_MANAGER"].includes(role),
      ),
    ),
  });
  const createAssessment = useMutation({
    mutationFn: () =>
      api.post("/compliance-assessments", {
        ...assessmentForm,
        frameworkId: Number(assessmentForm.frameworkId),
        scopeId: Number(assessmentForm.scopeId),
        assessorId: Number(assessmentForm.assessorId),
      }),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["compliance-assessments"] });
      setAssessmentDialog(false);
    },
  });
  const results = useQuery({
    queryKey: ["compliance-results", selectedAssessment],
    enabled: selectedAssessment !== null,
    queryFn: async () =>
      (
        await api.get<ComplianceResult[]>(
          `/compliance-assessments/${selectedAssessment}/results`,
        )
      ).data,
  });
  const updateResult = useMutation({
    scope: { id: "compliance-result-update" },
    mutationFn: async ({
      result,
      patch,
    }: {
      result: ComplianceResult;
      patch: Partial<ComplianceResult>;
    }) => {
      const cached = client
        .getQueryData<ComplianceResult[]>([
          "compliance-results",
          selectedAssessment,
        ])
        ?.find((item) => item.id === result.id);
      const current = { ...result, ...cached, ...patch };

      return api.put(`/compliance-results/${result.id}`, {
        maturityLevel: current.maturityLevel,
        complianceStatus: current.complianceStatus,
        comment: current.comment,
        evidence: current.evidence,
        remediationActionId: current.remediationAction?.id ?? null,
      });
    },
    onMutate: async ({ result, patch }) => {
      const queryKey = ["compliance-results", selectedAssessment] as const;
      await client.cancelQueries({ queryKey });
      const previous = client.getQueryData<ComplianceResult[]>(queryKey);
      client.setQueryData<ComplianceResult[]>(queryKey, (current = []) =>
        current.map((item) =>
          item.id === result.id ? { ...item, ...patch } : item,
        ),
      );

      return { previous, queryKey };
    },
    onError: (_error, _variables, context) => {
      if (context?.previous) {
        client.setQueryData(context.queryKey, context.previous);
      }
    },
    onSettled: () => {
      void client.invalidateQueries({
        queryKey: ["compliance-results", selectedAssessment],
      });
      void client.invalidateQueries({ queryKey: ["compliance-assessments"] });
    },
  });
  const selectedAssessmentRecord = assessments.data?.find(
    (assessment) => assessment.id === selectedAssessment,
  );
  const canEditSelected = Boolean(
    selectedAssessmentRecord &&
    (canAssess || selectedAssessmentRecord.assessor.id === user?.id),
  );
  const assessmentIsLocked =
    selectedAssessmentRecord?.status === "COMPLETED" ||
    selectedAssessmentRecord?.status === "ARCHIVED";
  const updateAssessmentStatus = useMutation({
    mutationFn: ({
      assessment,
      status,
    }: {
      assessment: ComplianceAssessment;
      status: ComplianceAssessment["status"];
    }) =>
      api.put(`/compliance-assessments/${assessment.id}`, {
        frameworkId: assessment.framework.id,
        scopeId: assessment.scope.id,
        assessorId: assessment.assessor.id,
        assessmentDate: assessment.assessmentDate,
        status,
      }),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["compliance-assessments"] });
    },
  });
  const resultSummary = useMemo(
    () => buildComplianceSummary(results.data ?? []),
    [results.data],
  );
  const selectedFrameworkForCreation = frameworks.data?.find(
    (framework) => framework.id === Number(assessmentForm.frameworkId),
  );
  if (frameworks.isLoading || assessments.isLoading)
    return <CircularProgress aria-label="Chargement de la page" />;
  if (frameworks.isError || assessments.isError)
    return (
      <Alert severity="error">
        Impossible de charger le module conformité.
      </Alert>
    );

  return (
    <Stack spacing={3}>
      <Stack
        direction={{ xs: "column", sm: "row" }}
        justifyContent="space-between"
        alignItems={{ xs: "stretch", sm: "center" }}
        gap={2}
      >
        <Stack>
          <Typography variant="h4" fontWeight={750}>
            Conformité
          </Typography>
          <Typography color="text.secondary">
            Référentiels, évaluations et plans de remédiation
          </Typography>
        </Stack>
        {canAssess && (
          <Button
            variant="contained"
            startIcon={<Add />}
            onClick={() => {
              setAssessmentForm((current) => ({
                ...current,
                assessorId: String(user?.id ?? ""),
              }));
              setAssessmentDialog(true);
            }}
          >
            Lancer une évaluation
          </Button>
        )}
      </Stack>
      <Tabs value={tab} onChange={(_, value) => setTab(value)}>
        <Tab label="Évaluations" />
        <Tab label="Référentiels" />
        <Tab label="SoA & contrôles" />
      </Tabs>
      {tab === 2 && <ComplianceGovernancePanel />}
      {tab === 1 && (
        <Card variant="outlined">
          <CardContent>
            <Table aria-label="Référentiels">
              <TableHead>
                <TableRow>
                  <TableCell>Référentiel</TableCell>
                  <TableCell>Éditeur</TableCell>
                  <TableCell>Exigences</TableCell>
                  <TableCell>Statut</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {frameworks.data?.map((framework) => (
                  <TableRow key={framework.id}>
                    <TableCell>
                      <Typography fontWeight={700}>{framework.name}</Typography>
                      <Typography variant="caption">
                        Version {framework.version}
                      </Typography>
                    </TableCell>
                    <TableCell>{framework.publisher ?? "—"}</TableCell>
                    <TableCell>{framework.requirementCount}</TableCell>
                    <TableCell>
                      <Chip
                        size="small"
                        label={framework.status}
                        color={
                          framework.status === "ACTIVE" ? "success" : "default"
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
      {tab === 0 && (
        <Stack
          direction={{ xs: "column", lg: "row" }}
          spacing={3}
          alignItems="flex-start"
        >
          <Stack spacing={1.5} sx={{ width: { xs: "100%", lg: 380 } }}>
            {assessments.data?.length === 0 && (
              <Alert severity="info">
                Aucune évaluation. Lancez-en une avec le bouton ci-dessus.
              </Alert>
            )}
            {assessments.data?.map((assessment) => (
              <Card
                key={assessment.id}
                variant="outlined"
                onClick={() => setSelectedAssessment(assessment.id)}
                sx={{
                  width: "100%",
                  cursor: "pointer",
                  borderColor:
                    selectedAssessment === assessment.id
                      ? "primary.main"
                      : undefined,
                }}
              >
                <CardContent>
                  <Stack spacing={1}>
                    <Stack direction="row" justifyContent="space-between">
                      <Typography fontWeight={750}>
                        {assessment.framework.name}
                      </Typography>
                      <Chip
                        size="small"
                        label={assessmentStatusLabels[assessment.status]}
                        color={assessmentStatusColors[assessment.status]}
                      />
                    </Stack>
                    <Typography variant="caption">
                      {assessment.scope.name} · {assessment.assessor.firstName}{" "}
                      {assessment.assessor.lastName}
                    </Typography>
                    <LinearProgress
                      aria-label={`Progression ${assessment.framework.name} : ${assessment.globalScore}%`}
                      variant="determinate"
                      value={assessment.globalScore}
                      color={
                        assessment.globalScore >= 75
                          ? "success"
                          : assessment.globalScore >= 50
                            ? "warning"
                            : "error"
                      }
                    />
                    <Typography fontWeight={700}>
                      {assessment.globalScore.toFixed(1)}% de conformité
                    </Typography>
                  </Stack>
                </CardContent>
              </Card>
            ))}
          </Stack>
          <Card variant="outlined" sx={{ flex: 1, width: "100%" }}>
            <CardContent>
              {selectedAssessment === null ? (
                <Typography color="text.secondary">
                  Sélectionnez une évaluation pour saisir ses résultats.
                </Typography>
              ) : results.isLoading ? (
                <CircularProgress aria-label="Chargement de la page" />
              ) : results.isError ? (
                <Alert severity="error">
                  Impossible de charger les résultats de cette évaluation.
                </Alert>
              ) : (
                <Stack spacing={2}>
                  <Stack
                    direction={{ xs: "column", sm: "row" }}
                    justifyContent="space-between"
                    alignItems={{ xs: "stretch", sm: "center" }}
                    gap={2}
                  >
                    <Box>
                      <Typography variant="h6" fontWeight={750}>
                        Résultats par exigence
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        Brouillon → En cours → Terminée, puis Archivée si
                        nécessaire
                      </Typography>
                    </Box>
                    {selectedAssessmentRecord && (
                      <FormControl size="small" sx={{ minWidth: 180 }}>
                        <InputLabel id="assessment-status-label">
                          État de l’évaluation
                        </InputLabel>
                        <Select
                          labelId="assessment-status-label"
                          label="État de l’évaluation"
                          value={selectedAssessmentRecord.status}
                          disabled={
                            !canEditSelected || updateAssessmentStatus.isPending
                          }
                          onChange={(event) =>
                            updateAssessmentStatus.mutate({
                              assessment: selectedAssessmentRecord,
                              status: event.target
                                .value as ComplianceAssessment["status"],
                            })
                          }
                        >
                          {Object.entries(assessmentStatusLabels).map(
                            ([status, label]) => (
                              <MenuItem key={status} value={status}>
                                {label}
                              </MenuItem>
                            ),
                          )}
                        </Select>
                      </FormControl>
                    )}
                  </Stack>
                  {updateAssessmentStatus.isError && (
                    <Alert severity="error">
                      Le changement d’état de l’évaluation a échoué.
                    </Alert>
                  )}
                  {assessmentIsLocked && (
                    <Alert severity="info">
                      {selectedAssessmentRecord?.status === "ARCHIVED"
                        ? "Cette évaluation est archivée. Repassez-la « En cours » pour modifier ses résultats."
                        : "Cette évaluation est terminée. Repassez-la « En cours » pour modifier ses résultats."}
                    </Alert>
                  )}
                  {updateResult.isError && (
                    <Alert severity="error">
                      La mise à jour du résultat a échoué. La valeur précédente
                      a été restaurée.
                    </Alert>
                  )}
                  {(results.data?.length ?? 0) > 0 && (
                    <Card variant="outlined">
                      <CardContent>
                        <Stack
                          direction={{ xs: "column", md: "row" }}
                          justifyContent="space-between"
                          spacing={2}
                        >
                          <Box>
                            <Typography variant="h6" fontWeight={750}>
                              Toile d’araignée des résultats · 0 à 5
                            </Typography>
                            <Typography color="text.secondary">
                              Les creux indiquent les exigences à renforcer ;
                              les sommets mettent en évidence les points forts.
                            </Typography>
                          </Box>
                          <Box sx={{ textAlign: { md: "right" } }}>
                            <Typography variant="h4" fontWeight={800}>
                              {resultSummary.average === null
                                ? "—"
                                : `${resultSummary.average.toFixed(1)} / 5`}
                            </Typography>
                            <Typography
                              variant="caption"
                              color="text.secondary"
                            >
                              {resultSummary.remaining.length} à terminer
                            </Typography>
                          </Box>
                        </Stack>
                        <Box
                          sx={{
                            display: "grid",
                            gridTemplateColumns: {
                              xs: "minmax(0, 1fr)",
                              lg: "minmax(0, 2fr) minmax(280px, 1fr)",
                            },
                            gap: 2,
                            alignItems: "center",
                            mt: 2,
                          }}
                        >
                          {resultSummary.radar.length >= 3 ? (
                            <Box
                              role="img"
                              aria-label="Toile d’araignée des résultats de conformité, échelle de 0 à 5"
                              sx={{ height: { xs: 340, md: 460 }, minWidth: 0 }}
                            >
                              <ResponsiveContainer>
                                <RadarChart
                                  data={resultSummary.radar}
                                  outerRadius="78%"
                                >
                                  <PolarGrid stroke="#cbd5e1" />
                                  <PolarAngleAxis
                                    dataKey="requirement"
                                    tick={{ fontSize: 12, fill: "#64748b" }}
                                  />
                                  <PolarRadiusAxis
                                    domain={[0, 5]}
                                    tickCount={6}
                                    tick={{ fontSize: 11, fill: "#64748b" }}
                                  />
                                  <Tooltip
                                    formatter={(value) => [
                                      `${value} / 5`,
                                      "Maturité",
                                    ]}
                                    labelFormatter={(reference) => {
                                      const item = resultSummary.radar.find(
                                        (entry) =>
                                          entry.requirement === reference,
                                      );
                                      return item
                                        ? `${reference} · ${item.title}`
                                        : reference;
                                    }}
                                  />
                                  <Radar
                                    name="Maturité"
                                    dataKey="maturity"
                                    isAnimationActive={false}
                                    stroke="#1769e0"
                                    strokeWidth={2}
                                    fill="#1769e0"
                                    fillOpacity={0.28}
                                    dot={{ r: 3, fill: "#1769e0" }}
                                  />
                                </RadarChart>
                              </ResponsiveContainer>
                            </Box>
                          ) : (
                            <Alert severity="info">
                              Évaluez au moins trois exigences pour afficher une
                              toile représentative.
                            </Alert>
                          )}
                          <Stack spacing={1}>
                            <Alert severity="error">
                              Points faibles (0–2) : {resultSummary.weak.length}
                              {resultSummary.weak.length > 0 &&
                                ` · ${summarizeReferences(resultSummary.weak)}`}
                            </Alert>
                            <Alert severity="success">
                              Points forts (4–5) : {resultSummary.strong.length}
                              {resultSummary.strong.length > 0 &&
                                ` · ${summarizeReferences(resultSummary.strong)}`}
                            </Alert>
                            {resultSummary.remaining.length > 0 && (
                              <Alert severity="warning">
                                À terminer : {resultSummary.remaining.length}
                                {` · ${summarizeReferences(resultSummary.remaining)}`}
                              </Alert>
                            )}
                            {resultSummary.notApplicable.length > 0 && (
                              <Alert severity="info">
                                Non applicables :{" "}
                                {resultSummary.notApplicable.length}
                                {` · ${summarizeReferences(resultSummary.notApplicable)}`}
                              </Alert>
                            )}
                          </Stack>
                        </Box>
                      </CardContent>
                    </Card>
                  )}
                  {results.data?.map((result) => (
                    <Box
                      key={result.id}
                      sx={{
                        p: 2,
                        border: "1px solid #e2e8f0",
                        borderLeft: `5px solid ${statusColors[result.complianceStatus]}`,
                        borderRadius: 1.5,
                      }}
                    >
                      <Stack
                        direction={{ xs: "column", md: "row" }}
                        justifyContent="space-between"
                        gap={2}
                      >
                        <Stack>
                          <Typography fontWeight={700}>
                            {result.requirement.reference} ·{" "}
                            {result.requirement.title}
                          </Typography>
                          <Typography variant="caption" color="text.secondary">
                            {result.requirement.category}
                          </Typography>
                        </Stack>
                        <Stack
                          direction={{ xs: "column", sm: "row" }}
                          spacing={1}
                          alignItems={{ xs: "stretch", sm: "center" }}
                        >
                          <Button
                            size="small"
                            variant="outlined"
                            startIcon={<SmartToyOutlined />}
                            onClick={() => setCopilotResult(result)}
                          >
                            Copilote IA
                          </Button>
                          <FormControl size="small">
                            <Select
                              aria-label={`Maturité ${result.requirement.reference}`}
                              value={result.maturityLevel}
                              disabled={!canEditSelected || assessmentIsLocked}
                              onChange={(event) =>
                                updateResult.mutate({
                                  result,
                                  patch: {
                                    maturityLevel: Number(event.target.value),
                                  },
                                })
                              }
                            >
                              {[0, 1, 2, 3, 4, 5].map((level) => (
                                <MenuItem key={level} value={level}>
                                  Maturité {level}
                                </MenuItem>
                              ))}
                            </Select>
                          </FormControl>
                          <FormControl size="small">
                            <Select
                              aria-label={`Conformité ${result.requirement.reference}`}
                              value={result.complianceStatus}
                              disabled={!canEditSelected || assessmentIsLocked}
                              onChange={(event) =>
                                updateResult.mutate({
                                  result,
                                  patch: {
                                    complianceStatus: event.target
                                      .value as ComplianceResult["complianceStatus"],
                                  },
                                })
                              }
                            >
                              {Object.entries(complianceLabels).map(
                                ([value, label]) => (
                                  <MenuItem key={value} value={value}>
                                    {label}
                                  </MenuItem>
                                ),
                              )}
                            </Select>
                          </FormControl>
                        </Stack>
                      </Stack>
                      {result.remediationAction && (
                        <Chip
                          sx={{ mt: 1 }}
                          size="small"
                          color="warning"
                          label={`Action : ${result.remediationAction.title}`}
                        />
                      )}
                    </Box>
                  ))}
                </Stack>
              )}
            </CardContent>
          </Card>
        </Stack>
      )}
      <Dialog
        open={assessmentDialog}
        onClose={() => setAssessmentDialog(false)}
        fullWidth
        maxWidth="sm"
      >
        <Stack
          component="form"
          onSubmit={(event: FormEvent) => {
            event.preventDefault();
            createAssessment.mutate();
          }}
        >
          <DialogTitle>Lancer une évaluation</DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ pt: 1 }}>
              {createAssessment.isError && (
                <Alert severity="error">
                  Impossible de créer l’évaluation.
                </Alert>
              )}
              <FormControl required>
                <InputLabel>Référentiel</InputLabel>
                <Select
                  label="Référentiel"
                  value={assessmentForm.frameworkId}
                  onChange={(e) =>
                    setAssessmentForm({
                      ...assessmentForm,
                      frameworkId: String(e.target.value),
                    })
                  }
                >
                  {frameworks.data
                    ?.filter((item) => item.status === "ACTIVE")
                    .map((item) => (
                      <MenuItem key={item.id} value={item.id}>
                        {item.name} · {item.version}
                      </MenuItem>
                    ))}
                </Select>
              </FormControl>
              {selectedFrameworkForCreation && (
                <Alert severity="info">
                  {selectedFrameworkForCreation.requirementCount} points actifs
                  seront ajoutés automatiquement avec l’état « Non évalué ».
                  Vous pourrez ensuite préciser leur maturité, leur conformité,
                  les preuves et les actions correctives.
                </Alert>
              )}
              <FormControl required>
                <InputLabel>Périmètre</InputLabel>
                <Select
                  label="Périmètre"
                  value={assessmentForm.scopeId}
                  onChange={(e) =>
                    setAssessmentForm({
                      ...assessmentForm,
                      scopeId: String(e.target.value),
                    })
                  }
                >
                  {scopes.data?.map((item) => (
                    <MenuItem key={item.id} value={item.id}>
                      {item.name}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
              <FormControl required>
                <InputLabel>Évaluateur</InputLabel>
                <Select
                  label="Évaluateur"
                  value={assessmentForm.assessorId}
                  onChange={(e) =>
                    setAssessmentForm({
                      ...assessmentForm,
                      assessorId: String(e.target.value),
                    })
                  }
                >
                  {users.data?.map((item) => (
                    <MenuItem key={item.id} value={item.id}>
                      {item.firstName} {item.lastName}
                    </MenuItem>
                  )) ?? (
                    <MenuItem value={user?.id}>
                      {user?.firstName} {user?.lastName}
                    </MenuItem>
                  )}
                </Select>
              </FormControl>
              <TextField
                required
                type="date"
                label="Date"
                InputLabelProps={{ shrink: true }}
                value={assessmentForm.assessmentDate}
                onChange={(e) =>
                  setAssessmentForm({
                    ...assessmentForm,
                    assessmentDate: e.target.value,
                  })
                }
              />
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setAssessmentDialog(false)}>Annuler</Button>
            <Button
              type="submit"
              variant="contained"
              disabled={createAssessment.isPending}
            >
              Créer
            </Button>
          </DialogActions>
        </Stack>
      </Dialog>
      {copilotResult && (
        <ComplianceCopilotDialog
          result={copilotResult}
          onClose={() => setCopilotResult(null)}
        />
      )}
    </Stack>
  );
}
