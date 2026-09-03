import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
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
  MenuItem,
  Pagination,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { AddOutlined } from "@mui/icons-material";
import { useState, type FormEvent } from "react";
import { api } from "../../api/client";
import { useAuth } from "../../auth/useAuth";
import { hasAnyRole } from "../../auth/roles";

type Evidence = {
  id: number;
  title: string;
  kind: string;
  classification: string;
  sourceReference: string;
  sha256: string;
  version: number;
  status: string;
  expiresAt: string | null;
  requirementIds: number[];
  controlIds: number[];
  resultIds: number[];
  actionIds: number[];
};

export function EvidenceRegistryPanel() {
  const { user } = useAuth();
  const canManage = hasAnyRole(user?.roles, [
    "ROLE_SUPER_ADMIN",
    "ROLE_ADMIN",
    "ROLE_RISK_MANAGER",
  ]);
  const client = useQueryClient();
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({
    title: "",
    kind: "EXTERNAL_REFERENCE",
    classification: "INTERNAL",
    sourceReference: "",
    sha256: "",
    validFrom: "",
    expiresAt: "",
  });
  const evidence = useQuery({
    queryKey: ["evidence", page],
    queryFn: async () =>
      (
        await api.get<{ items: Evidence[]; pages: number; total: number }>(
          `/evidence?page=${page}&limit=12`,
        )
      ).data,
  });
  const create = useMutation({
    mutationFn: () =>
      api.post("/evidence", {
        ...form,
        validFrom: form.validFrom || null,
        expiresAt: form.expiresAt || null,
        requirementIds: [],
        controlIds: [],
        resultIds: [],
        actionIds: [],
      }),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["evidence"] });
      setOpen(false);
    },
  });
  const submit = useMutation({
    mutationFn: (id: number) => api.post(`/evidence/${id}/submit`),
    onSuccess: () => client.invalidateQueries({ queryKey: ["evidence"] }),
  });
  const approve = useMutation({
    mutationFn: (id: number) => api.post(`/evidence/${id}/approve`),
    onSuccess: () => client.invalidateQueries({ queryKey: ["evidence"] }),
  });
  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    create.mutate();
  };

  return (
    <Stack spacing={2}>
      <Alert severity="info">
        Chaque preuve possède une version, une empreinte SHA-256 et des liens
        explicites vers exigences, contrôles, résultats et actions. Une version
        approuvée devient immuable.
      </Alert>
      {canManage && (
        <Button
          startIcon={<AddOutlined />}
          variant="contained"
          sx={{ alignSelf: "flex-start" }}
          onClick={() => setOpen(true)}
        >
          Ajouter une preuve
        </Button>
      )}
      {evidence.data?.items.map((item) => (
        <Card key={item.id}>
          <CardContent>
            <Stack spacing={1}>
              <Stack
                direction={{ xs: "column", sm: "row" }}
                justifyContent="space-between"
                gap={1}
              >
                <div>
                  <Typography fontWeight={750}>
                    {item.title} · v{item.version}
                  </Typography>
                  <Typography variant="body2" color="text.secondary">
                    {item.kind} · {item.classification} · expiration{" "}
                    {item.expiresAt ?? "non définie"}
                  </Typography>
                </div>
                <Chip label={item.status} />
              </Stack>
              <Typography variant="body2" sx={{ overflowWrap: "anywhere" }}>
                SHA-256 : {item.sha256}
              </Typography>
              <Typography variant="body2">
                Couverture : {item.requirementIds.length} exigence(s),{" "}
                {item.controlIds.length} contrôle(s), {item.resultIds.length}{" "}
                résultat(s), {item.actionIds.length} action(s)
              </Typography>
              {canManage && item.status === "DRAFT" && (
                <Button onClick={() => submit.mutate(item.id)}>
                  Soumettre à revue
                </Button>
              )}
              {hasAnyRole(user?.roles, ["ROLE_SUPER_ADMIN", "ROLE_ADMIN"]) &&
                item.status === "IN_REVIEW" && (
                  <Button onClick={() => approve.mutate(item.id)}>
                    Approuver
                  </Button>
                )}
            </Stack>
          </CardContent>
        </Card>
      ))}
      {evidence.data && evidence.data.pages > 1 && (
        <Pagination
          page={page}
          count={evidence.data.pages}
          onChange={(_, value) => setPage(value)}
        />
      )}
      <Dialog open={open} onClose={() => setOpen(false)} fullWidth>
        <form onSubmit={onSubmit}>
          <DialogTitle>Nouvelle preuve gouvernée</DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ mt: 1 }}>
              <TextField
                required
                label="Titre"
                value={form.title}
                onChange={(e) => setForm({ ...form, title: e.target.value })}
              />
              <TextField
                select
                label="Type"
                value={form.kind}
                onChange={(e) => setForm({ ...form, kind: e.target.value })}
              >
                {[
                  "FILE",
                  "EXTERNAL_REFERENCE",
                  "ATTESTATION",
                  "TEST_RESULT",
                  "AUDIT_RECORD",
                ].map((value) => (
                  <MenuItem key={value} value={value}>
                    {value}
                  </MenuItem>
                ))}
              </TextField>
              <TextField
                select
                label="Classification"
                value={form.classification}
                onChange={(e) =>
                  setForm({ ...form, classification: e.target.value })
                }
              >
                {["PUBLIC", "INTERNAL", "CONFIDENTIAL", "RESTRICTED"].map(
                  (value) => (
                    <MenuItem key={value} value={value}>
                      {value}
                    </MenuItem>
                  ),
                )}
              </TextField>
              <TextField
                required
                label="Référence source"
                value={form.sourceReference}
                onChange={(e) =>
                  setForm({ ...form, sourceReference: e.target.value })
                }
              />
              <TextField
                required
                label="Empreinte SHA-256"
                helperText="64 caractères hexadécimaux calculés sur le fichier ou l’artefact source."
                value={form.sha256}
                onChange={(e) =>
                  setForm({ ...form, sha256: e.target.value.toLowerCase() })
                }
              />
              <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                <TextField
                  fullWidth
                  type="date"
                  label="Valide à partir du"
                  InputLabelProps={{ shrink: true }}
                  value={form.validFrom}
                  onChange={(e) =>
                    setForm({ ...form, validFrom: e.target.value })
                  }
                />
                <TextField
                  fullWidth
                  type="date"
                  label="Expire le"
                  InputLabelProps={{ shrink: true }}
                  value={form.expiresAt}
                  onChange={(e) =>
                    setForm({ ...form, expiresAt: e.target.value })
                  }
                />
              </Stack>
              {(create.isError || submit.isError || approve.isError) && (
                <Alert severity="error">
                  L’opération sur la preuve a échoué.
                </Alert>
              )}
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setOpen(false)}>Annuler</Button>
            <Button
              type="submit"
              variant="contained"
              disabled={create.isPending}
            >
              Créer
            </Button>
          </DialogActions>
        </form>
      </Dialog>
    </Stack>
  );
}
