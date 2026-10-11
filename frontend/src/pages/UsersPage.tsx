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
import { useConfirmation } from "../components/confirmation-context";

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
  const confirm = useConfirmation();
  const { user: actor } = useAuth();
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState<User | null>(null);
  const [form, setForm] = useState<UserForm>(emptyForm);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [error, setError] = useState("");
  const [mfaResetPassword, setMfaResetPassword] = useState("");
  const [mfaResetConfirmation, setMfaResetConfirmation] = useState("");
  const [mfaResetSuccess, setMfaResetSuccess] = useState("");
  const isSuperAdmin = actor?.roles.includes("ROLE_SUPER_ADMIN") ?? false;
  const canManage =
    isSuperAdmin || (actor?.roles.includes("ROLE_ADMIN") ?? false);
  const availableRoles = Object.keys(roleLabels).filter(
    (role) => role !== "ROLE_SUPER_ADMIN" || isSuperAdmin,
  );
  const users = useQuery({
    queryKey: ["users"],
    queryFn: async () => (await api.get<User[]>("/users")).data,
  });
  const save = useMutation({
    mutationFn: async () => {
      if (editing)
        await api.put(`/users/${editing.id}`, {
          email: form.email,
          firstName: form.firstName,
          lastName: form.lastName,
          roles: form.roles,
          status: form.status,
        });
      else
        await api.post("/users", {
          email: form.email,
          firstName: form.firstName,
          lastName: form.lastName,
          password: form.password,
          roles: form.roles,
        });
    },
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
  const resetMfa = useMutation({
    mutationFn: () =>
      api.post(`/users/${editing?.id}/mfa/disable`, {
        currentPassword: mfaResetPassword,
        confirmation: mfaResetConfirmation,
      }),
    onSuccess: async () => {
      setEditing((current) =>
        current ? { ...current, mfaEnabled: false } : current,
      );
      setMfaResetPassword("");
      setMfaResetConfirmation("");
      setMfaResetSuccess("MFA désactivé et sessions révoquées.");
      await queryClient.invalidateQueries({ queryKey: ["users"] });
    },
    onError: (caught) => setError(errorMessage(caught)),
  });

  function clearMfaReset() {
    setMfaResetPassword("");
    setMfaResetConfirmation("");
    setMfaResetSuccess("");
  }

  function openCreate() {
    setEditing(null);
    setForm(emptyForm);
    setError("");
    clearMfaReset();
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
    clearMfaReset();
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
  const activeUsers =
    users.data?.filter((user) => user.status === "ACTIVE") ?? [];
  const mfaUsers = activeUsers.filter((user) => user.mfaEnabled).length;
  const privilegedWithoutMfa = activeUsers.filter(
    (user) =>
      !user.mfaEnabled &&
      user.roles.some((role) =>
        ["ROLE_SUPER_ADMIN", "ROLE_ADMIN", "ROLE_RISK_MANAGER"].includes(role),
      ),
  ).length;
  return (
    <Stack spacing={3}>
      <Stack
        direction={{ xs: "column", sm: "row" }}
        justifyContent="space-between"
        alignItems={{ xs: "stretch", sm: "center" }}
        gap={2}
      >
        <Stack>
          <Typography variant="h4" fontWeight={750}>
            Utilisateurs
          </Typography>
          <Typography color="text.secondary">
            {count} utilisateur(s) dans votre périmètre.
          </Typography>
        </Stack>
        {canManage && (
          <Button variant="contained" startIcon={<Add />} onClick={openCreate}>
            Créer un utilisateur
          </Button>
        )}
      </Stack>
      {error && !dialogOpen && <Alert severity="error">{error}</Alert>}
      <Stack direction={{ xs: "column", sm: "row" }} spacing={1}>
        <Chip
          color={mfaUsers === activeUsers.length ? "success" : "warning"}
          label={`MFA actif : ${mfaUsers}/${activeUsers.length} comptes actifs`}
        />
        {privilegedWithoutMfa > 0 && (
          <Chip
            color="error"
            label={`${privilegedWithoutMfa} compte(s) privilégié(s) sans MFA`}
          />
        )}
      </Stack>
      <Card variant="outlined">
        <CardContent>
          <Table aria-label="Utilisateurs de l’organisation">
            <TableHead>
              <TableRow>
                <TableCell>Utilisateur</TableCell>
                <TableCell>Organisation</TableCell>
                <TableCell>Rôles</TableCell>
                <TableCell>MFA</TableCell>
                <TableCell>Statut</TableCell>
                {canManage && <TableCell align="right">Actions</TableCell>}
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
                      color={user.mfaEnabled ? "success" : "warning"}
                      label={user.mfaEnabled ? "Activé" : "À configurer"}
                    />
                  </TableCell>
                  <TableCell>
                    <Chip
                      size="small"
                      color={user.status === "ACTIVE" ? "success" : "default"}
                      label={user.status}
                    />
                  </TableCell>
                  {canManage && (
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
                        onClick={async () => {
                          if (
                            await confirm({
                              message: "confirmation.disableUser",
                              values: {
                                name: `${user.firstName} ${user.lastName}`,
                              },
                            })
                          )
                            remove.mutate(user.id);
                        }}
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
              {mfaResetSuccess && (
                <Alert severity="success">{mfaResetSuccess}</Alert>
              )}
              {editing?.mfaEnabled &&
                editing.id !== actor?.id &&
                (isSuperAdmin ||
                  !editing.roles.includes("ROLE_SUPER_ADMIN")) && (
                  <Card variant="outlined">
                    <CardContent>
                      <Stack spacing={2}>
                        <Alert severity="warning">
                          Récupération d’urgence uniquement : cette action
                          désactive le MFA et révoque toutes les sessions de
                          l’utilisateur.
                        </Alert>
                        <TextField
                          fullWidth
                          type="password"
                          autoComplete="current-password"
                          label="Votre mot de passe administrateur"
                          value={mfaResetPassword}
                          onChange={(event) =>
                            setMfaResetPassword(event.target.value)
                          }
                        />
                        <TextField
                          fullWidth
                          label="Confirmer l’adresse email"
                          helperText={`Saisissez exactement ${editing.email}`}
                          value={mfaResetConfirmation}
                          onChange={(event) =>
                            setMfaResetConfirmation(event.target.value)
                          }
                        />
                        <Button
                          type="button"
                          color="error"
                          variant="outlined"
                          disabled={
                            resetMfa.isPending ||
                            mfaResetPassword === "" ||
                            mfaResetConfirmation !== editing.email
                          }
                          onClick={() => {
                            setError("");
                            setMfaResetSuccess("");
                            resetMfa.mutate();
                          }}
                        >
                          {resetMfa.isPending
                            ? "Désactivation…"
                            : "Désactiver le MFA et révoquer les sessions"}
                        </Button>
                      </Stack>
                    </CardContent>
                  </Card>
                )}
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setDialogOpen(false)}>Annuler</Button>
            <Button
              type="submit"
              variant="contained"
              disabled={save.isPending || resetMfa.isPending}
            >
              {save.isPending ? "Enregistrement…" : "Enregistrer"}
            </Button>
          </DialogActions>
        </Stack>
      </Dialog>
    </Stack>
  );
}
