import { useMemo, useState, type FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Add,
  DeleteOutline,
  EditOutlined,
  VerifiedOutlined,
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
  MenuItem,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { api } from "../api/client";
import { useAuth } from "../auth/useAuth";
import type { User } from "../api/types";

type RecordItem = {
  id: number;
  type: string;
  title: string;
  status: string;
  details: Record<string, unknown>;
  dueAt: string | null;
  expiresAt: string | null;
  owner: { id: number; name: string };
  approvedBy: { id: number; name: string } | null;
  approvedAt: string | null;
  evidence: string[];
};
const types = [
  "PROCESSING_ACTIVITY",
  "DPIA",
  "DATA_BREACH",
  "OBLIGATION",
  "EXCEPTION",
];
const statuses = [
  "DRAFT",
  "ACTIVE",
  "IN_REVIEW",
  "COMPLIANT",
  "NON_COMPLIANT",
  "CLOSED",
  "EXPIRED",
];
const labels: Record<string, string> = {
  PROCESSING_ACTIVITY: "Traitement RGPD",
  DPIA: "AIPD",
  DATA_BREACH: "Violation de données",
  OBLIGATION: "Obligation",
  EXCEPTION: "Dérogation",
};
const fields: Record<
  string,
  Array<{ key: string; label: string; list?: boolean }>
> = {
  PROCESSING_ACTIVITY: [
    { key: "purpose", label: "Finalité" },
    { key: "dataCategories", label: "Catégories de données", list: true },
    { key: "legalBasis", label: "Base légale" },
    { key: "retention", label: "Durée de conservation" },
    { key: "recipients", label: "Destinataires", list: true },
  ],
  DPIA: [
    { key: "processing", label: "Traitement évalué" },
    { key: "necessity", label: "Nécessité et proportionnalité" },
    { key: "risks", label: "Risques pour les personnes", list: true },
    { key: "measures", label: "Mesures de réduction", list: true },
  ],
  DATA_BREACH: [
    { key: "nature", label: "Nature de la violation" },
    { key: "affectedSubjects", label: "Personnes concernées" },
    { key: "consequences", label: "Conséquences" },
    { key: "measures", label: "Mesures prises", list: true },
    { key: "notificationDecision", label: "Décision de notification" },
  ],
  OBLIGATION: [
    { key: "source", label: "Source juridique/contractuelle" },
    { key: "requirement", label: "Exigence" },
    { key: "applicability", label: "Applicabilité" },
  ],
  EXCEPTION: [
    { key: "justification", label: "Justification" },
    { key: "risk", label: "Risque accepté" },
    { key: "compensatingMeasure", label: "Mesure compensatoire" },
  ],
};
const empty = {
  type: "PROCESSING_ACTIVITY",
  title: "",
  status: "DRAFT",
  ownerId: "",
  dueAt: "",
  expiresAt: "",
  evidence: "",
  details: {} as Record<string, string>,
};
const split = (value: string) =>
  value
    .split(",")
    .map((item) => item.trim())
    .filter(Boolean);

export function RegulatoryPage() {
  const { user } = useAuth();
  const cache = useQueryClient();
  const records = useQuery({
    queryKey: ["regulatory-records"],
    queryFn: async () =>
      (await api.get<RecordItem[]>("/regulatory-records")).data,
  });
  const users = useQuery({
    queryKey: ["users"],
    queryFn: async () => (await api.get<User[]>("/users")).data,
  });
  const canManage = Boolean(
    user?.roles.some((role) =>
      [
        "ROLE_SUPER_ADMIN",
        "ROLE_ADMIN",
        "ROLE_RISK_MANAGER",
        "ROLE_AUDITOR",
      ].includes(role),
    ),
  );
  const isAdmin = Boolean(
    user?.roles.some((role) =>
      ["ROLE_SUPER_ADMIN", "ROLE_ADMIN"].includes(role),
    ),
  );
  const [selected, setSelected] = useState<RecordItem | null>(null);
  const [open, setOpen] = useState(false);
  const [filter, setFilter] = useState("ALL");
  const [form, setForm] = useState(empty);
  const [message, setMessage] = useState<string | null>(null);
  const filtered = useMemo(
    () =>
      (records.data ?? []).filter(
        (item) => filter === "ALL" || item.type === filter,
      ),
    [records.data, filter],
  );
  const refresh = async () =>
    cache.invalidateQueries({ queryKey: ["regulatory-records"] });
  const save = useMutation({
    mutationFn: async () => {
      const definition = fields[form.type];
      const details = Object.fromEntries(
        definition.map((field) => [
          field.key,
          field.list
            ? split(form.details[field.key] ?? "")
            : (form.details[field.key] ?? ""),
        ]),
      );
      const payload = {
        type: form.type,
        title: form.title,
        status: form.status,
        ownerId: Number(form.ownerId),
        dueAt: form.dueAt || null,
        expiresAt: form.expiresAt || null,
        evidence: split(form.evidence),
        details,
      };
      return selected
        ? api.put(`/regulatory-records/${selected.id}`, payload)
        : api.post("/regulatory-records", payload);
    },
    onSuccess: async () => {
      setOpen(false);
      setMessage("Registre mis à jour.");
      await refresh();
    },
  });
  const begin = (item?: RecordItem) => {
    setSelected(item ?? null);
    setForm(
      item
        ? {
            type: item.type,
            title: item.title,
            status: item.status === "APPROVED" ? "IN_REVIEW" : item.status,
            ownerId: String(item.owner.id),
            dueAt: item.dueAt ?? "",
            expiresAt: item.expiresAt ?? "",
            evidence: item.evidence.join(", "),
            details: Object.fromEntries(
              Object.entries(item.details).map(([key, value]) => [
                key,
                Array.isArray(value) ? value.join(", ") : String(value ?? ""),
              ]),
            ),
          }
        : { ...empty, ownerId: String(user?.id ?? ""), details: {} },
    );
    setOpen(true);
  };
  const approve = async (item: RecordItem) => {
    await api.post(`/regulatory-records/${item.id}/approve`);
    setMessage("Dérogation approuvée.");
    await refresh();
  };
  const remove = async (item: RecordItem) => {
    if (!window.confirm(`Supprimer « ${item.title} » ?`)) return;
    await api.delete(`/regulatory-records/${item.id}`);
    await refresh();
  };
  if (records.isLoading)
    return (
      <CircularProgress aria-label="Chargement du registre réglementaire" />
    );
  if (records.isError)
    return (
      <Alert severity="error">
        Impossible de charger le registre réglementaire.
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
            Vie privée et obligations
          </Typography>
          <Typography color="text.secondary">
            Traitements, AIPD, violations, veille et dérogations
          </Typography>
        </div>
        {canManage && (
          <Button
            startIcon={<Add />}
            variant="contained"
            onClick={() => begin()}
          >
            Nouvel enregistrement
          </Button>
        )}
      </Stack>
      {message && (
        <Alert severity="success" onClose={() => setMessage(null)}>
          {message}
        </Alert>
      )}
      <TextField
        select
        label="Filtrer par registre"
        value={filter}
        onChange={(e) => setFilter(e.target.value)}
        sx={{ maxWidth: 320 }}
      >
        <MenuItem value="ALL">Tous les registres</MenuItem>
        {types.map((type) => (
          <MenuItem key={type} value={type}>
            {labels[type]}
          </MenuItem>
        ))}
      </TextField>
      {filtered.length === 0 && (
        <Alert severity="info">Aucun enregistrement réglementaire.</Alert>
      )}
      <Stack
        sx={{
          display: "grid",
          gridTemplateColumns: { xs: "1fr", md: "repeat(2, 1fr)" },
          gap: 2,
        }}
      >
        {filtered.map((item) => (
          <Card variant="outlined" key={item.id}>
            <CardContent>
              <Stack spacing={1}>
                <Stack direction="row" justifyContent="space-between" gap={1}>
                  <Typography fontWeight={750}>{item.title}</Typography>
                  <Chip
                    size="small"
                    label={item.status}
                    color={
                      item.status === "APPROVED" || item.status === "COMPLIANT"
                        ? "success"
                        : item.status === "NON_COMPLIANT" ||
                            item.status === "EXPIRED"
                          ? "error"
                          : "default"
                    }
                  />
                </Stack>
                <Typography variant="body2">
                  {labels[item.type] ?? item.type} · {item.owner.name}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  Échéance {item.dueAt ?? item.expiresAt ?? "non définie"} ·{" "}
                  {item.evidence.length} preuve(s)
                </Typography>
                {item.approvedBy && (
                  <Typography variant="caption">
                    Approuvé par {item.approvedBy.name}
                  </Typography>
                )}
              </Stack>
            </CardContent>
            {canManage && (
              <CardActions>
                <Button
                  size="small"
                  startIcon={<EditOutlined />}
                  onClick={() => begin(item)}
                >
                  Modifier
                </Button>
                {isAdmin &&
                  item.type === "EXCEPTION" &&
                  item.status !== "APPROVED" &&
                  item.owner.id !== user?.id && (
                    <Button
                      size="small"
                      color="success"
                      startIcon={<VerifiedOutlined />}
                      onClick={() => void approve(item)}
                    >
                      Approuver
                    </Button>
                  )}
                <Button
                  size="small"
                  color="error"
                  startIcon={<DeleteOutline />}
                  onClick={() => void remove(item)}
                >
                  Supprimer
                </Button>
              </CardActions>
            )}
          </Card>
        ))}
      </Stack>
      <Dialog
        open={open}
        onClose={() => setOpen(false)}
        fullWidth
        maxWidth="md"
        component="form"
        onSubmit={(event: FormEvent) => {
          event.preventDefault();
          save.mutate();
        }}
      >
        <DialogTitle>
          {selected
            ? "Modifier l’enregistrement"
            : "Nouvel enregistrement réglementaire"}
        </DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            {save.isError && (
              <Alert severity="error">
                L’opération a échoué. Vérifiez les champs obligatoires et les
                règles d’approbation.
              </Alert>
            )}
            <Stack direction={{ xs: "column", sm: "row" }} gap={2}>
              <TextField
                select
                fullWidth
                label="Type"
                value={form.type}
                disabled={Boolean(selected)}
                onChange={(e) =>
                  setForm({ ...form, type: e.target.value, details: {} })
                }
              >
                {types.map((type) => (
                  <MenuItem key={type} value={type}>
                    {labels[type]}
                  </MenuItem>
                ))}
              </TextField>
              <TextField
                select
                fullWidth
                label="Statut"
                value={form.status}
                onChange={(e) => setForm({ ...form, status: e.target.value })}
              >
                {statuses.map((status) => (
                  <MenuItem key={status} value={status}>
                    {status}
                  </MenuItem>
                ))}
              </TextField>
              <TextField
                select
                required
                fullWidth
                label="Responsable"
                value={form.ownerId}
                onChange={(e) => setForm({ ...form, ownerId: e.target.value })}
              >
                {users.data?.map((item) => (
                  <MenuItem key={item.id} value={item.id}>
                    {item.firstName} {item.lastName}
                  </MenuItem>
                ))}
              </TextField>
            </Stack>
            <TextField
              required
              label="Titre"
              value={form.title}
              onChange={(e) => setForm({ ...form, title: e.target.value })}
            />
            {fields[form.type].map((field) => (
              <TextField
                key={field.key}
                required
                multiline
                minRows={field.list ? 1 : 2}
                label={`${field.label}${field.list ? " (séparés par des virgules)" : ""}`}
                value={form.details[field.key] ?? ""}
                onChange={(e) =>
                  setForm({
                    ...form,
                    details: { ...form.details, [field.key]: e.target.value },
                  })
                }
              />
            ))}
            <TextField
              label="Références de preuves (virgules)"
              value={form.evidence}
              onChange={(e) => setForm({ ...form, evidence: e.target.value })}
            />
            <Stack direction={{ xs: "column", sm: "row" }} gap={2}>
              <TextField
                fullWidth
                type="date"
                label="Échéance"
                value={form.dueAt}
                onChange={(e) => setForm({ ...form, dueAt: e.target.value })}
                slotProps={{ inputLabel: { shrink: true } }}
              />
              <TextField
                required={form.type === "EXCEPTION"}
                fullWidth
                type="date"
                label="Expiration"
                value={form.expiresAt}
                onChange={(e) =>
                  setForm({ ...form, expiresAt: e.target.value })
                }
                slotProps={{ inputLabel: { shrink: true } }}
              />
            </Stack>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setOpen(false)}>Annuler</Button>
          <Button type="submit" variant="contained" disabled={save.isPending}>
            Enregistrer
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
