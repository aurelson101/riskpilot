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
} from "@mui/material";
import { useState } from "react";
import { api } from "../api/client";
import type {
  ComplianceAssessment,
  ComplianceResult,
  Framework,
} from "../api/types";

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

export function CompliancePage() {
  const [tab, setTab] = useState(0);
  const [selectedAssessment, setSelectedAssessment] = useState<number | null>(
    null,
  );
  const client = useQueryClient();
  const frameworks = useQuery({
    queryKey: ["frameworks"],
    queryFn: async () => (await api.get<Framework[]>("/frameworks")).data,
  });
  const assessments = useQuery({
    queryKey: ["compliance-assessments"],
    queryFn: async () =>
      (await api.get<ComplianceAssessment[]>("/compliance-assessments")).data,
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
    mutationFn: ({
      result,
      patch,
    }: {
      result: ComplianceResult;
      patch: Partial<ComplianceResult>;
    }) =>
      api.put(`/compliance-results/${result.id}`, {
        maturityLevel: patch.maturityLevel ?? result.maturityLevel,
        complianceStatus: patch.complianceStatus ?? result.complianceStatus,
        comment: result.comment,
        evidence: result.evidence,
        remediationActionId: result.remediationAction?.id ?? null,
      }),
    onSuccess: () => {
      client.invalidateQueries({
        queryKey: ["compliance-results", selectedAssessment],
      });
      client.invalidateQueries({ queryKey: ["compliance-assessments"] });
    },
  });
  if (frameworks.isLoading || assessments.isLoading)
    return <CircularProgress />;
  if (frameworks.isError || assessments.isError)
    return (
      <Alert severity="error">
        Impossible de charger le module conformité.
      </Alert>
    );

  return (
    <Stack spacing={3}>
      <Stack>
        <Typography variant="h4" fontWeight={750}>
          Conformité
        </Typography>
        <Typography color="text.secondary">
          Référentiels, évaluations et plans de remédiation
        </Typography>
      </Stack>
      <Tabs value={tab} onChange={(_, value) => setTab(value)}>
        <Tab label="Évaluations" />
        <Tab label="Référentiels" />
      </Tabs>
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
                Aucune évaluation. Lancez-en une via l’API.
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
                      <Chip size="small" label={assessment.status} />
                    </Stack>
                    <Typography variant="caption">
                      {assessment.scope.name} · {assessment.assessor.firstName}{" "}
                      {assessment.assessor.lastName}
                    </Typography>
                    <LinearProgress
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
                <CircularProgress />
              ) : (
                <Stack spacing={2}>
                  <Typography variant="h6" fontWeight={750}>
                    Résultats par exigence
                  </Typography>
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
                        <Stack direction="row" spacing={1}>
                          <FormControl size="small">
                            <Select
                              aria-label={`Maturité ${result.requirement.reference}`}
                              value={result.maturityLevel}
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
    </Stack>
  );
}
