import { AddOutlined } from "@mui/icons-material";
import {
  Alert,
  Button,
  Card,
  CardContent,
  Chip,
  LinearProgress,
  MenuItem,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { api } from "../api/client";
import { useAuth } from "../auth/useAuth";
import { hasAnyRole } from "../auth/roles";
type Analysis = {
  id: number;
  key: string;
  version: number;
  method: string;
  title: string;
  status: string;
  completeness: number;
  qualityFindings: string[];
  createdById: number;
};
const findingLabels: Record<string, string> = {
  OBJECTIVES_REQUIRED: "Définir les objectifs de l’analyse",
  TEAM_REQUIRED: "Ajouter une équipe responsable",
  SCENARIOS_REQUIRED: "Associer au moins un scénario de risque",
};
const artifactKinds = [
  "METHOD_STEP",
  "EVIDENCE",
  "CONTROL_EFFECTIVENESS",
  "TREATMENT_SCENARIO",
  "ROADMAP_OPTION",
  "ACL_GRANT",
  "ACTIVITY",
  "IMPORT_BATCH",
  "LIBRARY_UPDATE",
  "SUPPLIER_TIER",
  "PRODUCT_METRIC",
];
export function AnalysisWorkspacePage() {
  const { user } = useAuth();
  const canManage = hasAnyRole(user?.roles, [
    "ROLE_SUPER_ADMIN",
    "ROLE_ADMIN",
    "ROLE_RISK_MANAGER",
  ]);
  const canApprove = hasAnyRole(user?.roles, [
    "ROLE_SUPER_ADMIN",
    "ROLE_ADMIN",
  ]);
  const qc = useQueryClient();
  const [selected, setSelected] = useState<number | null>(null);
  const [form, setForm] = useState({
    key: "",
    title: "",
    method: "EBIOS_RM",
    scenarioIds: "",
  });
  const [artifact, setArtifact] = useState({
    kind: "METHOD_STEP",
    title: "",
    summary: "",
    evidenceReference: "",
    recommendation: "",
  });
  const [formError, setFormError] = useState<string | null>(null);
  const analyses = useQuery({
    queryKey: ["risk-analyses"],
    queryFn: async () =>
      (
        await api.get<{ items: Analysis[] }>(
          "/analysis-workspace/analyses?limit=100",
        )
      ).data.items,
  });
  const create = useMutation({
    mutationFn: () =>
      api.post("/analysis-workspace/analyses", {
        key: form.key,
        title: form.title,
        method: form.method,
        objectives: ["Protéger le périmètre"],
        team: user?.id ? [user.id] : [],
        milestones: [],
        scenarioIds: form.scenarioIds
          .split(",")
          .map((value) => Number(value.trim()))
          .filter((value) => Number.isInteger(value) && value > 0),
      }),
    onSuccess: async () =>
      qc.invalidateQueries({ queryKey: ["risk-analyses"] }),
  });
  const add = useMutation({
    mutationFn: () =>
      api.post(`/analysis-workspace/analyses/${selected}/artifacts`, {
        kind: artifact.kind,
        title: artifact.title,
        payload: {
          summary: artifact.summary,
          evidenceReference: artifact.evidenceReference || null,
          recommendation: artifact.recommendation || null,
        },
        idempotencyKey: crypto.randomUUID(),
      }),
  });
  const quality = useMutation({
    mutationFn: (id: number) =>
      api.post(`/analysis-workspace/analyses/${id}/quality`),
    onSuccess: async () =>
      qc.invalidateQueries({ queryKey: ["risk-analyses"] }),
  });
  const approve = useMutation({
    mutationFn: (id: number) =>
      api.post(`/analysis-workspace/analyses/${id}/approve`),
    onSuccess: async () =>
      qc.invalidateQueries({ queryKey: ["risk-analyses"] }),
  });
  const selectedAnalysis = analyses.data?.find((item) => item.id === selected);
  const addArtifact = () => {
    setFormError(null);
    add.mutate();
  };
  return (
    <Stack spacing={3}>
      <div>
        <Typography variant="h4" fontWeight={800}>
          Analyses et capitalisation
        </Typography>
        <Typography color="text.secondary">
          Analyses versionnées, méthodes guidées, preuves, simulations, roadmaps
          et qualité
        </Typography>
      </div>
      {(create.isError ||
        add.isError ||
        quality.isError ||
        approve.isError) && (
        <Alert severity="error">L’opération n’a pas pu être terminée.</Alert>
      )}
      {formError && <Alert severity="error">{formError}</Alert>}
      {canManage ? (
        <Card>
          <CardContent>
            <Stack spacing={2}>
              <Typography variant="h6">Nouvelle analyse</Typography>
              <Stack direction={{ xs: "column", md: "row" }} spacing={2}>
                <TextField
                  label="Clé stable"
                  value={form.key}
                  onChange={(e) => setForm({ ...form, key: e.target.value })}
                />
                <TextField
                  label="Titre"
                  value={form.title}
                  onChange={(e) => setForm({ ...form, title: e.target.value })}
                />
                <TextField
                  label="Scénarios (identifiants séparés par des virgules)"
                  value={form.scenarioIds}
                  onChange={(e) =>
                    setForm({ ...form, scenarioIds: e.target.value })
                  }
                />
                <TextField
                  select
                  label="Méthode"
                  value={form.method}
                  onChange={(e) => setForm({ ...form, method: e.target.value })}
                >
                  {["EBIOS_RM", "ISO_27005", "SIMPLIFIED"].map((x) => (
                    <MenuItem key={x} value={x}>
                      {x}
                    </MenuItem>
                  ))}
                </TextField>
                <Button
                  variant="contained"
                  startIcon={<AddOutlined />}
                  disabled={!form.key || !form.title || create.isPending}
                  onClick={() => create.mutate()}
                >
                  Créer l’analyse
                </Button>
              </Stack>
            </Stack>
          </CardContent>
        </Card>
      ) : (
        <Alert severity="info">
          Consultation uniquement : la création et la modification nécessitent
          le rôle de responsable des risques.
        </Alert>
      )}
      <Stack spacing={2}>
        {analyses.data?.map((a) => (
          <Card
            key={a.id}
            variant={selected === a.id ? "outlined" : undefined}
            onClick={() => setSelected(a.id)}
            sx={{ cursor: "pointer" }}
          >
            <CardContent>
              <Stack
                direction={{ xs: "column", sm: "row" }}
                justifyContent="space-between"
              >
                <div>
                  <Typography fontWeight={750}>{a.title}</Typography>
                  <Typography variant="body2">
                    {a.key} · v{a.version} · {a.method} · complétude{" "}
                    {a.completeness}%
                  </Typography>
                  <LinearProgress
                    variant="determinate"
                    value={a.completeness}
                    sx={{ mt: 1, minWidth: { sm: 220 } }}
                  />
                </div>
                <Chip label={a.status} />
              </Stack>
            </CardContent>
          </Card>
        ))}
      </Stack>
      {selectedAnalysis && (
        <Card>
          <CardContent>
            <Stack spacing={2}>
              <Typography variant="h6">Validation de l’analyse</Typography>
              <Typography color="text.secondary">
                Vérifiez la qualité des données avant de figer une baseline
                approuvée et traçable.
              </Typography>
              {selectedAnalysis.qualityFindings.length > 0 && (
                <Alert severity="warning">
                  {selectedAnalysis.qualityFindings.map((finding) => (
                    <div key={finding}>
                      {findingLabels[finding] ??
                        (finding.startsWith("FOREIGN_OR_MISSING_SCENARIO_")
                          ? "Corriger un scénario absent ou inaccessible"
                          : finding)}
                    </div>
                  ))}
                </Alert>
              )}
              <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                {canManage && selectedAnalysis.status === "DRAFT" && (
                  <Button
                    variant="outlined"
                    disabled={quality.isPending}
                    onClick={() => quality.mutate(selectedAnalysis.id)}
                  >
                    Contrôler la qualité
                  </Button>
                )}
                {canApprove &&
                  selectedAnalysis.status === "IN_REVIEW" &&
                  selectedAnalysis.createdById !== user?.id && (
                  <Button
                    variant="contained"
                    disabled={approve.isPending}
                    onClick={() => approve.mutate(selectedAnalysis.id)}
                  >
                    Approuver la baseline
                  </Button>
                )}
                {selectedAnalysis.status === "APPROVED" && (
                  <Chip color="success" label="Baseline approuvée" />
                )}
              </Stack>
            </Stack>
          </CardContent>
        </Card>
      )}
      {selected && canManage && (
        <Card>
          <CardContent>
            <Stack spacing={2}>
              <Typography variant="h6">Ajouter un artefact gouverné</Typography>
              <TextField
                select
                label="Type"
                value={artifact.kind}
                onChange={(e) =>
                  setArtifact({ ...artifact, kind: e.target.value })
                }
              >
                {artifactKinds.map((x) => (
                  <MenuItem key={x} value={x}>
                    {x}
                  </MenuItem>
                ))}
              </TextField>
              <TextField
                label="Titre"
                value={artifact.title}
                onChange={(e) =>
                  setArtifact({ ...artifact, title: e.target.value })
                }
              />
              <TextField
                multiline
                minRows={3}
                label="Synthèse"
                required
                value={artifact.summary}
                onChange={(e) =>
                  setArtifact({ ...artifact, summary: e.target.value })
                }
              />
              <TextField
                label="Référence de preuve"
                value={artifact.evidenceReference}
                onChange={(e) =>
                  setArtifact({
                    ...artifact,
                    evidenceReference: e.target.value,
                  })
                }
              />
              <TextField
                multiline
                minRows={2}
                label="Recommandation"
                value={artifact.recommendation}
                onChange={(e) =>
                  setArtifact({ ...artifact, recommendation: e.target.value })
                }
              />
              <Button
                variant="contained"
                disabled={!artifact.title || !artifact.summary || add.isPending}
                onClick={addArtifact}
              >
                Ajouter l’artefact
              </Button>
            </Stack>
          </CardContent>
        </Card>
      )}
    </Stack>
  );
}
