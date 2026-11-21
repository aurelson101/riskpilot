import { useState } from "react";
import {
  Alert,
  Button,
  Card,
  CardContent,
  Checkbox,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  MenuItem,
  Stack,
  Tab,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Tabs,
  TextField,
  Typography,
} from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import axios from "axios";
import { api } from "../api/client";
import { useAuth } from "../auth/useAuth";

type StepStatus =
  "DONE" | "PENDING" | "DUE_SOON" | "OVERDUE" | "NOT_APPLICABLE";
type Step = {
  key: string;
  article: string;
  dueAt: string;
  doneAt: string | null;
  status: StepStatus;
  late: boolean;
  provisional: boolean;
};
type Incident = {
  id: number;
  regime: string;
  title: string;
  description: string;
  awareAt: string;
  applicable: boolean;
  status: "OPEN" | "CLOSED";
  owner: { id: number; name: string };
  aiSystem: { id: number; name: string } | null;
  product: { id: number; name: string; version: string } | null;
  steps: Step[];
  worstStatus: StepStatus;
};
type IncidentList = {
  summary: { open: number; overdue: number; dueSoon: number };
  items: Incident[];
};
type Product = {
  id: number;
  name: string;
  version: string;
  category: string;
  role: string;
  status: string;
  supportEndsAt: string | null;
  sbomReference: string | null;
  gaps: string[];
};
type ProductList = {
  summary: { products: number; withGaps: number; onMarket: number };
  items: Product[];
};
type Arrangement = {
  id: number;
  thirdParty: { id: number; name: string };
  serviceType: string;
  description: string;
  supportsCriticalFunction: boolean;
  providerLei: string | null;
  status: string;
  gaps: string[];
};
type ArrangementList = {
  summary: {
    arrangements: number;
    criticalFunctions: number;
    withGaps: number;
  };
  items: Arrangement[];
};
type ThirdPartyOption = { id: number; name: string };

const regimeLabels: Record<string, string> = {
  DORA_MAJOR_INCIDENT: "DORA — incident majeur (art. 19)",
  CRA_VULNERABILITY: "CRA — vulnérabilité exploitée (art. 14)",
  CRA_INCIDENT: "CRA — incident grave (art. 14)",
  AI_ACT_SERIOUS_INCIDENT: "AI Act — incident grave (art. 73)",
};
const stepLabels: Record<string, string> = {
  INITIAL: "Notification initiale",
  INTERMEDIATE: "Rapport intermédiaire",
  FINAL: "Rapport final",
  EARLY_WARNING: "Alerte précoce",
  NOTIFICATION: "Notification",
  REPORT: "Signalement",
};
const statusLabels: Record<StepStatus, string> = {
  DONE: "Déposé",
  PENDING: "À venir",
  DUE_SOON: "Échéance proche",
  OVERDUE: "En retard",
  NOT_APPLICABLE: "Sans objet",
};
const statusColors: Record<
  StepStatus,
  "success" | "default" | "warning" | "error"
