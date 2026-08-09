import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AddOutlined, CheckOutlined, CloseOutlined } from "@mui/icons-material";
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
  FormControlLabel,
  MenuItem,
  Stack,
  Switch,
  Tab,
  Tabs,
  TextField,
  Typography,
} from "@mui/material";
import { useState, type FormEvent } from "react";
import { api } from "../api/client";
import { useAuth } from "../auth/useAuth";
import { RecordDetails } from "../components/RecordDetails";

type Proposal = {
  id: number;
  kind: string;
  proposal: Record<string, unknown>;
  sources: Array<{ type: string; id: number; label: string }>;
  status: string;
  sourceCoverage: number;
  appliedAutomatically: boolean;
  requestedBy: number;
};
type LibraryItem = {
  id: number;
  key: string;
  kind: string;
  title: string;
  version: number;
  status: string;
  content: Record<string, unknown>;
  dependencies: Array<{ key: string; minVersion: number }>;
  ownerId: number;
  supersedesId: number | null;
};

const assistantKinds = [
  "MAPPING_SUGGESTION",
  "GAP_SUMMARY",
  "REPORT_DRAFT",
  "QUESTION_SUGGESTIONS",
];
const libraryKinds = [
  "RISK_SCENARIO",
  "ASSET",
  "THREAT",
  "VULNERABILITY",
  "CONTROL",
  "QUESTIONNAIRE",
  "REPORT_TEMPLATE",
];

