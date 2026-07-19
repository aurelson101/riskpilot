import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Add, DeleteOutline, EditOutlined } from "@mui/icons-material";
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
import type { Asset, Scope, User } from "../api/types";
import { useAuth } from "../auth/useAuth";

type InventoryKind =
  "scopes" | "assets" | "threats" | "vulnerabilities" | "security-controls";
type InventoryItem = Record<string, unknown> & {
  id: number;
  name: string;
  description?: string | null;
  status?: string;
};
type FormData = Record<string, string | number | number[] | null>;

const configurations: Record<
  InventoryKind,
  {
    title: string;
    subtitle: string;
    columns: Array<{ key: string; label: string }>;
  }
> = {
  scopes: {
    title: "Périmètres",
    subtitle: "Structure organisationnelle et technique",
    columns: [
      { key: "type", label: "Type" },
      { key: "owner", label: "Responsable" },
    ],
  },
  assets: {
    title: "Actifs",
    subtitle: "Inventaire des actifs métier et techniques",
    columns: [
      { key: "type", label: "Type" },
      { key: "scope", label: "Périmètre" },
      { key: "criticality", label: "Criticité" },
      { key: "owner", label: "Responsable" },
    ],
  },
  threats: {
    title: "Menaces",
    subtitle: "Catalogue des menaces applicables",
    columns: [
      { key: "category", label: "Catégorie" },
      { key: "source", label: "Source" },
    ],
  },
  vulnerabilities: {
    title: "Vulnérabilités",
    subtitle: "Faiblesses et actifs affectés",
    columns: [
      { key: "category", label: "Catégorie" },
      { key: "severity", label: "Sévérité" },
      { key: "affectedAssets", label: "Actifs affectés" },
    ],
  },
  "security-controls": {
    title: "Mesures de sécurité",
    subtitle: "Mesures existantes et efficacité déclarée",
    columns: [
      { key: "category", label: "Catégorie" },
      { key: "effectiveness", label: "Efficacité (%)" },
      { key: "implementationStatus", label: "Déploiement" },
      { key: "owner", label: "Responsable" },
    ],
  },
};
const types: Record<InventoryKind, string[]> = {
  scopes: [
    "ORGANIZATION",
    "BUSINESS_UNIT",
    "SITE",
    "DEPARTMENT",
    "PROJECT",
    "APPLICATION",
    "INFRASTRUCTURE",
  ],
  assets: [
    "BUSINESS_PROCESS",
    "DATA",
    "APPLICATION",
    "SERVER",
    "NETWORK",
    "WORKSTATION",
    "CLOUD_SERVICE",
    "SUPPLIER",
    "FACILITY",
    "OTHER",
  ],
  threats: [],
  vulnerabilities: [],
  "security-controls": [],
};
const initialForms: Record<InventoryKind, FormData> = {
  scopes: {
    name: "",
    description: "",
    type: "DEPARTMENT",
    parentScopeId: null,
    ownerId: null,
    status: "ACTIVE",
  },
  assets: {
    name: "",
    description: "",
    type: "OTHER",
    criticality: 3,
    confidentiality: 3,
    integrity: 3,
    availability: 3,
    ownerId: null,
    scopeId: "",
    relatedAssetIds: [],
    status: "ACTIVE",
  },
  threats: {
    name: "",
    description: "",
    category: "HUMAN",
    source: "",
    status: "ACTIVE",
  },
  vulnerabilities: {
    name: "",
    description: "",
    category: "CONFIGURATION",
    severity: "MEDIUM",
    affectedAssetIds: [],
    status: "OPEN",
  },
  "security-controls": {
    name: "",
    description: "",
    category: "ACCESS",
    effectiveness: 0,
    implementationStatus: "NOT_IMPLEMENTED",
    ownerId: null,
  },
};

