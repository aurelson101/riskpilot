import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Add, Download, FactCheck, Refresh } from "@mui/icons-material";
import {
  Alert,
  Button,
  Card,
  CardContent,
  Chip,
  CircularProgress,
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
import type {
  Framework,
  RequirementMapping,
  Scope,
  SecurityControlTest,
  StatementOfApplicability,
  User,
} from "../../api/types";
import { useAuth } from "../../auth/useAuth";

export function ComplianceGovernancePanel() {
  const { user } = useAuth();
  const client = useQueryClient();
  const [dialog, setDialog] = useState(false);
  const [form, setForm] = useState({
    title: "Déclaration d’applicabilité",
    frameworkId: "",
    scopeId: "",
    ownerId: String(user?.id ?? ""),
  });
  const statements = useQuery({
    queryKey: ["statements-of-applicability"],
    queryFn: async () =>
      (
        await api.get<StatementOfApplicability[]>(
          "/statements-of-applicability",
        )
      ).data,
  });
  const frameworks = useQuery({
    queryKey: ["frameworks"],
    queryFn: async () => (await api.get<Framework[]>("/frameworks")).data,
  });
  const scopes = useQuery({
    queryKey: ["scopes"],
    queryFn: async () => (await api.get<Scope[]>("/scopes")).data,
  });
  const users = useQuery({
    queryKey: ["users"],
    queryFn: async () => (await api.get<User[]>("/users")).data,
  });
  const controlTests = useQuery({
    queryKey: ["control-tests"],
    queryFn: async () =>
      (await api.get<SecurityControlTest[]>("/control-tests")).data,
  });
  const mappings = useQuery({
    queryKey: ["requirement-mappings"],
    queryFn: async () =>
      (await api.get<RequirementMapping[]>("/requirement-mappings")).data,
  });
  const create = useMutation({
    mutationFn: () =>
      api.post("/statements-of-applicability", {
        ...form,
        frameworkId: Number(form.frameworkId),
        scopeId: Number(form.scopeId),
        ownerId: Number(form.ownerId),
      }),
    onSuccess: async () => {
      await client.invalidateQueries({
        queryKey: ["statements-of-applicability"],
      });
      setDialog(false);
    },
  });
  const approve = useMutation({
    mutationFn: (id: number) =>
      api.post(`/statements-of-applicability/${id}/approve`),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: ["statements-of-applicability"] }),
  });
  const revise = useMutation({
    mutationFn: (id: number) =>
      api.post(`/statements-of-applicability/${id}/revise`),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: ["statements-of-applicability"] }),
  });
  const download = async (item: StatementOfApplicability) => {
    const response = await api.get(
      `/statements-of-applicability/${item.id}/export`,
      { responseType: "blob" },
    );
    const url = URL.createObjectURL(response.data);
    const link = document.createElement("a");
    link.href = url;
    link.download = `soa-${item.framework.name}-v${item.version}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  };

  if (statements.isLoading) return <CircularProgress />;
  if (statements.isError)
    return (
      <Alert severity="error">
        Impossible de charger les déclarations d’applicabilité.
      </Alert>
    );

  return (
    <Stack spacing={2}>
      <Stack
        direction={{ xs: "column", sm: "row" }}
        justifyContent="space-between"
        gap={1}
      >
        <Stack>
          <Typography variant="h6" fontWeight={750}>
            Déclarations d’applicabilité
          </Typography>
          <Typography variant="body2" color="text.secondary">
            Versions ISO 27001 exportables, preuves et liens vers risques,
            contrôles et actions.
          </Typography>
        </Stack>
        <Button
          startIcon={<Add />}
          variant="contained"
          onClick={() => setDialog(true)}
        >
          Nouvelle SoA
        </Button>
      </Stack>
      {statements.data?.length === 0 && (
        <Alert severity="info">
          Créez une SoA depuis un référentiel actif. Ses exigences seront
          ajoutées automatiquement.
        </Alert>
      )}
      <Stack
        sx={{
          display: "grid",
          gridTemplateColumns: { xs: "1fr", md: "repeat(2, minmax(0, 1fr))" },
          gap: 2,
        }}
      >
        {statements.data?.map((item) => (
          <Card variant="outlined" key={item.id}>
            <CardContent>
              <Stack spacing={1.25}>
                <Stack direction="row" justifyContent="space-between" gap={1}>
                  <Typography fontWeight={750}>{item.title}</Typography>
                  <Chip
                    size="small"
                    label={`${item.status} · v${item.version}`}
                    color={item.status === "APPROVED" ? "success" : "default"}
                  />
                </Stack>
                <Typography variant="body2">
                  {item.framework.name} {item.framework.version} ·{" "}
                  {item.scope.name}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {item.itemCount} exigences · Responsable : {item.owner.name}
                </Typography>
                <Stack direction="row" flexWrap="wrap" gap={1}>
                  <Button
                    size="small"
                    startIcon={<Download />}
                    onClick={() => void download(item)}
                  >
                    CSV
                  </Button>
                  {item.status !== "APPROVED" &&
                    item.status !== "SUPERSEDED" && (
                      <Button
                        size="small"
                        startIcon={<FactCheck />}
                        onClick={() => approve.mutate(item.id)}
                      >
                        Approuver
                      </Button>
                    )}
                  {item.status === "APPROVED" && (
                    <Button
                      size="small"
                      startIcon={<Refresh />}
                      onClick={() => revise.mutate(item.id)}
                    >
                      Réviser
                    </Button>
                  )}
                </Stack>
              </Stack>
            </CardContent>
          </Card>
        ))}
      </Stack>
      {(approve.isError || revise.isError) && (
        <Alert severity="warning">
          Action refusée : vérifiez la séparation responsable/approbateur et vos
          droits.
        </Alert>
      )}
      <Stack
        sx={{
          display: "grid",
          gridTemplateColumns: { xs: "1fr", lg: "repeat(2, minmax(0, 1fr))" },
          gap: 2,
        }}
      >
        <Card variant="outlined">
          <CardContent>
            <Typography fontWeight={750} gutterBottom>
              Tests d’efficacité des contrôles
            </Typography>
            <Stack spacing={1}>
              {controlTests.data?.length === 0 && (
                <Typography variant="body2" color="text.secondary">
                  Aucun test de conception ou d’efficacité opérationnelle.
                </Typography>
              )}
              {controlTests.data?.slice(0, 8).map((test) => (
                <Stack
                  key={test.id}
                  direction="row"
                  justifyContent="space-between"
                  gap={1}
                >
                  <Stack>
                    <Typography variant="body2" fontWeight={700}>
                      {test.control.name}
                    </Typography>
                    <Typography variant="caption" color="text.secondary">
                      {test.type} · prochaine revue {test.nextReviewAt}
                    </Typography>
                  </Stack>
                  <Chip
                    size="small"
                    label={test.result}
                    color={test.result === "EFFECTIVE" ? "success" : "warning"}
                  />
                </Stack>
              ))}
            </Stack>
          </CardContent>
        </Card>
        <Card variant="outlined">
          <CardContent>
            <Typography fontWeight={750} gutterBottom>
              Correspondances multinormes
            </Typography>
            <Stack spacing={1}>
              {mappings.data?.length === 0 && (
                <Typography variant="body2" color="text.secondary">
                  Aucune correspondance de preuves entre référentiels.
                </Typography>
              )}
              {mappings.data?.slice(0, 8).map((mapping) => (
                <Stack key={mapping.id} direction="row" gap={1}>
                  <Chip size="small" label={`${mapping.coveragePercent}%`} />
                  <Typography variant="body2">
                    {mapping.source.framework} {mapping.source.reference} →{" "}
                    {mapping.target.framework} {mapping.target.reference}
                    {mapping.inheritEvidence ? " · preuves héritées" : ""}
                  </Typography>
                </Stack>
              ))}
            </Stack>
          </CardContent>
        </Card>
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
          <DialogTitle>Nouvelle déclaration d’applicabilité</DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ pt: 1 }}>
              {create.isError && (
                <Alert severity="error">
                  Création impossible. Vérifiez les relations sélectionnées.
                </Alert>
              )}
              <TextField
                required
                label="Titre"
                value={form.title}
                onChange={(e) => setForm({ ...form, title: e.target.value })}
              />
              <FormControl required>
                <InputLabel>Référentiel</InputLabel>
                <Select
                  label="Référentiel"
                  value={form.frameworkId}
                  onChange={(e) =>
                    setForm({ ...form, frameworkId: String(e.target.value) })
                  }
                >
                  {frameworks.data
                    ?.filter((item) => item.status === "ACTIVE")
                    .map((item) => (
                      <MenuItem key={item.id} value={item.id}>
                        {item.name} · {item.version}
                      </MenuItem>
                    ))}
                </Select>
              </FormControl>
              <FormControl required>
                <InputLabel>Périmètre</InputLabel>
                <Select
                  label="Périmètre"
                  value={form.scopeId}
                  onChange={(e) =>
                    setForm({ ...form, scopeId: String(e.target.value) })
                  }
                >
                  {scopes.data?.map((item) => (
                    <MenuItem key={item.id} value={item.id}>
                      {item.name}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
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
            <Button
              type="submit"
              variant="contained"
              disabled={create.isPending}
            >
              Créer
            </Button>
          </DialogActions>
        </Stack>
      </Dialog>
    </Stack>
  );
}
