import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Add, ArchiveOutlined, EditOutlined } from "@mui/icons-material";
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
  FormControl,
  IconButton,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from "@mui/material";
import axios from "axios";
import { useState, type FormEvent } from "react";
import { api } from "../api/client";
import type {
  Asset,
  Organization,
  RiskLevel,
  RiskScenario,
  Scope,
  SecurityControl,
  Threat,
  User,
  Vulnerability,
} from "../api/types";
import { useAuth } from "../auth/useAuth";

const levelLabels: Record<RiskLevel, string> = {
  LOW: "Faible",
  MODERATE: "Modéré",
  HIGH: "Élevé",
  CRITICAL: "Critique",
};
type RiskForm = {
  title: string;
  description: string;
  scopeId: number | "";
  assetId: number | "";
  threatId: number | "";
  vulnerabilityIds: number[];
  currentControlIds: number[];
  riskOwnerId: number | "";
  likelihood: number;
  impact: number;
  currentLikelihood: number;
  currentImpact: number;
  residualLikelihood: number;
  residualImpact: number;
  treatmentDecision: string;
  status: string;
  reviewDate: string | null;
};
const emptyForm: RiskForm = {
  title: "",
  description: "",
  scopeId: "",
  assetId: "",
  threatId: "",
  vulnerabilityIds: [],
  currentControlIds: [],
  riskOwnerId: "",
  likelihood: 3,
  impact: 3,
  currentLikelihood: 2,
  currentImpact: 3,
  residualLikelihood: 2,
  residualImpact: 2,
  treatmentDecision: "REDUCE",
  status: "DRAFT",
  reviewDate: null,
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
  return (
    <Chip
      size="small"
      color={
        level === "LOW" ? "success" : level === "CRITICAL" ? "error" : "warning"
      }
      label={`${score} · ${levelLabels[level]}`}
    />
  );
}
function message(error: unknown) {
  return axios.isAxiosError<{ message?: string }>(error)
    ? (error.response?.data?.message ?? "L’opération a échoué.")
    : "L’opération a échoué.";
}