function renderValue(value: unknown): string {
  if (value == null) return "—";
  if (Array.isArray(value))
    return (
      value
        .map((item) =>
          typeof item === "object" && item && "name" in item
            ? String(item.name)
            : String(item),
        )
        .join(", ") || "—"
    );
  if (typeof value === "object") {
    if ("firstName" in value && "lastName" in value)
      return `${String(value.firstName)} ${String(value.lastName)}`;
    if ("name" in value) return String(value.name);
  }
  return String(value);
}
function apiError(error: unknown): string {
  return axios.isAxiosError<{ message?: string }>(error)
    ? (error.response?.data?.message ?? "L’opération a échoué.")
    : "L’opération a échoué.";
}
function relationId(value: unknown): number | null {
  return typeof value === "object" && value && "id" in value
    ? Number(value.id)
    : null;
}

export function InventoryPage({ kind }: { kind: InventoryKind }) {
  const { user } = useAuth();
  const canManage = user?.roles.some((role) =>
    ["ROLE_SUPER_ADMIN", "ROLE_ADMIN", "ROLE_RISK_MANAGER"].includes(role),
  );
  const config = configurations[kind];
  const queryClient = useQueryClient();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<InventoryItem | null>(null);
  const [form, setForm] = useState<FormData>({ ...initialForms[kind] });
  const [error, setError] = useState("");
  const query = useQuery({
    queryKey: [kind],
    queryFn: async () => (await api.get<InventoryItem[]>(`/${kind}`)).data,
  });
  const users = useQuery({
    queryKey: ["users"],
    queryFn: async () => (await api.get<User[]>("/users")).data,
    enabled: Boolean(canManage),
  });
  const scopes = useQuery({
    queryKey: ["scopes"],
    queryFn: async () => (await api.get<Scope[]>("/scopes")).data,
  });
  const assets = useQuery({
    queryKey: ["assets"],
    queryFn: async () => (await api.get<Asset[]>("/assets")).data,
  });
  const save = useMutation({
    mutationFn: () =>
      editing
        ? api.put(`/${kind}/${editing.id}`, form)
        : api.post(`/${kind}`, form),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: [kind] });
      setDialogOpen(false);
    },
    onError: (caught) => setError(apiError(caught)),
  });
  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/${kind}/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [kind] }),
    onError: (caught) => setError(apiError(caught)),
  });
  const update = (key: string, value: FormData[string]) =>
    setForm((current) => ({ ...current, [key]: value }));
  function openCreate() {
    setEditing(null);
    setForm({ ...initialForms[kind] });
    setError("");
    setDialogOpen(true);
  }
  function openEdit(item: InventoryItem) {
    setEditing(item);
    const next = { ...initialForms[kind], ...item } as FormData;
    if (kind === "scopes") {
      next.parentScopeId = item.parentScopeId as number | null;
      next.ownerId = relationId(item.owner);
    }
    if (kind === "assets") {
      next.scopeId = relationId(item.scope);
      next.ownerId = relationId(item.owner);
      next.relatedAssetIds = Array.isArray(item.relatedAssets)
        ? item.relatedAssets
            .map(relationId)
            .filter((id): id is number => id !== null)
        : [];
    }
    if (kind === "vulnerabilities")
      next.affectedAssetIds = Array.isArray(item.affectedAssets)
        ? item.affectedAssets
            .map(relationId)
            .filter((id): id is number => id !== null)
        : [];
    if (kind === "security-controls") next.ownerId = relationId(item.owner);
    setForm(next);
    setError("");
    setDialogOpen(true);
  }
  function submit(event: FormEvent) {
    event.preventDefault();
    setError("");
    save.mutate();
  }
  if (query.isLoading) return <Typography>Chargement…</Typography>;
  if (query.isError)
    return (
      <Alert severity="error">
        Impossible de charger {config.title.toLowerCase()}.
      </Alert>
    );
  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Stack>
          <Typography variant="h4" fontWeight={750}>
            {config.title}
          </Typography>
          <Typography color="text.secondary">
            {config.subtitle} · {query.data?.length ?? 0} élément(s)
          </Typography>
        </Stack>
        {canManage && (
          <Button variant="contained" startIcon={<Add />} onClick={openCreate}>
            Créer
          </Button>
        )}
      </Stack>
      {error && !dialogOpen && <Alert severity="error">{error}</Alert>}
      <Card variant="outlined">
        <CardContent>
          <Table aria-label={config.title}>
            <TableHead>
              <TableRow>
                <TableCell>Nom</TableCell>
                {config.columns.map((column) => (
                  <TableCell key={column.key}>{column.label}</TableCell>
                ))}
                {kind !== "security-controls" && <TableCell>Statut</TableCell>}
                {canManage && <TableCell align="right">Actions</TableCell>}
              </TableRow>
            </TableHead>
            <TableBody>
              {query.data?.map((item) => (
                <TableRow key={item.id} hover>
                  <TableCell>
                    <Typography fontWeight={650}>{item.name}</Typography>
                  </TableCell>
                  {config.columns.map((column) => (
                    <TableCell key={column.key}>
                      {renderValue(item[column.key])}
                    </TableCell>
                  ))}
                  {kind !== "security-controls" && (
                    <TableCell>
                      <Chip
                        size="small"
                        label={item.status}
                        color={
                          item.status === "ACTIVE" || item.status === "OPEN"
                            ? "success"
                            : "default"
                        }
                      />
                    </TableCell>
                  )}
                  {canManage && (
                    <TableCell align="right">
                      <IconButton
                        aria-label="Modifier"
                        onClick={() => openEdit(item)}
                      >
                        <EditOutlined />
                      </IconButton>
                      <IconButton
                        aria-label="Supprimer"
                        color="error"
                        onClick={() =>
                          window.confirm(`Supprimer « ${item.name} » ?`) &&
                          remove.mutate(item.id)
                        }
                      >
                        <DeleteOutline />
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
        maxWidth="sm"
      >
        <Stack component="form" onSubmit={submit}>
          <DialogTitle>
            {editing ? "Modifier" : "Créer"} · {config.title}
          </DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ pt: 1 }}>
              {error && <Alert severity="error">{error}</Alert>}
              <TextField
                required
                label="Nom"
                value={form.name}
                onChange={(e) => update("name", e.target.value)}
              />
              <TextField
                label="Description"
                multiline
                minRows={2}
                value={form.description ?? ""}
                onChange={(e) => update("description", e.target.value)}
              />
              {types[kind].length > 0 && (
                <FormControl>
                  <InputLabel>Type</InputLabel>
                  <Select
                    label="Type"
                    value={form.type ?? ""}
                    onChange={(e) => update("type", e.target.value)}
                  >
                    {types[kind].map((value) => (
                      <MenuItem key={value} value={value}>
                        {value}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              )}
              {(kind === "threats" ||
                kind === "vulnerabilities" ||
                kind === "security-controls") && (
                <TextField
                  required
                  label="Catégorie"
                  value={form.category ?? ""}
                  onChange={(e) => update("category", e.target.value)}
                />
              )}
              {kind === "threats" && (
                <TextField
                  label="Source"
                  value={form.source ?? ""}
                  onChange={(e) => update("source", e.target.value)}
                />
              )}
              {kind === "vulnerabilities" && (
                <FormControl>
                  <InputLabel>Sévérité</InputLabel>
                  <Select
                    label="Sévérité"
                    value={form.severity}
                    onChange={(e) => update("severity", e.target.value)}
                  >
                    {["LOW", "MEDIUM", "HIGH", "CRITICAL"].map((value) => (
                      <MenuItem key={value} value={value}>
                        {value}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              )}
              {kind === "assets" && (
                <>
                  <FormControl required>
                    <InputLabel>Périmètre</InputLabel>
                    <Select
                      label="Périmètre"
                      value={form.scopeId}
                      onChange={(e) =>
                        update("scopeId", Number(e.target.value))
                      }
                    >
                      {scopes.data?.map((scope) => (
                        <MenuItem key={scope.id} value={scope.id}>
                          {scope.name}
                        </MenuItem>
                      ))}
                    </Select>
                  </FormControl>
                  {[
                    "criticality",
                    "confidentiality",
                    "integrity",
                    "availability",
                  ].map((field) => (
                    <TextField
                      key={field}
                      type="number"
                      label={field}
                      inputProps={{ min: 1, max: 5 }}
                      value={form[field]}
                      onChange={(e) => update(field, Number(e.target.value))}
                    />
                  ))}
                </>
              )}
              {kind === "scopes" && (
                <FormControl>
                  <InputLabel>Parent</InputLabel>
                  <Select
                    label="Parent"
                    value={form.parentScopeId ?? ""}
                    onChange={(e) =>
                      update(
                        "parentScopeId",
                        e.target.value === "" ? null : Number(e.target.value),
                      )
                    }
                  >
                    <MenuItem value="">Aucun</MenuItem>
                    {scopes.data
                      ?.filter((scope) => scope.id !== editing?.id)
                      .map((scope) => (
                        <MenuItem key={scope.id} value={scope.id}>
                          {scope.name}
                        </MenuItem>
                      ))}
                  </Select>
                </FormControl>
              )}
              {(kind === "scopes" ||
                kind === "assets" ||
                kind === "security-controls") && (
                <FormControl>
                  <InputLabel>Responsable</InputLabel>
                  <Select
                    label="Responsable"
                    value={form.ownerId ?? ""}
                    onChange={(e) =>
                      update(
                        "ownerId",
                        e.target.value === "" ? null : Number(e.target.value),
                      )
                    }
                  >
                    <MenuItem value="">Aucun</MenuItem>
                    {users.data?.map((user) => (
                      <MenuItem key={user.id} value={user.id}>
                        {user.firstName} {user.lastName}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              )}
              {kind === "vulnerabilities" && (
                <FormControl>
                  <InputLabel>Actifs affectés</InputLabel>
                  <Select
                    multiple
                    label="Actifs affectés"
                    value={form.affectedAssetIds as number[]}
                    onChange={(e) =>
                      update(
                        "affectedAssetIds",
                        typeof e.target.value === "string"
                          ? []
                          : e.target.value,
                      )
                    }
                  >
                    {assets.data?.map((asset) => (
                      <MenuItem key={asset.id} value={asset.id}>
                        {asset.name}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              )}
              {kind === "security-controls" && (
                <>
                  <TextField
                    type="number"
                    label="Efficacité (%)"
                    inputProps={{ min: 0, max: 100 }}
                    value={form.effectiveness}
                    onChange={(e) =>
                      update("effectiveness", Number(e.target.value))
                    }
                  />
                  <FormControl>
                    <InputLabel>Déploiement</InputLabel>
                    <Select
                      label="Déploiement"
                      value={form.implementationStatus}
                      onChange={(e) =>
                        update("implementationStatus", e.target.value)
                      }
                    >
                      {[
                        "NOT_IMPLEMENTED",
                        "PLANNED",
                        "PARTIAL",
                        "IMPLEMENTED",
                        "INEFFECTIVE",
                      ].map((value) => (
                        <MenuItem key={value} value={value}>
                          {value}
                        </MenuItem>
                      ))}
                    </Select>
                  </FormControl>
                </>
              )}
              {kind !== "security-controls" && (
                <FormControl>
                  <InputLabel>Statut</InputLabel>
                  <Select
                    label="Statut"
                    value={form.status}
                    onChange={(e) => update("status", e.target.value)}
                  >
                    {(kind === "vulnerabilities"
                      ? [
                          "OPEN",
                          "IN_PROGRESS",
                          "REMEDIATED",
                          "ACCEPTED",
                          "CLOSED",
                        ]
                      : ["ACTIVE", "INACTIVE", "ARCHIVED"]
                    ).map((value) => (
                      <MenuItem key={value} value={value}>
                        {value}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              )}
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
