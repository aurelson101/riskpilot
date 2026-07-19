import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Add, Check, Close } from "@mui/icons-material";
import {
  Alert,
  Box,
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
  LinearProgress,
  MenuItem,
  Select,
  Stack,
  Tab,
  Tabs,
  TextField,
  Typography,
} from "@mui/material";
import axios from "axios";
import { useState, type FormEvent } from "react";
import { api } from "../api/client";
import type {
  RiskAcceptance,
  RiskGovernancePolicy,
  RiskRecommendation,
  RiskReviewCampaign,
  RiskScenario,
  User,
} from "../api/types";
import { useAuth } from "../auth/useAuth";

function errorMessage(error: unknown) {
  return axios.isAxiosError<{ message?: string }>(error)
    ? (error.response?.data?.message ?? "L’opération a échoué.")
    : "L’opération a échoué.";
}

type DialogKind = "policy" | "acceptance" | "campaign" | "review" | null;

export function RiskGovernancePanel({ risks }: { risks: RiskScenario[] }) {
  const { user } = useAuth();
  const cache = useQueryClient();
  const [tab, setTab] = useState(0);
  const [dialog, setDialog] = useState<DialogKind>(null);
  const [selectedRisk, setSelectedRisk] = useState<number | null>(null);
  const [selectedReview, setSelectedReview] = useState<number | null>(null);
  const [error, setError] = useState("");
  const canManage = user?.roles.some((role) =>
    ["ROLE_SUPER_ADMIN", "ROLE_ADMIN", "ROLE_RISK_MANAGER"].includes(role),
  );
  const canDecide = user?.roles.some((role) =>
    ["ROLE_SUPER_ADMIN", "ROLE_ADMIN"].includes(role),
  );
  const policies = useQuery({
    queryKey: ["risk-policies"],
    queryFn: async () =>
      (await api.get<RiskGovernancePolicy[]>("/risk-governance/policies")).data,
  });
  const recommendations = useQuery({
    queryKey: ["risk-recommendations"],
    queryFn: async () =>
      (await api.get<RiskRecommendation[]>("/risk-governance/recommendations"))
        .data,
  });
  const portfolio = useQuery({
    queryKey: ["risk-portfolio"],
    queryFn: async () =>
      (
        await api.get<
          Array<{
            family: string;
            strategic: number;
            operational: number;
            aboveTolerance: number;
            averageResidualScore: number;
            maximumResidualScore: number;
            total: number;
          }>
        >("/risk-governance/portfolio")
      ).data,
  });
  const acceptances = useQuery({
    queryKey: ["risk-acceptances"],
    queryFn: async () =>
      (await api.get<RiskAcceptance[]>("/risk-governance/acceptances")).data,
  });
  const campaigns = useQuery({
    queryKey: ["risk-campaigns"],
    queryFn: async () =>
      (await api.get<RiskReviewCampaign[]>("/risk-governance/campaigns")).data,
  });
  const users = useQuery({
    queryKey: ["users"],
    queryFn: async () => (await api.get<User[]>("/users")).data,
    enabled: Boolean(canManage),
  });
  const refresh = () =>
    cache.invalidateQueries({
      predicate: ({ queryKey }) => String(queryKey[0]).startsWith("risk-"),
    });
  const decision = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) =>
      api.post(`/risk-governance/acceptances/${id}/decision`, { status }),
    onSuccess: refresh,
    onError: (caught) => setError(errorMessage(caught)),
  });
  const pendingReviews = campaigns.data?.flatMap((campaign) =>
    campaign.reviews.filter(
      (review) =>
        review.status !== "COMPLETED" &&
        (review.reviewer.id === user?.id || canManage),
    ),
  );

  return (
    <Card variant="outlined">
      <CardContent>
        <Stack spacing={2}>
          <Stack
            direction={{ xs: "column", md: "row" }}
            justifyContent="space-between"
            gap={1}
          >
            <Box>
              <Typography variant="h6">Gouvernance des risques</Typography>
              <Typography variant="body2" color="text.secondary">
                Appétence, décisions formelles et campagnes historisées
              </Typography>
            </Box>
            {error && <Alert severity="error">{error}</Alert>}
          </Stack>
          <Tabs
            value={tab}
            onChange={(_, value: number) => setTab(value)}
            variant="scrollable"
            allowScrollButtonsMobile
          >
            <Tab label={`Priorités (${recommendations.data?.length ?? 0})`} />
            <Tab label={`Politiques (${policies.data?.length ?? 0})`} />
            <Tab label={`Acceptations (${acceptances.data?.length ?? 0})`} />
            <Tab label={`Campagnes (${campaigns.data?.length ?? 0})`} />
          </Tabs>
          {tab === 0 && (
            <Stack spacing={1.5}>
              <Stack direction={{ xs: "column", md: "row" }} spacing={1.5}>
                {portfolio.data?.map((family) => (
                  <Card variant="outlined" key={family.family} sx={{ flex: 1 }}>
                    <CardContent>
                      <Typography fontWeight={700}>{family.family}</Typography>
                      <Typography variant="h5">
                        {family.averageResidualScore}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        moyenne · max {family.maximumResidualScore} ·{" "}
                        {family.strategic} stratégique(s) ·{" "}
                        {family.aboveTolerance} hors tolérance
                      </Typography>
                    </CardContent>
                  </Card>
                ))}
              </Stack>
              {recommendations.data?.map((item) => (
                <Card variant="outlined" key={item.riskId}>
                  <CardContent>
                    <Stack
                      direction={{ xs: "column", sm: "row" }}
                      justifyContent="space-between"
                      alignItems={{ xs: "stretch", sm: "center" }}
                      gap={2}
                    >
                      <Box>
                        <Stack direction="row" gap={1} flexWrap="wrap">
                          <Typography fontWeight={700}>{item.title}</Typography>
                          {item.strategic && (
                            <Chip
                              size="small"
                              label="Stratégique"
                              color="secondary"
                            />
                          )}
                          <Chip size="small" label={item.position} />
                        </Stack>
                        <Typography variant="body2" color="text.secondary">
                          {item.family} · {item.method} · score résiduel{" "}
                          {item.residualScore}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          Traitements :{" "}
                          {item.treatment.estimatedCost.toLocaleString("fr-FR")}{" "}
                          € · {item.treatment.estimatedEffortDays} j · réduction{" "}
                          {item.treatment.expectedReduction} · écart{" "}
                          {item.treatment.coverageGap}
                        </Typography>
                      </Box>
                      <Stack direction="row" gap={1} alignItems="center">
                        <Chip
                          color="primary"
                          label={item.recommendedDecision}
                        />
                        {canManage && item.recommendedDecision === "ACCEPT" && (
                          <Button
                            size="small"
                            onClick={() => {
                              setSelectedRisk(item.riskId);
                              setDialog("acceptance");
                            }}
                          >
                            Formaliser
                          </Button>
                        )}
                      </Stack>
                    </Stack>
                  </CardContent>
                </Card>
              ))}
            </Stack>
          )}
          {tab === 1 && (
            <Stack spacing={1.5}>
              {canManage && (
                <Button
                  startIcon={<Add />}
                  onClick={() => setDialog("policy")}
                  sx={{ alignSelf: "flex-start" }}
                >
                  Ajouter une politique
                </Button>
              )}
              {policies.data?.map((policy) => (
                <Card variant="outlined" key={policy.id}>
                  <CardContent>
                    <Typography fontWeight={700}>
                      {policy.domain} / {policy.family}
                    </Typography>
                    <Typography variant="body2" color="text.secondary">
                      Appétence {policy.appetiteScore} · Tolérance{" "}
                      {policy.toleranceScore} · Capacité {policy.capacityScore}{" "}
                      · {policy.method}
                    </Typography>
                    {policy.rationale && (
                      <Typography variant="body2" sx={{ mt: 1 }}>
                        {policy.rationale}
                      </Typography>
                    )}
                  </CardContent>
                </Card>
              ))}
            </Stack>
          )}
          {tab === 2 && (
            <Stack spacing={1.5}>
              {canManage && (
                <Button
                  startIcon={<Add />}
                  onClick={() => setDialog("acceptance")}
                  sx={{ alignSelf: "flex-start" }}
                >
                  Demander une acceptation
                </Button>
              )}
              {acceptances.data?.map((item) => (
                <Card variant="outlined" key={item.id}>
                  <CardContent>
                    <Stack
                      direction={{ xs: "column", sm: "row" }}
                      justifyContent="space-between"
                      gap={2}
                    >
                      <Box>
                        <Typography fontWeight={700}>
                          {item.risk.title}
                        </Typography>
                        <Typography variant="body2">
                          {item.justification}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          Autorité : {item.authority} · échéance{" "}
                          {new Date(item.expiresAt).toLocaleDateString("fr-FR")}
                        </Typography>
                      </Box>
                      <Stack direction="row" gap={1} alignItems="center">
                        <Chip
                          label={item.status}
                          color={
                            item.status === "APPROVED"
                              ? "success"
                              : item.status === "REJECTED"
                                ? "error"
                                : "warning"
                          }
                        />
                        {canDecide && item.status === "PENDING" && (
                          <>
                            <Button
                              size="small"
                              color="success"
                              startIcon={<Check />}
                              onClick={() =>
                                decision.mutate({
                                  id: item.id,
                                  status: "APPROVED",
                                })
                              }
                            >
                              Approuver
                            </Button>
                            <Button
                              size="small"
                              color="error"
                              startIcon={<Close />}
                              onClick={() =>
                                decision.mutate({
                                  id: item.id,
                                  status: "REJECTED",
                                })
                              }
                            >
                              Refuser
                            </Button>
                          </>
                        )}
                      </Stack>
                    </Stack>
                  </CardContent>
                </Card>
              ))}
            </Stack>
          )}
          {tab === 3 && (
            <Stack spacing={1.5}>
              {canManage && (
                <Button
                  startIcon={<Add />}
                  onClick={() => setDialog("campaign")}
                  sx={{ alignSelf: "flex-start" }}
                >
                  Lancer une campagne
                </Button>
              )}
              {campaigns.data?.map((campaign) => (
                <Card variant="outlined" key={campaign.id}>
                  <CardContent>
                    <Stack spacing={1}>
                      <Stack
                        direction="row"
                        justifyContent="space-between"
                        gap={1}
                      >
                        <Typography fontWeight={700}>
                          {campaign.title}
                        </Typography>
                        <Chip size="small" label={campaign.status} />
                      </Stack>
                      <LinearProgress
                        variant="determinate"
                        value={
                          campaign.progress.total
                            ? (campaign.progress.completed * 100) /
                              campaign.progress.total
                            : 0
                        }
                      />
                      <Typography variant="caption" color="text.secondary">
                        {campaign.progress.completed}/{campaign.progress.total}{" "}
                        revues · échéance{" "}
                        {new Date(campaign.dueAt).toLocaleDateString("fr-FR")}
                      </Typography>
                      {campaign.reviews.map((review) => (
                        <Stack
                          key={review.id}
                          direction={{ xs: "column", sm: "row" }}
                          justifyContent="space-between"
                          gap={1}
                        >
                          <Typography variant="body2">
                            {review.risk.title} · {review.reviewer.name} ·{" "}
                            {review.baselineScore} →{" "}
                            {review.reviewedScore ?? "—"}
                          </Typography>
                          {review.status !== "COMPLETED" &&
                            (review.reviewer.id === user?.id || canManage) && (
                              <Button
                                size="small"
                                onClick={() => {
                                  setSelectedReview(review.id);
                                  setDialog("review");
                                }}
                              >
                                Saisir la revue
                              </Button>
                            )}
                        </Stack>
                      ))}
                    </Stack>
                  </CardContent>
                </Card>
              ))}
              {!pendingReviews?.length && campaigns.isSuccess && (
                <Typography color="text.secondary">
                  Aucune revue en attente pour vous.
                </Typography>
              )}
            </Stack>
          )}
        </Stack>
      </CardContent>
      <GovernanceDialog
        kind={dialog}
        onClose={() => {
          setDialog(null);
          setError("");
        }}
        onSuccess={() => {
          setDialog(null);
          void refresh();
        }}
        onError={(caught) => setError(errorMessage(caught))}
        risks={risks}
        users={users.data ?? []}
        selectedRisk={selectedRisk}
        selectedReview={selectedReview}
      />
    </Card>
  );
}

