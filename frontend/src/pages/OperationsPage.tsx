import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AddOutlined } from "@mui/icons-material";
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
  LinearProgress,
  MenuItem,
  Stack,
  Tab,
  Tabs,
  TextField,
  Typography,
} from "@mui/material";
import { useMemo, useState, type FormEvent } from "react";
import { api } from "../api/client";

type RecordType =
  | "TASK"
  | "RESPONSIBILITY_RULE"
  | "COMPLIANCE_PROGRAM"
  | "QUESTIONNAIRE_TEMPLATE"
  | "QUESTIONNAIRE_CAMPAIGN"
  | "REFERENCE_PACK";
type RecordItem = {
  id: number;
  type: RecordType;
  title: string;
  status: string;
  details: Record<string, unknown>;
  owner: { id: number; name: string } | null;
  dueAt: string | null;
};
type Task = {
  id: number;
  title: string;
  status: string;
  source: string;
  link: string;
  dueAt: string | null;
  overdue: boolean;
};
type Trajectory = {
  id: number;
  title: string;
  current: number;
  expected: number;
  target: number;
  atRisk: boolean;
  dueAt: string;
};

const sections: Array<{ type: RecordType | "MY_TASKS"; label: string }> = [
  { type: "MY_TASKS", label: "Mes tâches" },
  { type: "RESPONSIBILITY_RULE", label: "Responsabilités" },
  { type: "COMPLIANCE_PROGRAM", label: "Trajectoires" },
  { type: "QUESTIONNAIRE_TEMPLATE", label: "Questionnaires" },
  { type: "QUESTIONNAIRE_CAMPAIGN", label: "Campagnes" },
  { type: "REFERENCE_PACK", label: "Packs" },
];

