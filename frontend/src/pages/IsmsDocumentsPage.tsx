import {
  AddOutlined,
  DeleteOutline,
  EditOutlined,
  HistoryOutlined,
  LinkOutlined,
  LockOutlined,
  PersonAddOutlined,
  RestoreOutlined,
  ShareOutlined,
  AttachFileOutlined,
  DownloadOutlined,
  TaskAltOutlined,
} from "@mui/icons-material";
import {
  Alert,
  Box,
  Button,
  Card,
  CardActions,
  CardContent,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  FormControl,
  Grid,
  IconButton,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  Tab,
  Tabs,
  TextField,
  Tooltip,
  Typography,
} from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import axios from "axios";
import { useMemo, useState, type FormEvent } from "react";
import { api } from "../api/client";
import type { IsmsDocument, User } from "../api/types";
import { useAuth } from "../auth/useAuth";

const categories = [
  "Politique",
  "Procédure",
  "Instruction",
  "Preuve",
  "Registre",
  "Modèle",
  "Rapport",
  "Autre",
];
const emptyForm = {
  title: "",
  category: "Politique",
  status: "DRAFT",
  classification: "INTERNAL",
  visibility: "ORGANIZATION",
  content: "",
  ownerId: 0,
  versionComment: "",
};
type DocumentForm = typeof emptyForm;

function message(error: unknown) {
  return axios.isAxiosError<{ message?: string }>(error)
    ? (error.response?.data?.message ?? "L’opération a échoué.")
    : "L’opération a échoué.";
}
function person(user: Pick<User, "firstName" | "lastName">) {
  return `${user.firstName} ${user.lastName}`;
}
function date(value: string | null) {
  return value
    ? new Intl.DateTimeFormat("fr-FR", {
        dateStyle: "medium",
        timeStyle: "short",
      }).format(new Date(value))
    : "Sans expiration";
}

