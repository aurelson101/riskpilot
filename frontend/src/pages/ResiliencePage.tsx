import { useMemo, useState, type FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Add,
  DeleteOutline,
  EditOutlined,
  HistoryOutlined,
  ScienceOutlined,
} from "@mui/icons-material";
import {
  Alert,
  Button,
  Card,
  CardActions,
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
  TextField,
  Typography,
} from "@mui/material";
import { api } from "../api/client";
import { hasAnyRole } from "../auth/roles";
import { useAuth } from "../auth/useAuth";
import type { Scope, User } from "../api/types";

type TimelineEvent = { at: string; event: string; actor: string };
type Incident = {
  id: number;
  title: string;
  description: string;
  severity: string;
  status: string;
  owner: { id: number; name: string };
  detectedAt: string;
  closedAt: string | null;
  impacts: Record<string, unknown>;
  timeline: TimelineEvent[];
  evidence: string[];
  regulatoryNotificationRequired: boolean;
  notifiedAt: string | null;
  lessonsLearned: string | null;
  assetIds: number[];
  thirdPartyIds: number[];
  riskIds: number[];
  actionIds: number[];
};
type Exercise = {
  date: string;
  scenario: string;
  participants: string[];
  result: string;
  gaps: string[];
  improvements: string[];
};
type Process = {
  id: number;
  name: string;
  criticality: string;
  scope: { id: number; name: string };
  owner: { id: number; name: string };
  mtpdHours: number;
  rtoHours: number;
  rpoHours: number;
  dependencies: string[];
  businessImpact: string;
  bcpProcedure: string | null;
  drpProcedure: string | null;
  nextExerciseAt: string | null;
  exercises: Exercise[];
};
type DialogKind = "incident" | "process" | "timeline" | "exercise" | null;
const severities = ["LOW", "MEDIUM", "HIGH", "CRITICAL"];
const statuses = [
  "DETECTED",
  "QUALIFIED",
  "CONTAINED",
  "ERADICATED",
  "RECOVERED",
  "CLOSED",
];
const split = (value: string) =>
  value
    .split(",")
    .map((item) => item.trim())
    .filter(Boolean);
const dateTimeLocal = (value: string | null) =>
  value ? value.slice(0, 16) : "";

