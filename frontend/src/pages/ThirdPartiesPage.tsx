import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Add, EditOutlined } from "@mui/icons-material";
import {
  Alert,
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
  FormControl,
  FormControlLabel,
  InputLabel,
  MenuItem,
  Pagination,
  Select,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import axios from "axios";
import { useEffect, useState, type FormEvent } from "react";
import { downloadCsv } from "../api/csv";
import { api } from "../api/client";
import type { User } from "../api/types";
import { useAuth } from "../auth/useAuth";
import { useInterfaceLocale } from "../i18n/InterfaceLocaleContext";
import { SupplierAssessmentDialog } from "../components/SupplierAssessmentDialog";
import { SupplierCampaignDialog } from "../components/SupplierCampaignDialog";

type Criticality = "LOW" | "MEDIUM" | "HIGH" | "CRITICAL";
type ThirdPartyStatus =
  "PROSPECT" | "ACTIVE" | "SUSPENDED" | "EXIT_PLANNED" | "TERMINATED";

type ThirdParty = {
  id: number;
  name: string;
  contactEmail: string | null;
  services: string | null;
  dataCategories: string[];
  criticality: Criticality;
  status: ThirdPartyStatus;
  contractReference: string | null;
  sla: string | null;
  dependencies: string | null;
  exitPlan: string | null;
  contractEndsAt: string | null;
  nextAssessmentAt: string | null;
  cyberScore: number;
  certifications: string[];
  riskSummary: string | null;
  compensatingMeasures: string | null;
  owner: { id: number; name: string };
  assessments: Array<{
    id: number;
    title: string;
    status: string;
    score: number | null;
  }>;
};

type ThirdPartyForm = {
  name: string;
  contactEmail: string;
  services: string;
  criticality: Criticality;
  status: ThirdPartyStatus;
  ownerId: string;
  dataCategories: string;
  initialDataCategories: string[];
  contractReference: string;
  sla: string;
  dependencies: string;
  exitPlan: string;
  contractEndsAt: string;
  nextAssessmentAt: string;
  certifications: string;
  initialCertifications: string[];
  riskSummary: string;
  compensatingMeasures: string;
};

const criticalities: Criticality[] = ["LOW", "MEDIUM", "HIGH", "CRITICAL"];
const statuses: ThirdPartyStatus[] = [
  "PROSPECT",
  "ACTIVE",
  "SUSPENDED",
  "EXIT_PLANNED",
  "TERMINATED",
];
const managerRoles = [
  "ROLE_SUPER_ADMIN",
  "ROLE_ADMIN",
  "ROLE_RISK_MANAGER",
  "ROLE_AUDITOR",
];
const userDirectoryRoles = [
  "ROLE_SUPER_ADMIN",
  "ROLE_ADMIN",
  "ROLE_RISK_MANAGER",
];

function emptyForm(ownerId = ""): ThirdPartyForm {
  return {
    name: "",
    contactEmail: "",
    services: "",
    criticality: "MEDIUM",
    status: "ACTIVE",
    ownerId,
    dataCategories: "",
    initialDataCategories: [],
    contractReference: "",
    sla: "",
    dependencies: "",
    exitPlan: "",
    contractEndsAt: "",
    nextAssessmentAt: "",
    certifications: "",
    initialCertifications: [],
    riskSummary: "",
    compensatingMeasures: "",
  };
}

function formFromThirdParty(item: ThirdParty): ThirdPartyForm {
  return {
    name: item.name,
    contactEmail: item.contactEmail ?? "",
    services: item.services ?? "",
    criticality: item.criticality,
    status: item.status,
    ownerId: String(item.owner.id),
    dataCategories: item.dataCategories.join(", "),
    initialDataCategories: [...item.dataCategories],
    contractReference: item.contractReference ?? "",
    sla: item.sla ?? "",
    dependencies: item.dependencies ?? "",
    exitPlan: item.exitPlan ?? "",
    contractEndsAt: item.contractEndsAt ?? "",
    nextAssessmentAt: item.nextAssessmentAt ?? "",
    certifications: item.certifications.join(", "),
    initialCertifications: [...item.certifications],
    riskSummary: item.riskSummary ?? "",
    compensatingMeasures: item.compensatingMeasures ?? "",
  };
}

function commaSeparatedValues(value: string): string[] {
  return value
    .split(",")
    .map((item) => item.trim())
    .filter(Boolean);
}

function localDateYmd(date = new Date()): string {
  const pad = (value: number) => String(value).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function responseMessage(error: unknown, fallback: string): string {
  return axios.isAxiosError<{ message?: string }>(error)
    ? (error.response?.data?.message ?? fallback)
    : fallback;
}

export function ThirdPartiesPage() {
  const locale = useInterfaceLocale();
  const english = locale === "en";
  const { user } = useAuth();
  const client = useQueryClient();
  const canManage = Boolean(
    user?.roles.some((role) => managerRoles.includes(role)),
  );
  const canListUsers = Boolean(
    user?.roles.some((role) => userDirectoryRoles.includes(role)),
  );
  const [dialog, setDialog] = useState(false);
  const [editing, setEditing] = useState<ThirdParty | null>(null);
  const [form, setForm] = useState<ThirdPartyForm>(() => emptyForm());
  const [search, setSearch] = useState("");
  const [criticalityFilter, setCriticalityFilter] = useState<
    Criticality | "ALL"
  >("ALL");
  const [statusFilter, setStatusFilter] = useState<ThirdPartyStatus | "ALL">(
    "ALL",
  );
  const [followUpOnly, setFollowUpOnly] = useState(false);
  const [ownerFilter, setOwnerFilter] = useState("ALL");
  const [reviewFilter, setReviewFilter] = useState("ALL");
  const [sort, setSort] = useState("name");
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(12);
  useEffect(() => {
    setPage(1);
  }, [
    search,
    criticalityFilter,
    statusFilter,
    followUpOnly,
    ownerFilter,
    reviewFilter,
    sort,
    pageSize,
  ]);
  const [assessmentId, setAssessmentId] = useState<number | null>(null);
  const [campaignParty, setCampaignParty] = useState<ThirdParty | null>(null);

  const query = useQuery({
    queryKey: ["third-parties"],
    queryFn: async ({ signal }) =>
      (await api.get<ThirdParty[]>("/third-parties", { signal })).data,
  });
  const users = useQuery({
    queryKey: ["users"],
    enabled: canListUsers && dialog,
    queryFn: async ({ signal }) =>
      (await api.get<User[]>("/users", { signal })).data,
  });

  const save = useMutation({
    mutationFn: () => {
      const dataCategories = form.initialDataCategories.join(", ");
      const certifications = form.initialCertifications.join(", ");
      const payload = {
        name: form.name,
        contactEmail: form.contactEmail || null,
        services: form.services || null,
        dataCategories:
          form.dataCategories === dataCategories
            ? form.initialDataCategories
            : commaSeparatedValues(form.dataCategories),
        criticality: form.criticality,
        status: form.status,
        ownerId: Number(form.ownerId),
        contractReference: form.contractReference || null,
        sla: form.sla || null,
        dependencies: form.dependencies || null,
        exitPlan: form.exitPlan || null,
        contractEndsAt: form.contractEndsAt || null,
        nextAssessmentAt: form.nextAssessmentAt || null,
        certifications:
          form.certifications === certifications
            ? form.initialCertifications
            : commaSeparatedValues(form.certifications),
        riskSummary: form.riskSummary || null,
        compensatingMeasures: form.compensatingMeasures || null,
      };
      return editing
        ? api.put(`/third-parties/${editing.id}`, payload)
        : api.post("/third-parties", payload);
    },
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["third-parties"] });
      setDialog(false);
      setEditing(null);
      setForm(emptyForm(String(user?.id ?? "")));
    },
  });

  const openCreate = () => {
    save.reset();
    setEditing(null);
    setForm(emptyForm(String(user?.id ?? "")));
    setDialog(true);
  };
  const openEdit = (item: ThirdParty) => {
    save.reset();
    setEditing(item);
    setForm(formFromThirdParty(item));
    setDialog(true);
  };
  const closeDialog = () => {
    if (save.isPending) return;
    setDialog(false);
    setEditing(null);
    setForm(emptyForm(String(user?.id ?? "")));
    save.reset();
  };

  const criticalityLabel = (value: Criticality) =>
    ({
      LOW: english ? "Low" : "Faible",
      MEDIUM: english ? "Medium" : "Moyenne",
      HIGH: english ? "High" : "Élevée",
      CRITICAL: english ? "Critical" : "Critique",
    })[value];
  const statusLabel = (value: ThirdPartyStatus) =>
    ({
      PROSPECT: "Prospect",
      ACTIVE: english ? "Active" : "Actif",
      SUSPENDED: english ? "Suspended" : "Suspendu",
      EXIT_PLANNED: english ? "Exit planned" : "Sortie planifiée",
      TERMINATED: english ? "Terminated" : "Terminé",
    })[value];
  const assessmentStatusLabel = (value: string) =>
    ({
      DRAFT: english ? "Draft" : "Brouillon",
      SENT: english ? "Sent" : "Envoyée",
      IN_PROGRESS: english ? "In progress" : "En cours",
      SUBMITTED: english ? "Awaiting review" : "À valider",
      REVIEWED: english ? "Reviewed" : "Validée",
      EXPIRED: english ? "Expired" : "Expirée",
    })[value] ?? (english ? "Unknown status" : "Statut inconnu");
  const today = localDateYmd();
  const overdue = (item: ThirdParty) => ({
    contract:
      item.status !== "TERMINATED" &&
      item.contractEndsAt !== null &&
      item.contractEndsAt < today,
    assessment:
      item.status !== "TERMINATED" &&
      item.nextAssessmentAt !== null &&
      item.nextAssessmentAt < today,
  });
  const normalizedSearch = search.trim().toLocaleLowerCase(locale);
  const needsFollowUp = (item: ThirdParty) => {
    if (item.status === "TERMINATED") return false;
    const dates = overdue(item);
    return (
      dates.contract ||
      dates.assessment ||
      item.assessments.some(
        (assessment) => assessment.status === "SUBMITTED",
      ) ||
      ((item.criticality === "HIGH" || item.criticality === "CRITICAL") &&
        !item.assessments.some(
          (assessment) => assessment.status === "REVIEWED",
        ))
    );
  };
  const visibleItems = (query.data ?? []).filter((item) => {
    const matchesSearch = [
      item.name,
      item.services ?? "",
      item.owner.name,
      item.contactEmail ?? "",
      item.contractReference ?? "",
      item.sla ?? "",
      ...item.dataCategories,
      ...item.certifications,
    ]
      .join(" ")
      .toLocaleLowerCase(locale)
      .includes(normalizedSearch);
    return (
      matchesSearch &&
      (criticalityFilter === "ALL" || item.criticality === criticalityFilter) &&
      (statusFilter === "ALL" || item.status === statusFilter) &&
      (ownerFilter === "ALL" || String(item.owner.id) === ownerFilter) &&
      (reviewFilter === "ALL" ||
        item.assessments.some((a) => a.status === "REVIEWED") ===
          (reviewFilter === "REVIEWED")) &&
      (!followUpOnly || needsFollowUp(item))
    );
  });

  visibleItems.sort((a, b) => {
    if (sort === "criticality") {
      const priority =
        criticalities.indexOf(b.criticality) -
        criticalities.indexOf(a.criticality);
      if (priority) return priority;
    }
    if (sort === "assessment") {
      const date = (a.nextAssessmentAt ?? "9999-12-31").localeCompare(
        b.nextAssessmentAt ?? "9999-12-31",
      );
      if (date) return date;
    }
    return a.name.localeCompare(b.name, locale) || a.id - b.id;
  });
  const pageCount = Math.max(1, Math.ceil(visibleItems.length / pageSize));
  const currentPage = Math.min(page, pageCount);
  const pageItems = visibleItems.slice(
    (currentPage - 1) * pageSize,
    currentPage * pageSize,
  );
  const filterOwners = new Map(
    (query.data ?? []).map((item) => [item.owner.id, item.owner.name]),
  );
  const resetFilters = () => {
    setSearch("");
    setCriticalityFilter("ALL");
    setStatusFilter("ALL");
    setFollowUpOnly(false);
    setOwnerFilter("ALL");
    setReviewFilter("ALL");
    setPage(1);
  };
  const exportCsv = () =>
    downloadCsv(`riskpilot-tiers-${today}.csv`, [
      english
        ? [
            "ID",
            "Name",
            "Contact",
            "Owner",
            "Criticality",
            "Status",
            "Services",
            "Data categories",
            "Contract",
            "SLA",
            "Dependencies",
            "Exit plan",
            "Contract end",
            "Next assessment",
            "Declared certifications",
            "Risk summary",
            "Compensating measures",
            "Reviewed score (%)",
            "Assessments",
          ]
        : [
            "ID",
            "Nom",
            "Contact",
            "Responsable",
            "Criticité",
            "Statut",
            "Services",
            "Catégories de données",
            "Contrat",
            "SLA",
            "Dépendances",
            "Réversibilité",
            "Fin du contrat",
            "Prochaine évaluation",
            "Certifications déclarées",
            "Synthèse des risques",
            "Mesures compensatoires",
            "Score validé (%)",
            "Évaluations",
          ],
      ...visibleItems.map((item) => [
        item.id,
        item.name,
        item.contactEmail,
        item.owner.name,
        criticalityLabel(item.criticality),
        statusLabel(item.status),
        item.services,
        item.dataCategories.join(" | "),
        item.contractReference,
        item.sla,
        item.dependencies,
        item.exitPlan,
        item.contractEndsAt,
        item.nextAssessmentAt,
        item.certifications.join(" | "),
        item.riskSummary,
        item.compensatingMeasures,
        item.assessments.some((a) => a.status === "REVIEWED")
          ? item.cyberScore
          : null,
        item.assessments
          .map(
            (a) =>
              `${a.title}: ${assessmentStatusLabel(a.status)}${a.status !== "REVIEWED" || a.score === null ? "" : ` (${a.score}%)`}`,
          )
          .join(" | "),
      ]),
    ]);
  const nextMonth = new Date(`${today}T12:00:00`);
  nextMonth.setDate(nextMonth.getDate() + 30);
  const upcoming = (date: string | null) =>
    date !== null && date >= today && date <= localDateYmd(nextMonth);
  const ownerOptions = new Map<number, string>();
  if (canListUsers) {
    users.data?.forEach((item) =>
      ownerOptions.set(item.id, `${item.firstName} ${item.lastName}`.trim()),
    );
  }
  if (editing) ownerOptions.set(editing.owner.id, editing.owner.name);
  if (user) {
    ownerOptions.set(
      user.id,
      `${user.firstName} ${user.lastName}`.trim() || user.email,
    );
  }
  const formIsValid =
    form.name.trim() !== "" && Number(form.ownerId) > 0 && canManage;

  return (
    <Stack spacing={3}>
      <Stack
        direction={{ xs: "column", sm: "row" }}
        justifyContent="space-between"
        gap={2}
      >
        <Stack>
          <Typography variant="h4" fontWeight={750}>
            {english ? "Third parties and suppliers" : "Tiers et fournisseurs"}
          </Typography>
          <Typography color="text.secondary">
            {english
              ? "Dependencies, contracts, cyber assessments and exit plans"
              : "Dépendances, contrats, évaluations cyber et plans de sortie"}
          </Typography>
        </Stack>
        {canManage && (
          <Button variant="contained" startIcon={<Add />} onClick={openCreate}>
            {english ? "Add a third party" : "Ajouter un tiers"}
          </Button>
        )}
      </Stack>

      <Stack
        sx={{
          display: "grid",
          gridTemplateColumns: {
            xs: "1fr",
            sm: "minmax(0, 2fr) repeat(2, minmax(150px, 1fr))",
          },
          gap: 2,
          alignItems: "center",
        }}
      >
        <TextField
          label={english ? "Search" : "Rechercher"}
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          helperText={
            english
              ? "Name, contact, services, owner, contract or declarations"
              : "Nom, contact, services, responsable, contrat ou déclarations"
          }
        />
        <FormControl fullWidth>
          <InputLabel id="third-party-criticality-filter-label">
            {english ? "Criticality" : "Criticité"}
          </InputLabel>
          <Select
            id="third-party-criticality-filter"
            labelId="third-party-criticality-filter-label"
            label={english ? "Criticality" : "Criticité"}
            value={criticalityFilter}
            onChange={(event) =>
              setCriticalityFilter(event.target.value as Criticality | "ALL")
            }
          >
            <MenuItem value="ALL">{english ? "All" : "Toutes"}</MenuItem>
            {criticalities.map((value) => (
              <MenuItem key={value} value={value}>
                {criticalityLabel(value)}
              </MenuItem>
            ))}
          </Select>
        </FormControl>
        <FormControl fullWidth>
          <InputLabel id="third-party-status-filter-label">
            {english ? "Status" : "Statut"}
          </InputLabel>
          <Select
            id="third-party-status-filter"
            labelId="third-party-status-filter-label"
            label={english ? "Status" : "Statut"}
            value={statusFilter}
            onChange={(event) =>
              setStatusFilter(event.target.value as ThirdPartyStatus | "ALL")
            }
          >
            <MenuItem value="ALL">{english ? "All" : "Tous"}</MenuItem>
            {statuses.map((value) => (
              <MenuItem key={value} value={value}>
                {statusLabel(value)}
              </MenuItem>
            ))}
          </Select>
        </FormControl>
      </Stack>
      <Stack
        direction={{ xs: "column", sm: "row" }}
        spacing={2}
        flexWrap="wrap"
        useFlexGap
      >
        {[
          {
            label: english ? "Owner filter" : "Filtrer par responsable",
            value: ownerFilter,
            set: setOwnerFilter,
            options: [
              ["ALL", english ? "All" : "Tous"],
              ...[...filterOwners].map(([key, name]) => [String(key), name]),
            ],
          },
          {
            label: english ? "Assessment filter" : "Filtrer les évaluations",
            value: reviewFilter,
            set: setReviewFilter,
            options: [
              ["ALL", english ? "All" : "Toutes"],
              ["REVIEWED", english ? "Reviewed" : "Validées"],
              ["UNREVIEWED", english ? "Not reviewed" : "Non validées"],
            ],
          },
          {
            label: english ? "Sort" : "Trier",
            value: sort,
            set: setSort,
            options: [
              ["name", english ? "Name" : "Nom"],
              ["criticality", english ? "Criticality" : "Criticité"],
              [
                "assessment",
                english ? "Next assessment" : "Prochaine évaluation",
              ],
            ],
          },
        ].map((filter, index) => (
          <TextField
            key={filter.label}
            id={`third-party-extra-filter-${index}`}
            select
            label={filter.label}
            value={filter.value}
            onChange={(event) => filter.set(event.target.value)}
            sx={{ minWidth: 180, flex: 1 }}
          >
            {filter.options.map(([value, label]) => (
              <MenuItem key={value} value={value}>
                {label}
              </MenuItem>
            ))}
          </TextField>
        ))}
        <Button onClick={resetFilters}>
          {english ? "Reset filters" : "Réinitialiser les filtres"}
        </Button>
        <Button
          onClick={exportCsv}
          disabled={!query.isSuccess || visibleItems.length === 0}
        >
          {english ? "Export filtered CSV" : "Exporter le CSV filtré"}
        </Button>
      </Stack>
      {query.isSuccess && (
        <Stack
          direction="row"
          flexWrap="wrap"
          useFlexGap
          gap={1}
          aria-label={
            english
              ? "Filtered register summary"
              : "Synthèse du registre filtré"
          }
        >
          <Chip label={`Total: ${visibleItems.length}`} />
          <Chip
            label={`${english ? "Active" : "Actifs"}: ${visibleItems.filter((item) => item.status === "ACTIVE").length}`}
          />
          <Chip
            label={`${english ? "High priority" : "Prioritaires"}: ${visibleItems.filter((item) => ["HIGH", "CRITICAL"].includes(item.criticality)).length}`}
          />
          <Chip
            label={`${english ? "Follow-up" : "À suivre"}: ${visibleItems.filter(needsFollowUp).length}`}
          />
          <Chip
            label={`${english ? "Reviewed" : "Validés"}: ${visibleItems.filter((item) => item.assessments.some((a) => a.status === "REVIEWED")).length}`}
          />
        </Stack>
      )}
      <FormControlLabel
        control={
          <Checkbox
            checked={followUpOnly}
            onChange={(event) => setFollowUpOnly(event.target.checked)}
          />
        }
        label={english ? "Follow-up only" : "À suivre uniquement"}
      />
      <Typography variant="caption" color="text.secondary">
        {english
          ? "Overdue dates, submitted assessments awaiting review, or high-priority suppliers not assessed."
          : "Échéances dépassées, évaluations soumises à valider ou tiers prioritaires non évalués."}
      </Typography>

      {query.isPending && (
        <Stack alignItems="center">
          <CircularProgress
            aria-label={
              english ? "Loading third parties" : "Chargement des tiers"
            }
          />
        </Stack>
      )}
      {query.isError && (
        <Alert
          severity="error"
          action={
            <Button color="inherit" onClick={() => void query.refetch()}>
              {english ? "Retry" : "Réessayer"}
            </Button>
          }
        >
          {english
            ? "Unable to load the third-party register."
            : "Impossible de charger le registre des tiers."}
        </Alert>
      )}
      {query.isSuccess && query.data.length === 0 && (
        <Alert severity="info">
          {english ? "No third party recorded." : "Aucun tiers enregistré."}
        </Alert>
      )}
      {query.isSuccess &&
        query.data.length > 0 &&
        visibleItems.length === 0 && (
          <Alert severity="info">
            {english
              ? "No third party matches these filters."
              : "Aucun tiers ne correspond à ces filtres."}
          </Alert>
        )}

      <Stack
        sx={{
          display: "grid",
          gridTemplateColumns: { xs: "1fr", md: "repeat(2, minmax(0, 1fr))" },
          gap: 2,
        }}
      >
        {pageItems.map((item) => {
          const dates = overdue(item);
          const hasReviewedAssessment = item.assessments.some(
            (assessment) => assessment.status === "REVIEWED",
          );
          const hasSubmittedAssessment = item.assessments.some(
            (assessment) => assessment.status === "SUBMITTED",
          );
          return (
            <Card
              variant="outlined"
              key={item.id}
              sx={{ minWidth: 0, overflowWrap: "anywhere" }}
            >
              <CardContent>
                <Stack spacing={1.25}>
                  <Stack
                    direction={{ xs: "column", sm: "row" }}
                    justifyContent="space-between"
                    alignItems={{ xs: "stretch", sm: "center" }}
                    gap={1}
                  >
                    <Typography fontWeight={750}>{item.name}</Typography>
                    <Stack direction="row" flexWrap="wrap" useFlexGap gap={1}>
                      <Chip
                        size="small"
                        label={criticalityLabel(item.criticality)}
                        color={
                          item.criticality === "CRITICAL"
                            ? "error"
                            : item.criticality === "HIGH"
                              ? "warning"
                              : "default"
                        }
                      />
                      <Chip
                        size="small"
                        variant="outlined"
                        label={statusLabel(item.status)}
                        color={item.status === "ACTIVE" ? "success" : "default"}
                      />
                      {canManage && (
                        <Button
                          size="small"
                          startIcon={<EditOutlined />}
                          aria-label={`${english ? "Edit" : "Modifier"} ${item.name}`}
                          onClick={() => openEdit(item)}
                        >
                          {english ? "Edit" : "Modifier"}
                        </Button>
                      )}
                    </Stack>
                  </Stack>
                  <Typography variant="body2">
                    {item.services ??
                      (english
                        ? "Services to document"
                        : "Services à documenter")}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {english ? "Owner" : "Responsable"}: {item.owner.name} ·{" "}
                    {item.contactEmail ??
                      (english ? "no contact" : "contact absent")}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {english ? "Contract end" : "Fin du contrat"}:{" "}
                    {item.contractEndsAt ??
                      (english ? "not provided" : "non renseignée")}
                    {" · "}
                    {english ? "Next assessment" : "Prochaine évaluation"}:{" "}
                    {item.nextAssessmentAt ??
                      (english ? "not scheduled" : "non planifiée")}
                  </Typography>
                  {dates.contract && (
                    <Alert severity="warning">
                      {english
                        ? `Contract expired on ${item.contractEndsAt}`
                        : `Contrat expiré le ${item.contractEndsAt}`}
                    </Alert>
                  )}
                  {dates.assessment && (
                    <Alert severity="warning">
                      {english
                        ? `Reassessment overdue since ${item.nextAssessmentAt}`
                        : `Réévaluation en retard depuis le ${item.nextAssessmentAt}`}
                    </Alert>
                  )}
                  {item.status !== "TERMINATED" &&
                    upcoming(item.contractEndsAt) && (
                      <Alert severity="info">
                        {english
                          ? "Contract ends within 30 days."
                          : "Le contrat se termine dans les 30 jours."}
                      </Alert>
                    )}
                  {item.status !== "TERMINATED" &&
                    upcoming(item.nextAssessmentAt) && (
                      <Alert severity="info">
                        {english
                          ? "Assessment due within 30 days."
                          : "Évaluation prévue dans les 30 jours."}
                      </Alert>
                    )}
                  {item.status !== "TERMINATED" && !item.contactEmail && (
                    <Alert severity="info">
                      {english
                        ? "Supplier contact is missing."
                        : "Le contact du fournisseur est manquant."}
                    </Alert>
                  )}
                  {item.status !== "TERMINATED" &&
                    ["HIGH", "CRITICAL"].includes(item.criticality) &&
                    !item.exitPlan?.trim() && (
                      <Alert severity="warning">
                        {english
                          ? "Document the exit plan for this priority supplier."
                          : "Documentez la réversibilité de ce tiers prioritaire."}
                      </Alert>
                    )}
                  {item.status !== "TERMINATED" &&
                    ["HIGH", "CRITICAL"].includes(item.criticality) &&
                    !item.nextAssessmentAt && (
                      <Alert severity="warning">
                        {english
                          ? "Schedule the assessment of this priority supplier."
                          : "Planifiez l’évaluation de ce tiers prioritaire."}
                      </Alert>
                    )}
                  {item.status !== "TERMINATED" && hasSubmittedAssessment && (
                    <Alert severity="info">
                      {english
                        ? "Submitted assessment awaiting review."
                        : "Évaluation soumise à valider."}
                    </Alert>
                  )}
                  {item.status !== "TERMINATED" &&
                    !hasReviewedAssessment &&
                    (item.criticality === "HIGH" ||
                      item.criticality === "CRITICAL") && (
                      <Alert severity="warning">
                        {english
                          ? "High-priority supplier not assessed."
                          : "Tiers prioritaire non évalué."}
                      </Alert>
                    )}
                  <Stack direction="row" flexWrap="wrap" useFlexGap gap={1}>
                    <Chip
                      size="small"
                      label={
                        hasReviewedAssessment
                          ? `Cyberscore ${item.cyberScore}%`
                          : english
                            ? "Not assessed"
                            : "Non évalué"
                      }
                      color={
                        !hasReviewedAssessment
                          ? "default"
                          : item.cyberScore >= 70
                            ? "success"
                            : "warning"
                      }
                    />
                    <Chip
                      size="small"
                      label={
                        english
                          ? `${item.assessments.length} assessment(s)`
                          : `${item.assessments.length} évaluation(s)`
                      }
                    />
                    {canManage && item.status !== "TERMINATED" && (
                      <Button
                        size="small"
                        aria-label={`${english ? "Create campaign for" : "Créer une campagne pour"} ${item.name}`}
                        onClick={() => setCampaignParty(item)}
                      >
                        {english ? "Create campaign" : "Créer une campagne"}
                      </Button>
                    )}
                  </Stack>
                  {item.assessments.length > 0 && (
                    <Stack spacing={0.5}>
                      {item.assessments.map((assessment) => (
                        <Button
                          key={assessment.id}
                          size="small"
                          sx={{
                            justifyContent: "flex-start",
                            overflowWrap: "anywhere",
                          }}
                          aria-label={`${english ? "View assessment" : "Consulter l’évaluation"} ${assessment.title}`}
                          onClick={() => setAssessmentId(assessment.id)}
                        >
                          {assessment.title} ·{" "}
                          {assessmentStatusLabel(assessment.status)}
                        </Button>
                      ))}
                    </Stack>
                  )}
                </Stack>
              </CardContent>
            </Card>
          );
        })}
      </Stack>

      {visibleItems.length > 0 && (
        <Stack
          direction={{ xs: "column", sm: "row" }}
          spacing={2}
          alignItems="center"
        >
          <Pagination
            page={currentPage}
            count={pageCount}
            onChange={(_, value) => setPage(value)}
            aria-label={english ? "Register pages" : "Pages du registre"}
          />
          <TextField
            id="third-party-page-size"
            select
            label={english ? "Per page" : "Par page"}
            value={pageSize}
            onChange={(event) => setPageSize(Number(event.target.value))}
            sx={{ minWidth: 120 }}
          >
            {[12, 24, 48].map((size) => (
              <MenuItem key={size} value={size}>
                {size}
              </MenuItem>
            ))}
          </TextField>
        </Stack>
      )}
      <Dialog
        open={dialog}
        onClose={closeDialog}
        aria-labelledby="third-party-dialog-title"
        fullWidth
        maxWidth="md"
      >
        <Stack
          component="form"
          sx={{ minHeight: 0 }}
          onSubmit={(event: FormEvent) => {
            event.preventDefault();
            if (formIsValid && !save.isPending) save.mutate();
          }}
        >
          <DialogTitle id="third-party-dialog-title">
            {editing
              ? english
                ? "Edit third party"
                : "Modifier le tiers"
              : english
                ? "New third party"
                : "Nouveau tiers"}
          </DialogTitle>
          <DialogContent dividers>
            <Stack spacing={2}>
              {save.isError && (
                <Alert severity="error">
                  {responseMessage(
                    save.error,
                    editing
                      ? english
                        ? "Unable to update the third party."
                        : "Modification du tiers impossible."
                      : english
                        ? "Unable to create the third party."
                        : "Création du tiers impossible.",
                  )}
                </Alert>
              )}
              {canListUsers && users.isError && (
                <Alert
                  severity="error"
                  action={
                    <Button
                      color="inherit"
                      onClick={() => void users.refetch()}
                    >
                      {english ? "Retry" : "Réessayer"}
                    </Button>
                  }
                >
                  {english
                    ? "Unable to load owners. The current owner remains available."
                    : "Impossible de charger les responsables. Le responsable actuel reste disponible."}
                </Alert>
              )}

              <Typography variant="subtitle2" fontWeight={750}>
                {english ? "Identification" : "Identification"}
              </Typography>
              <TextField
                required
                disabled={save.isPending}
                label={english ? "Name" : "Nom"}
                value={form.name}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    name: event.target.value,
                  }))
                }
              />
              <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                <TextField
                  fullWidth
                  type="email"
                  disabled={save.isPending}
                  label="Contact"
                  value={form.contactEmail}
                  onChange={(event) =>
                    setForm((current) => ({
                      ...current,
                      contactEmail: event.target.value,
                    }))
                  }
                />
                <TextField
                  fullWidth
                  disabled={save.isPending}
                  label="Services"
                  value={form.services}
                  onChange={(event) =>
                    setForm((current) => ({
                      ...current,
                      services: event.target.value,
                    }))
                  }
                />
              </Stack>
              <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                <FormControl fullWidth disabled={save.isPending}>
                  <InputLabel id="third-party-criticality-label">
                    {english ? "Criticality" : "Criticité"}
                  </InputLabel>
                  <Select
                    id="third-party-criticality"
                    labelId="third-party-criticality-label"
                    label={english ? "Criticality" : "Criticité"}
                    value={form.criticality}
                    onChange={(event) =>
                      setForm((current) => ({
                        ...current,
                        criticality: event.target.value as Criticality,
                      }))
                    }
                  >
                    {criticalities.map((value) => (
                      <MenuItem key={value} value={value}>
                        {criticalityLabel(value)}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
                <FormControl fullWidth disabled={save.isPending}>
                  <InputLabel id="third-party-status-label">
                    {english ? "Status" : "Statut"}
                  </InputLabel>
                  <Select
                    id="third-party-status"
                    labelId="third-party-status-label"
                    label={english ? "Status" : "Statut"}
                    value={form.status}
                    onChange={(event) =>
                      setForm((current) => ({
                        ...current,
                        status: event.target.value as ThirdPartyStatus,
                      }))
                    }
                  >
                    {statuses.map((value) => (
                      <MenuItem key={value} value={value}>
                        {statusLabel(value)}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
                <FormControl
                  fullWidth
                  required
                  disabled={save.isPending || (canListUsers && users.isLoading)}
                >
                  <InputLabel id="third-party-owner-label">
                    {english ? "Owner" : "Responsable"}
                  </InputLabel>
                  <Select
                    id="third-party-owner"
                    labelId="third-party-owner-label"
                    label={english ? "Owner" : "Responsable"}
                    value={form.ownerId}
                    onChange={(event) =>
                      setForm((current) => ({
                        ...current,
                        ownerId: String(event.target.value),
                      }))
                    }
                  >
                    {[...ownerOptions.entries()].map(([id, name]) => (
                      <MenuItem key={id} value={String(id)}>
                        {name}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Stack>
              <TextField
                disabled={save.isPending}
                label={
                  english
                    ? "Data categories (comma-separated)"
                    : "Catégories de données (séparées par virgules)"
                }
                value={form.dataCategories}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    dataCategories: event.target.value,
                  }))
                }
              />

              <Typography variant="subtitle2" fontWeight={750} sx={{ pt: 1 }}>
                {english ? "Contract and monitoring" : "Contrat et suivi"}
              </Typography>
              <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                <TextField
                  fullWidth
                  disabled={save.isPending}
                  label={english ? "Contract reference" : "Référence contrat"}
                  value={form.contractReference}
                  onChange={(event) =>
                    setForm((current) => ({
                      ...current,
                      contractReference: event.target.value,
                    }))
                  }
                />
                <TextField
                  fullWidth
                  disabled={save.isPending}
                  label="SLA"
                  value={form.sla}
                  onChange={(event) =>
                    setForm((current) => ({
                      ...current,
                      sla: event.target.value,
                    }))
                  }
                />
              </Stack>
              <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                <TextField
                  fullWidth
                  type="date"
                  disabled={save.isPending}
                  label={english ? "Contract end" : "Fin du contrat"}
                  value={form.contractEndsAt}
                  InputLabelProps={{ shrink: true }}
                  onChange={(event) =>
                    setForm((current) => ({
                      ...current,
                      contractEndsAt: event.target.value,
                    }))
                  }
                />
                <TextField
                  fullWidth
                  type="date"
                  disabled={save.isPending}
                  label={english ? "Next assessment" : "Prochaine évaluation"}
                  value={form.nextAssessmentAt}
                  InputLabelProps={{ shrink: true }}
                  onChange={(event) =>
                    setForm((current) => ({
                      ...current,
                      nextAssessmentAt: event.target.value,
                    }))
                  }
                />
              </Stack>
              <TextField
                multiline
                minRows={2}
                disabled={save.isPending}
                label={english ? "Dependencies" : "Dépendances"}
                value={form.dependencies}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    dependencies: event.target.value,
                  }))
                }
              />
              <TextField
                multiline
                minRows={2}
                disabled={save.isPending}
                label={english ? "Exit plan" : "Plan de sortie"}
                value={form.exitPlan}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    exitPlan: event.target.value,
                  }))
                }
              />

              <Typography variant="subtitle2" fontWeight={750} sx={{ pt: 1 }}>
                {english ? "Risk declarations" : "Déclarations de risque"}
              </Typography>
              <Alert severity="info">
                {english
                  ? "Certifications are third-party declarations to verify with evidence. They never establish automatic compliance."
                  : "Les certifications sont des déclarations du tiers à vérifier par des preuves. Elles n’établissent jamais une conformité automatique."}
              </Alert>
              <TextField
                disabled={save.isPending}
                label={
                  english
                    ? "Declared certifications (comma-separated)"
                    : "Certifications déclarées (séparées par virgules)"
                }
                value={form.certifications}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    certifications: event.target.value,
                  }))
                }
              />
              <TextField
                multiline
                minRows={2}
                disabled={save.isPending}
                label={english ? "Risk summary" : "Synthèse du risque"}
                value={form.riskSummary}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    riskSummary: event.target.value,
                  }))
                }
              />
              <TextField
                multiline
                minRows={2}
                disabled={save.isPending}
                label={
                  english ? "Compensating measures" : "Mesures compensatoires"
                }
                value={form.compensatingMeasures}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    compensatingMeasures: event.target.value,
                  }))
                }
              />
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={closeDialog} disabled={save.isPending}>
              {english ? "Cancel" : "Annuler"}
            </Button>
            <Button
              type="submit"
              variant="contained"
              disabled={!formIsValid || save.isPending}
            >
              {save.isPending
                ? english
                  ? "Saving…"
                  : "Enregistrement…"
                : editing
                  ? english
                    ? "Save"
                    : "Enregistrer"
                  : english
                    ? "Create"
                    : "Créer"}
            </Button>
          </DialogActions>
        </Stack>
      </Dialog>
      {assessmentId !== null && (
        <SupplierAssessmentDialog
          key={assessmentId}
          assessmentId={assessmentId}
          canManage={canManage}
          onClose={() => setAssessmentId(null)}
        />
      )}
      {campaignParty !== null && (
        <SupplierCampaignDialog
          key={campaignParty.id}
          thirdParty={campaignParty}
          onClose={() => setCampaignParty(null)}
        />
      )}
    </Stack>
  );
}
