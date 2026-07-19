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
import type { User } from "../api/types";
import { useAuth } from "../auth/useAuth";

const roleLabels: Record<string, string> = {
  ROLE_SUPER_ADMIN: "Super administrateur",
  ROLE_ADMIN: "Administrateur",
  ROLE_RISK_MANAGER: "Risk manager",
  ROLE_ACTION_OWNER: "Responsable d’action",
  ROLE_AUDITOR: "Auditeur",
  ROLE_VIEWER: "Lecteur",
};
const statuses = ["ACTIVE", "INACTIVE", "LOCKED"];
type UserForm = {
  email: string;
  firstName: string;
  lastName: string;
  password: string;
  roles: string[];
  status: string;
};
const emptyForm: UserForm = {
  email: "",
  firstName: "",
  lastName: "",
  password: "",
  roles: ["ROLE_VIEWER"],
  status: "ACTIVE",
};

function errorMessage(error: unknown): string {
  if (axios.isAxiosError<{ message?: string }>(error))
    return error.response?.data?.message ?? "L’opération a échoué.";
  return "L’opération a échoué.";
}

export function UsersPage() {
  const { user: actor } = useAuth();
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState<User | null>(null);
  const [form, setForm] = useState<UserForm>(emptyForm);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [error, setError] = useState("");
  const isSuperAdmin = actor?.roles.includes("ROLE_SUPER_ADMIN") ?? false;
  const availableRoles = Object.keys(roleLabels).filter(
    (role) => role !== "ROLE_SUPER_ADMIN" || isSuperAdmin,
  );
  const users = useQuery({
    queryKey: ["users"],
    queryFn: async () => (await api.get<User[]>("/users")).data,
  });
  const save = useMutation({
    mutationFn: () =>
      editing
        ? api.put(`/users/${editing.id}`, {
            email: form.email,
            firstName: form.firstName,
            lastName: form.lastName,
            roles: form.roles,
            status: form.status,
          })
        : api.post("/users", {
            email: form.email,
            firstName: form.firstName,
            lastName: form.lastName,
            password: form.password,
            roles: form.roles,
          }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["users"] });
      setDialogOpen(false);
    },
    onError: (caught) => setError(errorMessage(caught)),
  });
  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/users/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["users"] }),
    onError: (caught) => setError(errorMessage(caught)),
  });

  function openCreate() {
    setEditing(null);
    setForm(emptyForm);
    setError("");
    setDialogOpen(true);
  }
  function openEdit(user: User) {
    setEditing(user);
    setForm({
      email: user.email,
      firstName: user.firstName,
      lastName: user.lastName,
      password: "",
      roles: user.roles,
      status: user.status,
    });
    setError("");
    setDialogOpen(true);
  }
  function submit(event: FormEvent) {
    event.preventDefault();
    setError("");
    save.mutate();
  }

  if (users.isError)
    return (
      <Alert severity="error">Impossible de charger les utilisateurs.</Alert>
    );
  const count = users.data?.length ?? 0;
  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="space-between" alignItems="center">
        <Stack>
          <Typography variant="h4" fontWeight={750}>
            Utilisateurs
          </Typography>
          <Typography color="text.secondary">
            {count} utilisateur(s) dans votre périmètre.
          </Typography>
        </Stack>
        <Button variant="contained" startIcon={<Add />} onClick={openCreate}>
          Créer un utilisateur
        </Button>
      </Stack>
      {error && !dialogOpen && <Alert severity="error">{error}</Alert>}
      <Card variant="outlined">
        <CardContent>
          <Table aria-label="Utilisateurs de l’organisation">
            <TableHead>
              <TableRow>
                <TableCell>Utilisateur</TableCell>
                <TableCell>Organisation</TableCell>
                <TableCell>Rôles</TableCell>
                <TableCell>Statut</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {users.data?.map((user) => (
                <TableRow key={user.id} hover>
                  <TableCell>
                    <Typography fontWeight={650}>
                      {user.firstName} {user.lastName}
                    </Typography>
                    <Typography variant="body2" color="text.secondary">
                      {user.email}
                    </Typography>
                  </TableCell>
                  <TableCell>{user.organization.name}</TableCell>
                  <TableCell>
                    <Stack direction="row" gap={0.5} flexWrap="wrap">
                      {user.roles.map((role) => (
                        <Chip
                          key={role}
                          size="small"
                          label={roleLabels[role] ?? role}
                          color={
                            role === "ROLE_SUPER_ADMIN" ? "primary" : "default"
                          }
                        />
                      ))}
                    </Stack>
                  </TableCell>
                  <TableCell>
                    <Chip
                      size="small"
                      color={user.status === "ACTIVE" ? "success" : "default"}
                      label={user.status}
                    />
                  </TableCell>
                  <TableCell align="right">
                    <IconButton
                      aria-label="Modifier"
                      onClick={() => openEdit(user)}
                    >
                      <EditOutlined />
                    </IconButton>
                    <IconButton
                      aria-label="Supprimer"
                      color="error"
                      disabled={
                        user.id === actor?.id || user.status === "INACTIVE"
                      }
                      onClick={() =>
                        window.confirm(
                          `Désactiver ${user.firstName} ${user.lastName} ?`,
                        ) && remove.mutate(user.id)
                      }
                    >
                      <DeleteOutline />
                    </IconButton>
                  </TableCell>
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
            {editing ? "Modifier l’utilisateur" : "Créer un utilisateur"}
          </DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ pt: 1 }}>
              {error && <Alert severity="error">{error}</Alert>}
              <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                <TextField
                  required
                  fullWidth
                  label="Prénom"
                  value={form.firstName}
                  onChange={(e) =>
                    setForm({ ...form, firstName: e.target.value })
                  }
                />
                <TextField
                  required
                  fullWidth
                  label="Nom"
                  value={form.lastName}
                  onChange={(e) =>
                    setForm({ ...form, lastName: e.target.value })
                  }
                />
              </Stack>
              <TextField
                required
                fullWidth
                type="email"
                label="Email"
                value={form.email}
                onChange={(e) => setForm({ ...form, email: e.target.value })}
              />
              {!editing && (
                <TextField
                  required
                  fullWidth
                  type="password"
                  label="Mot de passe initial"
                  helperText="12 caractères minimum"
                  inputProps={{ minLength: 12 }}
                  value={form.password}
                  onChange={(e) =>
                    setForm({ ...form, password: e.target.value })
                  }
                />
              )}
              <FormControl fullWidth>
                <InputLabel>Rôles</InputLabel>
                <Select
                  multiple
                  label="Rôles"
                  value={form.roles}
                  onChange={(e) =>
                    setForm({
                      ...form,
                      roles:
                        typeof e.target.value === "string"
                          ? e.target.value.split(",")
                          : e.target.value,
                    })
                  }
                >
                  {availableRoles.map((role) => (
                    <MenuItem key={role} value={role}>
                      {roleLabels[role]}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
              {editing && (
                <FormControl fullWidth>
                  <InputLabel>Statut</InputLabel>
                  <Select
                    label="Statut"
                    value={form.status}
                    onChange={(e) =>
                      setForm({ ...form, status: e.target.value })
                    }
                  >
                    {statuses.map((status) => (
                      <MenuItem key={status} value={status}>
                        {status}
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
              {save.isPending ? "Enregistrement…" : "Enregistrer"}
            </Button>
          </DialogActions>
        </Stack>
      </Dialog>
    </Stack>
  );
}
