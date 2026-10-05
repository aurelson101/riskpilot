import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Add, DeleteOutline } from "@mui/icons-material";
import {
  Alert,
  Autocomplete,
  Box,
  Button,
  Card,
  CardContent,
  Checkbox,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  IconButton,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import axios from "axios";
import { useState, type FormEvent } from "react";
import { api } from "../../api/client";
import type {
  Framework,
  Requirement,
  RequirementMapping as Mapping,
} from "../../api/types";
import { useAuth } from "../../auth/useAuth";
import { useInterfaceLocale } from "../../i18n/InterfaceLocaleContext";

type RequirementMapping = Mapping & {
  direction?: "SOURCE_TO_TARGET";
  createdBy?: { id: number; name: string };
  createdAt?: string;
};

const managerRoles = [
  "ROLE_SUPER_ADMIN",
  "ROLE_ADMIN",
  "ROLE_RISK_MANAGER",
  "ROLE_AUDITOR",
];

const emptyForm = {
  sourceFrameworkId: "",
  targetFrameworkId: "",
  sourceRequirement: null as Requirement | null,
  targetRequirement: null as Requirement | null,
  coveragePercent: 100,
  rationale: "",
  inheritEvidence: false,
};

function responseMessage(error: unknown, fallback: string): string {
  return axios.isAxiosError<{ message?: string }>(error)
    ? (error.response?.data?.message ?? fallback)
    : fallback;
}

export function RequirementMappingsPanel() {
  const locale = useInterfaceLocale();
  const english = locale === "en";
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const canManage = Boolean(
    user?.roles.some((role) => managerRoles.includes(role)),
  );
  const [dialogOpen, setDialogOpen] = useState(false);
  const [form, setForm] = useState(emptyForm);
  const [mappingToDelete, setMappingToDelete] =
    useState<RequirementMapping | null>(null);

  const mappings = useQuery({
    queryKey: ["requirement-mappings"],
    queryFn: async ({ signal }) =>
      (
        await api.get<RequirementMapping[]>("/requirement-mappings", {
          signal,
        })
      ).data,
  });
  const frameworks = useQuery({
    queryKey: ["frameworks"],
    enabled: dialogOpen,
    queryFn: async ({ signal }) =>
      (await api.get<Framework[]>("/frameworks", { signal })).data,
  });
  const sourceRequirements = useQuery({
    queryKey: ["framework-requirements", form.sourceFrameworkId],
    enabled: dialogOpen && form.sourceFrameworkId !== "",
    queryFn: async ({ signal }) =>
      (
        await api.get<Requirement[]>(
          `/frameworks/${form.sourceFrameworkId}/requirements`,
          { signal },
        )
      ).data.filter((requirement) => requirement.status === "ACTIVE"),
  });
  const targetRequirements = useQuery({
    queryKey: ["framework-requirements", form.targetFrameworkId],
    enabled: dialogOpen && form.targetFrameworkId !== "",
    queryFn: async ({ signal }) =>
      (
        await api.get<Requirement[]>(
          `/frameworks/${form.targetFrameworkId}/requirements`,
          { signal },
        )
      ).data.filter((requirement) => requirement.status === "ACTIVE"),
  });

  const resetForm = () => setForm({ ...emptyForm });
  const refreshMappings = async () => {
    await queryClient.invalidateQueries({
      queryKey: ["requirement-mappings"],
    });
  };
  const createMapping = useMutation({
    mutationFn: () =>
      api.post("/requirement-mappings", {
        sourceRequirementId: form.sourceRequirement?.id,
        targetRequirementId: form.targetRequirement?.id,
        coveragePercent: form.coveragePercent,
        inheritEvidence: form.inheritEvidence,
        rationale: form.rationale.trim() || null,
      }),
    onSuccess: async () => {
      await refreshMappings();
      setDialogOpen(false);
      resetForm();
    },
  });
  const deleteMapping = useMutation({
    mutationFn: (id: number) => api.delete(`/requirement-mappings/${id}`),
    onSuccess: async () => {
      await refreshMappings();
      setMappingToDelete(null);
    },
  });
  const closeCreateDialog = () => {
    if (createMapping.isPending) return;
    setDialogOpen(false);
    createMapping.reset();
    resetForm();
  };

  const sameRequirement = Boolean(
    form.sourceRequirement &&
    form.targetRequirement &&
    form.sourceRequirement.id === form.targetRequirement.id,
  );
  const formIsValid = Boolean(
    form.sourceRequirement &&
    form.targetRequirement &&
    !sameRequirement &&
    Number.isInteger(form.coveragePercent) &&
    form.coveragePercent >= 1 &&
    form.coveragePercent <= 100 &&
    form.rationale.length <= 2000,
  );
  const formPending =
    createMapping.isPending ||
    frameworks.isLoading ||
    sourceRequirements.isLoading ||
    targetRequirements.isLoading;
  const activeFrameworks = (frameworks.data ?? []).filter(
    (framework) => framework.status === "ACTIVE",
  );
  const requirementLabel = (requirement: Requirement) =>
    `${requirement.reference} — ${requirement.title}`;
  const mappingName = (mapping: RequirementMapping) =>
    `${mapping.source.framework} ${mapping.source.reference} ${
      english ? "to" : "vers"
    } ${mapping.target.framework} ${mapping.target.reference}`;

  return (
    <Card variant="outlined">
      <CardContent>
        <Stack spacing={2}>
          <Stack
            direction={{ xs: "column", sm: "row" }}
            justifyContent="space-between"
            alignItems={{ xs: "stretch", sm: "center" }}
            gap={1}
          >
            <Box>
              <Typography variant="h6" fontWeight={750}>
                {english
                  ? "Multi-standard mappings"
                  : "Correspondances multinormes"}
              </Typography>
              <Typography variant="body2" color="text.secondary">
                {english
                  ? "Link requirements without duplicating frameworks or claiming automatic compliance."
                  : "Reliez les exigences sans dupliquer les référentiels ni déduire une conformité automatique."}
              </Typography>
            </Box>
            {canManage && (
              <Button
                variant="outlined"
                startIcon={<Add />}
                sx={{ flexShrink: 0, whiteSpace: "nowrap" }}
                onClick={() => {
                  createMapping.reset();
                  setDialogOpen(true);
                }}
              >
                {english ? "Map two requirements" : "Relier deux exigences"}
              </Button>
            )}
          </Stack>

          {mappings.isPending && (
            <Stack alignItems="center" py={2}>
              <CircularProgress
                size={28}
                aria-label={
                  english
                    ? "Loading mappings"
                    : "Chargement des correspondances"
                }
              />
            </Stack>
          )}
          {mappings.isError && (
            <Alert
              severity="error"
              action={
                <Button color="inherit" onClick={() => void mappings.refetch()}>
                  {english ? "Retry" : "Réessayer"}
                </Button>
              }
            >
              {english
                ? "Unable to load mappings."
                : "Impossible de charger les correspondances."}
            </Alert>
          )}
          {mappings.isSuccess && mappings.data.length === 0 && (
            <Alert severity="info">
              {english
                ? "No requirement mapping has been defined."
                : "Aucune correspondance entre exigences n’est définie."}
            </Alert>
          )}
          {mappings.data?.map((mapping) => (
            <Card key={mapping.id} variant="outlined">
              <CardContent>
                <Stack spacing={1}>
                  <Stack
                    direction={{ xs: "column", sm: "row" }}
                    justifyContent="space-between"
                    alignItems={{ xs: "stretch", sm: "center" }}
                    gap={1}
                  >
                    <Typography
                      fontWeight={700}
                      sx={{ overflowWrap: "anywhere", minWidth: 0 }}
                    >
                      {mapping.source.framework} {mapping.source.reference} →{" "}
                      {mapping.target.framework} {mapping.target.reference}
                    </Typography>
                    <Stack
                      direction="row"
                      alignItems="center"
                      flexWrap="wrap"
                      useFlexGap
                      gap={1}
                      sx={{ minWidth: 0 }}
                    >
                      <Chip
                        size="small"
                        label={`${mapping.coveragePercent}% ${
                          english
                            ? "estimated coverage"
                            : "de couverture estimée"
                        }`}
                      />
                      {mapping.inheritEvidence && (
                        <Chip
                          size="small"
                          variant="outlined"
                          label={
                            english
                              ? "Reusable evidence references"
                              : "Références de preuves réutilisables"
                          }
                          sx={{
                            maxWidth: "100%",
                            height: "auto",
                            "& .MuiChip-label": {
                              whiteSpace: "normal",
                              overflowWrap: "anywhere",
                              py: 0.5,
                            },
                          }}
                        />
                      )}
                      {canManage && (
                        <IconButton
                          color="error"
                          size="small"
                          disabled={deleteMapping.isPending}
                          aria-label={`${
                            english
                              ? "Delete mapping"
                              : "Supprimer la correspondance"
                          } ${mappingName(mapping)}`}
                          onClick={() => {
                            deleteMapping.reset();
                            setMappingToDelete(mapping);
                          }}
                        >
                          <DeleteOutline fontSize="small" />
                        </IconButton>
                      )}
                    </Stack>
                  </Stack>
                  {mapping.rationale && (
                    <Typography variant="body2">{mapping.rationale}</Typography>
                  )}
                  {(mapping.createdBy || mapping.createdAt) && (
                    <Typography variant="caption" color="text.secondary">
                      {mapping.createdBy?.name ?? "—"}
                      {mapping.createdAt
                        ? ` · ${new Intl.DateTimeFormat(locale).format(
                            new Date(mapping.createdAt),
                          )}`
                        : ""}
                    </Typography>
                  )}
                </Stack>
              </CardContent>
            </Card>
          ))}
        </Stack>
      </CardContent>

      <Dialog
        open={dialogOpen}
        onClose={closeCreateDialog}
        fullWidth
        maxWidth="sm"
      >
        <Box
          component="form"
          onSubmit={(event: FormEvent) => {
            event.preventDefault();
            if (formIsValid && !formPending) createMapping.mutate();
          }}
        >
          <DialogTitle>
            {english ? "Map two requirements" : "Relier deux exigences"}
          </DialogTitle>
          <DialogContent dividers>
            <Stack spacing={2}>
              <Alert severity="warning">
                {english
                  ? "A mapping suggests reusable evidence references. It never copies evidence or makes a requirement automatically compliant."
                  : "Une correspondance propose des références de preuves réutilisables. Elle ne copie aucune preuve et ne rend jamais une exigence conforme automatiquement."}
              </Alert>
              {frameworks.isError && (
                <Alert
                  severity="error"
                  action={
                    <Button
                      color="inherit"
                      onClick={() => void frameworks.refetch()}
                    >
                      {english ? "Retry" : "Réessayer"}
                    </Button>
                  }
                >
                  {english
                    ? "Unable to load frameworks."
                    : "Impossible de charger les référentiels."}
                </Alert>
              )}
              {createMapping.isError && (
                <Alert severity="error">
                  {responseMessage(
                    createMapping.error,
                    english
                      ? "Unable to create the mapping."
                      : "Impossible de créer la correspondance.",
                  )}
                </Alert>
              )}
              <Autocomplete
                options={activeFrameworks}
                value={
                  activeFrameworks.find(
                    (framework) =>
                      String(framework.id) === form.sourceFrameworkId,
                  ) ?? null
                }
                disabled={formPending}
                getOptionLabel={(framework) =>
                  `${framework.name} · ${framework.version}`
                }
                isOptionEqualToValue={(option, value) => option.id === value.id}
                noOptionsText={english ? "No framework" : "Aucun référentiel"}
                onChange={(_, framework) =>
                  setForm((current) => ({
                    ...current,
                    sourceFrameworkId: framework ? String(framework.id) : "",
                    sourceRequirement: null,
                  }))
                }
                renderInput={(params) => (
                  <TextField
                    {...params}
                    required
                    label={english ? "Source framework" : "Référentiel source"}
                  />
                )}
              />
              {sourceRequirements.isError && (
                <Alert
                  severity="error"
                  action={
                    <Button
                      color="inherit"
                      onClick={() => void sourceRequirements.refetch()}
                    >
                      {english ? "Retry" : "Réessayer"}
                    </Button>
                  }
                >
                  {english
                    ? "Unable to load source requirements."
                    : "Impossible de charger les exigences sources."}
                </Alert>
              )}
              <Autocomplete
                options={sourceRequirements.data ?? []}
                value={form.sourceRequirement}
                loading={sourceRequirements.isFetching}
                disabled={formPending || form.sourceFrameworkId === ""}
                getOptionLabel={requirementLabel}
                isOptionEqualToValue={(option, value) => option.id === value.id}
                noOptionsText={english ? "No requirement" : "Aucune exigence"}
                loadingText={english ? "Loading…" : "Chargement…"}
                onChange={(_, requirement) =>
                  setForm((current) => ({
                    ...current,
                    sourceRequirement: requirement,
                  }))
                }
                renderInput={(params) => (
                  <TextField
                    {...params}
                    required
                    label={english ? "Source requirement" : "Exigence source"}
                  />
                )}
              />
              <Autocomplete
                options={activeFrameworks}
                value={
                  activeFrameworks.find(
                    (framework) =>
                      String(framework.id) === form.targetFrameworkId,
                  ) ?? null
                }
                disabled={formPending}
                getOptionLabel={(framework) =>
                  `${framework.name} · ${framework.version}`
                }
                isOptionEqualToValue={(option, value) => option.id === value.id}
                noOptionsText={english ? "No framework" : "Aucun référentiel"}
                onChange={(_, framework) =>
                  setForm((current) => ({
                    ...current,
                    targetFrameworkId: framework ? String(framework.id) : "",
                    targetRequirement: null,
                  }))
                }
                renderInput={(params) => (
                  <TextField
                    {...params}
                    required
                    label={english ? "Target framework" : "Référentiel cible"}
                  />
                )}
              />
              {targetRequirements.isError && (
                <Alert
                  severity="error"
                  action={
                    <Button
                      color="inherit"
                      onClick={() => void targetRequirements.refetch()}
                    >
                      {english ? "Retry" : "Réessayer"}
                    </Button>
                  }
                >
                  {english
                    ? "Unable to load target requirements."
                    : "Impossible de charger les exigences cibles."}
                </Alert>
              )}
              <Autocomplete
                options={targetRequirements.data ?? []}
                value={form.targetRequirement}
                loading={targetRequirements.isFetching}
                disabled={formPending || form.targetFrameworkId === ""}
                getOptionLabel={requirementLabel}
                getOptionDisabled={(option) =>
                  option.id === form.sourceRequirement?.id
                }
                isOptionEqualToValue={(option, value) => option.id === value.id}
                noOptionsText={english ? "No requirement" : "Aucune exigence"}
                loadingText={english ? "Loading…" : "Chargement…"}
                onChange={(_, requirement) =>
                  setForm((current) => ({
                    ...current,
                    targetRequirement: requirement,
                  }))
                }
                renderInput={(params) => (
                  <TextField
                    {...params}
                    required
                    label={english ? "Target requirement" : "Exigence cible"}
                    error={sameRequirement}
                    helperText={
                      sameRequirement
                        ? english
                          ? "Source and target must be different."
                          : "La source et la cible doivent être différentes."
                        : undefined
                    }
                  />
                )}
              />
              <TextField
                required
                type="number"
                label={
                  english ? "Estimated coverage (%)" : "Couverture estimée (%)"
                }
                value={form.coveragePercent}
                disabled={formPending}
                inputProps={{ min: 1, max: 100, step: 1 }}
                helperText={
                  english
                    ? "Relationship estimate, not a compliance score."
                    : "Estimation de la relation, pas un score de conformité."
                }
                error={
                  !Number.isInteger(form.coveragePercent) ||
                  form.coveragePercent < 1 ||
                  form.coveragePercent > 100
                }
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    coveragePercent: Number(event.target.value),
                  }))
                }
              />
              <TextField
                multiline
                minRows={3}
                label={english ? "Rationale" : "Justification"}
                value={form.rationale}
                disabled={formPending}
                inputProps={{ maxLength: 2000 }}
                helperText={`${form.rationale.length}/2000`}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    rationale: event.target.value,
                  }))
                }
              />
              <FormControlLabel
                disabled={formPending}
                control={
                  <Checkbox
                    checked={form.inheritEvidence}
                    onChange={(event) =>
                      setForm((current) => ({
                        ...current,
                        inheritEvidence: event.target.checked,
                      }))
                    }
                  />
                }
                label={
                  english
                    ? "Suggest reusable evidence references"
                    : "Proposer les références de preuves réutilisables"
                }
              />
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button
              onClick={closeCreateDialog}
              disabled={createMapping.isPending}
            >
              {english ? "Cancel" : "Annuler"}
            </Button>
            <Button
              type="submit"
              variant="contained"
              disabled={!formIsValid || formPending}
            >
              {createMapping.isPending
                ? english
                  ? "Creating…"
                  : "Création…"
                : english
                  ? "Create mapping"
                  : "Créer la correspondance"}
            </Button>
          </DialogActions>
        </Box>
      </Dialog>

      <Dialog
        open={mappingToDelete !== null}
        onClose={() => {
          if (!deleteMapping.isPending) setMappingToDelete(null);
        }}
        fullWidth
        maxWidth="xs"
      >
        <DialogTitle>
          {english ? "Delete mapping?" : "Supprimer la correspondance ?"}
        </DialogTitle>
        <DialogContent>
          <Stack spacing={2}>
            <Typography>
              {mappingToDelete ? mappingName(mappingToDelete) : ""}
            </Typography>
            <Alert severity="warning">
              {english
                ? "This removes the link only. Requirements and evidence are not deleted."
                : "Seul le lien sera supprimé. Les exigences et les preuves seront conservées."}
            </Alert>
            {deleteMapping.isError && (
              <Alert severity="error">
                {responseMessage(
                  deleteMapping.error,
                  english
                    ? "Unable to delete the mapping."
                    : "Impossible de supprimer la correspondance.",
                )}
              </Alert>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button
            disabled={deleteMapping.isPending}
            onClick={() => setMappingToDelete(null)}
          >
            {english ? "Cancel" : "Annuler"}
          </Button>
          <Button
            color="error"
            variant="contained"
            disabled={!mappingToDelete || deleteMapping.isPending}
            onClick={() => {
              if (mappingToDelete) deleteMapping.mutate(mappingToDelete.id);
            }}
          >
            {deleteMapping.isPending
              ? english
                ? "Deleting…"
                : "Suppression…"
              : deleteMapping.isError
                ? english
                  ? "Retry"
                  : "Réessayer"
                : english
                  ? "Delete"
                  : "Supprimer"}
          </Button>
        </DialogActions>
      </Dialog>
    </Card>
  );
}
