import { useQuery } from "@tanstack/react-query";
import {
  Alert,
  Card,
  CardContent,
  Chip,
  CircularProgress,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from "@mui/material";
import { api } from "../api/client";
import type { Organization, RiskLevel, RiskScenario } from "../api/types";
import { useAuth } from "../auth/useAuth";

const levelLabels: Record<RiskLevel, string> = {
  LOW: "Faible",
  MODERATE: "Modéré",
  HIGH: "Élevé",
  CRITICAL: "Critique",
};

function scoreLevel(
  score: number,
  thresholds: Organization["riskThresholds"],
): RiskLevel {
  if (score <= thresholds.lowMax) return "LOW";
  if (score <= thresholds.moderateMax) return "MODERATE";
  if (score <= thresholds.highMax) return "HIGH";
  return "CRITICAL";
}

function ScoreChip({ score }: { score: number }) {
  const { user } = useAuth();
  const level = scoreLevel(score, user!.organization.riskThresholds);
  const colors: Record<RiskLevel, "success" | "warning" | "error"> = {
    LOW: "success",
    MODERATE: "warning",
    HIGH: "warning",
    CRITICAL: "error",
  };
  return (
    <Chip
      size="small"
      color={colors[level]}
      label={`${score} · ${levelLabels[level]}`}
    />
  );
}

export function RisksPage() {
  const query = useQuery({
    queryKey: ["risks"],
    queryFn: async () => (await api.get<RiskScenario[]>("/risks")).data,
  });
  if (query.isLoading) return <CircularProgress />;
  if (query.isError)
    return (
      <Alert severity="error">
        Impossible de charger le registre des risques.
      </Alert>
    );

  return (
    <Stack spacing={3}>
      <Stack>
        <Typography variant="h4" fontWeight={750}>
          Registre des risques
        </Typography>
        <Typography color="text.secondary">
          Scénarios évalués · {query.data?.length ?? 0} risque(s)
        </Typography>
      </Stack>
      <Card variant="outlined">
        <CardContent>
          <Table aria-label="Registre des risques">
            <TableHead>
              <TableRow>
                <TableCell>Scénario</TableCell>
                <TableCell>Actif / menace</TableCell>
                <TableCell>Brut</TableCell>
                <TableCell>Actuel</TableCell>
                <TableCell>Résiduel</TableCell>
                <TableCell>Traitement</TableCell>
                <TableCell>Statut</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {query.data?.map((risk) => (
                <TableRow key={risk.id} hover>
                  <TableCell>
                    <Typography fontWeight={650}>{risk.title}</Typography>
                    <Typography variant="caption" color="text.secondary">
                      {risk.scope.name}
                    </Typography>
                  </TableCell>
                  <TableCell>
                    {risk.asset.name}
                    <Typography
                      display="block"
                      variant="caption"
                      color="text.secondary"
                    >
                      {risk.threat.name}
                    </Typography>
                  </TableCell>
                  <TableCell>
                    <ScoreChip score={risk.grossRiskScore} />
                  </TableCell>
                  <TableCell>
                    <ScoreChip score={risk.currentRiskScore} />
                  </TableCell>
                  <TableCell>
                    <ScoreChip score={risk.residualRiskScore} />
                  </TableCell>
                  <TableCell>{risk.treatmentDecision}</TableCell>
                  <TableCell>
                    <Chip
                      size="small"
                      label={risk.status}
                      color={
                        risk.status === "APPROVED" ||
                        risk.status === "TREATMENT_IN_PROGRESS"
                          ? "primary"
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
    </Stack>
  );
}
