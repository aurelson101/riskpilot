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
import { useState } from "react";
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
  const { token, login } = useAuth();
  const navigate = useNavigate();
  const isDemo = import.meta.env.VITE_DEMO_MODE === "true";
  const [error, setError] = useState<string | null>(null);
  const [mfaRequired, setMfaRequired] = useState(false);
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
              <Button type="button" onClick={() => navigate("/reset-password")}>
                Mot de passe oublié ?
              </Button>
            </Stack>
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
