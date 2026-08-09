import {
  Alert,
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
import { useQuery } from "@tanstack/react-query";
import { api } from "../api/client";
import type {
  ComplianceAssessment,
  ComplianceResult,
  Framework,
} from "../api/types";

export function Nis2Page() {
  const frameworks = useQuery({
    queryKey: ["frameworks"],
    queryFn: async () => (await api.get<Framework[]>("/frameworks")).data,
  });
  const assessments = useQuery({
    queryKey: ["compliance-assessments"],
    queryFn: async () =>
      (await api.get<ComplianceAssessment[]>("/compliance-assessments")).data,
  });
  const nis2 = frameworks.data?.find((item) => /nis\s*2/i.test(item.name));
  const current = assessments.data
    ?.filter((item) => item.framework.id === nis2?.id)
    .sort((a, b) => b.id - a.id)[0];
  const results = useQuery({
    queryKey: ["nis2-results", current?.id],
    enabled: Boolean(current),
    queryFn: async () =>
      (
        await api.get<ComplianceResult[]>(
          `/compliance-assessments/${current?.id}/results`,
        )
      ).data,
  });
  const counts = (results.data ?? []).reduce<Record<string, number>>(
    (all, item) => ({
      ...all,
      [item.complianceStatus]: (all[item.complianceStatus] ?? 0) + 1,
    }),
    {},
  );
  if (frameworks.isError || assessments.isError || results.isError)
    return (
      <Alert severity="error">Impossible de charger le pilotage NIS2.</Alert>
    );
  if (!nis2)
    return (
      <Alert severity="info">
        Installez le pack de démarrage NIS2 pour activer cette vue.
      </Alert>
    );
  return (
    <Stack spacing={3}>
      <div>
        <Typography variant="h4" fontWeight={800}>
          Conformité NIS2
        </Typography>
        <Typography color="text.secondary">
          Pilotage dédié des exigences, écarts, preuves et remédiations
        </Typography>
      </div>
      <Stack direction={{ xs: "column", md: "row" }} spacing={2}>
        {[
          [
            "Conformité globale",
            `${current?.globalScore?.toFixed(1) ?? "0.0"} %`,
          ],
          ["Conformes", counts.COMPLIANT ?? 0],
          ["Partielles", counts.PARTIAL ?? 0],
          ["Non conformes", counts.NON_COMPLIANT ?? 0],
        ].map(([label, value]) => (
          <Card key={label} sx={{ flex: 1 }}>
            <CardContent>
              <Typography color="text.secondary">{label}</Typography>
              <Typography variant="h4" fontWeight={800}>
                {value}
              </Typography>
            </CardContent>
          </Card>
        ))}
      </Stack>
      <LinearProgress
        variant="determinate"
        value={current?.globalScore ?? 0}
        sx={{ height: 12, borderRadius: 6 }}
      />
      {!current ? (
        <Alert severity="info">
          Lancez une évaluation sur le référentiel {nis2.name} depuis le module
          Conformité.
        </Alert>
      ) : (
        <Card>
          <CardContent>
            <Table aria-label="Exigences NIS2">
              <TableHead>
                <TableRow>
                  <TableCell>Exigence</TableCell>
                  <TableCell>Statut</TableCell>
                  <TableCell>Maturité</TableCell>
                  <TableCell>Preuves</TableCell>
                  <TableCell>Action</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {results.data?.map((item) => (
                  <TableRow key={item.id}>
                    <TableCell>
                      <strong>{item.requirement.reference}</strong>
                      <br />
                      {item.requirement.title}
                    </TableCell>
                    <TableCell>
                      <Chip label={item.complianceStatus} />
                    </TableCell>
                    <TableCell>{item.maturityLevel}/5</TableCell>
                    <TableCell>{item.evidence.length}</TableCell>
                    <TableCell>
                      {item.remediationAction?.title ?? "—"}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}
    </Stack>
  );
}