function GovernanceDialog({
  kind,
  onClose,
  onSuccess,
  onError,
  risks,
  users,
  selectedRisk,
  selectedReview,
}: {
  kind: DialogKind;
  onClose: () => void;
  onSuccess: () => void;
  onError: (error: unknown) => void;
  risks: RiskScenario[];
  users: User[];
  selectedRisk: number | null;
  selectedReview: number | null;
}) {
  const { user } = useAuth();
  const [values, setValues] = useState<
    Record<string, string | number | number[]>
  >({});
  const submit = useMutation({
    mutationFn: () => {
      if (kind === "policy")
        return api.post("/risk-governance/policies", {
          appetiteScore: 4,
          toleranceScore: 9,
          capacityScore: 16,
          method: "SIMPLIFIED",
          ...values,
          ownerId: values.ownerId || user?.id,
        });
      if (kind === "acceptance")
        return api.post(
          `/risk-governance/risks/${values.riskId || selectedRisk}/acceptances`,
          values,
        );
      if (kind === "campaign")
        return api.post("/risk-governance/campaigns", {
          ...values,
          status: "ACTIVE",
        });
      return api.post(
        `/risk-governance/reviews/${selectedReview}/complete`,
        values,
      );
    },
    onSuccess,
    onError,
  });
  const update = (key: string, value: string | number | number[]) =>
    setValues((current) => ({ ...current, [key]: value }));
  const send = (event: FormEvent) => {
    event.preventDefault();
    submit.mutate();
  };
  return (
    <Dialog open={kind !== null} onClose={onClose} fullWidth maxWidth="sm">
      <Stack component="form" onSubmit={send}>
        <DialogTitle>
          {kind === "policy"
            ? "Politique d’appétence"
            : kind === "acceptance"
              ? "Acceptation formelle"
              : kind === "campaign"
                ? "Campagne de revue"
                : "Résultat de revue"}
        </DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            {kind === "policy" && (
              <>
                <TextField
                  required
                  label="Domaine"
                  onChange={(event) => update("domain", event.target.value)}
                />
                <TextField
                  required
                  label="Famille"
                  onChange={(event) => update("family", event.target.value)}
                />
                <Stack direction={{ xs: "column", sm: "row" }} spacing={1}>
                  {[
                    ["Appétence", "appetiteScore", 4],
                    ["Tolérance", "toleranceScore", 9],
                    ["Capacité", "capacityScore", 16],
                  ].map(([label, key, fallback]) => (
                    <TextField
                      key={String(key)}
                      required
                      type="number"
                      inputProps={{ min: 1, max: 25 }}
                      label={String(label)}
                      defaultValue={fallback}
                      onChange={(event) =>
                        update(String(key), Number(event.target.value))
                      }
                    />
                  ))}
                </Stack>
                <FormControl>
                  <InputLabel>Méthode</InputLabel>
                  <Select
                    label="Méthode"
                    defaultValue="SIMPLIFIED"
                    onChange={(event) => update("method", event.target.value)}
                  >
                    {["SIMPLIFIED", "ISO_27005", "EBIOS_RM"].map((value) => (
                      <MenuItem key={value} value={value}>
                        {value}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
                <TextField
                  multiline
                  label="Justification"
                  onChange={(event) => update("rationale", event.target.value)}
                />
              </>
            )}
            {kind === "acceptance" && (
              <>
                {!selectedRisk && (
                  <FormControl required>
                    <InputLabel>Risque</InputLabel>
                    <Select
                      label="Risque"
                      onChange={(event) =>
                        update("riskId", Number(event.target.value))
                      }
                    >
                      {risks.map((risk) => (
                        <MenuItem key={risk.id} value={risk.id}>
                          {risk.title}
                        </MenuItem>
                      ))}
                    </Select>
                  </FormControl>
                )}
                <TextField
                  required
                  multiline
                  label="Justification"
                  onChange={(event) =>
                    update("justification", event.target.value)
                  }
                />
                <TextField
                  required
                  label="Autorité de décision"
                  onChange={(event) => update("authority", event.target.value)}
                />
                <TextField
                  required
                  type="date"
                  label="Expiration"
                  InputLabelProps={{ shrink: true }}
                  onChange={(event) => update("expiresAt", event.target.value)}
                />
                <TextField
                  label="Référence de preuve"
                  onChange={(event) =>
                    update("evidenceReference", event.target.value)
                  }
                />
              </>
            )}
            {kind === "campaign" && (
              <>
                <TextField
                  required
                  label="Titre"
                  onChange={(event) => update("title", event.target.value)}
                />
                <TextField
                  multiline
                  label="Description"
                  onChange={(event) =>
                    update("description", event.target.value)
                  }
                />
                <Stack direction={{ xs: "column", sm: "row" }} spacing={1}>
                  <TextField
                    required
                    fullWidth
                    type="date"
                    label="Début"
                    InputLabelProps={{ shrink: true }}
                    onChange={(event) => update("startsAt", event.target.value)}
                  />
                  <TextField
                    required
                    fullWidth
                    type="date"
                    label="Échéance"
                    InputLabelProps={{ shrink: true }}
                    onChange={(event) => update("dueAt", event.target.value)}
                  />
                </Stack>
                <FormControl required>
                  <InputLabel>Réviseur</InputLabel>
                  <Select
                    label="Réviseur"
                    onChange={(event) =>
                      update("reviewerId", Number(event.target.value))
                    }
                  >
                    {users.map((item) => (
                      <MenuItem key={item.id} value={item.id}>
                        {item.firstName} {item.lastName}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
                <FormControl required>
                  <InputLabel>Risques</InputLabel>
                  <Select
                    multiple
                    label="Risques"
                    value={(values.riskIds as number[] | undefined) ?? []}
                    onChange={(event) =>
                      update(
                        "riskIds",
                        typeof event.target.value === "string"
                          ? []
                          : event.target.value,
                      )
                    }
                  >
                    {risks.map((risk) => (
                      <MenuItem key={risk.id} value={risk.id}>
                        {risk.title}
                      </MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </>
            )}
            {kind === "review" && (
              <>
                <TextField
                  required
                  type="number"
                  inputProps={{ min: 1, max: 25 }}
                  label="Score actuel revu"
                  onChange={(event) =>
                    update("reviewedScore", Number(event.target.value))
                  }
                />
                <TextField
                  multiline
                  label="Conclusion et éléments vérifiés"
                  onChange={(event) => update("comment", event.target.value)}
                />
              </>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>Annuler</Button>
          <Button type="submit" variant="contained" disabled={submit.isPending}>
            Enregistrer
          </Button>
        </DialogActions>
      </Stack>
    </Dialog>
  );
}