export function RisksPage() {
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<RiskScenario | null>(null);
  const [form, setForm] = useState<RiskForm>(emptyForm);
  const [error, setError] = useState("");
  const canManage = user?.roles.some((role) =>
    ["ROLE_SUPER_ADMIN", "ROLE_ADMIN", "ROLE_RISK_MANAGER"].includes(role),
  );
  const query = useQuery({
    queryKey: ["risks"],
    queryFn: async () => (await api.get<RiskScenario[]>("/risks")).data,
  });
  const scopes = useQuery({
    queryKey: ["scopes"],
    queryFn: async () => (await api.get<Scope[]>("/scopes")).data,
  });
  const assets = useQuery({
    queryKey: ["assets"],
    queryFn: async () => (await api.get<Asset[]>("/assets")).data,
  });
  const threats = useQuery({
    queryKey: ["threats"],
    queryFn: async () => (await api.get<Threat[]>("/threats")).data,
  });
  const vulnerabilities = useQuery({
    queryKey: ["vulnerabilities"],
    queryFn: async () =>
      (await api.get<Vulnerability[]>("/vulnerabilities")).data,
  });
  const controls = useQuery({
    queryKey: ["security-controls"],
    queryFn: async () =>
      (await api.get<SecurityControl[]>("/security-controls")).data,
  });
  const users = useQuery({
    queryKey: ["users"],
    queryFn: async () => (await api.get<User[]>("/users")).data,
    enabled: Boolean(canManage),
  });
  const save = useMutation({
    mutationFn: () =>
      editing
        ? api.put(`/risks/${editing.id}`, form)
        : api.post("/risks", form),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["risks"] });
      await queryClient.invalidateQueries({ queryKey: ["dashboard"] });
      setDialogOpen(false);
    },
    onError: (caught) => setError(message(caught)),
  });
  const archive = useMutation({
    mutationFn: (id: number) => api.delete(`/risks/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["risks"] }),
    onError: (caught) => setError(message(caught)),
  });
  function openCreate() {
    setEditing(null);
    setForm(emptyForm);
    setError("");
    setDialogOpen(true);
  }
  function openEdit(risk: RiskScenario) {
    setEditing(risk);
    setForm({
      title: risk.title,
      description: risk.description ?? "",
      scopeId: risk.scope.id,
      assetId: risk.asset.id,
      threatId: risk.threat.id,
      vulnerabilityIds: risk.vulnerabilities.map((item) => item.id),
      currentControlIds: risk.currentControls.map((item) => item.id),
      riskOwnerId: risk.riskOwner.id,
      likelihood: risk.likelihood,
      impact: risk.impact,
      currentLikelihood: risk.currentLikelihood,
      currentImpact: risk.currentImpact,
      residualLikelihood: risk.residualLikelihood,
      residualImpact: risk.residualImpact,
      treatmentDecision: risk.treatmentDecision,
      status: risk.status,
      reviewDate: risk.reviewDate,
    });
    setError("");
    setDialogOpen(true);
  }
  const update = <K extends keyof RiskForm>(key: K, value: RiskForm[K]) =>
    setForm((current) => ({ ...current, [key]: value }));
  function submit(event: FormEvent) {
    event.preventDefault();
    setError("");
    save.mutate();
  }
  if (query.isError)
    return (
      <Alert severity="error">
        Impossible de charger le registre des risques.
      </Alert>
    );
  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Stack>
          <Typography variant="h4" fontWeight={750}>
            Registre des risques
          </Typography>
          <Typography color="text.secondary">
            Scénarios évalués · {query.data?.length ?? 0} risque(s)
          </Typography>
        </Stack>
        {canManage && (
          <Button variant="contained" startIcon={<Add />} onClick={openCreate}>
            Créer un risque
          </Button>
        )}
      </Stack>
      {error && !dialogOpen && <Alert severity="error">{error}</Alert>}
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
                {canManage && <TableCell align="right">Actions</TableCell>}
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
                  {canManage && (
                    <TableCell align="right">
                      <IconButton
                        aria-label="Modifier"
                        onClick={() => openEdit(risk)}
                      >
                        <EditOutlined />
                      </IconButton>
                      <IconButton
                        aria-label="Archiver"
                        color="warning"
                        disabled={risk.status === "ARCHIVED"}
                        onClick={() =>
                          window.confirm(`Archiver « ${risk.title} » ?`) &&
                          archive.mutate(risk.id)
                        }
                      >
                        <ArchiveOutlined />
                      </IconButton>
                    </TableCell>
                  )}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
      <Dialog
        open={dialogOpen}
        onClose={() => setDialogOpen(false)}
        fullWidth
        maxWidth="md"
      >
        <Stack component="form" onSubmit={submit}>
          <DialogTitle>
            {editing ? "Modifier le risque" : "Créer un risque"}
          </DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ pt: 1 }}>
              {error && <Alert severity="error">{error}</Alert>}
              <TextField
                required
                label="Titre"
                value={form.title}
                onChange={(e) => update("title", e.target.value)}
              />
              <TextField
                multiline
                minRows={2}
                label="Description"
                value={form.description}
                onChange={(e) => update("description", e.target.value)}
              />
              {[
                ["Périmètre", "scopeId", scopes.data],
                ["Actif", "assetId", assets.data],
                ["Menace", "threatId", threats.data],
                ["Responsable", "riskOwnerId", users.data],
              ].map(([label, key, items]) => (
                <FormControl required key={String(key)}>
                  <InputLabel>{String(label)}</InputLabel>
                  <Select
                    label={String(label)}
                    value={form[key as keyof RiskForm] as number | ""}
                    onChange={(e) =>
                      update(key as "scopeId", Number(e.target.value))
                    }
                  >
                    {(
                      items as
                        | Array<{
                            id: number;
                            name?: string;
                            firstName?: string;
                            lastName?: string;
                          }>
                        | undefined
                    )?.map((item) => (
                      <MenuItem key={item.id} value={item.id}>
                        {item.name ?? `${item.firstName} ${item.lastName}`}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              ))}
              <FormControl>
                <InputLabel>Vulnérabilités</InputLabel>
                <Select
                  multiple
                  label="Vulnérabilités"
                  value={form.vulnerabilityIds}
                  onChange={(e) =>
                    update(
                      "vulnerabilityIds",
                      typeof e.target.value === "string" ? [] : e.target.value,
                    )
                  }
                >
                  {vulnerabilities.data?.map((item) => (
                    <MenuItem key={item.id} value={item.id}>
                      {item.name}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
              <FormControl>
                <InputLabel>Mesures existantes</InputLabel>
                <Select
                  multiple
                  label="Mesures existantes"
                  value={form.currentControlIds}
                  onChange={(e) =>
                    update(
                      "currentControlIds",
                      typeof e.target.value === "string" ? [] : e.target.value,
                    )
                  }
                >
                  {controls.data?.map((item) => (
                    <MenuItem key={item.id} value={item.id}>
                      {item.name}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
              <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                {[
                  ["Vraisemblance brute", "likelihood"],
                  ["Impact brut", "impact"],
                  ["Vraisemblance actuelle", "currentLikelihood"],
                  ["Impact actuel", "currentImpact"],
                  ["Vraisemblance résiduelle", "residualLikelihood"],
                  ["Impact résiduel", "residualImpact"],
                ].map(([label, key]) => (
                  <TextField
                    key={key}
                    fullWidth
                    type="number"
                    label={label}
                    inputProps={{ min: 1, max: 5 }}
                    value={form[key as keyof RiskForm]}
                    onChange={(e) =>
                      update(key as "likelihood", Number(e.target.value))
                    }
                  />
                ))}
              </Stack>
              <FormControl>
                <InputLabel>Traitement</InputLabel>
                <Select
                  label="Traitement"
                  value={form.treatmentDecision}
                  onChange={(e) => update("treatmentDecision", e.target.value)}
                >
                  {["REDUCE", "ACCEPT", "TRANSFER", "AVOID"].map((value) => (
                    <MenuItem key={value} value={value}>
                      {value}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
              <FormControl>
                <InputLabel>Statut</InputLabel>
                <Select
                  label="Statut"
                  value={form.status}
                  onChange={(e) => update("status", e.target.value)}
                >
                  {[
                    "DRAFT",
                    "IN_REVIEW",
                    "APPROVED",
                    "TREATMENT_IN_PROGRESS",
                    "ACCEPTED",
                    "CLOSED",
                    "ARCHIVED",
                  ].map((value) => (
                    <MenuItem key={value} value={value}>
                      {value}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
              <TextField
                type="date"
                label="Date de révision"
                InputLabelProps={{ shrink: true }}
                value={form.reviewDate ?? ""}
                onChange={(e) => update("reviewDate", e.target.value || null)}
              />
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setDialogOpen(false)}>Annuler</Button>
            <Button type="submit" variant="contained" disabled={save.isPending}>
              Enregistrer
            </Button>
          </DialogActions>
        </Stack>
      </Dialog>
    </Stack>
  );
}
