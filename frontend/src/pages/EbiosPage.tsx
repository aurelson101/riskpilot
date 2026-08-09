import {
  Alert,
  Button,
  Card,
  CardContent,
  Chip,
  MenuItem,
  Stack,
  Tab,
  Tabs,
  TextField,
  Typography,
} from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useMemo, useState } from "react";
import { api } from "../api/client";

type Workshop = {
  number: number;
  status: string;
  version: number;
  payload: Record<string, unknown>;
  missingFields: string[];
};
type Analysis = {
  id: number;
  title: string;
  version: number;
  status: string;
  workshops: Workshop[];
};
const definitions = [
  {
    title: "Atelier 1 — Cadrage et socle",
    fields: [
      "context",
      "businessValues",
      "supportingAssets",
      "dreadedEvents",
      "securityBaseline",
    ],
  },
  {
    title: "Atelier 2 — Sources de risque",
    fields: ["riskSources", "targetObjectives"],
  },
  {
    title: "Atelier 3 — Scénarios stratégiques",
    fields: ["ecosystem", "strategicScenarios"],
  },
  {
    title: "Atelier 4 — Scénarios opérationnels",
    fields: ["operationalScenarios"],
  },
  {
    title: "Atelier 5 — Traitement du risque",
    fields: ["riskTreatments", "residualRisks"],
  },
];
const labels: Record<string, string> = {
  context: "Contexte et périmètre",
  businessValues: "Valeurs métier",
  supportingAssets: "Biens supports",
  dreadedEvents: "Événements redoutés",
  securityBaseline: "Socle de sécurité",
  riskSources: "Sources de risque",
  targetObjectives: "Objectifs visés",
  ecosystem: "Écosystème et parties prenantes",
  strategicScenarios: "Scénarios stratégiques",
  operationalScenarios: "Scénarios opérationnels et étapes",
  riskTreatments: "Mesures et plans de traitement",
  residualRisks: "Risques résiduels",
};

export function EbiosPage() {
  const client = useQueryClient();
  const [analysisId, setAnalysisId] = useState(0);
  const [tab, setTab] = useState(0);
  const analyses = useQuery({
    queryKey: ["ebios-analyses"],
    queryFn: async () => (await api.get<Analysis[]>("/v1/ebios/analyses")).data,
  });
  const selected =
    analyses.data?.find((item) => item.id === analysisId) ?? analyses.data?.[0];
  const workshop = selected?.workshops.find((item) => item.number === tab + 1);
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const values = useMemo(
    () =>
      Object.fromEntries(
        definitions[tab].fields.map((field) => [
          field,
          drafts[`${selected?.id}-${tab}-${field}`] ??
            (Array.isArray(workshop?.payload[field])
              ? (workshop!.payload[field] as unknown[]).join("\n")
              : String(workshop?.payload[field] ?? "")),
        ]),
      ),
    [drafts, selected?.id, tab, workshop],
  );
  const save = useMutation({
    mutationFn: () =>
      api.put(`/v1/ebios/analyses/${selected?.id}/workshops/${tab + 1}`, {
        payload: Object.fromEntries(
          Object.entries(values).map(([key, value]) => [
            key,
            value.includes("\n")
              ? value
                  .split("\n")
                  .map((line) => line.trim())
                  .filter(Boolean)
              : value,
          ]),
        ),
      }),
    onSuccess: async () =>
      client.invalidateQueries({ queryKey: ["ebios-analyses"] }),
  });
  const validate = useMutation({
    mutationFn: () =>
      api.post(
        `/v1/ebios/analyses/${selected?.id}/workshops/${tab + 1}/validate`,
      ),
    onSuccess: async () =>
      client.invalidateQueries({ queryKey: ["ebios-analyses"] }),
  });
  if (analyses.isError)
    return (
      <Alert severity="error">
        Impossible de charger les analyses EBIOS RM.
      </Alert>
    );
  return (
    <Stack spacing={3}>
      <div>
        <Typography variant="h4" fontWeight={800}>
          EBIOS Risk Manager
        </Typography>
        <Typography color="text.secondary">
          Workflow gouverné des cinq ateliers, avec validation indépendante
        </Typography>
      </div>
      {!analyses.data?.length ? (
        <Alert severity="info">
          Créez d’abord une analyse EBIOS RM dans Analyses et capitalisation.
        </Alert>
      ) : (
        <>
          <TextField
            select
            label="Analyse"
            value={selected?.id ?? ""}
            onChange={(event) => setAnalysisId(Number(event.target.value))}
          >
            {analyses.data.map((item) => (
              <MenuItem key={item.id} value={item.id}>
                {item.title} · v{item.version}
              </MenuItem>
            ))}
          </TextField>
          <Tabs
            value={tab}
            onChange={(_, value) => setTab(value)}
            variant="scrollable"
          >
            {definitions.map((item, index) => (
              <Tab key={item.title} label={`Atelier ${index + 1}`} />
            ))}
          </Tabs>
          <Card>
            <CardContent>
              <Stack spacing={2}>
                <Stack direction="row" justifyContent="space-between">
                  <Typography variant="h6">{definitions[tab].title}</Typography>
                  <Chip
                    label={workshop?.status ?? "DRAFT"}
                    color={
                      workshop?.status === "VALIDATED" ? "success" : "default"
                    }
                  />
                </Stack>
                {definitions[tab].fields.map((field) => (
                  <TextField
                    key={field}
                    label={labels[field]}
                    multiline
                    minRows={field === "context" ? 3 : 4}
                    disabled={workshop?.status === "VALIDATED"}
                    value={values[field]}
                    helperText={
                      field === "context"
                        ? "Texte structuré"
                        : "Un élément par ligne"
                    }
                    onChange={(event) =>
                      setDrafts((current) => ({
                        ...current,
                        [`${selected?.id}-${tab}-${field}`]: event.target.value,
                      }))
                    }
                  />
                ))}
                {(save.isError || validate.isError) && (
                  <Alert severity="error">
                    L’atelier est incomplet ou l’opération n’est pas autorisée.
                  </Alert>
                )}
                <Stack direction="row" spacing={2}>
                  <Button
                    variant="contained"
                    disabled={
                      workshop?.status === "VALIDATED" || save.isPending
                    }
                    onClick={() => save.mutate()}
                  >
                    Enregistrer l’atelier
                  </Button>
                  <Button
                    variant="outlined"
                    disabled={
                      !workshop ||
                      workshop.status !== "READY" ||
                      validate.isPending
                    }
                    onClick={() => validate.mutate()}
                  >
                    Valider indépendamment
                  </Button>
                </Stack>
              </Stack>
            </CardContent>
          </Card>
        </>
      )}
    </Stack>
  );
}
