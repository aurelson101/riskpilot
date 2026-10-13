import { zodResolver } from "@hookform/resolvers/zod";
import { ShieldOutlined } from "@mui/icons-material";
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import axios from "axios";
import { useEffect, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { api } from "../api/client";
import { useForm } from "react-hook-form";
import { Navigate, useNavigate } from "react-router-dom";
import { z } from "zod";
import { useAuth } from "../auth/useAuth";

const schema = z.object({
  email: z.email("Adresse email invalide"),
  password: z.string().min(1, "Mot de passe obligatoire"),
  mfaCode: z.string().optional(),
});
type LoginForm = z.infer<typeof schema>;

export function LoginPage() {
  const { token, login, loginSso } = useAuth();
  const navigate = useNavigate();
  // The build flag keeps local demo images explicit, while the hostname
  // fallback prevents a stale/cached production build from hiding the public
  // demo credentials on the dedicated demo instance.
  const isDemo =
    import.meta.env.VITE_DEMO_MODE === "true" ||
    window.location.hostname === "demo.riskpilot.site";
  const [ssoStatus] = useState(() =>
    new URLSearchParams(window.location.search).get("sso"),
  );
  const [error, setError] = useState<string | null>(
    ssoStatus === "error"
      ? "Connexion SSO impossible. Vérifiez la configuration et l’association de votre compte."
      : null,
  );
  const [mfaRequired, setMfaRequired] = useState(false);
  const [ssoPending, setSsoPending] = useState(ssoStatus === "complete");
  const ssoStarted = useRef(false);
  const ssoRequestPending = useRef(false);
  const [ssoSubmitting, setSsoSubmitting] = useState(ssoStatus === "complete");
  const providers = useQuery({
    queryKey: ["sso-providers"],
    queryFn: async () =>
      (
        await api.get<{
          items: { id: number; name: string; provider: string }[];
        }>("/auth/sso/providers")
      ).data.items,
    retry: false,
    enabled: !token,
  });
  useEffect(() => {
    if (ssoStatus === "error" || ssoStatus === "cancelled") {
      window.history.replaceState(null, "", "/login");
    }
    if (ssoStatus !== "complete" || ssoStarted.current || !loginSso) return;
    ssoStarted.current = true;
    ssoRequestPending.current = true;
    window.history.replaceState(null, "", "/login");
    void loginSso()
      .then((needsMfa) => {
        if (needsMfa) setMfaRequired(true);
        else navigate("/");
      })
      .catch((reason) => {
        setSsoPending(false);
        setError(
          axios.isAxiosError<{ message?: string }>(reason)
            ? (reason.response?.data?.message ??
                "Connexion SSO impossible. Vérifiez la configuration et l’association de votre compte.")
            : "Connexion SSO impossible. Vérifiez la configuration et l’association de votre compte.",
        );
      })
      .finally(() => {
        ssoRequestPending.current = false;
        setSsoSubmitting(false);
      });
  }, [ssoStatus, loginSso, navigate]);
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginForm>({ resolver: zodResolver(schema) });
  if (token) return <Navigate to="/" replace />;

  const onSubmit = async (values: LoginForm) => {
    setError(null);
    try {
      const needsMfa = await login(
        values.email,
        values.password,
        values.mfaCode,
      );
      if (needsMfa) {
        setMfaRequired(true);
        return;
      }
      navigate("/");
    } catch (reason) {
      setError(
        axios.isAxiosError<{ message?: string }>(reason)
          ? reason.response?.status === 401
            ? mfaRequired
              ? "Code MFA ou code de secours invalide."
              : "Email ou mot de passe incorrect."
            : (reason.response?.data?.message ??
              "Le service est momentanément indisponible.")
          : "Le service est momentanément indisponible.",
      );
    }
  };

  return (
    <Box
      component="main"
      sx={{
        minHeight: "100vh",
        display: "grid",
        placeItems: "center",
        bgcolor: "#eef4fb",
        p: 2,
      }}
    >
      <Stack spacing={2} sx={{ width: "100%", maxWidth: 440 }}>
        <Card sx={{ width: "100%", borderRadius: 3 }}>
          <CardContent sx={{ p: { xs: 3, sm: 5 } }}>
            <Stack direction="row" spacing={1.5} alignItems="center" mb={4}>
              <ShieldOutlined sx={{ fontSize: 46, color: "#1769e0" }} />
              <Box>
                <Typography variant="h4" component="h1" fontWeight={750}>
                  RiskPilot
                </Typography>
                <Typography color="text.secondary">
                  Connexion à votre espace GRC
                </Typography>
              </Box>
            </Stack>
            {error && (
              <Alert severity="error" sx={{ mb: 2 }}>
                {error}
              </Alert>
            )}
            {ssoStatus === "cancelled" && (
              <Alert severity="info" sx={{ mb: 2 }}>
                Connexion SSO annulée. Vous pouvez réessayer ou utiliser votre
                mot de passe.
              </Alert>
            )}
            {ssoPending ? (
              <Stack
                component="form"
                spacing={2}
                onSubmit={async (event) => {
                  event.preventDefault();
                  if (ssoRequestPending.current || !loginSso) return;
                  ssoRequestPending.current = true;
                  setSsoSubmitting(true);
                  setError(null);
                  const input = event.currentTarget.elements.namedItem(
                    "ssoMfa",
                  ) as HTMLInputElement | null;
                  try {
                    const needsMfa = await loginSso(input?.value);
                    if (!needsMfa) navigate("/");
                    else setError("Code MFA ou code de secours invalide.");
                  } catch (reason) {
                    if (
                      axios.isAxiosError<{ code?: string; message?: string }>(
                        reason,
                      )
                    ) {
                      setError(
                        reason.response?.data?.message ??
                          "Code MFA invalide ou session SSO expirée.",
                      );
                      if (
                        reason.response?.status === 401 &&
                        reason.response.data?.code !== "INVALID_MFA_CODE"
                      ) {
                        setSsoPending(false);
                        setMfaRequired(false);
                      }
                    } else
                      setError("Le service est momentanément indisponible.");
                  } finally {
                    ssoRequestPending.current = false;
                    setSsoSubmitting(false);
                  }
                }}
              >
                {mfaRequired ? (
                  <>
                    <TextField
                      required
                      name="ssoMfa"
                      label="Code MFA ou code de secours"
                      autoComplete="one-time-code"
                      autoFocus
                      disabled={ssoSubmitting}
                    />
                    <Button
                      type="submit"
                      variant="contained"
                      disabled={ssoSubmitting}
                    >
                      Valider le MFA
                    </Button>
                  </>
                ) : (
                  <Typography>Connexion SSO en cours…</Typography>
                )}
                <Button
                  disabled={ssoSubmitting}
                  onClick={() => {
                    setSsoPending(false);
                    setMfaRequired(false);
                    setError(null);
                  }}
                >
                  Retour à la connexion
                </Button>
              </Stack>
            ) : (
              <Stack
                component="form"
                spacing={2.5}
                onSubmit={handleSubmit(onSubmit)}
                noValidate
              >
                <TextField
                  label="Adresse email"
                  type="email"
                  autoComplete="email"
                  error={Boolean(errors.email)}
                  helperText={errors.email?.message}
                  {...register("email")}
                />
                <TextField
                  label="Mot de passe"
                  type="password"
                  autoComplete="current-password"
                  error={Boolean(errors.password)}
                  helperText={errors.password?.message}
                  {...register("password")}
                />
                {mfaRequired && (
                  <TextField
                    label="Code MFA ou code de secours"
                    autoComplete="one-time-code"
                    autoFocus
                    helperText="Saisissez le code à 6 chiffres de votre application d’authentification."
                    {...register("mfaCode")}
                  />
                )}
                <Button
                  type="submit"
                  variant="contained"
                  size="large"
                  disabled={isSubmitting}
                >
                  {isSubmitting ? "Connexion…" : "Se connecter"}
                </Button>
                <Button
                  type="button"
                  onClick={() => navigate("/reset-password")}
                >
                  Mot de passe oublié ?
                </Button>
              </Stack>
            )}
            {!ssoPending &&
              providers.data?.map((provider) => (
                <Button
                  key={provider.id}
                  fullWidth
                  variant="outlined"
                  sx={{ mt: 2 }}
                  href={`/api/auth/sso/${provider.id}/start`}
                >
                  Se connecter avec {provider.name}
                </Button>
              ))}
          </CardContent>
        </Card>
        {isDemo && (
          <Alert severity="info" variant="outlined">
            <Typography fontWeight={700} gutterBottom>
              Accès à la démonstration
            </Typography>
            <Typography variant="body2">
              Administrateur : <strong>admin@riskpilot.local</strong>
            </Typography>
            <Typography variant="body2">
              Risk manager : <strong>risk.manager@riskpilot.local</strong>
            </Typography>
            <Typography variant="body2">
              Responsable d’action :{" "}
              <strong>action.owner@riskpilot.local</strong>
            </Typography>
            <Typography variant="body2" sx={{ mt: 1 }}>
              Mot de passe commun : <strong>ChangeMe123!</strong>
            </Typography>
            <Typography variant="caption" color="text.secondary">
              Les données de démonstration sont réinitialisées toutes les deux
              heures.
            </Typography>
          </Alert>
        )}
      </Stack>
    </Box>
  );
}
