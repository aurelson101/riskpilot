import { useState } from "react";
import {
  Alert,
  Button,
  Card,
  CardContent,
  Checkbox,
  Chip,
  FormControlLabel,
  FormGroup,
  MenuItem,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "../../api/client";
import { downloadApiFile } from "../../api/download";
import type { ComplianceAssessment } from "../../api/types";

type Summary = {
  declarationComplete: boolean;
  defaultTarget: number;
  ready: boolean;
  totals: {
    total: number;
    assessed: number;
    meets: number;
    blocking: number;
    incomplete: number;
  };
  modules: Array<{
    name: string;
    total: number;
    assessed: number;
    meets: number;
    blocking: number;
    incomplete: number;
    gaps: Array<{
      resultId: number;
      reference: string;
      title: string;
      maturity: number;
      target: number;
    }>;
  }>;
};

const objectives: Array<[string, string]> = [
  ["INFO_HIGH", "Information : protection élevée"],
  ["INFO_VERY_HIGH", "Information : protection très élevée"],
  ["PROTO_PARTS", "Prototypes : composants et pièces"],
  ["PROTO_VEHICLES", "Prototypes : véhicules"],
  ["TEST_VEHICLES", "Véhicules d’essai"],
  ["EVENTS", "Événements et prises de vue"],
  ["DATA", "Protection des données"],
  ["SPECIAL_DATA", "Données à caractère particulier"],
];

export function TisaxPanel({
  assessment,
  canEdit,
}: {
  assessment: ComplianceAssessment;
  canEdit: boolean;
}) {
  const cache = useQueryClient();
  const declared = assessment.tisax;
  const [draft, setDraft] = useState<null | {
    level: string;
    objectives: string[];
    siteScope: string;
    participantId: string;
  }>(null);
  const form = draft ?? {
    level: declared?.level ?? "",
    objectives: declared?.objectives ?? [],
    siteScope: declared?.siteScope ?? "",
    participantId: declared?.participantId ?? "",
  };
  const summary = useQuery({
    queryKey: ["tisax-summary", assessment.id],
    queryFn: async () =>
      (await api.get<Summary>(`/compliance-assessments/${assessment.id}/tisax`))
        .data,
  });
  const save = useMutation({
    mutationFn: () =>
      api.put(`/compliance-assessments/${assessment.id}`, {
        frameworkId: assessment.framework.id,
        scopeId: assessment.scope.id,
        assessorId: assessment.assessor.id,
        assessmentDate: assessment.assessmentDate.slice(0, 10),
        status: assessment.status,
        tisaxLevel: form.level || null,
        tisaxObjectives: form.objectives,
        siteScope: form.siteScope || null,
        participantId: form.participantId || null,
      }),
    onSuccess: async () => {
      setDraft(null);
      await cache.invalidateQueries({ queryKey: ["compliance-assessments"] });
      await cache.invalidateQueries({
        queryKey: ["tisax-summary", assessment.id],
      });
    },
  });
  const [exportError, setExportError] = useState(false);
  const exportWorkbook = async () => {
    setExportError(false);
    try {
      await downloadApiFile(
        `/compliance-assessments/${assessment.id}/tisax.xlsx`,
        `tisax-${assessment.id}.xlsx`,
      );
    } catch {
      setExportError(true);
    }
  };
  const toggle = (value: string) =>
    setDraft({
      ...form,
      objectives: form.objectives.includes(value)
        ? form.objectives.filter((item) => item !== value)
        : [...form.objectives, value],
    });

  return (
    <Card variant="outlined">
      <CardContent>
        <Stack spacing={2}>
          <Typography variant="h6" fontWeight={750}>
            Évaluation TISAX
          </Typography>
          <Alert severity="info">
            La maturité attendue par défaut est{" "}
            {summary.data?.defaultTarget ?? 3} sur 5 pour chaque exigence
            évaluée. Ce suivi prépare l’évaluation : le label TISAX est délivré
            par un prestataire d’audit reconnu.
          </Alert>
          <Stack direction={{ xs: "column", md: "row" }} spacing={2}>
            <TextField
              select
              fullWidth
              label="Niveau d’évaluation"
              value={form.level}
              disabled={!canEdit}
              onChange={(event) =>
                setDraft({ ...form, level: event.target.value })
              }
            >
              <MenuItem value="">Non renseigné</MenuItem>
              <MenuItem value="AL2">AL2 — plausibilité des preuves</MenuItem>
              <MenuItem value="AL3">
                AL3 — audit sur site et entretiens
              </MenuItem>
            </TextField>
            <TextField
              fullWidth
              label="Périmètre et sites"
              value={form.siteScope}
              disabled={!canEdit}
              slotProps={{ htmlInput: { maxLength: 255 } }}
              onChange={(event) =>
                setDraft({ ...form, siteScope: event.target.value })
              }
            />
            <TextField
              fullWidth
              label="Identifiant de participant ENX"
              value={form.participantId}
              disabled={!canEdit}
              slotProps={{ htmlInput: { maxLength: 40 } }}
              onChange={(event) =>
                setDraft({ ...form, participantId: event.target.value })
              }
            />
          </Stack>
          <FormGroup row aria-label="Objectifs d’évaluation TISAX">
            {objectives.map(([value, label]) => (
              <FormControlLabel
                key={value}
                label={label}
                control={
                  <Checkbox
                    checked={form.objectives.includes(value)}
                    disabled={!canEdit}
                    onChange={() => toggle(value)}
                  />
                }
              />
            ))}
          </FormGroup>
          <Stack direction="row" spacing={1}>
            {canEdit && (
              <Button
                variant="contained"
                disabled={draft === null || save.isPending}
                onClick={() => save.mutate()}
              >
                Enregistrer la déclaration TISAX
              </Button>
            )}
            <Button variant="outlined" onClick={() => void exportWorkbook()}>
              Exporter l’évaluation TISAX (Excel)
            </Button>
          </Stack>
          {save.isError && (
            <Alert severity="error">
              La déclaration TISAX n’a pas pu être enregistrée : vérifiez le
              niveau, les objectifs et l’identifiant.
            </Alert>
          )}
          {exportError && (
            <Alert severity="error">
              L’export TISAX n’a pas pu être généré.
            </Alert>
          )}
          {summary.data && (
            <>
              <Alert
                severity={
                  summary.data.ready
                    ? "success"
                    : summary.data.totals.blocking
                      ? "error"
                      : "warning"
                }
              >
                {summary.data.ready
                  ? "Aucun écart de maturité ni exigence incomplète : prêt pour l’évaluation."
                  : `${summary.data.totals.blocking} écart(s) de maturité et ${summary.data.totals.incomplete} exigence(s) non évaluée(s)${summary.data.declarationComplete ? "" : " ; la déclaration (niveau, objectifs, périmètre) est incomplète"}.`}
              </Alert>
              <Table
                size="small"
                aria-label="Maturité TISAX par module"
                sx={{ minWidth: 520 }}
              >
                <TableHead>
                  <TableRow>
                    <TableCell>Module</TableCell>
                    <TableCell>Exigences</TableCell>
                    <TableCell>Atteintes</TableCell>
                    <TableCell>Écarts</TableCell>
                    <TableCell>Non évaluées</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {summary.data.modules.map((module) => (
                    <TableRow key={module.name}>
                      <TableCell>
                        {module.name}
                        {module.gaps.slice(0, 5).map((gap) => (
                          <Chip
                            key={gap.resultId}
                            size="small"
                            sx={{ ml: 0.5 }}
                            color="error"
                            variant="outlined"
                            label={`${gap.reference} ${gap.maturity}/${gap.target}`}
                          />
                        ))}
                      </TableCell>
                      <TableCell>{module.total}</TableCell>
                      <TableCell>{module.meets}</TableCell>
                      <TableCell>{module.blocking}</TableCell>
                      <TableCell>{module.incomplete}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </>
          )}
        </Stack>
      </CardContent>
    </Card>
  );
}
