import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Box,
  Button,
  Checkbox,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
  FormControlLabel,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  Tab,
  Tabs,
  TextField,
  Typography,
} from "@mui/material";
import { useState, type FormEvent } from "react";
import axios from "axios";
import { api } from "../api/client";
import type { Asset, Scope, Threat, User } from "../api/types";
import { useAuth } from "../auth/useAuth";

type Message = { role: "user" | "assistant"; content: string };
type Context = {
  enabled: boolean;
  provider: string | null;
  model: string | null;
  notice: string;
};
type Option = { id: number; name: string };
type RiskDraft = {
  title: string;
  description: string;
  scopeId: number;
  assetId: number;
  threatId: number;
  likelihood: number;
  impact: number;
  rationale: string;
};
type ComplianceOption = {
  id: number;
  label: string;
  status: string;
  requirementId: number;
  frameworkId: number;
};
type ComplianceActionDraft = {
  title: string;
  description: string;
  complianceResultId: number;
  priority: "LOW" | "MEDIUM" | "HIGH" | "CRITICAL";
  actionType:
    | "TECHNICAL"
    | "ORGANIZATIONAL"
    | "HUMAN"
    | "PHYSICAL"
    | "CONTRACTUAL"
    | "OTHER";
  dueInDays: number;
  rationale: string;
};

function errorMessage(error: unknown) {
  return axios.isAxiosError<{ message?: string }>(error)
    ? (error.response?.data?.message ?? "L’opération a échoué.")
    : "L’opération a échoué.";
}

