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
import { api } from "../api/client";
import type { User } from "../api/types";
import { useAuth } from "../auth/useAuth";

type ThirdParty = {
  id: number;
  name: string;
  contactEmail: string | null;
  services: string | null;
  criticality: string;
  status: string;
  cyberScore: number;
  nextAssessmentAt: string | null;
  owner: { id: number; name: string };
  assessments: Array<{
    id: number;
    title: string;
    status: string;
    score: number;
  }>;
};
export function ThirdPartiesPage() {
  const { user } = useAuth();
  const client = useQueryClient();
  const [dialog, setDialog] = useState(false);
  const [form, setForm] = useState({
    name: "",
    contactEmail: "",
    services: "",
    criticality: "MEDIUM",
    status: "ACTIVE",
    ownerId: String(user?.id ?? ""),
    dataCategories: "",
    contractReference: "",
    sla: "",
    dependencies: "",
    exitPlan: "",
    contractEndsAt: "",
    nextAssessmentAt: "",
  });
  const query = useQuery({
    queryKey: ["third-parties"],
    queryFn: async () => (await api.get<ThirdParty[]>("/third-parties")).data,
  });
  const users = useQuery({
    queryKey: ["users"],
    queryFn: async () => (await api.get<User[]>("/users")).data,
  });
  const create = useMutation({
    mutationFn: () =>
      api.post("/third-parties", {
        ...form,
        ownerId: Number(form.ownerId),
        dataCategories: form.dataCategories
          .split(",")
          .map((item) => item.trim())
          .filter(Boolean),
        contractEndsAt: form.contractEndsAt || null,
        nextAssessmentAt: form.nextAssessmentAt || null,
      }),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["third-parties"] });
      setDialog(false);
    },
  });
  if (query.isError)
    return (
      <Alert severity="error">
        Impossible de charger le registre des tiers.
      </Alert>
    );
  return (
    <Stack spacing={3}>
      <Stack
        direction={{ xs: "column", sm: "row" }}
        justifyContent="space-between"
        gap={2}
      >
        <Stack>
          <Typography variant="h4" fontWeight={750}>
            Tiers et fournisseurs
          </Typography>
          <Typography color="text.secondary">
            Dépendances, contrats, cyberscore, questionnaires et plans de sortie
          </Typography>
        </Stack>
        <Button
          variant="contained"
          startIcon={<Add />}
          onClick={() => setDialog(true)}
        >
          Ajouter un tiers
        </Button>
      </Stack>
      {query.data?.length === 0 && (
        <Alert severity="info">Aucun tiers enregistré.</Alert>
      )}
      <Stack
        sx={{
          display: "grid",
          gridTemplateColumns: { xs: "1fr", md: "repeat(2, minmax(0, 1fr))" },
          gap: 2,
        }}
      >
        {query.data?.map((item) => (
          <Card variant="outlined" key={item.id}>
            <CardContent>
              <Stack spacing={1}>
                <Stack direction="row" justifyContent="space-between">
                  <Typography fontWeight={750}>{item.name}</Typography>
                  <Chip
                    size="small"
                    label={item.criticality}
                    color={
                      item.criticality === "CRITICAL"
                        ? "error"
                        : item.criticality === "HIGH"
                          ? "warning"
                          : "default"
                    }
                  />
                </Stack>
                <Typography variant="body2">
                  {item.services ?? "Services à documenter"}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  Responsable : {item.owner.name} ·{" "}
                  {item.contactEmail ?? "contact absent"}
                </Typography>
                <Stack direction="row" gap={1}>
                  <Chip
                    size="small"
                    label={`Cyberscore ${item.cyberScore}%`}
                    color={item.cyberScore >= 70 ? "success" : "warning"}
                  />
                  <Chip
                    size="small"
                    label={`${item.assessments.length} évaluation(s)`}
                  />
                </Stack>
              </Stack>
            </CardContent>
          </Card>
        ))}
      </Stack>
      <Dialog
        open={dialog}
        onClose={() => setDialog(false)}
        fullWidth
        maxWidth="md"
      >
        <Stack
          component="form"
          onSubmit={(event: FormEvent) => {
            event.preventDefault();
            create.mutate();
          }}
        >
          <DialogTitle>Nouveau tiers</DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ pt: 1 }}>
              {create.isError && (
                <Alert severity="error">Création impossible.</Alert>
              )}
              <TextField
                required
                label="Nom"
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
              />
              <TextField
                type="email"
                label="Contact"
                value={form.contactEmail}
                onChange={(e) =>
                  setForm({ ...form, contactEmail: e.target.value })
                }
              />
              <TextField
                label="Services"
                value={form.services}
                onChange={(e) => setForm({ ...form, services: e.target.value })}
              />
              <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                <FormControl fullWidth>
                  <InputLabel>Criticité</InputLabel>
                  <Select
                    label="Criticité"
                    value={form.criticality}
                    onChange={(e) =>
                      setForm({ ...form, criticality: String(e.target.value) })
                    }
                  >
                    {["LOW", "MEDIUM", "HIGH", "CRITICAL"].map((value) => (
                      <MenuItem key={value} value={value}>
                        {value}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
                <FormControl fullWidth>
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
              <TextField
                label="Catégories de données (séparées par virgules)"
                value={form.dataCategories}
                onChange={(e) =>
                  setForm({ ...form, dataCategories: e.target.value })
                }
              />
              <TextField
                multiline
                label="Dépendances"
                value={form.dependencies}
                onChange={(e) =>
                  setForm({ ...form, dependencies: e.target.value })
                }
              />
              <TextField
                multiline
                label="Plan de sortie"
                value={form.exitPlan}
                onChange={(e) => setForm({ ...form, exitPlan: e.target.value })}
              />
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