export function ExperimentsPage() {
  const { user } = useAuth();
  const client = useQueryClient();
  const [tab, setTab] = useState<"assistant" | "library">("assistant");
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [proposalForm, setProposalForm] = useState({
    kind: "GAP_SUMMARY",
    context: "{}",
  });
  const [libraryForm, setLibraryForm] = useState({
    key: "",
    kind: "CONTROL",
    title: "",
    content: "{}",
    dependencies: "[]",
    source: "",
    license: "",
  });
  const isAdmin = user?.roles.some((role) =>
    ["ROLE_ADMIN", "ROLE_SUPER_ADMIN"].includes(role),
  );
  const canContribute = user?.roles.some((role) =>
    ["ROLE_RISK_MANAGER", "ROLE_ADMIN", "ROLE_SUPER_ADMIN"].includes(role),
  );
  const settings = useQuery({
    queryKey: ["experiment-settings"],
    enabled: tab === "assistant",
    queryFn: async () =>
      (
        await api.get<{
          assistantEnabled: boolean;
          allowedKinds: string[];
          automaticDecisions: boolean;
        }>("/experiments/settings")
      ).data,
  });
  const proposals = useQuery({
    queryKey: ["assistant-proposals"],
    enabled: tab === "assistant",
    queryFn: async () =>
      (await api.get<{ items: Proposal[] }>("/experiments/assistant/proposals"))
        .data.items,
  });
  const library = useQuery({
    queryKey: ["knowledge-library"],
    enabled: tab === "library",
    queryFn: async () =>
      (
        await api.get<{ items: LibraryItem[] }>(
          "/experiments/library?limit=100",
        )
      ).data.items,
  });
  const updateSettings = useMutation({
    mutationFn: (enabled: boolean) =>
      api.put("/experiments/settings", {
        assistantEnabled: enabled,
        allowedKinds: assistantKinds,
      }),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: ["experiment-settings"] }),
  });
  const createProposal = useMutation({
    mutationFn: (context: Record<string, unknown>) =>
      api.post("/experiments/assistant/proposals", {
        kind: proposalForm.kind,
        context,
      }),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["assistant-proposals"] });
      setOpen(false);
    },
  });
  const validate = useMutation({
    mutationFn: ({ id, decision }: { id: number; decision: string }) =>
      api.post(`/experiments/assistant/proposals/${id}/validate`, {
        decision,
        comment: "Validation humaine depuis l’interface P3",
      }),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: ["assistant-proposals"] }),
  });
  const createLibrary = useMutation({
    mutationFn: (payload: Record<string, unknown>) =>
      api.post("/experiments/library", payload),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["knowledge-library"] });
      setOpen(false);
    },
  });
  const transitionLibrary = useMutation({
    mutationFn: ({ id, action }: { id: number; action: string }) =>
      api.post(`/experiments/library/${id}/${action}`),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: ["knowledge-library"] }),
  });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    try {
      setError(null);
      if (tab === "assistant")
        createProposal.mutate(
          JSON.parse(proposalForm.context) as Record<string, unknown>,
        );
      else
        createLibrary.mutate({
          key: libraryForm.key,
          kind: libraryForm.kind,
          title: libraryForm.title,
          content: JSON.parse(libraryForm.content),
          dependencies: JSON.parse(libraryForm.dependencies),
          source: libraryForm.source || null,
          license: libraryForm.license || null,
        });
    } catch {
      setError("La configuration JSON n’est pas valide.");
    }
  };
  const failed =
    settings.isError ||
    proposals.isError ||
    library.isError ||
    updateSettings.isError ||
    createProposal.isError ||
    validate.isError ||
    createLibrary.isError ||
    transitionLibrary.isError;

  return (
    <Stack spacing={3}>
      <div>
        <Typography variant="h4" fontWeight={800}>
          Expérimentations sous contrôle
        </Typography>
        <Typography color="text.secondary">
          Propositions sourcées et bibliothèque versionnée, sans décision
          automatique
        </Typography>
      </div>
      <Alert severity="warning">
        Aucune proposition ne modifie un risque, un contrôle ou un résultat. Une
        validation humaine reste obligatoire.
      </Alert>
      <Card>
        <Tabs value={tab} onChange={(_, value) => setTab(value)}>
          <Tab value="assistant" label="Assistant contrôlé" />
          <Tab value="library" label="Bibliothèque interne" />
        </Tabs>
      </Card>
      {(failed || error) && (
        <Alert severity="error">
          {error ?? "L’opération n’a pas pu être terminée."}
        </Alert>
      )}
      {((tab === "assistant" && (settings.isLoading || proposals.isLoading)) ||
        (tab === "library" && library.isLoading)) && (
        <Stack alignItems="center" py={4}>
          <CircularProgress aria-label="Chargement des expérimentations" />
        </Stack>
      )}
      {tab === "assistant" && (
        <>
          <Card>
            <CardContent>
              <Stack
                direction={{ xs: "column", sm: "row" }}
                justifyContent="space-between"
                alignItems={{ sm: "center" }}
                gap={2}
              >
                <div>
                  <Typography fontWeight={750}>
                    Assistant tenant-scoped
                  </Typography>
                  <Typography variant="body2">
                    Décisions automatiques : désactivées
                  </Typography>
                </div>
                {isAdmin && (
                  <FormControlLabel
                    control={
                      <Switch
                        checked={settings.data?.assistantEnabled ?? false}
                        onChange={(_, checked) =>
                          updateSettings.mutate(checked)
                        }
                      />
                    }
                    label="Activer l’expérimentation"
                  />
                )}
              </Stack>
            </CardContent>
          </Card>
          {canContribute && (
            <Button
              startIcon={<AddOutlined />}
              variant="contained"
              sx={{ alignSelf: "flex-start" }}
              disabled={!settings.data?.assistantEnabled}
              onClick={() => setOpen(true)}
            >
              Créer une proposition
            </Button>
          )}
          {proposals.data?.map((item) => (
            <Card key={item.id}>
              <CardContent>
                <Stack spacing={1}>
                  <Stack direction="row" justifyContent="space-between">
                    <Typography fontWeight={750}>{item.kind}</Typography>
                    <Chip label={item.status} />
                  </Stack>
                  <RecordDetails details={item.proposal} />
                  <Typography variant="body2">
                    Sources visibles : {item.sources.length} · couverture{" "}
                    {Math.round(item.sourceCoverage * 100)}%
                  </Typography>
                  {item.sources.map((source) => (
                    <Chip
                      key={`${source.type}-${source.id}`}
                      label={`${source.type} #${source.id} — ${source.label}`}
                    />
                  ))}
                  {item.status === "PENDING" &&
                    canContribute &&
                    item.requestedBy !== user?.id && (
                      <Stack direction="row" gap={1}>
                        <Button
                          startIcon={<CheckOutlined />}
                          onClick={() =>
                            validate.mutate({
                              id: item.id,
                              decision: "APPROVED",
                            })
                          }
                        >
                          Approuver
                        </Button>
                        <Button
                          color="error"
                          startIcon={<CloseOutlined />}
                          onClick={() =>
                            validate.mutate({
                              id: item.id,
                              decision: "REJECTED",
                            })
                          }
                        >
                          Rejeter
                        </Button>
                      </Stack>
                    )}
                </Stack>
              </CardContent>
            </Card>
          ))}
          {proposals.data?.length === 0 && (
            <Alert severity="info">Aucune proposition enregistrée.</Alert>
          )}
        </>
      )}
      {tab === "library" && (
        <>
          {canContribute && (
            <Button
              startIcon={<AddOutlined />}
              variant="contained"
              sx={{ alignSelf: "flex-start" }}
              onClick={() => setOpen(true)}
            >
              Créer une ressource
            </Button>
          )}
          {library.data?.map((item) => (
            <Card key={item.id}>
              <CardContent>
                <Stack spacing={1}>
                  <Stack direction="row" justifyContent="space-between">
                    <div>
                      <Typography fontWeight={750}>{item.title}</Typography>
                      <Typography variant="body2">
                        {item.key} · v{item.version} · {item.kind}
                      </Typography>
                    </div>
                    <Chip label={item.status} />
                  </Stack>
                  <RecordDetails details={item.content} />
                  {item.supersedesId && (
                    <Typography variant="body2">
                      Remplace la version #{item.supersedesId} sans la modifier.
                    </Typography>
                  )}
                  <Stack direction="row" gap={1}>
                    {item.status === "DRAFT" && (
                      <Button
                        onClick={() =>
                          transitionLibrary.mutate({
                            id: item.id,
                            action: "submit",
                          })
                        }
                      >
                        Soumettre
                      </Button>
                    )}
                    {item.status === "IN_REVIEW" &&
                      isAdmin &&
                      item.ownerId !== user?.id && (
                        <Button
                          onClick={() =>
                            transitionLibrary.mutate({
                              id: item.id,
                              action: "approve",
                            })
                          }
                        >
                          Approuver
                        </Button>
                      )}
                    {item.status === "APPROVED" && isAdmin && (
                      <Button
                        color="warning"
                        onClick={() =>
                          transitionLibrary.mutate({
                            id: item.id,
                            action: "retire",
                          })
                        }
                      >
                        Retirer
                      </Button>
                    )}
                  </Stack>
                </Stack>
              </CardContent>
            </Card>
          ))}
          {library.data?.length === 0 && (
            <Alert severity="info">
              Aucune ressource dans la bibliothèque.
            </Alert>
          )}
        </>
      )}
      <Dialog
        open={open}
        onClose={() => setOpen(false)}
        fullWidth
        maxWidth="md"
      >
        <form onSubmit={submit}>
          <DialogTitle>
            {tab === "assistant"
              ? "Nouvelle proposition"
              : "Nouvelle ressource"}
          </DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ mt: 1 }}>
              {tab === "assistant" ? (
                <>
                  <TextField
                    select
                    label="Type de proposition"
                    value={proposalForm.kind}
                    onChange={(event) =>
                      setProposalForm({
                        ...proposalForm,
                        kind: event.target.value,
                      })
                    }
                  >
                    {assistantKinds.map((kind) => (
                      <MenuItem key={kind} value={kind}>
                        {kind}
                      </MenuItem>
                    ))}
                  </TextField>
                  <TextField
                    multiline
                    minRows={8}
                    label="Contexte JSON"
                    value={proposalForm.context}
                    onChange={(event) =>
                      setProposalForm({
                        ...proposalForm,
                        context: event.target.value,
                      })
                    }
                  />
                </>
              ) : (
                <>
                  <TextField
                    required
                    label="Clé stable"
                    value={libraryForm.key}
                    onChange={(event) =>
                      setLibraryForm({
                        ...libraryForm,
                        key: event.target.value,
                      })
                    }
                  />
                  <TextField
                    select
                    label="Type de ressource"
                    value={libraryForm.kind}
                    onChange={(event) =>
                      setLibraryForm({
                        ...libraryForm,
                        kind: event.target.value,
                      })
                    }
                  >
                    {libraryKinds.map((kind) => (
                      <MenuItem key={kind} value={kind}>
                        {kind}
                      </MenuItem>
                    ))}
                  </TextField>
                  <TextField
                    required
                    label="Titre"
                    value={libraryForm.title}
                    onChange={(event) =>
                      setLibraryForm({
                        ...libraryForm,
                        title: event.target.value,
                      })
                    }
                  />
                  <TextField
                    multiline
                    minRows={8}
                    label="Contenu JSON"
                    value={libraryForm.content}
                    onChange={(event) =>
                      setLibraryForm({
                        ...libraryForm,
                        content: event.target.value,
                      })
                    }
                  />
                  <TextField
                    multiline
                    label="Dépendances JSON"
                    value={libraryForm.dependencies}
                    onChange={(event) =>
                      setLibraryForm({
                        ...libraryForm,
                        dependencies: event.target.value,
                      })
                    }
                  />
                  <TextField
                    label="Source"
                    value={libraryForm.source}
                    onChange={(event) =>
                      setLibraryForm({
                        ...libraryForm,
                        source: event.target.value,
                      })
                    }
                  />
                  <TextField
                    label="Licence"
                    value={libraryForm.license}
                    onChange={(event) =>
                      setLibraryForm({
                        ...libraryForm,
                        license: event.target.value,
                      })
                    }
                  />
                </>
              )}
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setOpen(false)}>Annuler</Button>
            <Button type="submit" variant="contained">
              Créer
            </Button>
          </DialogActions>
        </form>
      </Dialog>
    </Stack>
  );
}
