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
  IconButton,
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
import type { Organization } from "../api/types";
import { useAuth } from "../auth/useAuth";

type Form = {
  name: string;
  description: string;
  status: string;
  lowMax: number;
  moderateMax: number;
  highMax: number;
};
const empty: Form = {
  name: "",
  description: "",
  status: "ACTIVE",
  lowMax: 4,
  moderateMax: 9,
  highMax: 16,
};
const errorMessage = (error: unknown) =>
  axios.isAxiosError<{ message?: string }>(error)
    ? (error.response?.data?.message ?? "L’opération a échoué.")
    : "L’opération a échoué.";

export function OrganizationsPage() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<Organization | null>(null);
  const [form, setForm] = useState<Form>(empty);
  const [error, setError] = useState("");
  const query = useQuery({
    queryKey: ["organizations"],
    queryFn: async () => (await api.get<Organization[]>("/organizations")).data,
  });
  const save = useMutation({
    mutationFn: () =>
      editing
        ? api.put(`/organizations/${editing.id}`, form)
        : api.post("/organizations", {
            name: form.name,
            description: form.description,
          }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["organizations"] });
      setDialogOpen(false);
    },
    onError: (caught) => setError(errorMessage(caught)),
  });
  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/organizations/${id}`),
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: ["organizations"] }),
    onError: (caught) => setError(errorMessage(caught)),
  });
  function openCreate() {
    setEditing(null);
    setForm(empty);
    setError("");
    setDialogOpen(true);
  }
  function openEdit(item: Organization) {
    setEditing(item);
    setForm({
      name: item.name,
      description: item.description ?? "",
      status: item.status,
      lowMax: item.riskThresholds.lowMax,
      moderateMax: item.riskThresholds.moderateMax,
      highMax: item.riskThresholds.highMax,
    });
    setError("");
    setDialogOpen(true);
  }
  function submit(event: FormEvent) {
    event.preventDefault();
    setError("");
    save.mutate();
  }
  return (
    <Stack spacing={3}>
      <Stack
        direction={{ xs: "column", sm: "row" }}
        justifyContent="space-between"
        gap={2}
      >
        <Stack>
          <Typography variant="h4" fontWeight={750}>
            Organisations
          </Typography>
          <Typography color="text.secondary">
            Administration des tenants et seuils de risque.
          </Typography>
        </Stack>
        <Button variant="contained" startIcon={<Add />} onClick={openCreate}>
          Créer une organisation
        </Button>
      </Stack>
      {error && !dialogOpen && <Alert severity="error">{error}</Alert>}
      <Card variant="outlined">
        <CardContent>
          <Table>
            <TableHead>
              <TableRow>
                <TableCell>Organisation</TableCell>
                <TableCell>Statut</TableCell>
                <TableCell>Seuils faible / modéré / élevé</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {query.data?.map((item) => (
                <TableRow key={item.id}>
                  <TableCell>
                    <Typography fontWeight={650}>{item.name}</Typography>
                    <Typography variant="caption">
                      {item.description}
                    </Typography>
                  </TableCell>
                  <TableCell>
                    <Chip
                      size="small"
                      color={item.status === "ACTIVE" ? "success" : "default"}
                      label={item.status}
                    />
                  </TableCell>
                  <TableCell>
                    {item.riskThresholds.lowMax} /{" "}
                    {item.riskThresholds.moderateMax} /{" "}
                    {item.riskThresholds.highMax}
                  </TableCell>
                  <TableCell align="right">
                    <IconButton onClick={() => openEdit(item)}>
                      <EditOutlined />
                    </IconButton>
                    <IconButton
                      color="error"
                      disabled={
                        item.id === user?.organization.id ||
                        item.status === "INACTIVE"
                      }
                      onClick={() =>
                        window.confirm(`Désactiver « ${item.name} » ?`) &&
                        remove.mutate(item.id)
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
            {editing ? "Modifier l’organisation" : "Créer une organisation"}
          </DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ pt: 1 }}>
              {error && <Alert severity="error">{error}</Alert>}
              <TextField
                required
                label="Nom"
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
              />
              <TextField
                multiline
                minRows={2}
                label="Description"
                value={form.description}
                onChange={(e) =>
                  setForm({ ...form, description: e.target.value })
                }
              />
              {editing && (
                <>
                  <TextField
                    select
                    label="Statut"
                    value={form.status}
                    onChange={(e) =>
                      setForm({ ...form, status: e.target.value })
                    }
                    slotProps={{ select: { native: true } }}
                  >
                    <option value="ACTIVE">ACTIVE</option>
                    <option value="INACTIVE">INACTIVE</option>
                  </TextField>
                  <Stack direction="row" spacing={2}>
                    {(["lowMax", "moderateMax", "highMax"] as const).map(
                      (key) => (
                        <TextField
                          key={key}
                          fullWidth
                          type="number"
                          label={key}
                          value={form[key]}
                          onChange={(e) =>
                            setForm({ ...form, [key]: Number(e.target.value) })
                          }
                        />
                      ),
                    )}
                  </Stack>
                </>
              )}
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setDialogOpen(false)}>Annuler</Button>
            <Button variant="contained" type="submit">
              Enregistrer
            </Button>
          </DialogActions>
        </Stack>
      </Dialog>
    </Stack>
  );
}
