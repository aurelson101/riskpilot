import { useState } from "react";
import {
  Alert,
  Button,
  Card,
  CardContent,
  Chip,
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
import { Link } from "react-router-dom";
import { api } from "../../api/client";
import { useAuth } from "../../auth/useAuth";

type StepStatus =
  "DONE" | "PENDING" | "DUE_SOON" | "OVERDUE" | "NOT_APPLICABLE";
type Step = {
  key: "EARLY_WARNING" | "NOTIFICATION" | "FINAL_REPORT";
  article: string;
  dueAt: string;
  doneAt: string | null;
  status: StepStatus;
  late: boolean;
  provisional: boolean;
};
type Overview = {
  entity: {
    type: string | null;
    sector: string | null;
    authority: string | null;
  };
  summary: {
    significantOpen: number;
    overdue: number;
    dueSoon: number;
    criticalSuppliers: number;
  };
  incidents: Array<{
    id: number;
    title: string;
    severity: string;
    status: string;
    category: string;
    significant: boolean;
    detectedAt: string;
    worstStatus: StepStatus;
    steps: Step[];
  }>;
  criticalSuppliers: Array<{ id: number; name: string; criticality: string }>;
};

const stepLabels: Record<Step["key"], string> = {
  EARLY_WARNING: "Alerte précoce (24 h)",
  NOTIFICATION: "Notification (72 h)",
  FINAL_REPORT: "Rapport final (1 mois)",
};
const statusLabels: Record<StepStatus, string> = {
  DONE: "Déposé",
  PENDING: "À venir",
  DUE_SOON: "Échéance proche",
  OVERDUE: "En retard",
  NOT_APPLICABLE: "Sans objet",
};
const statusColors: Record<
  StepStatus,
  "success" | "default" | "warning" | "error"
> = {
  DONE: "success",
  PENDING: "default",
  DUE_SOON: "warning",
  OVERDUE: "error",
  NOT_APPLICABLE: "default",
};
const entityLabels: Record<string, string> = {
  ESSENTIAL: "Entité essentielle",
  IMPORTANT: "Entité importante",
  NOT_IN_SCOPE: "Hors champ NIS2",
};

const formatDate = (value: string) =>
  new Date(value).toLocaleString("fr-FR", {
    dateStyle: "short",
    timeStyle: "short",
  });

export function Nis2OperationsPanel() {
  const { user } = useAuth();
  const cache = useQueryClient();
  const isAdmin = user?.roles.some((role) =>
    ["ROLE_ADMIN", "ROLE_SUPER_ADMIN"].includes(role),
  );
  const overview = useQuery({
    queryKey: ["nis2-overview"],
    queryFn: async () => (await api.get<Overview>("/nis2/overview")).data,
  });
  const [draft, setDraft] = useState<Overview["entity"] | null>(null);
  const entity = draft ?? overview.data?.entity ?? null;
  const save = useMutation({
    mutationFn: async () => (await api.put("/nis2/entity", entity)).data,
    onSuccess: async () => {
      setDraft(null);
      await cache.invalidateQueries({ queryKey: ["nis2-overview"] });
    },
  });
  const [downloadError, setDownloadError] = useState(false);
  const downloadReport = async (id: number) => {
    setDownloadError(false);
    try {
      const response = await api.get<Blob>(`/nis2/incidents/${id}/report`, {
        responseType: "blob",
      });
      const url = URL.createObjectURL(response.data);
      const link = document.createElement("a");
      link.href = url;
      link.download = `nis2-incident-${id}.md`;
      link.click();
      URL.revokeObjectURL(url);
    } catch {
      setDownloadError(true);
    }
  };

  if (overview.isError)
    return (
      <Alert severity="error">
        Impossible de charger le suivi opérationnel NIS2.
      </Alert>
    );
  if (!overview.data || !entity) return null;
  const { summary, incidents, criticalSuppliers } = overview.data;

  return (
    <Stack spacing={2}>
      <Card>
        <CardContent>
          <Stack spacing={2}>
            <Typography variant="h6" fontWeight={750}>
              Classification de l’entité
            </Typography>
            {!entity.type && (
              <Alert severity="warning">
                La classification NIS2 n’est pas renseignée. Elle détermine les
                obligations applicables (entité essentielle, importante ou hors
                champ).
              </Alert>
            )}
            <Stack direction={{ xs: "column", md: "row" }} spacing={2}>
              <TextField
                select
                fullWidth
                label="Type d’entité"
                value={entity.type ?? ""}
                disabled={!isAdmin}
                onChange={(event) =>
                  setDraft({ ...entity, type: event.target.value || null })
                }
              >
                <MenuItem value="">Non renseigné</MenuItem>
                {Object.entries(entityLabels).map(([value, label]) => (
                  <MenuItem key={value} value={value}>
                    {label}
                  </MenuItem>
                ))}
              </TextField>
              <TextField
                fullWidth
                label="Secteur d’activité"
                value={entity.sector ?? ""}
                disabled={!isAdmin}
                slotProps={{ htmlInput: { maxLength: 120 } }}
                onChange={(event) =>
                  setDraft({ ...entity, sector: event.target.value })
                }
              />
              <TextField
                fullWidth
                label="Autorité compétente (CSIRT)"
                value={entity.authority ?? ""}
                disabled={!isAdmin}
                slotProps={{ htmlInput: { maxLength: 180 } }}
                onChange={(event) =>
                  setDraft({ ...entity, authority: event.target.value })
                }
              />
            </Stack>
            {isAdmin && (
              <div>
                <Button
                  variant="contained"
                  disabled={draft === null || save.isPending}
                  onClick={() => save.mutate()}
                >
                  Enregistrer la classification
                </Button>
              </div>
            )}
            {save.isError && (
              <Alert severity="error">
                La classification n’a pas pu être enregistrée.
              </Alert>
            )}
          </Stack>
        </CardContent>
      </Card>

      <Card>
        <CardContent sx={{ overflowX: "auto" }}>
          <Stack spacing={2}>
            <Typography variant="h6" fontWeight={750}>
              Incidents significatifs et délais de notification (article 23)
            </Typography>
            <Alert
              severity={
                summary.overdue ? "error" : summary.dueSoon ? "warning" : "info"
              }
            >
              {summary.overdue
                ? `${summary.overdue} incident(s) significatif(s) avec une déclaration en retard.`
                : summary.dueSoon
                  ? `${summary.dueSoon} incident(s) avec une échéance proche.`
                  : `${summary.significantOpen} incident(s) significatif(s) ouvert(s), aucun retard.`}{" "}
              Les délais courent depuis la prise de connaissance de l’incident ;
              l’autorité nationale peut imposer des règles plus strictes.
            </Alert>
            {downloadError && (
              <Alert severity="error">
                La trame de notification n’a pas pu être téléchargée.
              </Alert>
            )}
            {incidents.length === 0 ? (
              <Alert severity="info">
                Aucun incident significatif ou ouvert à suivre. Qualifiez un
                incident de « significatif » dans Incidents et continuité pour
                activer le calendrier.
              </Alert>
            ) : (
              <Table
                aria-label="Délais de notification NIS2"
                sx={{ minWidth: 760 }}
              >
                <TableHead>
                  <TableRow>
                    <TableCell>Incident</TableCell>
                    {(Object.keys(stepLabels) as Step["key"][]).map((key) => (
                      <TableCell key={key}>{stepLabels[key]}</TableCell>
                    ))}
                    <TableCell>Trame</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {incidents.map((incident) => (
                    <TableRow key={incident.id}>
                      <TableCell>
                        <strong>{incident.title}</strong>
                        <br />
                        <Typography variant="caption" color="text.secondary">
                          Détecté le {formatDate(incident.detectedAt)} ·{" "}
                          {incident.significant
                            ? "significatif"
                            : "non significatif"}
                        </Typography>
                      </TableCell>
                      {incident.steps.map((step) => (
                        <TableCell key={step.key}>
                          <Chip
                            size="small"
                            color={statusColors[step.status]}
                            label={
                              step.late
                                ? `${statusLabels[step.status]} (hors délai)`
                                : statusLabels[step.status]
                            }
                          />
                          <br />
                          <Typography variant="caption" color="text.secondary">
                            {step.status === "DONE" && step.doneAt
                              ? `le ${formatDate(step.doneAt)}`
                              : step.status === "NOT_APPLICABLE"
                                ? "—"
                                : `${step.provisional ? "au plus tard " : "avant le "}${formatDate(step.dueAt)}`}
                          </Typography>
                        </TableCell>
                      ))}
                      <TableCell>
                        <Button
                          size="small"
                          onClick={() => void downloadReport(incident.id)}
                        >
                          Télécharger
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </Stack>
        </CardContent>
      </Card>

      <Card>
        <CardContent>
          <Stack spacing={1}>
            <Typography variant="h6" fontWeight={750}>
              Chaîne d’approvisionnement (article 21.2 d)
            </Typography>
            <Typography color="text.secondary">
              {summary.criticalSuppliers} fournisseur(s) de criticité élevée ou
              critique à couvrir dans l’évaluation de la sécurité de la chaîne
              d’approvisionnement.
            </Typography>
            <Stack direction="row" gap={1} flexWrap="wrap">
              {criticalSuppliers.map((supplier) => (
                <Chip
                  key={supplier.id}
                  label={`${supplier.name} · ${supplier.criticality}`}
                />
              ))}
            </Stack>
            <div>
              <Button component={Link} to="/third-parties" size="small">
                Ouvrir les tiers et fournisseurs
              </Button>
            </div>
          </Stack>
        </CardContent>
      </Card>
    </Stack>
  );
}