export function OperationsPage() {
  const client = useQueryClient();
  const [section, setSection] = useState<RecordType | "MY_TASKS">("MY_TASKS");
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({
    title: "",
    dueAt: "",
    ownerId: "",
    details: "{}",
  });
  const [formError, setFormError] = useState<string | null>(null);
  const tasks = useQuery({
    queryKey: ["my-tasks"],
    queryFn: async () =>
      (await api.get<{ items: Task[] }>("/operations/my-tasks")).data.items,
  });
  const records = useQuery({
    queryKey: ["operations", section],
    enabled: section !== "MY_TASKS",
    queryFn: async () =>
      (await api.get<RecordItem[]>(`/operations/records?type=${section}`)).data,
  });
  const trajectory = useQuery({
    queryKey: ["compliance-trajectory"],
    enabled: section === "COMPLIANCE_PROGRAM",
    queryFn: async () =>
      (await api.get<Trajectory[]>("/operations/compliance-trajectory")).data,
  });
  const create = useMutation({
    mutationFn: () =>
      api.post("/operations/records", {
        type: section,
        title: form.title,
        status: "ACTIVE",
        ownerId: form.ownerId || null,
        dueAt: form.dueAt || null,
        details: JSON.parse(form.details),
      }),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["operations"] });
      await client.invalidateQueries({ queryKey: ["my-tasks"] });
      setOpen(false);
      setForm({ title: "", dueAt: "", ownerId: "", details: "{}" });
    },
  });
  const currentTrajectory = useMemo(
    () => new Map((trajectory.data ?? []).map((item) => [item.id, item])),
    [trajectory.data],
  );
  const submit = (event: FormEvent) => {
    event.preventDefault();
    try {
      JSON.parse(form.details);
      setFormError(null);
      create.mutate();
    } catch {
      setFormError("La configuration JSON n’est pas valide.");
    }
  };

  return (
    <Stack spacing={3}>
      <div>
        <Typography variant="h4" fontWeight={800}>
          Pilotage opérationnel
        </Typography>
        <Typography color="text.secondary">
          Responsabilités, tâches, trajectoires, questionnaires, campagnes et
          contenus de référence
        </Typography>
      </div>
      <Card>
        <Tabs
          value={section}
          onChange={(_, value) => setSection(value)}
          variant="scrollable"
          scrollButtons="auto"
          aria-label="Pilotage opérationnel"
        >
          {sections.map((item) => (
            <Tab key={item.type} value={item.type} label={item.label} />
          ))}
        </Tabs>
      </Card>
      {(tasks.isError ||
        records.isError ||
        trajectory.isError ||
        create.isError) && (
        <Alert severity="error">L’opération n’a pas pu être terminée.</Alert>
      )}
      {formError && <Alert severity="error">{formError}</Alert>}
      {section === "MY_TASKS" ? (
        <Stack spacing={2}>
          {tasks.data?.map((item) => (
            <Card key={`${item.source}-${item.id}`}>
              <CardContent>
                <Stack
                  direction={{ xs: "column", sm: "row" }}
                  justifyContent="space-between"
                  gap={1}
                >
                  <div>
                    <Typography fontWeight={750}>{item.title}</Typography>
                    <Typography variant="body2" color="text.secondary">
                      {item.source} ·{" "}
                      {item.dueAt
                        ? new Date(item.dueAt).toLocaleDateString()
                        : "Sans échéance"}
                    </Typography>
                  </div>
                  <Chip
                    color={item.overdue ? "error" : "default"}
                    label={item.status}
                  />
                </Stack>
              </CardContent>
            </Card>
          ))}
          {tasks.data?.length === 0 && (
            <Alert severity="success">Aucune tâche ouverte.</Alert>
          )}
        </Stack>
      ) : (
        <Stack spacing={2}>
          <Button
            sx={{ alignSelf: "flex-start" }}
            variant="contained"
            startIcon={<AddOutlined />}
            onClick={() => setOpen(true)}
          >
            Créer
          </Button>
          {records.data?.map((item) => {
            const progress = currentTrajectory.get(item.id);
            return (
              <Card key={item.id}>
                <CardContent>
                  <Stack spacing={1}>
                    <Stack
                      direction="row"
                      justifyContent="space-between"
                      gap={1}
                    >
                      <Typography fontWeight={750}>{item.title}</Typography>
                      <Chip
                        label={item.status}
                        color={progress?.atRisk ? "warning" : "default"}
                      />
                    </Stack>
                    {item.owner && (
                      <Typography variant="body2">
                        Responsable : {item.owner.name}
                      </Typography>
                    )}
                    {progress && (
                      <>
                        <Typography variant="body2">
                          Réel {progress.current}% · attendu {progress.expected}
                          % · cible {progress.target}%
                        </Typography>
                        <LinearProgress
                          variant="determinate"
                          value={progress.current}
                          color={progress.atRisk ? "warning" : "primary"}
                        />
                      </>
                    )}
                    <Typography
                      component="pre"
                      variant="caption"
                      sx={{ whiteSpace: "pre-wrap", m: 0 }}
                    >
                      {JSON.stringify(item.details, null, 2)}
                    </Typography>
                  </Stack>
                </CardContent>
              </Card>
            );
          })}
        </Stack>
      )}
      <Dialog
        open={open}
        onClose={() => setOpen(false)}
        fullWidth
        maxWidth="sm"
      >
        <form onSubmit={submit}>
          <DialogTitle>
            Nouvel élément —{" "}
            {sections.find((item) => item.type === section)?.label}
          </DialogTitle>
          <DialogContent>
            <Stack spacing={2} mt={1}>
              <TextField
                required
                label="Titre"
                value={form.title}
                onChange={(e) => setForm({ ...form, title: e.target.value })}
              />
              <TextField
                type="datetime-local"
                label="Échéance"
                InputLabelProps={{ shrink: true }}
                value={form.dueAt}
                onChange={(e) => setForm({ ...form, dueAt: e.target.value })}
              />
              <TextField
                label="Identifiant du responsable"
                type="number"
                value={form.ownerId}
                onChange={(e) => setForm({ ...form, ownerId: e.target.value })}
              />
              <TextField
                select
                label="Modèle de données"
                value={form.details}
                onChange={(e) => setForm({ ...form, details: e.target.value })}
              >
                <MenuItem value="{}">Vide</MenuItem>
                {section === "COMPLIANCE_PROGRAM" && (
                  <MenuItem
                    value={
                      '{"startDate":"2026-08-01","currentScore":0,"targetScore":100,"frameworks":[]}'
                    }
                  >
                    Programme de conformité
                  </MenuItem>
                )}
                {section === "RESPONSIBILITY_RULE" && (
                  <MenuItem
                    value={
                      '{"domain":"governance","scopeType":"ORGANIZATION","defaultRole":"ROLE_RISK_MANAGER","requiresApproval":true}'
                    }
                  >
                    Règle de responsabilité
                  </MenuItem>
                )}
                {section === "QUESTIONNAIRE_TEMPLATE" && (
                  <MenuItem
                    value={
                      '{"useCase":"EVIDENCE_COLLECTION","version":1,"questions":[],"reminderDays":[7,2]}'
                    }
                  >
                    Collecte de preuves
                  </MenuItem>
                )}
                {section === "QUESTIONNAIRE_CAMPAIGN" && (
                  <MenuItem
                    value={
                      '{"templateId":null,"recipientIds":[],"responseStatus":"DRAFT"}'
                    }
                  >
                    Campagne interne
                  </MenuItem>
                )}
                {section === "REFERENCE_PACK" && (
                  <MenuItem
                    value={
                      '{"code":"STARTER","version":"1.0","license":"metadata-only","frameworks":[],"mappings":[]}'
                    }
                  >
                    Pack gouverné vide
                  </MenuItem>
                )}
              </TextField>
              <TextField
                multiline
                minRows={7}
                label="Configuration JSON"
                value={form.details}
                onChange={(e) => setForm({ ...form, details: e.target.value })}
              />
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