> = {
  DONE: "success",
  PENDING: "default",
  DUE_SOON: "warning",
  OVERDUE: "error",
  NOT_APPLICABLE: "default",
};
const gapLabels: Record<string, string> = {
  SBOM_MISSING: "Nomenclature logicielle (SBOM) manquante",
  DISCLOSURE_CONTACT_MISSING: "Point de contact de divulgation manquant",
  SUPPORT_END_UNDEFINED: "Fin de support non définie",
  SUPPORT_ENDED_STILL_ON_MARKET: "Support terminé mais produit sur le marché",
  SUPPORT_EXPIRING: "Support se terminant sous 12 mois",
  SUPPORT_PERIOD_UNDER_FIVE_YEARS: "Support inférieur à cinq ans",
  CONFORMITY_NOT_ASSESSED: "Conformité non évaluée",
  DECLARATION_MISSING: "Déclaration de conformité manquante",
  THIRD_PARTY_ASSESSMENT_REQUIRED:
    "Évaluation par un tiers probablement requise",
  CONTRACT_REFERENCE_MISSING: "Référence du contrat manquante",
  PROVIDER_LEI_MISSING: "LEI du prestataire manquant",
  DATA_LOCATION_MISSING: "Localisation des données manquante",
  SUBCONTRACTORS_NOT_DOCUMENTED: "Sous-traitants non documentés",
  START_DATE_MISSING: "Date de début manquante",
  FUNCTION_NOT_DESCRIBED: "Fonction soutenue non décrite",
  SUBSTITUTABILITY_NOT_ASSESSED: "Substituabilité non évaluée",
  EXIT_PLAN_NOT_TESTED: "Plan de sortie jamais testé",
  EXIT_PLAN_TEST_OUTDATED: "Test du plan de sortie de plus de 12 mois",
  CONTRACT_ENDING_SOON: "Contrat se terminant sous 6 mois",
};
const serviceTypeLabels: Record<string, string> = {
  CLOUD_INFRASTRUCTURE: "Infrastructure cloud",
  SAAS: "Logiciel en tant que service",
  DATA_CENTER: "Centre de données",
  NETWORK: "Réseau et télécommunications",
  SECURITY_SERVICES: "Services de sécurité",
  DATA_ANALYTICS: "Analyse de données",
  PAYMENT_PROCESSING: "Traitement des paiements",
  OTHER: "Autre",
};
const categoryLabels: Record<string, string> = {
  DEFAULT: "Standard",
  IMPORTANT_CLASS_I: "Important — classe I",
  IMPORTANT_CLASS_II: "Important — classe II",
  CRITICAL: "Critique",
};
const productStatusLabels: Record<string, string> = {
  IN_DEVELOPMENT: "En développement",
  ON_MARKET: "Sur le marché",
  SUPPORT_ENDED: "Support terminé",
  WITHDRAWN: "Retiré",
};
const conformityLabels: Record<string, string> = {
  NOT_ASSESSED: "Non évaluée",
  INTERNAL_CONTROL: "Contrôle interne",
  THIRD_PARTY: "Évaluation par un tiers",
  EU_CERTIFICATION: "Certification européenne",
};

const formatDate = (value: string) =>
  new Date(value).toLocaleString("fr-FR", {
    dateStyle: "short",
    timeStyle: "short",
  });
const errorText = (error: unknown) =>
  axios.isAxiosError<{ message?: string }>(error)
    ? (error.response?.data?.message ?? "L’opération a échoué.")
    : "L’opération a échoué.";
const nowLocal = () =>
  new Date(Date.now() - new Date().getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 16);
const download = async (path: string, filename: string) => {
  const response = await api.get<Blob>(path, { responseType: "blob" });
  const url = URL.createObjectURL(response.data);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
};