export function GlobalCopilotDialog({
  open,
  onClose,
}: {
  open: boolean;
  onClose: () => void;
}) {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [tab, setTab] = useState<"chat" | "risk" | "compliance" | "isms">(
    "chat",
  );
  const [messages, setMessages] = useState<Message[]>([]);
  const [question, setQuestion] = useState("");
  const [consent, setConsent] = useState(false);
  const [riskRequest, setRiskRequest] = useState("");
  const [riskConsent, setRiskConsent] = useState(false);
  const [riskRationale, setRiskRationale] = useState("");
  const [complianceRequest, setComplianceRequest] = useState("");
  const [complianceConsent, setComplianceConsent] = useState(false);
  const [complianceRationale, setComplianceRationale] = useState("");
  const [confirmed, setConfirmed] = useState(false);
  const [success, setSuccess] = useState<{
    label: string;
    href: string;
  } | null>(null);
  const [risk, setRisk] = useState({
    title: "",
    description: "",
    scopeId: "",
    assetId: "",
    threatId: "",
    riskOwnerId: user?.id ? String(user.id) : "",
    likelihood: 3,
    impact: 3,
  });
  const [isms, setIsms] = useState({
    title: "Politique de sécurité du système d’information",
    category: "Gouvernance",
    content:
      "# Objet\n\nDéfinir le périmètre et les objectifs du SMSI.\n\n# Gouvernance\n\nRôles, responsabilités et instances de décision.\n\n# Gestion des risques\n\nMéthode, critères d’acceptation et plan de traitement.\n\n# Amélioration continue\n\nIndicateurs, audits, revues et actions correctives.",
  });
  const [complianceAction, setComplianceAction] = useState({
    title: "",
    description: "",
    complianceResultId: "",
    ownerId: user?.id ? String(user.id) : "",
    priority: "MEDIUM",
    actionType: "ORGANIZATIONAL",
    dueDate: new Date(Date.now() + 30 * 86_400_000).toISOString().slice(0, 10),
  });
  const canCreateRisk = user?.roles.some((role) =>
    ["ROLE_RISK_MANAGER", "ROLE_ADMIN", "ROLE_SUPER_ADMIN"].includes(role),
  );
  const context = useQuery({
    queryKey: ["global-copilot-context"],
    enabled: open,
    queryFn: async () => (await api.get<Context>("/copilot/context")).data,
  });
  const scopes = useQuery({
    queryKey: ["scopes"],
    enabled: open && tab === "risk" && Boolean(canCreateRisk),
    queryFn: async () => (await api.get<Scope[]>("/scopes")).data,
  });
  const assets = useQuery({
    queryKey: ["assets"],
    enabled: open && tab === "risk" && Boolean(canCreateRisk),
    queryFn: async () => (await api.get<Asset[]>("/assets")).data,
  });
  const threats = useQuery({
    queryKey: ["threats"],
    enabled: open && tab === "risk" && Boolean(canCreateRisk),
    queryFn: async () => (await api.get<Threat[]>("/threats")).data,
  });
  const complianceCatalog = useQuery({
    queryKey: ["global-copilot-compliance-catalog"],
    enabled: open && tab === "compliance" && Boolean(canCreateRisk),
    queryFn: async () =>
      (
        await api.get<{ items: ComplianceOption[] }>(
          "/copilot/compliance-catalog",
        )
      ).data.items,
  });
  const users = useQuery({
    queryKey: ["users"],
    enabled:
      open &&
      (tab === "risk" || tab === "compliance") &&
      Boolean(canCreateRisk),
    queryFn: async () => (await api.get<User[]>("/users")).data,
  });
  const chat = useMutation({
    mutationFn: async () =>
      (
        await api.post<{ answer: string }>("/copilot", {
          question,
          consent,
          history: messages.slice(-8),
        })
      ).data,
    onSuccess: (response) => {
      setMessages((current) => [
        ...current,
        { role: "user", content: question },
        { role: "assistant", content: response.answer },
      ]);
      setQuestion("");
      setConsent(false);
    },
  });
  const createRisk = useMutation({
    mutationFn: async () =>
      (
        await api.post<{ id: number; title: string }>("/risks", {
          title: risk.title,
          description: risk.description || null,
          family: "THIRD_PARTY",
          analysisMethod: "SIMPLIFIED",
          strategic: false,
          methodData: {},
          scopeId: Number(risk.scopeId),
          assetId: Number(risk.assetId),
          threatId: Number(risk.threatId),
          vulnerabilityIds: [],
          currentControlIds: [],
          riskOwnerId: Number(risk.riskOwnerId),
          likelihood: risk.likelihood,
          impact: risk.impact,
          currentLikelihood: risk.likelihood,
          currentImpact: risk.impact,
          residualLikelihood: risk.likelihood,
          residualImpact: risk.impact,
          treatmentDecision: "REDUCE",
          status: "DRAFT",
          reviewDate: null,
        })
      ).data,
    onSuccess: async (created) => {
      await queryClient.invalidateQueries({ queryKey: ["risks"] });
      setSuccess({ label: `Risque créé : ${created.title}`, href: "/risks" });
      setConfirmed(false);
    },
  });
  const generateRisk = useMutation({
    mutationFn: async () =>
      (
        await api.post<{ draft: RiskDraft }>("/copilot/risk-draft", {
          prompt: riskRequest,
          consent: riskConsent,
        })
      ).data,
    onSuccess: ({ draft }) => {
      setRisk((current) => ({
        ...current,
        title: draft.title,
        description: draft.description,
        scopeId: String(draft.scopeId),
        assetId: String(draft.assetId),
        threatId: String(draft.threatId),
        likelihood: draft.likelihood,
        impact: draft.impact,
      }));
      setRiskRationale(draft.rationale);
      setRiskConsent(false);
      setConfirmed(false);
    },
  });
  const generateComplianceAction = useMutation({
    mutationFn: async () =>
      (
        await api.post<{ draft: ComplianceActionDraft }>(
          "/copilot/compliance-action-draft",
          { prompt: complianceRequest, consent: complianceConsent },
        )
      ).data,
    onSuccess: ({ draft }) => {
      const dueDate = new Date(Date.now() + draft.dueInDays * 86_400_000)
        .toISOString()
        .slice(0, 10);
      setComplianceAction((current) => ({
        ...current,
        title: draft.title,
        description: draft.description,
        complianceResultId: String(draft.complianceResultId),
        priority: draft.priority,
        actionType: draft.actionType,
        dueDate,
      }));
      setComplianceRationale(draft.rationale);
      setComplianceConsent(false);
      setConfirmed(false);
    },
  });
  const createComplianceAction = useMutation({
    mutationFn: async () => {
      const source = complianceCatalog.data?.find(
        (item) => item.id === Number(complianceAction.complianceResultId),
      );
      if (!source) throw new Error("Compliance source is unavailable.");

      return (
        await api.post<{ id: number; title: string }>("/actions", {
          title: complianceAction.title,
          description: complianceAction.description || null,
          relatedRiskId: null,
          relatedControlId: null,
          ownerId: Number(complianceAction.ownerId),
          priority: complianceAction.priority,
          status: "OPEN",
          startDate: new Date().toISOString().slice(0, 10),
          dueDate: complianceAction.dueDate,
          completionDate: null,
          progress: 0,
          estimatedCost: null,
          estimatedEffortDays: null,
          actualCost: null,
          expectedRiskReduction: null,
          evidence: [],
          ticketNumber: null,
          ticketUrl: null,
          origin: "NON_CONFORMITY",
          actionType: complianceAction.actionType,
          frameworkIds: [source.frameworkId],
          requirementIds: [source.requirementId],
          customFields: {},
          nonConformities: [{ type: "COMPLIANCE_RESULT", id: source.id }],
        })
      ).data;
    },
    onSuccess: async (created) => {
      await queryClient.invalidateQueries({ queryKey: ["actions"] });
      setSuccess({
        label: `Action conformité créée : ${created.title}`,
        href: "/actions",
      });
      setConfirmed(false);
    },
  });
  const createIsms = useMutation({
    mutationFn: async () =>
      (
        await api.post<{ id: number; title: string }>("/isms-documents", {
          title: isms.title,
          category: isms.category,
          content: isms.content,
          classification: "INTERNAL",
          visibility: "ORGANIZATION",
          ownerId: user?.id,
          versionComment: "Brouillon créé depuis le copilote IA",
        })
      ).data,
    onSuccess: async (created) => {
      await queryClient.invalidateQueries({ queryKey: ["isms-documents"] });
      setSuccess({
        label: `Document ISMS créé : ${created.title}`,
        href: "/isms-documents",
      });
      setConfirmed(false);
    },
  });
  const ask = (event: FormEvent) => {
    event.preventDefault();
    if (question.trim().length >= 3 && consent) chat.mutate();
  };
  const options = (values: Option[] | undefined, empty: string) =>
    values?.length ? (
      values.map((item) => (
        <MenuItem key={item.id} value={String(item.id)}>
          {item.name}
        </MenuItem>
      ))
    ) : (
      <MenuItem disabled value="">
        {empty}
      </MenuItem>
    );
  const riskReady = Boolean(
    risk.title.trim() &&
    risk.scopeId &&
    risk.assetId &&
    risk.threatId &&
    risk.riskOwnerId,
  );
  const complianceActionReady = Boolean(
    complianceAction.title.trim() &&
    complianceAction.description.trim() &&
    complianceAction.complianceResultId &&
    complianceAction.ownerId &&
    complianceAction.dueDate,
  );
  const pending =
    generateRisk.isPending ||
    createRisk.isPending ||
    generateComplianceAction.isPending ||
    createComplianceAction.isPending ||
    createIsms.isPending;
  const failure =
    chat.error ||
    generateRisk.error ||
    createRisk.error ||
    generateComplianceAction.error ||
    createComplianceAction.error ||
    createIsms.error;

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="md">
      <DialogTitle>Copilote IA RiskPilot</DialogTitle>
      <DialogContent dividers>
        <Stack spacing={2}>
          {context.data?.enabled ? (
            <Stack direction="row" gap={1} flexWrap="wrap">
              <Chip color="success" label="IA activée" />
              <Chip label={context.data.provider ?? "—"} />
              <Chip label={context.data.model ?? "—"} />
            </Stack>
          ) : (
            <Alert severity="warning">
              Configurez et activez Mistral, OpenAI ou Gemini dans Paramètres →
              Intégrations pour discuter avec le copilote.
            </Alert>
          )}
          <Alert severity="info">
            Le copilote prépare des conseils et brouillons. Une confirmation
            humaine reste obligatoire avant toute création.
          </Alert>
          <Tabs
            value={tab}
            variant="scrollable"
            scrollButtons="auto"
            onChange={(_, value) => {
              setTab(value);
              setConfirmed(false);
            }}
          >
            <Tab value="chat" label="Discussion" />
            <Tab value="risk" label="Risque tiers guidé" />
            <Tab value="compliance" label="Action conformité guidée" />
            <Tab value="isms" label="Document ISMS guidé" />
          </Tabs>
          {success && (
            <Alert
              severity="success"
              action={
                <Button color="inherit" href={success.href}>
                  Ouvrir
                </Button>
              }
            >
              {success.label}
            </Alert>
          )}
          {failure && <Alert severity="error">{errorMessage(failure)}</Alert>}
          {tab === "chat" && (
            <>
              <Stack
                spacing={1}
                sx={{ minHeight: 180, maxHeight: 360, overflowY: "auto" }}
                role="log"
                aria-label="Conversation avec le copilote IA"
              >
                {messages.length === 0 && (
                  <Typography color="text.secondary">
                    Demandez par exemple : « Aide-moi à construire mon SMSI » ou
                    « Quelles questions poser pour un risque tiers ? »
                  </Typography>
                )}
                {messages.map((message, index) => (
                  <Box
                    key={`${message.role}-${index}`}
                    sx={{
                      alignSelf:
                        message.role === "user" ? "flex-end" : "flex-start",
                      bgcolor:
                        message.role === "user" ? "primary.main" : "grey.100",
                      color:
                        message.role === "user"
                          ? "primary.contrastText"
                          : "text.primary",
                      borderRadius: 2,
                      px: 2,
                      py: 1,
                      maxWidth: "85%",
                      whiteSpace: "pre-wrap",
                    }}
                  >
                    {message.content}
                  </Box>
                ))}
              </Stack>
              <Stack component="form" spacing={1} onSubmit={ask}>
                <TextField
                  label="Votre question"
                  multiline
                  minRows={2}
                  value={question}
                  inputProps={{ maxLength: 2000 }}
                  onChange={(event) => setQuestion(event.target.value)}
                />
                <FormControlLabel
                  control={
                    <Checkbox
                      checked={consent}
                      onChange={(event) => setConsent(event.target.checked)}
                    />
                  }
                  label="J’autorise l’envoi de cette conversation au fournisseur IA configuré."
                />
                <Button
                  type="submit"
                  variant="contained"
                  disabled={
                    !context.data?.enabled ||
                    !consent ||
                    question.trim().length < 3 ||
                    chat.isPending
                  }
                >
                  Envoyer
                </Button>
              </Stack>
            </>
          )}
          {tab === "risk" && (
            <Stack spacing={2}>
              {!canCreateRisk && (
                <Alert severity="error">
                  Votre rôle ne permet pas de créer un risque.
                </Alert>
              )}
              <Alert severity="info">
                Décrivez la situation : l’IA prépare les champs et rapproche la
                demande de vos périmètres, actifs et menaces. Relisez ensuite le
                brouillon avant de le créer avec le traitement « Réduire ».
              </Alert>
              <TextField
                label="Décrivez le risque à créer"
                placeholder="Ex. Notre prestataire de paie héberge des données personnelles. Une compromission pourrait interrompre les salaires et exposer les dossiers employés."
                multiline
                minRows={3}
                value={riskRequest}
                inputProps={{ maxLength: 2000 }}
                onChange={(event) => setRiskRequest(event.target.value)}
              />
              <FormControlLabel
                control={
                  <Checkbox
                    checked={riskConsent}
                    onChange={(event) => setRiskConsent(event.target.checked)}
                  />
                }
                label="J’autorise l’envoi de cette demande et des noms du référentiel au fournisseur IA configuré."
              />
              <Button
                variant="outlined"
                disabled={
                  !canCreateRisk ||
                  !context.data?.enabled ||
                  !riskConsent ||
                  riskRequest.trim().length < 10 ||
                  pending
                }
                onClick={() => generateRisk.mutate()}
              >
                Générer le brouillon avec l’IA
              </Button>
              {riskRationale && (
                <Alert severity="warning">
                  <Typography fontWeight={600}>Justification IA</Typography>
                  {riskRationale}
                </Alert>
              )}
              <TextField
                label="Quel événement redouté souhaitez-vous traiter ?"
                value={risk.title}
                onChange={(event) =>
                  setRisk({ ...risk, title: event.target.value })
                }
              />
              <TextField
                label="Décrivez le fournisseur, le service, les données et les conséquences"
                multiline
                minRows={3}
                value={risk.description}
                onChange={(event) =>
                  setRisk({ ...risk, description: event.target.value })
                }
              />
              {[
                [
                  "Périmètre concerné",
                  "scopeId",
                  scopes.data,
                  "Créez d’abord un périmètre",
                ],
                [
                  "Actif ou service dépendant",
                  "assetId",
                  assets.data,
                  "Créez d’abord un actif",
                ],
                [
                  "Menace principale",
                  "threatId",
                  threats.data,
                  "Créez d’abord une menace",
                ],
                [
                  "Responsable du risque",
                  "riskOwnerId",
                  users.data?.map((item) => ({
                    id: item.id,
                    name: `${item.firstName} ${item.lastName}`,
                  })),
                  "Aucun responsable disponible",
                ],
              ].map(([label, key, values, empty]) => (
                <FormControl key={String(key)} fullWidth>
                  <InputLabel id={`global-copilot-${key}-label`}>
                    {String(label)}
                  </InputLabel>
                  <Select
                    labelId={`global-copilot-${key}-label`}
                    label={String(label)}
                    value={risk[key as keyof typeof risk]}
                    onChange={(event) =>
                      setRisk({ ...risk, [String(key)]: event.target.value })
                    }
                  >
                    {options(values as Option[] | undefined, String(empty))}
                  </Select>
                </FormControl>
              ))}
              <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                {[
                  ["Vraisemblance 1–5", "likelihood"],
                  ["Impact 1–5", "impact"],
                ].map(([label, key]) => (
                  <TextField
                    key={key}
                    fullWidth
                    type="number"
                    label={label}
                    inputProps={{ min: 1, max: 5 }}
                    value={risk[key as "likelihood" | "impact"]}
                    onChange={(event) =>
                      setRisk({
                        ...risk,
                        [key]: Math.max(
                          1,
                          Math.min(5, Number(event.target.value)),
                        ),
                      })
                    }
                  />
                ))}
              </Stack>
              <Typography>
                Aperçu : {risk.title || "Titre à compléter"} · score brut estimé{" "}
                {risk.likelihood * risk.impact}/25 · statut Brouillon
              </Typography>
              <FormControlLabel
                control={
                  <Checkbox
                    checked={confirmed}
                    onChange={(event) => setConfirmed(event.target.checked)}
                  />
                }
                label="J’ai relu le brouillon et je confirme sa création."
              />
              <Button
                variant="contained"
                disabled={!canCreateRisk || !riskReady || !confirmed || pending}
                onClick={() => createRisk.mutate()}
              >
                Confirmer et créer le risque
              </Button>
            </Stack>
          )}
          {tab === "compliance" && (
            <Stack spacing={2}>
              {!canCreateRisk && (
                <Alert severity="error">
                  Votre rôle ne permet pas de créer une action de conformité.
                </Alert>
              )}
              <Alert severity="info">
                Décrivez le besoin, l’écart ou le résultat attendu. L’IA formule
                une action mesurable et la relie uniquement à une exigence
                partielle, non conforme ou non évaluée de votre organisation.
              </Alert>
              <TextField
                label="Décrivez la demande de conformité"
                placeholder="Ex. Nous devons formaliser la revue trimestrielle des accès privilégiés et conserver les preuves de validation."
                multiline
                minRows={3}
                value={complianceRequest}
                inputProps={{ maxLength: 2000 }}
                onChange={(event) => setComplianceRequest(event.target.value)}
              />
              <FormControlLabel
                control={
                  <Checkbox
                    checked={complianceConsent}
                    onChange={(event) =>
                      setComplianceConsent(event.target.checked)
                    }
                  />
                }
                label="J’autorise l’envoi de cette demande et du catalogue de conformité au fournisseur IA configuré."
              />
              <Button
                variant="outlined"
                disabled={
                  !canCreateRisk ||
                  !context.data?.enabled ||
                  !complianceConsent ||
                  complianceRequest.trim().length < 10 ||
                  !complianceCatalog.data?.length ||
                  pending
                }
                onClick={() => generateComplianceAction.mutate()}
              >
                Formuler l’action avec l’IA
              </Button>
              {complianceCatalog.isSuccess &&
                complianceCatalog.data.length === 0 && (
                  <Alert severity="warning">
                    Aucune exigence partielle, non conforme ou non évaluée n’est
                    disponible.
                  </Alert>
                )}
              {complianceRationale && (
                <Alert severity="warning">
                  <Typography fontWeight={600}>Justification IA</Typography>
                  {complianceRationale}
                </Alert>
              )}
              <TextField
                label="Titre de l’action"
                value={complianceAction.title}
                inputProps={{ maxLength: 255 }}
                onChange={(event) =>
                  setComplianceAction({
                    ...complianceAction,
                    title: event.target.value,
                  })
                }
              />
              <TextField
                label="Description et résultat attendu"
                multiline
                minRows={4}
                value={complianceAction.description}
                inputProps={{ maxLength: 10000 }}
                onChange={(event) =>
                  setComplianceAction({
                    ...complianceAction,
                    description: event.target.value,
                  })
                }
              />
              <FormControl fullWidth>
                <InputLabel id="global-copilot-compliance-result-label">
                  Exigence ou écart concerné
                </InputLabel>
                <Select
                  labelId="global-copilot-compliance-result-label"
                  label="Exigence ou écart concerné"
                  value={complianceAction.complianceResultId}
                  onChange={(event) =>
                    setComplianceAction({
                      ...complianceAction,
                      complianceResultId: event.target.value,
                    })
                  }
                >
                  {complianceCatalog.data?.map((item) => (
                    <MenuItem key={item.id} value={String(item.id)}>
                      {item.label} · {item.status.replaceAll("_", " ")}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
              <FormControl fullWidth>
                <InputLabel id="global-copilot-compliance-owner-label">
                  Responsable de l’action
                </InputLabel>
                <Select
                  labelId="global-copilot-compliance-owner-label"
                  label="Responsable de l’action"
                  value={complianceAction.ownerId}
                  onChange={(event) =>
                    setComplianceAction({
                      ...complianceAction,
                      ownerId: event.target.value,
                    })
                  }
                >
                  {options(
                    users.data?.map((item) => ({
                      id: item.id,
                      name: `${item.firstName} ${item.lastName}`,
                    })),
                    "Aucun responsable disponible",
                  )}
                </Select>
              </FormControl>
              <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
                <FormControl fullWidth>
                  <InputLabel id="global-copilot-compliance-priority-label">
                    Priorité
                  </InputLabel>
                  <Select
                    labelId="global-copilot-compliance-priority-label"
                    label="Priorité"
                    value={complianceAction.priority}
                    onChange={(event) =>
                      setComplianceAction({
                        ...complianceAction,
                        priority: event.target.value,
                      })
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
                  <InputLabel id="global-copilot-compliance-type-label">
                    Type d’action
                  </InputLabel>
                  <Select
                    labelId="global-copilot-compliance-type-label"
                    label="Type d’action"
                    value={complianceAction.actionType}
                    onChange={(event) =>
                      setComplianceAction({
                        ...complianceAction,
                        actionType: event.target.value,
                      })
                    }
                  >
                    {[
                      "TECHNICAL",
                      "ORGANIZATIONAL",
                      "CONTRACTUAL",
                      "HUMAN",
                      "PHYSICAL",
                      "OTHER",
                    ].map((value) => (
                      <MenuItem key={value} value={value}>
                        {value}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
                <TextField
                  fullWidth
                  type="date"
                  label="Échéance"
                  InputLabelProps={{ shrink: true }}
                  value={complianceAction.dueDate}
                  onChange={(event) =>
                    setComplianceAction({
                      ...complianceAction,
                      dueDate: event.target.value,
                    })
                  }
                />
              </Stack>
              <Typography>
                Aperçu : action ouverte, origine Écart de conformité,
                progression 0 %. Aucune preuve n’est ajoutée automatiquement.
              </Typography>
              <FormControlLabel
                control={
                  <Checkbox
                    checked={confirmed}
                    onChange={(event) => setConfirmed(event.target.checked)}
                  />
                }
                label="J’ai relu le brouillon et je confirme sa création."
              />
              <Button
                variant="contained"
                disabled={
                  !canCreateRisk ||
                  !complianceActionReady ||
                  !confirmed ||
                  pending
                }
                onClick={() => createComplianceAction.mutate()}
              >
                Confirmer et créer l’action de conformité
              </Button>
            </Stack>
          )}
          {tab === "isms" && (
            <Stack spacing={2}>
              <Alert severity="info">
                Ce parcours crée un premier document gouverné, pas un SMSI
                complet. Utilisez ensuite le chatbot pour construire périmètre,
                politiques, analyse de risques, SoA, indicateurs, audits et
                amélioration continue.
              </Alert>
              <TextField
                label="Titre du document"
                value={isms.title}
                onChange={(event) =>
                  setIsms({ ...isms, title: event.target.value })
                }
              />
              <TextField
                label="Catégorie"
                value={isms.category}
                onChange={(event) =>
                  setIsms({ ...isms, category: event.target.value })
                }
              />
              <TextField
                label="Contenu du brouillon"
                multiline
                minRows={10}
                value={isms.content}
                onChange={(event) =>
                  setIsms({ ...isms, content: event.target.value })
                }
              />
              <Typography>
                Aperçu : document interne, visibilité organisation, statut
                Brouillon, propriétaire {user?.firstName} {user?.lastName}.
              </Typography>
              <FormControlLabel
                control={
                  <Checkbox
                    checked={confirmed}
                    onChange={(event) => setConfirmed(event.target.checked)}
                  />
                }
                label="J’ai relu le brouillon et je confirme sa création."
              />
              <Button
                variant="contained"
                disabled={
                  !isms.title.trim() ||
                  !isms.category.trim() ||
                  !isms.content.trim() ||
                  !confirmed ||
                  pending
                }
                onClick={() => createIsms.mutate()}
              >
                Confirmer et créer le document ISMS
              </Button>
            </Stack>
          )}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Fermer</Button>
      </DialogActions>
    </Dialog>
  );
}
