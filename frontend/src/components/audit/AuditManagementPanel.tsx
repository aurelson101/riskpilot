import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Add } from "@mui/icons-material";
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
  InputLabel,
  MenuItem,
  Select,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { useState, type FormEvent } from "react";
import { api } from "../../api/client";
import type { User } from "../../api/types";
import { useAuth } from "../../auth/useAuth";

type Program = {
  id: number;
  year: number;
  title: string;
  objectives: string | null;
  status: string;
  owner: { id: number; name: string };
  engagementCount: number;
};
type Dashboard = {
  programs: number;
  engagements: number;
  completedEngagements: number;
  openFindings: number;
  overdueFindings: number;
};

export function AuditManagementPanel() {
  const { user } = useAuth();
  const client = useQueryClient();
  const [dialog, setDialog] = useState(false);
  const [form, setForm] = useState({
    year: new Date().getFullYear(),
    title: `Programme d’audit ${new Date().getFullYear()}`,
    objectives: "",
    status: "DRAFT",
    ownerId: String(user?.id ?? ""),
  });
  const programs = useQuery({
    queryKey: ["audit-programs"],
    queryFn: async () =>
      (await api.get<Program[]>("/audit-management/programs")).data,
  });
  const dashboard = useQuery({
    queryKey: ["audit-dashboard"],
    queryFn: async () =>
      (await api.get<Dashboard>("/audit-management/dashboard")).data,
  });
  const users = useQuery({
    queryKey: ["users"],
    queryFn: async () => (await api.get<User[]>("/users")).data,
  });
  const create = useMutation({
    mutationFn: () =>
      api.post("/audit-management/programs", {
        ...form,
        ownerId: Number(form.ownerId),
      }),
    onSuccess: async () => {
      await Promise.all([
        client.invalidateQueries({ queryKey: ["audit-programs"] }),
        client.invalidateQueries({ queryKey: ["audit-dashboard"] }),
      ]);
      setDialog(false);
    },
  });
  return (
    <Stack spacing={2}>
      <Stack
        direction={{ xs: "column", sm: "row" }}
        justifyContent="space-between"
        gap={1}
      >
        <Stack>
          <Typography variant="h6" fontWeight={750}>
            Audits et CAPA
          </Typography>
          <Typography variant="body2" color="text.secondary">
            Programme annuel, indépendance, constats et validation d’efficacité.
          </Typography>
        </Stack>
        <Button
          variant="contained"
          startIcon={<Add />}
          onClick={() => setDialog(true)}
        >
          Nouveau programme
        </Button>
      </Stack>
      <Stack
        sx={{
          display: "grid",
          gridTemplateColumns: { xs: "repeat(2, 1fr)", lg: "repeat(5, 1fr)" },
          gap: 1.5,
        }}
      >
        {[
          { label: "Programmes", value: dashboard.data?.programs ?? 0 },
          { label: "Missions", value: dashboard.data?.engagements ?? 0 },
          {
            label: "Terminées",
            value: dashboard.data?.completedEngagements ?? 0,
          },
          {
            label: "Constats ouverts",
            value: dashboard.data?.openFindings ?? 0,
          },
          { label: "En retard", value: dashboard.data?.overdueFindings ?? 0 },
        ].map((item) => (
          <Card variant="outlined" key={item.label}>
            <CardContent>
              <Typography variant="h5" fontWeight={800}>
                {item.value}
              </Typography>
              <Typography variant="caption" color="text.secondary">
                {item.label}
              </Typography>
            </CardContent>
          </Card>
        ))}
      </Stack>
      {programs.data?.length === 0 && (
        <Alert severity="info">
          Aucun programme d’audit. Créez le plan annuel puis ajoutez les
          missions via l’API.
        </Alert>
      )}
      <Stack
        sx={{
          display: "grid",
          gridTemplateColumns: { xs: "1fr", md: "repeat(2, 1fr)" },
          gap: 2,
        }}
      >
        {programs.data?.map((program) => (
          <Card variant="outlined" key={program.id}>
            <CardContent>
              <Stack spacing={1}>
                <Stack direction="row" justifyContent="space-between">
                  <Typography fontWeight={750}>{program.title}</Typography>
                  <Chip size="small" label={program.status} />
                </Stack>
                <Typography variant="body2">
                  {program.year} · {program.owner.name}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {program.engagementCount} mission(s) ·{" "}
                  {program.objectives ?? "Objectifs à définir"}
                </Typography>
              </Stack>
            </CardContent>
          </Card>
        ))}
      </Stack>
      <Dialog
        open={dialog}
        onClose={() => setDialog(false)}
        fullWidth
        maxWidth="sm"
      >
        <Stack
          component="form"
          onSubmit={(event: FormEvent) => {
            event.preventDefault();
            create.mutate();
          }}
        >
          <DialogTitle>Nouveau programme annuel</DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ pt: 1 }}>
              {create.isError && (
                <Alert severity="error">
                  Création impossible ou année déjà planifiée.
                </Alert>
              )}
              <TextField
                required
                type="number"
                label="Année"
                value={form.year}
                onChange={(e) =>
                  setForm({ ...form, year: Number(e.target.value) })
                }
              />
              <TextField
                required
                label="Titre"
                value={form.title}
                onChange={(e) => setForm({ ...form, title: e.target.value })}
              />
              <TextField
                multiline
                minRows={2}
                label="Objectifs"
                value={form.objectives}
                onChange={(e) =>
                  setForm({ ...form, objectives: e.target.value })
                }
              />
              <FormControl required>
                <InputLabel>Responsable</InputLabel>
                <Select
                  label="Responsable"
                  value={form.ownerId}
                  onChange={(e) =>
                    setForm({ ...form, ownerId: String(e.target.value) })
                  }
                >
                  {users.data?.map((item) => (
                    <MenuItem key={item.id} value={item.id}>
                      {item.firstName} {item.lastName}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setDialog(false)}>Annuler</Button>
            <Button type="submit" variant="contained">
              Créer
            </Button>
          </DialogActions>
        </Stack>
      </Dialog>
    </Stack>
  );
}