export function IsmsDocumentsPage() {
  const { user } = useAuth();
  const client = useQueryClient();
  const [search, setSearch] = useState("");
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [editorOpen, setEditorOpen] = useState(false);
  const [editing, setEditing] = useState<IsmsDocument | null>(null);
  const [form, setForm] = useState<DocumentForm>(emptyForm);
  const [tab, setTab] = useState(0);
  const [error, setError] = useState("");
  const [aclUserId, setAclUserId] = useState("");
  const [aclPermission, setAclPermission] = useState("READ");
  const [sharePassword, setSharePassword] = useState("");
  const [shareExpiry, setShareExpiry] = useState("");
  const [createdUrl, setCreatedUrl] = useState("");
  const [wordFile, setWordFile] = useState<File | null>(null);
  const [nextReviewAt, setNextReviewAt] = useState("");

  const list = useQuery({
    queryKey: ["isms-documents"],
    queryFn: async () =>
      (await api.get<IsmsDocument[]>("/isms-documents")).data,
  });
  const detail = useQuery({
    queryKey: ["isms-documents", selectedId],
    queryFn: async () =>
      (await api.get<IsmsDocument>(`/isms-documents/${selectedId}`)).data,
    enabled: selectedId !== null,
  });
  const users = useQuery({
    queryKey: ["isms-document-collaborators"],
    queryFn: async () =>
      (await api.get<User[]>("/isms-documents/collaborators")).data,
  });
  const documents = useMemo(
    () =>
      (list.data ?? []).filter((item) =>
        `${item.title} ${item.category} ${person(item.owner)}`
          .toLowerCase()
          .includes(search.toLowerCase()),
      ),
    [list.data, search],
  );
  const refresh = async () => {
    await client.invalidateQueries({ queryKey: ["isms-documents"] });
  };
  const save = useMutation({
    mutationFn: async () => {
      const response = editing
        ? await api.put<IsmsDocument>(`/isms-documents/${editing.id}`, form)
        : await api.post<IsmsDocument>("/isms-documents", form);
      if (wordFile) {
        const body = new FormData();
        body.append("file", wordFile);
        await api.post(`/isms-documents/${response.data.id}/file`, body, {
          headers: { "Content-Type": "multipart/form-data" },
        });
      }
      return response;
    },
    onSuccess: async (response) => {
      await refresh();
      setWordFile(null);
      setEditorOpen(false);
      setSelectedId(response.data.id);
    },
    onError: (caught) => setError(message(caught)),
  });
  const deleteFile = useMutation({
    mutationFn: (id: number) => api.delete(`/isms-documents/${id}/file`),
    onSuccess: refresh,
    onError: (caught) => setError(message(caught)),
  });
  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/isms-documents/${id}`),
    onSuccess: async () => {
      setSelectedId(null);
      await refresh();
    },
    onError: (caught) => setError(message(caught)),
  });
  const saveAcl = useMutation({
    mutationFn: () =>
      api.post(`/isms-documents/${selectedId}/acl`, {
        userId: Number(aclUserId),
        permission: aclPermission,
      }),
    onSuccess: async () => {
      setAclUserId("");
      await refresh();
    },
    onError: (caught) => setError(message(caught)),
  });
  const deleteAcl = useMutation({
    mutationFn: (id: number) =>
      api.delete(`/isms-documents/${selectedId}/acl/${id}`),
    onSuccess: refresh,
    onError: (caught) => setError(message(caught)),
  });
  const createShare = useMutation({
    mutationFn: () =>
      api.post<{ url: string }>(`/isms-documents/${selectedId}/shares`, {
        password: sharePassword,
        expiresAt: shareExpiry ? new Date(shareExpiry).toISOString() : null,
      }),
    onSuccess: async ({ data }) => {
      setCreatedUrl(data.url);
      setSharePassword("");
      setShareExpiry("");
      await refresh();
    },
    onError: (caught) => setError(message(caught)),
  });
  const revokeShare = useMutation({
    mutationFn: (id: number) =>
      api.delete(`/isms-documents/${selectedId}/shares/${id}`),
    onSuccess: refresh,
    onError: (caught) => setError(message(caught)),
  });
  const restore = useMutation({
    mutationFn: (id: number) =>
      api.post(`/isms-documents/${selectedId}/versions/${id}/restore`),
    onSuccess: refresh,
    onError: (caught) => setError(message(caught)),
  });
  const approve = useMutation({
    mutationFn: () =>
      api.post(`/isms-documents/${selectedId}/approve`, { nextReviewAt }),
    onSuccess: async () => {
      setNextReviewAt("");
      await refresh();
    },
    onError: (caught) => setError(message(caught)),
  });

  const openCreate = () => {
    setEditing(null);
    setWordFile(null);
    setForm({ ...emptyForm, ownerId: user?.id ?? 0 });
    setError("");
    setEditorOpen(true);
  };
  const openEdit = (document: IsmsDocument) => {
    setEditing(document);
    setWordFile(null);
    setForm({
      title: document.title,
      category: document.category,
      status: document.status,
      classification: document.classification,
      visibility: document.visibility,
      content: document.content ?? "",
      ownerId: document.owner.id,
      versionComment: "",
    });
    setError("");
    setEditorOpen(true);
  };
  const downloadWord = async (document: IsmsDocument) => {
    try {
      const response = await api.get(`/isms-documents/${document.id}/file`, {
        responseType: "blob",
      });
      const url = URL.createObjectURL(response.data);
      const link = window.document.createElement("a");
      link.href = url;
      link.download = document.file?.name ?? "document.docx";
      link.click();
      URL.revokeObjectURL(url);
    } catch (caught) {
      setError(message(caught));
    }
  };
  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate();
  };
  const current = detail.data;

  return (
    <Stack spacing={3}>
      <Stack
        direction={{ xs: "column", sm: "row" }}
        justifyContent="space-between"
        gap={2}
      >
        <Box>
          <Typography variant="h4" fontWeight={750}>
            Documents ISMS
          </Typography>
          <Typography color="text.secondary">
            Politiques, procédures, preuves, versions et partages sécurisés
          </Typography>
        </Box>
        <Button
          variant="contained"
          startIcon={<AddOutlined />}
          onClick={openCreate}
        >
          Nouveau document
        </Button>
      </Stack>
      {error && (
        <Alert severity="error" onClose={() => setError("")}>
          {error}
        </Alert>
      )}
      <TextField
        label="Rechercher"
        value={search}
        onChange={(event) => setSearch(event.target.value)}
        fullWidth
      />
      {list.isError && (
        <Alert severity="error">Impossible de charger les documents.</Alert>
      )}
      <Grid container spacing={2}>
        {documents.map((document) => (
          <Grid key={document.id} size={{ xs: 12, md: 6, xl: 4 }}>
            <Card
              variant="outlined"
              sx={{ height: "100%", display: "flex", flexDirection: "column" }}
            >
              <CardContent sx={{ flexGrow: 1 }}>
                <Stack direction="row" justifyContent="space-between" gap={1}>
                  <Typography variant="h6">{document.title}</Typography>
                  <Chip size="small" label={`v${document.currentVersion}`} />
                </Stack>
                <Stack direction="row" gap={1} flexWrap="wrap" mt={1.5}>
                  <Chip
                    size="small"
                    label={document.category}
                    color="primary"
                    variant="outlined"
                  />
                  <Chip size="small" label={document.status} />
                  {document.approval.reviewOverdue && (
                    <Chip size="small" label="Revue en retard" color="error" />
                  )}
                  <Chip
                    size="small"
                    icon={
                      document.visibility === "RESTRICTED" ? (
                        <LockOutlined />
                      ) : undefined
                    }
                    label={document.classification}
                  />
                </Stack>
                <Typography variant="body2" color="text.secondary" mt={2}>
                  Propriétaire : {person(document.owner)}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  Mis à jour {date(document.updatedAt)}
                </Typography>
              </CardContent>
              <CardActions>
                <Button
                  onClick={() => {
                    setSelectedId(document.id);
                    setTab(0);
                  }}
                >
                  Ouvrir
                </Button>
                {document.permissions.edit && (
                  <IconButton
                    aria-label="Modifier"
                    onClick={async () => {
                      const full = (
                        await api.get<IsmsDocument>(
                          `/isms-documents/${document.id}`,
                        )
                      ).data;
                      openEdit(full);
                    }}
                  >
                    <EditOutlined />
                  </IconButton>
                )}
              </CardActions>
            </Card>
          </Grid>
        ))}
        {!list.isLoading && documents.length === 0 && (
          <Grid size={12}>
            <Alert severity="info">
              Aucun document ne correspond à votre recherche.
            </Alert>
          </Grid>
        )}
      </Grid>

      <Dialog
        open={selectedId !== null}
        onClose={() => setSelectedId(null)}
        fullWidth
        maxWidth="lg"
        fullScreen={false}
      >
        <DialogTitle>
          <Stack
            direction="row"
            justifyContent="space-between"
            alignItems="center"
          >
            <Box>
              {current?.title ?? "Chargement…"}
              <Typography
                variant="caption"
                display="block"
                color="text.secondary"
              >
                Version {current?.currentVersion}
              </Typography>
            </Box>
            {current?.permissions.edit && (
              <Button
                startIcon={<EditOutlined />}
                onClick={() => openEdit(current)}
              >
                Modifier
              </Button>
            )}
          </Stack>
        </DialogTitle>
        <Tabs
          value={tab}
          onChange={(_, value) => setTab(value)}
          variant="scrollable"
        >
          <Tab label="Document" />
          <Tab
            label="Versions"
            icon={<HistoryOutlined />}
            iconPosition="start"
          />
          <Tab
            label="Accès"
            icon={<PersonAddOutlined />}
            iconPosition="start"
          />
          <Tab label="Partages" icon={<ShareOutlined />} iconPosition="start" />
        </Tabs>
        <DialogContent dividers sx={{ minHeight: 360 }}>
          {current && tab === 0 && (
            <Stack spacing={2}>
              <Stack direction="row" gap={1} flexWrap="wrap">
                <Chip label={current.category} color="primary" />
                <Chip label={current.status} />
                <Chip label={current.classification} />
                <Chip
                  label={
                    current.visibility === "RESTRICTED"
                      ? "Accès restreint"
                      : "Organisation"
                  }
                />
              </Stack>
              {current.status === "APPROVED" && current.approval.approvedBy && (
                <Alert
                  severity={
                    current.approval.reviewOverdue ? "warning" : "success"
                  }
                  icon={<TaskAltOutlined />}
                >
                  Approuvé par {person(current.approval.approvedBy)} le{" "}
                  {date(current.approval.approvedAt)}. Prochaine revue :{" "}
                  {date(current.approval.nextReviewAt)}.
                </Alert>
              )}
              {current.permissions.manage && current.status !== "APPROVED" && (
                <Stack direction={{ xs: "column", sm: "row" }} gap={1}>
                  <TextField
                    label="Prochaine revue"
                    type="date"
                    value={nextReviewAt}
                    onChange={(event) => setNextReviewAt(event.target.value)}
                    slotProps={{ inputLabel: { shrink: true } }}
                    fullWidth
                  />
                  <Button
                    variant="contained"
                    color="success"
                    startIcon={<TaskAltOutlined />}
                    disabled={!nextReviewAt || approve.isPending}
                    onClick={() => approve.mutate()}
                  >
                    Approuver
                  </Button>
                </Stack>
              )}
              <Box
                sx={{
                  bgcolor: "grey.50",
                  border: "1px solid",
                  borderColor: "divider",
                  borderRadius: 2,
                  p: { xs: 2, md: 3 },
                  whiteSpace: "pre-wrap",
                  overflowWrap: "anywhere",
                }}
              >
                {current.content || (
                  <Typography color="text.secondary">Document vide</Typography>
                )}
              </Box>
              {current.file && (
                <Alert
                  severity="info"
                  icon={<AttachFileOutlined />}
                  action={
                    <Stack direction="row">
                      <Button
                        startIcon={<DownloadOutlined />}
                        onClick={() => downloadWord(current)}
                      >
                        Télécharger
                      </Button>
                      {current.permissions.edit && (
                        <Button
                          color="error"
                          onClick={() => deleteFile.mutate(current.id)}
                        >
                          Retirer
                        </Button>
                      )}
                    </Stack>
                  }
                >
                  {current.file.name} — {Math.ceil(current.file.size / 1024)} Ko
                </Alert>
              )}
            </Stack>
          )}
          {current && tab === 1 && (
            <Stack divider={<Divider />} spacing={0}>
              {current.versions?.map((version) => (
                <Stack
                  key={version.id}
                  direction={{ xs: "column", sm: "row" }}
                  justifyContent="space-between"
                  py={2}
                  gap={1}
                >
                  <Box>
                    <Typography fontWeight={700}>
                      Version {version.versionNumber}
                    </Typography>
                    <Typography variant="body2">
                      {version.comment ?? "Sans commentaire"}
                    </Typography>
                    <Typography variant="caption" color="text.secondary">
                      {person(version.author)} · {date(version.createdAt)}
                    </Typography>
                    {version.fileName && (
                      <Typography
                        variant="caption"
                        display="block"
                        color="text.secondary"
                      >
                        Fichier : {version.fileName} · SHA-256{" "}
                        {version.fileChecksum?.slice(0, 12)}…
                      </Typography>
                    )}
                  </Box>
                  {current.permissions.edit &&
                    version.versionNumber !== current.currentVersion && (
                      <Button
                        startIcon={<RestoreOutlined />}
                        onClick={() => restore.mutate(version.id)}
                      >
                        Restaurer
                      </Button>
                    )}
                </Stack>
              ))}
            </Stack>
          )}
          {current && tab === 2 && (
            <Stack spacing={2}>
              {current.permissions.manage ? (
                <>
                  <Stack direction={{ xs: "column", sm: "row" }} gap={1}>
                    <FormControl fullWidth>
                      <InputLabel>Utilisateur</InputLabel>
                      <Select
                        label="Utilisateur"
                        value={aclUserId}
                        onChange={(event) => setAclUserId(event.target.value)}
                      >
                        {users.data
                          ?.filter((user) => user.id !== current.owner.id)
                          .map((user) => (
                            <MenuItem key={user.id} value={user.id}>
                              {person(user)} — {user.email}
                            </MenuItem>
                          ))}
                      </Select>
                    </FormControl>
                    <FormControl sx={{ minWidth: 150 }}>
                      <InputLabel>Permission</InputLabel>
                      <Select
                        label="Permission"
                        value={aclPermission}
                        onChange={(event) =>
                          setAclPermission(event.target.value)
                        }
                      >
                        <MenuItem value="READ">Lecture</MenuItem>
                        <MenuItem value="EDIT">Édition</MenuItem>
                        <MenuItem value="MANAGE">Gestion</MenuItem>
                      </Select>
                    </FormControl>
                    <Button
                      variant="contained"
                      disabled={!aclUserId}
                      onClick={() => saveAcl.mutate()}
                    >
                      Ajouter
                    </Button>
                  </Stack>
                </>
              ) : (
                <Alert severity="info">
                  Vous pouvez consulter ce document mais pas administrer ses
                  accès.
                </Alert>
              )}
              <Divider />
              <Typography fontWeight={700}>
                Propriétaire : {person(current.owner)}
              </Typography>
              {current.acl?.map((entry) => (
                <Stack
                  key={entry.id}
                  direction="row"
                  justifyContent="space-between"
                  alignItems="center"
                >
                  <Box>
                    <Typography>{person(entry.user)}</Typography>
                    <Typography variant="caption">
                      {entry.user.email} · {entry.permission}
                    </Typography>
                  </Box>
                  {current.permissions.manage && (
                    <IconButton
                      aria-label="Supprimer l’accès"
                      onClick={() => deleteAcl.mutate(entry.id)}
                    >
                      <DeleteOutline />
                    </IconButton>
                  )}
                </Stack>
              ))}
            </Stack>
          )}
          {current && tab === 3 && (
            <Stack spacing={2}>
              {current.permissions.manage ? (
                <>
                  <Stack direction={{ xs: "column", sm: "row" }} gap={1}>
                    <TextField
                      label={
                        ["CONFIDENTIAL", "RESTRICTED"].includes(
                          current.classification,
                        )
                          ? "Mot de passe (obligatoire)"
                          : "Mot de passe (facultatif)"
                      }
                      type="password"
                      value={sharePassword}
                      onChange={(event) => setSharePassword(event.target.value)}
                      helperText="8 caractères minimum"
                      fullWidth
                    />
                    <TextField
                      label="Expiration"
                      type="datetime-local"
                      value={shareExpiry}
                      onChange={(event) => setShareExpiry(event.target.value)}
                      slotProps={{ inputLabel: { shrink: true } }}
                      fullWidth
                    />
                    <Button
                      variant="contained"
                      startIcon={<LinkOutlined />}
                      onClick={() => createShare.mutate()}
                    >
                      Créer
                    </Button>
                  </Stack>
                  {current.classification === "RESTRICTED" && (
                    <Alert severity="info">
                      Un document restreint exige un mot de passe et une
                      expiration maximale de 30 jours.
                    </Alert>
                  )}
                  {createdUrl && (
                    <Alert
                      severity="success"
                      action={
                        <Button
                          onClick={() =>
                            navigator.clipboard.writeText(createdUrl)
                          }
                        >
                          Copier
                        </Button>
                      }
                    >
                      Lien créé : {createdUrl}
                    </Alert>
                  )}
                </>
              ) : (
                <Alert severity="info">
                  Seul un gestionnaire peut créer un partage.
                </Alert>
              )}
              <Divider />
              {current.shares?.map((share) => (
                <Stack
                  key={share.id}
                  direction={{ xs: "column", sm: "row" }}
                  justifyContent="space-between"
                  py={1}
                  gap={1}
                >
                  <Box>
                    <Stack direction="row" gap={1}>
                      <Chip
                        size="small"
                        color={share.available ? "success" : "default"}
                        label={
                          share.available
                            ? "Actif"
                            : share.enabled
                              ? "Expiré"
                              : "Révoqué"
                        }
                      />
                      {share.hasPassword && (
                        <Chip
                          size="small"
                          icon={<LockOutlined />}
                          label="Protégé"
                        />
                      )}
                    </Stack>
                    <Typography variant="body2" mt={1}>
                      Expiration : {date(share.expiresAt)} · {share.accessCount}{" "}
                      consultation(s)
                    </Typography>
                  </Box>
                  {share.available && current.permissions.manage && (
                    <Button
                      color="error"
                      onClick={() => revokeShare.mutate(share.id)}
                    >
                      Révoquer
                    </Button>
                  )}
                </Stack>
              ))}
            </Stack>
          )}
        </DialogContent>
        <DialogActions>
          {current?.permissions.manage && (
            <Tooltip title="Suppression définitive">
              <Button
                color="error"
                startIcon={<DeleteOutline />}
                onClick={() => {
                  if (
                    confirm(
                      "Supprimer définitivement ce document et son historique ?",
                    )
                  )
                    remove.mutate(current.id);
                }}
              >
                Supprimer
              </Button>
            </Tooltip>
          )}
          <Button onClick={() => setSelectedId(null)}>Fermer</Button>
        </DialogActions>
      </Dialog>

      <Dialog
        open={editorOpen}
        onClose={() => setEditorOpen(false)}
        fullWidth
        maxWidth="md"
      >
        <Box component="form" onSubmit={submit}>
          <DialogTitle>
            {editing ? "Modifier le document" : "Nouveau document ISMS"}
          </DialogTitle>
          <DialogContent dividers>
            <Stack spacing={2}>
              <TextField
                required
                label="Titre"
                value={form.title}
                onChange={(event) =>
                  setForm({ ...form, title: event.target.value })
                }
              />
              <Grid container spacing={2}>
                <Grid size={{ xs: 12, sm: 6 }}>
                  <FormControl fullWidth>
                    <InputLabel>Catégorie</InputLabel>
                    <Select
                      label="Catégorie"
                      value={form.category}
                      onChange={(event) =>
                        setForm({ ...form, category: event.target.value })
                      }
                    >
                      {categories.map((item) => (
                        <MenuItem key={item} value={item}>
                          {item}
                        </MenuItem>
                      ))}
                    </Select>
                  </FormControl>
                </Grid>
                <Grid size={{ xs: 12, sm: 6 }}>
                  <FormControl fullWidth>
                    <InputLabel>Statut</InputLabel>
                    <Select
                      label="Statut"
                      value={form.status}
                      onChange={(event) =>
                        setForm({ ...form, status: event.target.value })
                      }
                    >
                      {[
                        "DRAFT",
                        "IN_REVIEW",
                        ...(editing?.status === "APPROVED" ? ["APPROVED"] : []),
                        ...(editing?.permissions.manage ? ["ARCHIVED"] : []),
                      ].map((item) => (
                        <MenuItem key={item} value={item}>
                          {item}
                        </MenuItem>
                      ))}
                    </Select>
                  </FormControl>
                </Grid>
              </Grid>
              <Grid container spacing={2}>
                <Grid size={{ xs: 12, sm: 6 }}>
                  <FormControl fullWidth>
                    <InputLabel>Classification</InputLabel>
                    <Select
                      label="Classification"
                      value={form.classification}
                      onChange={(event) =>
                        setForm({ ...form, classification: event.target.value })
                      }
                    >
                      {["PUBLIC", "INTERNAL", "CONFIDENTIAL", "RESTRICTED"].map(
                        (item) => (
                          <MenuItem key={item} value={item}>
                            {item}
                          </MenuItem>
                        ),
                      )}
                    </Select>
                  </FormControl>
                </Grid>
                <Grid size={{ xs: 12, sm: 6 }}>
                  <FormControl fullWidth>
                    <InputLabel>Visibilité</InputLabel>
                    <Select
                      label="Visibilité"
                      value={form.visibility}
                      onChange={(event) =>
                        setForm({ ...form, visibility: event.target.value })
                      }
                    >
                      <MenuItem value="ORGANIZATION">
                        Toute l’organisation
                      </MenuItem>
                      <MenuItem value="RESTRICTED">
                        Propriétaire et ACL
                      </MenuItem>
                    </Select>
                  </FormControl>
                </Grid>
              </Grid>
              {users.data && (
                <FormControl fullWidth>
                  <InputLabel>Propriétaire</InputLabel>
                  <Select
                    label="Propriétaire"
                    value={form.ownerId}
                    onChange={(event) =>
                      setForm({ ...form, ownerId: Number(event.target.value) })
                    }
                  >
                    {users.data.map((user) => (
                      <MenuItem key={user.id} value={user.id}>
                        {person(user)} — {user.email}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              )}
              <TextField
                label="Contenu (Markdown accepté)"
                value={form.content}
                onChange={(event) =>
                  setForm({ ...form, content: event.target.value })
                }
                multiline
                minRows={12}
              />
              <Button
                component="label"
                variant="outlined"
                startIcon={<AttachFileOutlined />}
              >
                {wordFile
                  ? wordFile.name
                  : editing?.file
                    ? "Remplacer le fichier Word"
                    : "Joindre un document Word"}
                <input
                  hidden
                  type="file"
                  accept=".doc,.docx,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                  onChange={(event) =>
                    setWordFile(event.target.files?.[0] ?? null)
                  }
                />
              </Button>
              <Typography variant="caption" color="text.secondary">
                Markdown et fichier Word peuvent être utilisés ensemble. Taille
                maximale : 10 Mo.
              </Typography>
              <TextField
                label="Commentaire de version"
                value={form.versionComment}
                onChange={(event) =>
                  setForm({ ...form, versionComment: event.target.value })
                }
              />
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setEditorOpen(false)}>Annuler</Button>
            <Button type="submit" variant="contained" disabled={save.isPending}>
              Enregistrer
            </Button>
          </DialogActions>
        </Box>
      </Dialog>
    </Stack>
  );
}