function IncidentsTab({ canManage }: { canManage: boolean }) {
  const cache = useQueryClient();
  const list = useQuery({
    queryKey: ["regulatory-incidents"],
    queryFn: async () =>
      (await api.get<IncidentList>("/regulatory-incidents")).data,
  });
  const [dialog, setDialog] = useState(false);
  const [form, setForm] = useState({
    regime: "AI_ACT_SERIOUS_INCIDENT",
    title: "",
    description: "",
    awareAt: nowLocal(),
    widespread: false,
    criticalInfrastructure: false,
    death: false,
  });
  const create = useMutation({
    mutationFn: async () =>
      api.post("/regulatory-incidents", {
        ...form,
        awareAt: new Date(form.awareAt).toISOString(),
      }),
    onSuccess: async () => {
      setDialog(false);
      await cache.invalidateQueries({ queryKey: ["regulatory-incidents"] });
    },
  });
  const file = useMutation({
    mutationFn: async ({
      incident,
      step,
    }: {
      incident: Incident;
      step: string;
    }) => {
      const done = Object.fromEntries(
        incident.steps
          .filter((item) => item.doneAt)
          .map((item) => [item.key, item.doneAt]),
      );
      return api.put(`/regulatory-incidents/${incident.id}`, {
        completed: { ...done, [step]: new Date().toISOString() },
      });
    },
    onSuccess: async () =>
      cache.invalidateQueries({ queryKey: ["regulatory-incidents"] }),
  });
  const close = useMutation({
    mutationFn: async (id: number) =>
      api.put(`/regulatory-incidents/${id}`, { status: "CLOSED" }),
    onSuccess: async () =>
      cache.invalidateQueries({ queryKey: ["regulatory-incidents"] }),
  });
  const [reportError, setReportError] = useState(false);

  if (list.isError)
    return (
      <Alert severity="error">Impossible de charger les notifications.</Alert>
    );
  if (!list.data) return null;

  return (
    <Stack spacing={2}>
      <Stack direction="row" spacing={1} alignItems="center">
        <Chip label={`${list.data.summary.open} ouverts`} />
        <Chip
          color={list.data.summary.overdue ? "error" : "default"}
          label={`${list.data.summary.overdue} en retard`}
        />
        <Chip
          color={list.data.summary.dueSoon ? "warning" : "default"}
          label={`${list.data.summary.dueSoon} échéance proche`}
        />
        {canManage && (
          <Button variant="contained" onClick={() => setDialog(true)}>
            Déclarer un dossier
          </Button>
        )}
      </Stack>
      <Alert severity="info">
        Les délais sont des repères de pilotage : l’autorité compétente et les
        textes applicables font foi. Le suivi NIS2 reste dans le tableau de bord
        NIS2.
      </Alert>
      {reportError && (
        <Alert severity="error">Le téléchargement de la trame a échoué.</Alert>
      )}
      {list.data.items.length === 0 && (
        <Alert severity="success">Aucun dossier de notification.</Alert>
      )}
      {list.data.items.map((incident) => (
        <Card key={incident.id}>
          <CardContent>
            <Stack spacing={1}>
              <Stack direction="row" spacing={1} alignItems="center">
                <Typography variant="subtitle1" fontWeight={750}>
                  {incident.title}
                </Typography>
                <Chip size="small" label={regimeLabels[incident.regime]} />
                <Chip
                  size="small"
                  color={statusColors[incident.worstStatus]}
                  label={statusLabels[incident.worstStatus]}
                />
                {incident.status === "CLOSED" && (
                  <Chip size="small" label="Clos" />
                )}
              </Stack>
              <Typography variant="body2" color="text.secondary">
                {`Connu le ${formatDate(incident.awareAt)} · ${incident.owner.name}`}
                {incident.aiSystem
                  ? ` · Système : ${incident.aiSystem.name}`
                  : ""}
                {incident.product
                  ? ` · Produit : ${incident.product.name} ${incident.product.version}`
                  : ""}
              </Typography>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>Étape</TableCell>
                    <TableCell>Article</TableCell>
                    <TableCell>Échéance</TableCell>
                    <TableCell>Statut</TableCell>
                    <TableCell />
                  </TableRow>
                </TableHead>
                <TableBody>
                  {incident.steps.map((step) => (
                    <TableRow key={step.key}>
                      <TableCell>{stepLabels[step.key] ?? step.key}</TableCell>
                      <TableCell>{step.article}</TableCell>
                      <TableCell>
                        {formatDate(step.dueAt)}
                        {step.provisional ? " (estimée)" : ""}
                      </TableCell>
                      <TableCell>
                        <Chip
                          size="small"
                          color={statusColors[step.status]}
                          label={
                            step.late
                              ? `${statusLabels[step.status]} en retard`
                              : statusLabels[step.status]
                          }
                        />
                      </TableCell>
                      <TableCell>
                        {canManage &&
                          incident.status === "OPEN" &&
                          !step.doneAt &&
                          step.status !== "NOT_APPLICABLE" && (
                            <Button
                              size="small"
                              disabled={file.isPending}
                              onClick={() =>
                                file.mutate({ incident, step: step.key })
                              }
                            >
                              Marquer déposé
                            </Button>
                          )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              <Stack direction="row" spacing={1}>
                <Button
                  size="small"
                  onClick={async () => {
                    setReportError(false);
                    try {
                      await download(
                        `/regulatory-incidents/${incident.id}/report`,
                        `notification-${incident.id}.md`,
                      );
                    } catch {
                      setReportError(true);
                    }
                  }}
                >
                  Télécharger la trame
                </Button>
                {canManage && incident.status === "OPEN" && (
                  <Button
                    size="small"
                    disabled={close.isPending}
                    onClick={() => close.mutate(incident.id)}
                  >
                    Clore
                  </Button>
                )}
              </Stack>
              {(file.isError || close.isError) && (
                <Alert severity="error">
                  {errorText(file.error ?? close.error)}
                </Alert>
              )}
            </Stack>
          </CardContent>
        </Card>
      ))}
      <Dialog open={dialog} onClose={() => setDialog(false)} fullWidth>
        <DialogTitle>Déclarer un dossier de notification</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField
              select
              label="Régime"
              value={form.regime}
              onChange={(e) => setForm({ ...form, regime: e.target.value })}
            >
              {Object.entries(regimeLabels).map(([value, label]) => (
                <MenuItem key={value} value={value}>
                  {label}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              label="Titre"
              value={form.title}
              onChange={(e) => setForm({ ...form, title: e.target.value })}
            />
            <TextField
              label="Description"
              multiline
              minRows={3}
              value={form.description}
              onChange={(e) =>
                setForm({ ...form, description: e.target.value })
              }
            />
            <TextField
              type="datetime-local"
              label="Date et heure de prise de connaissance"
              value={form.awareAt}
              onChange={(e) => setForm({ ...form, awareAt: e.target.value })}
              slotProps={{ inputLabel: { shrink: true } }}
            />
            {form.regime === "AI_ACT_SERIOUS_INCIDENT" && (
              <Stack>
                {(
                  [
                    ["widespread", "Infraction généralisée (2 jours)"],
                    [
                      "criticalInfrastructure",
                      "Infrastructure critique touchée (2 jours)",
                    ],
                    ["death", "Décès (10 jours)"],
                  ] as const
                ).map(([key, label]) => (
                  <FormControlLabel
                    key={key}
                    label={label}
                    control={
                      <Checkbox
                        checked={form[key]}
                        onChange={(e) =>
                          setForm({ ...form, [key]: e.target.checked })
                        }
                      />
                    }
                  />
                ))}
              </Stack>
            )}
            {create.isError && (
              <Alert severity="error">{errorText(create.error)}</Alert>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialog(false)}>Annuler</Button>
          <Button
            variant="contained"
            disabled={create.isPending}
            onClick={() => create.mutate()}
          >
            Enregistrer
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}

function GapChips({ gaps }: { gaps: string[] }) {
  if (gaps.length === 0)
    return <Chip size="small" color="success" label="Complet" />;
  return (
    <Stack direction="row" spacing={0.5} useFlexGap flexWrap="wrap">
      {gaps.map((gap) => (
        <Chip
          key={gap}
          size="small"
          color="warning"
          label={gapLabels[gap] ?? gap}
        />
      ))}
    </Stack>
  );
}

function ProductsTab({ canManage }: { canManage: boolean }) {
  const cache = useQueryClient();
  const list = useQuery({
    queryKey: ["cra-products"],
    queryFn: async () => (await api.get<ProductList>("/cra/products")).data,
  });
  const [dialog, setDialog] = useState(false);
  const empty = {
    name: "",
    version: "",
    category: "DEFAULT",
    status: "IN_DEVELOPMENT",
    placedOnMarketAt: "",
    supportEndsAt: "",
    sbomReference: "",
    vulnerabilityContact: "",
    conformityRoute: "NOT_ASSESSED",
    declarationReference: "",
  };
  const [form, setForm] = useState(empty);
  const create = useMutation({
    mutationFn: async () =>
      api.post("/cra/products", {
        ...form,
        placedOnMarketAt: form.placedOnMarketAt || null,
        supportEndsAt: form.supportEndsAt || null,
      }),
    onSuccess: async () => {
      setDialog(false);
      setForm(empty);
      await cache.invalidateQueries({ queryKey: ["cra-products"] });
    },
  });
  if (list.isError)
    return <Alert severity="error">Impossible de charger les produits.</Alert>;
  if (!list.data) return null;

  return (
    <Stack spacing={2}>
      <Stack direction="row" spacing={1} alignItems="center">
        <Chip label={`${list.data.summary.products} produits`} />
        <Chip label={`${list.data.summary.onMarket} sur le marché`} />
        <Chip
          color={list.data.summary.withGaps ? "warning" : "default"}
          label={`${list.data.summary.withGaps} avec écarts`}
        />
        {canManage && (
          <Button variant="contained" onClick={() => setDialog(true)}>
            Ajouter un produit
          </Button>
        )}
      </Stack>
      <Table size="small">
        <TableHead>
          <TableRow>
            <TableCell>Produit</TableCell>
            <TableCell>Catégorie</TableCell>
            <TableCell>Statut</TableCell>
            <TableCell>Fin de support</TableCell>
            <TableCell>Écarts de préparation</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {list.data.items.map((item) => (
            <TableRow key={item.id}>
              <TableCell>{`${item.name} ${item.version}`}</TableCell>
              <TableCell>{categoryLabels[item.category]}</TableCell>
              <TableCell>{productStatusLabels[item.status]}</TableCell>
              <TableCell>{item.supportEndsAt ?? "—"}</TableCell>
              <TableCell>
                <GapChips gaps={item.gaps} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      <Dialog open={dialog} onClose={() => setDialog(false)} fullWidth>
        <DialogTitle>Ajouter un produit</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <Stack direction="row" spacing={2}>
              <TextField
                fullWidth
                label="Nom"
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
              />
              <TextField
                label="Version"
                value={form.version}
                onChange={(e) => setForm({ ...form, version: e.target.value })}
              />
            </Stack>
            <Stack direction="row" spacing={2}>
              <TextField
                select
                fullWidth
                label="Catégorie"
                value={form.category}
                onChange={(e) => setForm({ ...form, category: e.target.value })}
              >
                {Object.entries(categoryLabels).map(([value, label]) => (
                  <MenuItem key={value} value={value}>
                    {label}
                  </MenuItem>
                ))}
              </TextField>
              <TextField
                select
                fullWidth
                label="Statut"
                value={form.status}
                onChange={(e) => setForm({ ...form, status: e.target.value })}
              >
                {Object.entries(productStatusLabels).map(([value, label]) => (
                  <MenuItem key={value} value={value}>
                    {label}
                  </MenuItem>
                ))}
              </TextField>
            </Stack>
            <Stack direction="row" spacing={2}>
              <TextField
                fullWidth
                type="date"
                label="Mise sur le marché"
                value={form.placedOnMarketAt}
                onChange={(e) =>
                  setForm({ ...form, placedOnMarketAt: e.target.value })
                }
                slotProps={{ inputLabel: { shrink: true } }}
              />
              <TextField
                fullWidth
                type="date"
                label="Fin de support"
                value={form.supportEndsAt}
                onChange={(e) =>
                  setForm({ ...form, supportEndsAt: e.target.value })
                }
                slotProps={{ inputLabel: { shrink: true } }}
              />
            </Stack>
            <TextField
              label="Référence de la SBOM"
              value={form.sbomReference}
              onChange={(e) =>
                setForm({ ...form, sbomReference: e.target.value })
              }
            />
            <TextField
              label="Contact de divulgation des vulnérabilités"
              value={form.vulnerabilityContact}
              onChange={(e) =>
                setForm({ ...form, vulnerabilityContact: e.target.value })
              }
            />
            <TextField
              select
              label="Voie de conformité"
              value={form.conformityRoute}
              onChange={(e) =>
                setForm({ ...form, conformityRoute: e.target.value })
              }
            >
              {Object.entries(conformityLabels).map(([value, label]) => (
                <MenuItem key={value} value={value}>
                  {label}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              label="Référence de la déclaration de conformité"
              value={form.declarationReference}
              onChange={(e) =>
                setForm({ ...form, declarationReference: e.target.value })
              }
            />
            {create.isError && (
              <Alert severity="error">{errorText(create.error)}</Alert>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialog(false)}>Annuler</Button>
          <Button
            variant="contained"
            disabled={create.isPending}
            onClick={() => create.mutate()}
          >
            Enregistrer
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}

function DoraRegisterTab({ canManage }: { canManage: boolean }) {
  const cache = useQueryClient();
  const list = useQuery({
    queryKey: ["dora-arrangements"],
    queryFn: async () =>
      (await api.get<ArrangementList>("/dora/arrangements")).data,
  });
  const providers = useQuery({
    queryKey: ["third-parties-options"],
    queryFn: async () =>
      (await api.get<ThirdPartyOption[]>("/third-parties")).data,
  });
  const [dialog, setDialog] = useState(false);
  const empty = {
    thirdPartyId: "",
    serviceType: "CLOUD_INFRASTRUCTURE",
    description: "",
    supportsCriticalFunction: false,
    providerLei: "",
    contractReference: "",
  };
  const [form, setForm] = useState(empty);
  const create = useMutation({
    mutationFn: async () =>
      api.post("/dora/arrangements", {
        ...form,
        thirdPartyId: Number(form.thirdPartyId),
      }),
    onSuccess: async () => {
      setDialog(false);
      setForm(empty);
      await cache.invalidateQueries({ queryKey: ["dora-arrangements"] });
    },
  });
  const [exportError, setExportError] = useState(false);
  if (list.isError)
    return <Alert severity="error">Impossible de charger le registre.</Alert>;
  if (!list.data) return null;

  return (
    <Stack spacing={2}>
      <Stack direction="row" spacing={1} alignItems="center">
        <Chip label={`${list.data.summary.arrangements} accords`} />
        <Chip
          label={`${list.data.summary.criticalFunctions} fonctions critiques`}
        />
        <Chip
          color={list.data.summary.withGaps ? "warning" : "default"}
          label={`${list.data.summary.withGaps} avec écarts`}
        />
        <Button
          onClick={async () => {
            setExportError(false);
            try {
              await download("/dora/register.csv", "registre-dora.csv");
            } catch {
              setExportError(true);
            }
          }}
        >
          Exporter en CSV
        </Button>
        {canManage && (
          <Button variant="contained" onClick={() => setDialog(true)}>
            Ajouter un accord
          </Button>
        )}
      </Stack>
      {exportError && (
        <Alert severity="error">L’export du registre a échoué.</Alert>
      )}
      <Alert severity="info">
        Ce registre couvre les champs saisis dans RiskPilot ; le format
        réglementaire complet de l’article 28 §3 peut en exiger davantage.
      </Alert>
      <Table size="small">
        <TableHead>
          <TableRow>
            <TableCell>Prestataire</TableCell>
            <TableCell>Service</TableCell>
            <TableCell>Fonction critique</TableCell>
            <TableCell>LEI</TableCell>
            <TableCell>Écarts</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {list.data.items.map((item) => (
            <TableRow key={item.id}>
              <TableCell>{item.thirdParty.name}</TableCell>
              <TableCell>{serviceTypeLabels[item.serviceType]}</TableCell>
              <TableCell>
                {item.supportsCriticalFunction ? "Oui" : "Non"}
              </TableCell>
              <TableCell>{item.providerLei ?? "—"}</TableCell>
              <TableCell>
                <GapChips gaps={item.gaps} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      <Dialog open={dialog} onClose={() => setDialog(false)} fullWidth>
        <DialogTitle>Ajouter un accord de services TIC</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField
              select
              label="Prestataire"
              value={form.thirdPartyId}
              onChange={(e) =>
                setForm({ ...form, thirdPartyId: e.target.value })
              }
            >
              {(providers.data ?? []).map((item) => (
                <MenuItem key={item.id} value={String(item.id)}>
                  {item.name}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              select
              label="Type de service"
              value={form.serviceType}
              onChange={(e) =>
                setForm({ ...form, serviceType: e.target.value })
              }
            >
              {Object.entries(serviceTypeLabels).map(([value, label]) => (
                <MenuItem key={value} value={value}>
                  {label}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              label="Description du service"
              multiline
              minRows={2}
              value={form.description}
              onChange={(e) =>
                setForm({ ...form, description: e.target.value })
              }
            />
            <FormControlLabel
              label="Soutient une fonction critique ou importante"
              control={
                <Checkbox
                  checked={form.supportsCriticalFunction}
                  onChange={(e) =>
                    setForm({
                      ...form,
                      supportsCriticalFunction: e.target.checked,
                    })
                  }
                />
              }
            />
            <TextField
              label="LEI du prestataire"
              value={form.providerLei}
              onChange={(e) =>
                setForm({ ...form, providerLei: e.target.value })
              }
              slotProps={{ htmlInput: { maxLength: 20 } }}
            />
            <TextField
              label="Référence du contrat"
              value={form.contractReference}
              onChange={(e) =>
                setForm({ ...form, contractReference: e.target.value })
              }
            />
            {create.isError && (
              <Alert severity="error">{errorText(create.error)}</Alert>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialog(false)}>Annuler</Button>
          <Button
            variant="contained"
            disabled={create.isPending || form.thirdPartyId === ""}
            onClick={() => create.mutate()}
          >
            Enregistrer
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}

export function RegulatoryNotificationsPage() {
  const { user } = useAuth();
  const [tab, setTab] = useState(0);
  const canManage = Boolean(
    user?.roles.some((role) =>
      ["ROLE_RISK_MANAGER", "ROLE_ADMIN", "ROLE_SUPER_ADMIN"].includes(role),
    ),
  );

  return (
    <Stack spacing={2}>
      <Typography variant="h4" component="h1" fontWeight={800}>
        Notifications réglementaires
      </Typography>
      <Typography color="text.secondary">
        Incidents à notifier (DORA, CRA, AI Act), inventaire des produits du
        Cyber Resilience Act et registre des accords de services TIC DORA.
      </Typography>
      <Tabs value={tab} onChange={(_event, value: number) => setTab(value)}>
        <Tab label="Incidents à notifier" />
        <Tab label="Produits CRA" />
        <Tab label="Registre DORA" />
      </Tabs>
      {tab === 0 && <IncidentsTab canManage={canManage} />}
      {tab === 1 && <ProductsTab canManage={canManage} />}
      {tab === 2 && <DoraRegisterTab canManage={canManage} />}
    </Stack>
  );
}
