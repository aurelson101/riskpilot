import { AddOutlined } from "@mui/icons-material";
import {
  Alert,
  Button,
  Card,
  CardContent,
  Chip,
  MenuItem,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { api } from "../api/client";
import { useAuth } from "../auth/useAuth";
type Analysis = {
  id: number;
  key: string;
  version: number;
  method: string;
  title: string;
  status: string;
  completeness: number;
  qualityFindings: string[];
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
    payload: "{}",
  });
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
        ...artifact,
        payload: JSON.parse(artifact.payload),
        idempotencyKey: crypto.randomUUID(),
      }),
  });
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
      {(create.isError || add.isError) && (
        <Alert severity="error">L’opération n’a pas pu être terminée.</Alert>
      )}
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
                </div>
                <Chip label={a.status} />
              </Stack>
            </CardContent>
          </Card>
        ))}
      </Stack>
      {selected && (
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
                minRows={5}
                label="Données JSON"
                value={artifact.payload}
                onChange={(e) =>
                  setArtifact({ ...artifact, payload: e.target.value })
                }
              />
              <Button
                variant="contained"
                disabled={!artifact.title || add.isPending}
                onClick={() => add.mutate()}
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