export function ResiliencePage() {
  const { user } = useAuth();
  const cache = useQueryClient();
  const incidents = useQuery({
    queryKey: ["incidents"],
    queryFn: async () =>
      (await api.get<Incident[]>("/resilience/incidents")).data,
  });
  const processes = useQuery({
    queryKey: ["continuity-processes"],
    queryFn: async () =>
      (await api.get<Process[]>("/resilience/continuity-processes")).data,
  });
  const users = useQuery({
    queryKey: ["users"],
    queryFn: async () => (await api.get<User[]>("/users")).data,
  });
  const scopes = useQuery({
    queryKey: ["scopes"],
    queryFn: async () => (await api.get<Scope[]>("/scopes")).data,
  });
  const canManage = hasAnyRole(user?.roles, [
    "ROLE_SUPER_ADMIN",
    "ROLE_ADMIN",
    "ROLE_RISK_MANAGER",
    "ROLE_AUDITOR",
    "ROLE_ACTION_OWNER",
  ]);
  const [kind, setKind] = useState<DialogKind>(null);
  const [selectedIncident, setSelectedIncident] = useState<Incident | null>(
    null,
  );
  const [selectedProcess, setSelectedProcess] = useState<Process | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [incidentForm, setIncidentForm] = useState({
    title: "",
    description: "",
    severity: "HIGH",
    status: "DETECTED",
    ownerId: "",
    detectedAt: new Date().toISOString().slice(0, 16),
    availabilityHours: "",
    affectedPeople: "",
    evidence: "",
    regulatoryNotificationRequired: false,
    notifiedAt: "",
    lessonsLearned: "",
  });
  const [processForm, setProcessForm] = useState({
    name: "",
    criticality: "HIGH",
    scopeId: "",
    ownerId: "",
    mtpdHours: "24",
    rtoHours: "4",
    rpoHours: "1",
    dependencies: "",
    businessImpact: "",
    bcpProcedure: "",
    drpProcedure: "",
    nextExerciseAt: "",
  });
  const [event, setEvent] = useState("");
  const [exercise, setExercise] = useState({
    date: new Date().toISOString().slice(0, 10),
    scenario: "",
    participants: "",
    result: "",
    gaps: "",
    improvements: "",
  });

  const refresh = async () => {
    await Promise.all([
      cache.invalidateQueries({ queryKey: ["incidents"] }),
      cache.invalidateQueries({ queryKey: ["continuity-processes"] }),
    ]);
  };
  const save = useMutation({
    mutationFn: async () => {
      if (kind === "incident") {
        const payload = {
          ...incidentForm,
          ownerId: Number(incidentForm.ownerId),
          impacts: {
            availabilityHours: Number(incidentForm.availabilityHours || 0),
            affectedPeople: Number(incidentForm.affectedPeople || 0),
          },
          evidence: split(incidentForm.evidence),
          notifiedAt: incidentForm.notifiedAt || null,
          lessonsLearned: incidentForm.lessonsLearned || null,
          assetIds: selectedIncident?.assetIds ?? [],
          thirdPartyIds: selectedIncident?.thirdPartyIds ?? [],
          riskIds: selectedIncident?.riskIds ?? [],
          actionIds: selectedIncident?.actionIds ?? [],
        };
        return selectedIncident
          ? api.put(`/resilience/incidents/${selectedIncident.id}`, payload)
          : api.post("/resilience/incidents", payload);
      }
      if (kind === "process") {
        const payload = {
          ...processForm,
          ownerId: Number(processForm.ownerId),
          scopeId: Number(processForm.scopeId),
          mtpdHours: Number(processForm.mtpdHours),
          rtoHours: Number(processForm.rtoHours),
          rpoHours: Number(processForm.rpoHours),
          dependencies: split(processForm.dependencies),
          nextExerciseAt: processForm.nextExerciseAt || null,
        };
        return selectedProcess
          ? api.put(
              `/resilience/continuity-processes/${selectedProcess.id}`,
              payload,
            )
          : api.post("/resilience/continuity-processes", payload);
      }
      if (kind === "timeline" && selectedIncident)
        return api.post(
          `/resilience/incidents/${selectedIncident.id}/timeline`,
          { event },
        );
      if (kind === "exercise" && selectedProcess)
        return api.post(
          `/resilience/continuity-processes/${selectedProcess.id}/exercises`,
          {
            ...exercise,
            participants: split(exercise.participants),
            gaps: split(exercise.gaps),
            improvements: split(exercise.improvements),
          },
        );
      throw new Error("Action invalide");
    },
    onSuccess: async () => {
      setKind(null);
      setMessage("Enregistrement effectué.");
      await refresh();
    },
  });

  const openIncident = (item?: Incident) => {
    setSelectedIncident(item ?? null);
    setIncidentForm(
      item
        ? {
            title: item.title,
            description: item.description,
            severity: item.severity,
            status: item.status,
            ownerId: String(item.owner.id),
            detectedAt: dateTimeLocal(item.detectedAt),
            availabilityHours: String(item.impacts.availabilityHours ?? ""),
            affectedPeople: String(item.impacts.affectedPeople ?? ""),
            evidence: item.evidence.join(", "),
            regulatoryNotificationRequired: item.regulatoryNotificationRequired,
            notifiedAt: dateTimeLocal(item.notifiedAt),
            lessonsLearned: item.lessonsLearned ?? "",
          }
        : {
            title: "",
            description: "",
            severity: "HIGH",
            status: "DETECTED",
            ownerId: String(user?.id ?? ""),
            detectedAt: new Date().toISOString().slice(0, 16),
            availabilityHours: "",
            affectedPeople: "",
            evidence: "",
            regulatoryNotificationRequired: false,
            notifiedAt: "",
            lessonsLearned: "",
          },
    );
    setKind("incident");
  };
  const openProcess = (item?: Process) => {
    setSelectedProcess(item ?? null);
    setProcessForm(
      item
        ? {
            name: item.name,
            criticality: item.criticality,
            scopeId: String(item.scope.id),
            ownerId: String(item.owner.id),
            mtpdHours: String(item.mtpdHours),
            rtoHours: String(item.rtoHours),
            rpoHours: String(item.rpoHours),
            dependencies: item.dependencies.join(", "),
            businessImpact: item.businessImpact,
            bcpProcedure: item.bcpProcedure ?? "",
            drpProcedure: item.drpProcedure ?? "",
            nextExerciseAt: item.nextExerciseAt ?? "",
          }
        : {
            name: "",
            criticality: "HIGH",
            scopeId: String(scopes.data?.[0]?.id ?? ""),
            ownerId: String(user?.id ?? ""),
            mtpdHours: "24",
            rtoHours: "4",
            rpoHours: "1",
            dependencies: "",
            businessImpact: "",
            bcpProcedure: "",
            drpProcedure: "",
            nextExerciseAt: "",
          },
    );
    setKind("process");
  };
  const deleteItem = async (type: "incident" | "process", id: number) => {
    if (!window.confirm("Supprimer définitivement cet enregistrement ?"))
      return;
    await api.delete(
      type === "incident"
        ? `/resilience/incidents/${id}`
        : `/resilience/continuity-processes/${id}`,
    );
    await refresh();
  };
  const summary = useMemo(
    () => ({
      open:
        incidents.data?.filter((item) => item.status !== "CLOSED").length ?? 0,
      critical:
        incidents.data?.filter(
          (item) => item.severity === "CRITICAL" && item.status !== "CLOSED",
        ).length ?? 0,
      overdueExercises:
        processes.data?.filter(
          (item) =>
            item.nextExerciseAt &&
            item.nextExerciseAt < new Date().toISOString().slice(0, 10),
        ).length ?? 0,
    }),
    [incidents.data, processes.data],
  );
  if (incidents.isLoading || processes.isLoading)
    return <CircularProgress aria-label="Chargement de la résilience" />;
  if (incidents.isError || processes.isError)
    return (
      <Alert severity="error">
        Impossible de charger le module résilience.
      </Alert>
    );

  return (
    <Stack spacing={3}>
      <Stack
        direction={{ xs: "column", sm: "row" }}
        justifyContent="space-between"
        gap={2}
      >
        <div>
          <Typography variant="h4" fontWeight={750}>
            Incidents et continuité
          </Typography>
          <Typography color="text.secondary">
            Chronologie de crise, BIA, PCA/PRA et exercices
          </Typography>
        </div>
        {canManage && (
          <Stack direction="row" gap={1}>
            <Button
              startIcon={<Add />}
              variant="contained"
              onClick={() => openIncident()}
            >
              Incident
            </Button>
            <Button
              startIcon={<Add />}
              variant="outlined"
              onClick={() => openProcess()}
            >
              Processus BIA
            </Button>
          </Stack>
        )}
      </Stack>
      {message && (
        <Alert severity="success" onClose={() => setMessage(null)}>
          {message}
        </Alert>
      )}
      <Stack direction={{ xs: "column", sm: "row" }} gap={2}>
        <Chip label={`${summary.open} incident(s) ouvert(s)`} />
        <Chip
          color={summary.critical ? "error" : "default"}
          label={`${summary.critical} critique(s)`}
        />
        <Chip
          color={summary.overdueExercises ? "warning" : "default"}
          label={`${summary.overdueExercises} exercice(s) en retard`}
        />
      </Stack>
      <Stack
        sx={{
          display: "grid",
          gridTemplateColumns: { xs: "1fr", lg: "repeat(2, 1fr)" },
          gap: 2,
        }}
      >
        <Stack spacing={1.5}>
          <Typography variant="h6" fontWeight={750}>
            Incidents
          </Typography>
          {incidents.data?.length === 0 && (
            <Alert severity="info">Aucun incident déclaré.</Alert>
          )}
          {incidents.data?.map((item) => (
            <Card variant="outlined" key={item.id}>
              <CardContent>
                <Stack spacing={1}>
                  <Stack direction="row" justifyContent="space-between">
                    <Typography fontWeight={700}>{item.title}</Typography>
                    <Chip
                      size="small"
                      label={item.severity}
                      color={
                        item.severity === "CRITICAL"
                          ? "error"
                          : item.severity === "HIGH"
                            ? "warning"
                            : "default"
                      }
                    />
                  </Stack>
                  <Typography variant="body2">
                    {item.status} · {item.owner.name}
                  </Typography>
                  <Typography variant="body2" color="text.secondary">
                    {item.description}
                  </Typography>
                  <Typography variant="caption">
                    {item.timeline.length} événement(s) · {item.evidence.length}{" "}
                    preuve(s)
                    {item.closedAt
                      ? ` · Clos le ${new Date(item.closedAt).toLocaleDateString("fr")}`
                      : ""}
                  </Typography>
                </Stack>
              </CardContent>
              {canManage && (
                <CardActions>
                  <Button
                    size="small"
                    startIcon={<EditOutlined />}
                    onClick={() => openIncident(item)}
                  >
                    Modifier
                  </Button>
                  <Button
                    size="small"
                    startIcon={<HistoryOutlined />}
                    onClick={() => {
                      setSelectedIncident(item);
                      setEvent("");
                      setKind("timeline");
                    }}
                  >
                    Chronologie
                  </Button>
                  <Button
                    size="small"
                    color="error"
                    startIcon={<DeleteOutline />}
                    onClick={() => void deleteItem("incident", item.id)}
                  >
                    Supprimer
                  </Button>
                </CardActions>
              )}
            </Card>
          ))}
        </Stack>
        <Stack spacing={1.5}>
          <Typography variant="h6" fontWeight={750}>
            BIA et continuité
          </Typography>
          {processes.data?.length === 0 && (
            <Alert severity="info">Aucun processus métier analysé.</Alert>
          )}
          {processes.data?.map((item) => (
            <Card variant="outlined" key={item.id}>
              <CardContent>
                <Stack spacing={1}>
                  <Stack direction="row" justifyContent="space-between">
                    <Typography fontWeight={700}>{item.name}</Typography>
                    <Chip size="small" label={item.criticality} />
                  </Stack>
                  <Typography variant="body2">
                    {item.scope.name} · {item.owner.name}
                  </Typography>
                  <Typography variant="body2">
                    MTPD {item.mtpdHours}h · RTO {item.rtoHours}h · RPO{" "}
                    {item.rpoHours}h
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {item.dependencies.join(", ") || "Aucune dépendance"} ·{" "}
                    {item.exercises.length} exercice(s) · prochain :{" "}
                    {item.nextExerciseAt ?? "non planifié"}
                  </Typography>
                </Stack>
              </CardContent>
              {canManage && (
                <CardActions>
                  <Button
                    size="small"
                    startIcon={<EditOutlined />}
                    onClick={() => openProcess(item)}
                  >
                    Modifier
                  </Button>
                  <Button
                    size="small"
                    startIcon={<ScienceOutlined />}
                    onClick={() => {
                      setSelectedProcess(item);
                      setExercise({
                        date: new Date().toISOString().slice(0, 10),
                        scenario: "",
                        participants: "",
                        result: "",
                        gaps: "",
                        improvements: "",
                      });
                      setKind("exercise");
                    }}
                  >
                    Exercice
                  </Button>
                  <Button
                    size="small"
                    color="error"
                    startIcon={<DeleteOutline />}
                    onClick={() => void deleteItem("process", item.id)}
                  >
                    Supprimer
                  </Button>
                </CardActions>
              )}
            </Card>
          ))}
        </Stack>
      </Stack>
      <Dialog
        open={kind !== null}
        onClose={() => setKind(null)}
        fullWidth
        maxWidth="md"
        component="form"
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        <DialogTitle>
          {kind === "incident"
            ? `${selectedIncident ? "Modifier" : "Déclarer"} un incident`
            : kind === "process"
              ? `${selectedProcess ? "Modifier" : "Créer"} un BIA/PCA`
              : kind === "timeline"
                ? "Ajouter à la chronologie"
                : "Enregistrer un exercice"}
        </DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            {save.isError && (
              <Alert severity="error">
                L’opération a échoué. Vérifiez les champs et les règles métier.
              </Alert>
            )}
            {kind === "incident" && (
              <>
                <TextField
                  required
                  label="Titre"
                  value={incidentForm.title}
                  onChange={(e) =>
                    setIncidentForm({ ...incidentForm, title: e.target.value })
                  }
                />
                <TextField
                  required
                  multiline
                  minRows={2}
                  label="Description"
                  value={incidentForm.description}
                  onChange={(e) =>
                    setIncidentForm({
                      ...incidentForm,
                      description: e.target.value,
                    })
                  }
                />
                <Stack direction={{ xs: "column", sm: "row" }} gap={2}>
                  <TextField
                    select
                    fullWidth
                    label="Sévérité"
                    value={incidentForm.severity}
                    onChange={(e) =>
                      setIncidentForm({
                        ...incidentForm,
                        severity: e.target.value,
                      })
                    }
                  >
                    {severities.map((value) => (
                      <MenuItem key={value} value={value}>
                        {value}
                      </MenuItem>
                    ))}
                  </TextField>
                  <TextField
                    select
                    fullWidth
                    label="Statut"
                    value={incidentForm.status}
                    onChange={(e) =>
                      setIncidentForm({
                        ...incidentForm,
                        status: e.target.value,
                      })
                    }
                  >
                    {statuses.map((value) => (
                      <MenuItem key={value} value={value}>
                        {value}
                      </MenuItem>
                    ))}
                  </TextField>
                  <TextField
                    select
                    required
                    fullWidth
                    label="Responsable"
                    value={incidentForm.ownerId}
                    onChange={(e) =>
                      setIncidentForm({
                        ...incidentForm,
                        ownerId: e.target.value,
                      })
                    }
                  >
                    {users.data?.map((item) => (
                      <MenuItem key={item.id} value={item.id}>
                        {item.firstName} {item.lastName}
                      </MenuItem>
                    ))}
                  </TextField>
                </Stack>
                <TextField
                  type="datetime-local"
                  label="Détecté le"
                  value={incidentForm.detectedAt}
                  onChange={(e) =>
                    setIncidentForm({
                      ...incidentForm,
                      detectedAt: e.target.value,
                    })
                  }
                  slotProps={{ inputLabel: { shrink: true } }}
                  disabled={Boolean(selectedIncident)}
                />
                <Stack direction={{ xs: "column", sm: "row" }} gap={2}>
                  <TextField
                    type="number"
                    fullWidth
                    label="Indisponibilité (heures)"
                    value={incidentForm.availabilityHours}
                    onChange={(e) =>
                      setIncidentForm({
                        ...incidentForm,
                        availabilityHours: e.target.value,
                      })
                    }
                  />
                  <TextField
                    type="number"
                    fullWidth
                    label="Personnes affectées"
                    value={incidentForm.affectedPeople}
                    onChange={(e) =>
                      setIncidentForm({
                        ...incidentForm,
                        affectedPeople: e.target.value,
                      })
                    }
                  />
                </Stack>
                <TextField
                  label="Références de preuves (virgules)"
                  value={incidentForm.evidence}
                  onChange={(e) =>
                    setIncidentForm({
                      ...incidentForm,
                      evidence: e.target.value,
                    })
                  }
                />
                <FormControlLabel
                  control={
                    <Switch
                      checked={incidentForm.regulatoryNotificationRequired}
                      onChange={(e) =>
                        setIncidentForm({
                          ...incidentForm,
                          regulatoryNotificationRequired: e.target.checked,
                        })
                      }
                    />
                  }
                  label="Notification réglementaire requise"
                />
                {incidentForm.regulatoryNotificationRequired && (
                  <TextField
                    type="datetime-local"
                    label="Notifié le"
                    value={incidentForm.notifiedAt}
                    onChange={(e) =>
                      setIncidentForm({
                        ...incidentForm,
                        notifiedAt: e.target.value,
                      })
                    }
                    slotProps={{ inputLabel: { shrink: true } }}
                  />
                )}
                <TextField
                  multiline
                  minRows={2}
                  label="Retour d’expérience"
                  value={incidentForm.lessonsLearned}
                  onChange={(e) =>
                    setIncidentForm({
                      ...incidentForm,
                      lessonsLearned: e.target.value,
                    })
                  }
                />
              </>
            )}
            {kind === "process" && (
              <>
                <TextField
                  required
                  label="Processus métier"
                  value={processForm.name}
                  onChange={(e) =>
                    setProcessForm({ ...processForm, name: e.target.value })
                  }
                />
                <Stack direction={{ xs: "column", sm: "row" }} gap={2}>
                  <TextField
                    select
                    fullWidth
                    label="Criticité"
                    value={processForm.criticality}
                    onChange={(e) =>
                      setProcessForm({
                        ...processForm,
                        criticality: e.target.value,
                      })
                    }
                  >
                    {severities.map((value) => (
                      <MenuItem key={value} value={value}>
                        {value}
                      </MenuItem>
                    ))}
                  </TextField>
                  <TextField
                    select
                    required
                    fullWidth
                    label="Périmètre"
                    value={processForm.scopeId}
                    onChange={(e) =>
                      setProcessForm({
                        ...processForm,
                        scopeId: e.target.value,
                      })
                    }
                  >
                    {scopes.data?.map((item) => (
                      <MenuItem key={item.id} value={item.id}>
                        {item.name}
                      </MenuItem>
                    ))}
                  </TextField>
                  <TextField
                    select
                    required
                    fullWidth
                    label="Responsable"
                    value={processForm.ownerId}
                    onChange={(e) =>
                      setProcessForm({
                        ...processForm,
                        ownerId: e.target.value,
                      })
                    }
                  >
                    {users.data?.map((item) => (
                      <MenuItem key={item.id} value={item.id}>
                        {item.firstName} {item.lastName}
                      </MenuItem>
                    ))}
                  </TextField>
                </Stack>
                <Stack direction={{ xs: "column", sm: "row" }} gap={2}>
                  <TextField
                    required
                    type="number"
                    label="MTPD (h)"
                    value={processForm.mtpdHours}
                    onChange={(e) =>
                      setProcessForm({
                        ...processForm,
                        mtpdHours: e.target.value,
                      })
                    }
                  />
                  <TextField
                    required
                    type="number"
                    label="RTO (h)"
                    value={processForm.rtoHours}
                    onChange={(e) =>
                      setProcessForm({
                        ...processForm,
                        rtoHours: e.target.value,
                      })
                    }
                  />
                  <TextField
                    required
                    type="number"
                    label="RPO (h)"
                    value={processForm.rpoHours}
                    onChange={(e) =>
                      setProcessForm({
                        ...processForm,
                        rpoHours: e.target.value,
                      })
                    }
                  />
                </Stack>
                <TextField
                  required
                  multiline
                  label="Impacts métier"
                  value={processForm.businessImpact}
                  onChange={(e) =>
                    setProcessForm({
                      ...processForm,
                      businessImpact: e.target.value,
                    })
                  }
                />
                <TextField
                  label="Dépendances (virgules)"
                  value={processForm.dependencies}
                  onChange={(e) =>
                    setProcessForm({
                      ...processForm,
                      dependencies: e.target.value,
                    })
                  }
                />
                <TextField
                  multiline
                  label="Procédure PCA"
                  value={processForm.bcpProcedure}
                  onChange={(e) =>
                    setProcessForm({
                      ...processForm,
                      bcpProcedure: e.target.value,
                    })
                  }
                />
                <TextField
                  multiline
                  label="Procédure PRA"
                  value={processForm.drpProcedure}
                  onChange={(e) =>
                    setProcessForm({
                      ...processForm,
                      drpProcedure: e.target.value,
                    })
                  }
                />
                <TextField
                  type="date"
                  label="Prochain exercice"
                  value={processForm.nextExerciseAt}
                  onChange={(e) =>
                    setProcessForm({
                      ...processForm,
                      nextExerciseAt: e.target.value,
                    })
                  }
                  slotProps={{ inputLabel: { shrink: true } }}
                />
              </>
            )}
            {kind === "timeline" && (
              <TextField
                required
                autoFocus
                multiline
                minRows={3}
                label="Événement horodaté"
                value={event}
                onChange={(e) => setEvent(e.target.value)}
              />
            )}
            {kind === "exercise" && (
              <>
                <TextField
                  required
                  type="date"
                  label="Date"
                  value={exercise.date}
                  onChange={(e) =>
                    setExercise({ ...exercise, date: e.target.value })
                  }
                  slotProps={{ inputLabel: { shrink: true } }}
                />
                <TextField
                  required
                  label="Scénario"
                  value={exercise.scenario}
                  onChange={(e) =>
                    setExercise({ ...exercise, scenario: e.target.value })
                  }
                />
                <TextField
                  required
                  label="Participants (virgules)"
                  value={exercise.participants}
                  onChange={(e) =>
                    setExercise({ ...exercise, participants: e.target.value })
                  }
                />
                <TextField
                  required
                  multiline
                  label="Résultat"
                  value={exercise.result}
                  onChange={(e) =>
                    setExercise({ ...exercise, result: e.target.value })
                  }
                />
                <TextField
                  label="Écarts (virgules)"
                  value={exercise.gaps}
                  onChange={(e) =>
                    setExercise({ ...exercise, gaps: e.target.value })
                  }
                />
                <TextField
                  label="Améliorations (virgules)"
                  value={exercise.improvements}
                  onChange={(e) =>
                    setExercise({ ...exercise, improvements: e.target.value })
                  }
                />
              </>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setKind(null)}>Annuler</Button>
          <Button type="submit" variant="contained" disabled={save.isPending}>
            Enregistrer
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
