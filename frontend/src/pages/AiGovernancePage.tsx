import { useState } from "react";
import {
  Alert,
  Button,
  Card,
  CardContent,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  MenuItem,
  Stack,
  Tab,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Tabs,
  TextField,
  Typography,
} from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import axios from "axios";
import { api } from "../api/client";
import { useAuth } from "../auth/useAuth";
import type { User } from "../api/types";

type AiSystem = {
  id: number;
  name: string;
  purpose: string;
  provider: string;
  model: string | null;
  dataCategories: string[];
  riskLevel: "MINIMAL" | "LIMITED" | "HIGH" | "PROHIBITED";
  status: "PLANNED" | "ACTIVE" | "SUSPENDED" | "RETIRED";
  humanOversight: string | null;
  reviewAt: string | null;
  builtIn: boolean;
  owner: { id: number; name: string };
  approvedAssessment: boolean;
};
type Assessment = {
  id: number;
  systemId: number;
  status: "DRAFT" | "IN_REVIEW" | "APPROVED";
  affectedGroups: string;
  benefits: string;
  harms: string;
  mitigations: string;
  residualRisk: "LOW" | "MEDIUM" | "HIGH" | "CRITICAL";
  author: { id: number; name: string };
  reviewer: { id: number; name: string } | null;
  approvedAt: string | null;
};
type MandatoryDocuments = {
  missing: number;
  items: Array<{
    key: string;
    clause: string;
    title: { fr: string; en: string };
    covered: boolean;
    documents: Array<{ id: number; title: string; status: string }>;
  }>;
};

const riskLabels: Record<AiSystem["riskLevel"], string> = {
  MINIMAL: "Minimal",
  LIMITED: "Limité (transparence)",
  HIGH: "Haut risque",
  PROHIBITED: "Inacceptable (interdit)",
};
const riskColors: Record<
  AiSystem["riskLevel"],
  "default" | "info" | "warning" | "error"
> = {
  MINIMAL: "default",
  LIMITED: "info",
  HIGH: "warning",
  PROHIBITED: "error",
};
const statusLabels: Record<AiSystem["status"], string> = {
  PLANNED: "Prévu",
  ACTIVE: "Actif",
  SUSPENDED: "Suspendu",
  RETIRED: "Retiré",
};
const assessmentLabels: Record<Assessment["status"], string> = {
  DRAFT: "Brouillon",
  IN_REVIEW: "En relecture",
  APPROVED: "Approuvée",
};
const residualLabels: Record<Assessment["residualRisk"], string> = {
  LOW: "Faible",
  MEDIUM: "Moyen",
  HIGH: "Élevé",
  CRITICAL: "Critique",
};

const errorText = (error: unknown) =>
  axios.isAxiosError<{ message?: string }>(error)
    ? (error.response?.data?.message ?? "L’opération a échoué.")
    : "L’opération a échoué.";

const emptySystem = {
  name: "",
  purpose: "",
  provider: "",
  model: "",
  dataCategories: "",
  riskLevel: "LIMITED",
  status: "PLANNED",
  humanOversight: "",
  reviewAt: "",
  ownerId: "",
};
const emptyAssessment = {
  affectedGroups: "",
  benefits: "",
  harms: "",
  mitigations: "",
  residualRisk: "MEDIUM",
};

export function AiGovernancePage() {
  const { user } = useAuth();
  const cache = useQueryClient();
  const [tab, setTab] = useState(0);
  const canManage = user?.roles.some((role) =>
    ["ROLE_RISK_MANAGER", "ROLE_ADMIN", "ROLE_SUPER_ADMIN"].includes(role),
  );
  const isAdmin = user?.roles.some((role) =>
    ["ROLE_ADMIN", "ROLE_SUPER_ADMIN"].includes(role),
  );
  const systems = useQuery({
    queryKey: ["ai-systems"],
    queryFn: async () =>
      (await api.get<AiSystem[]>("/ai-governance/systems")).data,
  });
  const users = useQuery({
    queryKey: ["users"],
    enabled: Boolean(canManage),
    queryFn: async () => (await api.get<User[]>("/users")).data,
  });
  const documents = useQuery({
    queryKey: ["ai-mandatory-documents"],
    enabled: tab === 2,
    queryFn: async () =>
      (await api.get<MandatoryDocuments>("/ai-governance/mandatory-documents"))
        .data,
  });
  const [systemDialog, setSystemDialog] = useState<AiSystem | "new" | null>(
    null,
  );
  const [systemForm, setSystemForm] = useState(emptySystem);
  const [selectedSystem, setSelectedSystem] = useState<number | null>(null);
  const assessments = useQuery({
    queryKey: ["ai-assessments", selectedSystem],
    enabled: selectedSystem !== null,
    queryFn: async () =>
      (
        await api.get<Assessment[]>(
          `/ai-governance/systems/${selectedSystem}/impact-assessments`,
        )
      ).data,
  });
  const [assessmentDialog, setAssessmentDialog] = useState<
    Assessment | "new" | null
  >(null);
  const [assessmentForm, setAssessmentForm] = useState(emptyAssessment);

  const refresh = async () => {
    await cache.invalidateQueries({ queryKey: ["ai-systems"] });
    await cache.invalidateQueries({ queryKey: ["ai-assessments"] });
  };
  const saveSystem = useMutation({
    mutationFn: async () => {
      const payload = {
        ...systemForm,
        model: systemForm.model || null,
        dataCategories: systemForm.dataCategories
          .split(",")
          .map((item) => item.trim())
          .filter(Boolean),
        humanOversight: systemForm.humanOversight || null,
        reviewAt: systemForm.reviewAt || null,
        ownerId: Number(systemForm.ownerId || user?.id),
      };
      return systemDialog === "new" || systemDialog === null
        ? api.post("/ai-governance/systems", payload)
        : api.put(`/ai-governance/systems/${systemDialog.id}`, payload);
    },
    onSuccess: async () => {
      setSystemDialog(null);
      await refresh();
    },
  });
  const saveAssessment = useMutation({
    mutationFn: async () =>
      assessmentDialog === "new" || assessmentDialog === null
        ? api.post(
            `/ai-governance/systems/${selectedSystem}/impact-assessments`,
            assessmentForm,
          )
        : api.put(
            `/ai-governance/impact-assessments/${assessmentDialog.id}`,
            assessmentForm,
          ),
    onSuccess: async () => {
      setAssessmentDialog(null);
      await refresh();
    },
  });
  const transition = useMutation({
    mutationFn: ({
      id,
      action,
    }: {
      id: number;
      action: "submit" | "approve";
    }) => api.post(`/ai-governance/impact-assessments/${id}/${action}`),
    onSuccess: refresh,
  });

  const openSystem = (item: AiSystem | "new") => {
    setSystemDialog(item);
    setSystemForm(
      item === "new"
        ? { ...emptySystem, ownerId: String(user?.id ?? "") }
        : {
            name: item.name,
            purpose: item.purpose,
            provider: item.provider,
            model: item.model ?? "",
            dataCategories: item.dataCategories.join(", "),
            riskLevel: item.riskLevel,
            status: item.status,
            humanOversight: item.humanOversight ?? "",
            reviewAt: item.reviewAt ?? "",
            ownerId: String(item.owner.id),
          },
    );
  };
  const openAssessment = (item: Assessment | "new") => {
    setAssessmentDialog(item);
    setAssessmentForm(
      item === "new"
        ? emptyAssessment
        : {
            affectedGroups: item.affectedGroups,
            benefits: item.benefits,
            harms: item.harms,
            mitigations: item.mitigations,
            residualRisk: item.residualRisk,
          },
    );
  };
  const system = systems.data?.find((item) => item.id === selectedSystem);

  return (
    <Stack spacing={3}>
      <div>
        <Typography variant="h4" fontWeight={800}>
          Gouvernance de l’IA
        </Typography>
        <Typography color="text.secondary">
          Registre des systèmes d’IA, évaluations d’impact et informations
          documentées du système de management (ISO/IEC 42001 et 27001)
        </Typography>
      </div>
      <Tabs
        value={tab}
        onChange={(_event, value: number) => setTab(value)}
        variant="scrollable"
      >
        <Tab label="Registre des systèmes d’IA" />
        <Tab label="Évaluations d’impact" />
        <Tab label="Documents obligatoires" />
      </Tabs>

      {tab === 0 && (
        <Card>
          <CardContent sx={{ overflowX: "auto" }}>
            <Stack spacing={2}>
              {canManage && (
                <div>
                  <Button variant="contained" onClick={() => openSystem("new")}>
                    Ajouter un système d’IA
                  </Button>
                </div>
              )}
              {systems.isError && (
                <Alert severity="error">
                  Impossible de charger le registre.
                </Alert>
              )}
              {systems.data?.length === 0 && (
                <Alert severity="info">
                  Aucun système d’IA enregistré. Inventoriez les systèmes
                  développés, achetés ou utilisés par l’organisation.
                </Alert>
              )}
              {!!systems.data?.length && (
                <Table
                  aria-label="Registre des systèmes d’IA"
                  sx={{ minWidth: 760 }}
                >
                  <TableHead>
                    <TableRow>
                      <TableCell>Système</TableCell>
                      <TableCell>Fournisseur</TableCell>
                      <TableCell>Niveau de risque</TableCell>
                      <TableCell>Statut</TableCell>
                      <TableCell>Responsable</TableCell>
                      <TableCell>Évaluation d’impact</TableCell>
                      <TableCell />
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {systems.data.map((item) => (
                      <TableRow key={item.id}>
                        <TableCell>
                          <strong>{item.name}</strong>
                          {item.builtIn && (
                            <Chip
                              size="small"
                              sx={{ ml: 1 }}
                              label="RiskPilot"
                            />
                          )}
                          <br />
                          <Typography variant="caption" color="text.secondary">
                            {item.purpose}
                          </Typography>
                        </TableCell>
                        <TableCell>
                          {item.provider}
                          {item.model ? ` · ${item.model}` : ""}
                        </TableCell>
                        <TableCell>
                          <Chip
                            size="small"
                            color={riskColors[item.riskLevel]}
                            label={riskLabels[item.riskLevel]}
                          />
                        </TableCell>
                        <TableCell>{statusLabels[item.status]}</TableCell>
                        <TableCell>{item.owner.name}</TableCell>
                        <TableCell>
                          <Chip
                            size="small"
                            color={
                              item.approvedAssessment ? "success" : "warning"
                            }
                            label={
                              item.approvedAssessment
                                ? "Approuvée"
                                : "À réaliser"
                            }
                          />
                        </TableCell>
                        <TableCell>
                          <Stack direction="row" spacing={1}>
                            <Button
                              size="small"
                              onClick={() => {
                                setSelectedSystem(item.id);
                                setTab(1);
                              }}
                            >
                              Évaluations
                            </Button>
                            {canManage && (
                              <Button
                                size="small"
                                onClick={() => openSystem(item)}
                              >
                                Modifier
                              </Button>
                            )}
                          </Stack>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </Stack>
          </CardContent>
        </Card>
      )}

      {tab === 1 && (
        <Card>
          <CardContent sx={{ overflowX: "auto" }}>
            <Stack spacing={2}>
              <TextField
                select
                label="Système d’IA"
                value={selectedSystem ?? ""}
                onChange={(event) =>
                  setSelectedSystem(Number(event.target.value) || null)
                }
              >
                {(systems.data ?? []).map((item) => (
                  <MenuItem key={item.id} value={item.id}>
                    {item.name}
                  </MenuItem>
                ))}
              </TextField>
              {!system && (
                <Alert severity="info">
                  Choisissez un système pour consulter ses évaluations d’impact.
                </Alert>
              )}
              {system && canManage && (
                <div>
                  <Button
                    variant="contained"
                    onClick={() => openAssessment("new")}
                  >
                    Nouvelle évaluation d’impact
                  </Button>
                </div>
              )}
              {transition.isError && (
                <Alert severity="error">{errorText(transition.error)}</Alert>
              )}
              {assessments.data?.map((item) => (
                <Card key={item.id} variant="outlined">
                  <CardContent>
                    <Stack spacing={1}>
                      <Stack
                        direction="row"
                        spacing={1}
                        alignItems="center"
                        flexWrap="wrap"
                      >
                        <Chip
                          size="small"
                          color={
                            item.status === "APPROVED"
                              ? "success"
                              : item.status === "IN_REVIEW"
                                ? "info"
                                : "default"
                          }
                          label={assessmentLabels[item.status]}
                        />
                        <Chip
                          size="small"
                          variant="outlined"
                          label={`Risque résiduel : ${residualLabels[item.residualRisk]}`}
                        />
                        <Typography variant="caption" color="text.secondary">
                          Auteur : {item.author.name}
                          {item.reviewer
                            ? ` · Approuvée par ${item.reviewer.name}`
                            : ""}
                        </Typography>
                      </Stack>
                      <Typography>
                        <strong>Personnes et groupes concernés :</strong>{" "}
                        {item.affectedGroups || "—"}
                      </Typography>
                      <Typography>
                        <strong>Bénéfices :</strong> {item.benefits || "—"}
                      </Typography>
                      <Typography>
                        <strong>Préjudices possibles :</strong>{" "}
                        {item.harms || "—"}
                      </Typography>
                      <Typography>
                        <strong>Mesures d’atténuation :</strong>{" "}
                        {item.mitigations || "—"}
                      </Typography>
                      <Stack direction="row" spacing={1}>
                        {canManage && item.status === "DRAFT" && (
                          <>
                            <Button
                              size="small"
                              onClick={() => openAssessment(item)}
                            >
                              Modifier
                            </Button>
                            <Button
                              size="small"
                              variant="outlined"
                              onClick={() =>
                                transition.mutate({
                                  id: item.id,
                                  action: "submit",
                                })
                              }
                            >
                              Soumettre à relecture
                            </Button>
                          </>
                        )}
                        {isAdmin && item.status === "IN_REVIEW" && (
                          <Button
                            size="small"
                            variant="contained"
                            disabled={item.author.id === user?.id}
                            onClick={() =>
                              transition.mutate({
                                id: item.id,
                                action: "approve",
                              })
                            }
                          >
                            Approuver
                          </Button>
                        )}
                      </Stack>
                      {isAdmin &&
                        item.status === "IN_REVIEW" &&
                        item.author.id === user?.id && (
                          <Typography variant="caption" color="text.secondary">
                            L’approbateur doit être distinct de l’auteur.
                          </Typography>
                        )}
                    </Stack>
                  </CardContent>
                </Card>
              ))}
            </Stack>
          </CardContent>
        </Card>
      )}

      {tab === 2 && (
        <Card>
          <CardContent sx={{ overflowX: "auto" }}>
            <Stack spacing={2}>
              {documents.data && (
                <Alert
                  severity={documents.data.missing ? "warning" : "success"}
                >
                  {documents.data.missing
                    ? `${documents.data.missing} information(s) documentée(s) obligatoire(s) sans document correspondant. La correspondance se fait sur le titre ou la catégorie des documents du SMSI.`
                    : "Toutes les informations documentées obligatoires ont un document correspondant."}
                </Alert>
              )}
              {documents.data && (
                <Table
                  aria-label="Documents obligatoires"
                  sx={{ minWidth: 600 }}
                >
                  <TableHead>
                    <TableRow>
                      <TableCell>Clause</TableCell>
                      <TableCell>Information documentée</TableCell>
                      <TableCell>État</TableCell>
                      <TableCell>Documents</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {documents.data.items.map((item) => (
                      <TableRow key={item.key}>
                        <TableCell>{item.clause}</TableCell>
                        <TableCell>
                          {user?.locale === "en"
                            ? item.title.en
                            : item.title.fr}
                        </TableCell>
                        <TableCell>
                          <Chip
                            size="small"
                            color={item.covered ? "success" : "warning"}
                            label={item.covered ? "Couvert" : "Manquant"}
                          />
                        </TableCell>
                        <TableCell>
                          {item.documents
                            .map((document) => document.title)
                            .join(", ") || "—"}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </Stack>
          </CardContent>
        </Card>
      )}

      <Dialog
        open={systemDialog !== null}
        onClose={() => setSystemDialog(null)}
        fullWidth
        maxWidth="sm"
      >
        <DialogTitle>
          {systemDialog === "new"
            ? "Ajouter un système d’IA"
            : "Modifier le système d’IA"}
        </DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField
              label="Nom"
              required
              value={systemForm.name}
              onChange={(e) =>
                setSystemForm({ ...systemForm, name: e.target.value })
              }
            />
            <TextField
              label="Finalité"
              required
              multiline
              minRows={2}
              value={systemForm.purpose}
              onChange={(e) =>
                setSystemForm({ ...systemForm, purpose: e.target.value })
              }
            />
            <Stack direction="row" spacing={2}>
              <TextField
                fullWidth
                label="Fournisseur"
                required
                disabled={
                  systemDialog !== null &&
                  systemDialog !== "new" &&
                  systemDialog.builtIn
                }
                value={systemForm.provider}
                onChange={(e) =>
                  setSystemForm({ ...systemForm, provider: e.target.value })
                }
              />
              <TextField
                fullWidth
                label="Modèle"
                disabled={
                  systemDialog !== null &&
                  systemDialog !== "new" &&
                  systemDialog.builtIn
                }
                value={systemForm.model}
                onChange={(e) =>
                  setSystemForm({ ...systemForm, model: e.target.value })
                }
              />
            </Stack>
            <TextField
              label="Catégories de données (séparées par des virgules)"
              value={systemForm.dataCategories}
              onChange={(e) =>
                setSystemForm({ ...systemForm, dataCategories: e.target.value })
              }
            />
            <Stack direction="row" spacing={2}>
              <TextField
                select
                fullWidth
                label="Niveau de risque"
                value={systemForm.riskLevel}
                onChange={(e) =>
                  setSystemForm({ ...systemForm, riskLevel: e.target.value })
                }
              >
                {Object.entries(riskLabels).map(([value, label]) => (
                  <MenuItem key={value} value={value}>
                    {label}
                  </MenuItem>
                ))}
              </TextField>
              <TextField
                select
                fullWidth
                label="Statut"
                value={systemForm.status}
                onChange={(e) =>
                  setSystemForm({ ...systemForm, status: e.target.value })
                }
              >
                {Object.entries(statusLabels).map(([value, label]) => (
                  <MenuItem key={value} value={value}>
                    {label}
                  </MenuItem>
                ))}
              </TextField>
            </Stack>
            <TextField
              label="Supervision humaine"
              multiline
              minRows={2}
              value={systemForm.humanOversight}
              onChange={(e) =>
                setSystemForm({ ...systemForm, humanOversight: e.target.value })
              }
              helperText="Obligatoire pour un système à haut risque actif."
            />
            <Stack direction="row" spacing={2}>
              <TextField
                select
                fullWidth
                label="Responsable"
                value={systemForm.ownerId}
                onChange={(e) =>
                  setSystemForm({ ...systemForm, ownerId: e.target.value })
                }
              >
                {(users.data ?? []).map((item) => (
                  <MenuItem
                    key={item.id}
                    value={String(item.id)}
                  >{`${item.firstName} ${item.lastName}`}</MenuItem>
                ))}
              </TextField>
              <TextField
                fullWidth
                type="date"
                label="Prochaine revue"
                value={systemForm.reviewAt}
                onChange={(e) =>
                  setSystemForm({ ...systemForm, reviewAt: e.target.value })
                }
                slotProps={{ inputLabel: { shrink: true } }}
              />
            </Stack>
            {saveSystem.isError && (
              <Alert severity="error">{errorText(saveSystem.error)}</Alert>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setSystemDialog(null)}>Annuler</Button>
          <Button
            variant="contained"
            disabled={saveSystem.isPending}
            onClick={() => saveSystem.mutate()}
          >
            Enregistrer
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog
        open={assessmentDialog !== null}
        onClose={() => setAssessmentDialog(null)}
        fullWidth
        maxWidth="sm"
      >
        <DialogTitle>Évaluation d’impact du système d’IA</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField
              label="Personnes et groupes concernés"
              multiline
              minRows={2}
              value={assessmentForm.affectedGroups}
              onChange={(e) =>
                setAssessmentForm({
                  ...assessmentForm,
                  affectedGroups: e.target.value,
                })
              }
            />
            <TextField
              label="Bénéfices attendus"
              multiline
              minRows={2}
              value={assessmentForm.benefits}
              onChange={(e) =>
                setAssessmentForm({
                  ...assessmentForm,
                  benefits: e.target.value,
                })
              }
            />
            <TextField
              label="Préjudices possibles"
              multiline
              minRows={2}
              value={assessmentForm.harms}
              onChange={(e) =>
                setAssessmentForm({ ...assessmentForm, harms: e.target.value })
              }
            />
            <TextField
              label="Mesures d’atténuation"
              multiline
              minRows={2}
              value={assessmentForm.mitigations}
              onChange={(e) =>
                setAssessmentForm({
                  ...assessmentForm,
                  mitigations: e.target.value,
                })
              }
            />
            <TextField
              select
              label="Risque résiduel"
              value={assessmentForm.residualRisk}
              onChange={(e) =>
                setAssessmentForm({
                  ...assessmentForm,
                  residualRisk: e.target.value,
                })
              }
            >
              {Object.entries(residualLabels).map(([value, label]) => (
                <MenuItem key={value} value={value}>
                  {label}
                </MenuItem>
              ))}
            </TextField>
            {saveAssessment.isError && (
              <Alert severity="error">{errorText(saveAssessment.error)}</Alert>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setAssessmentDialog(null)}>Annuler</Button>
          <Button
            variant="contained"
            disabled={saveAssessment.isPending}
            onClick={() => saveAssessment.mutate()}
          >
            Enregistrer
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
