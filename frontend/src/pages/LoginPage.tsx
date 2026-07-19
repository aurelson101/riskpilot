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
});
type LoginForm = z.infer<typeof schema>;

export function LoginPage() {
  const { token, login } = useAuth();
  const navigate = useNavigate();
  const [error, setError] = useState<string | null>(null);
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginForm>({ resolver: zodResolver(schema) });
  if (token) return <Navigate to="/" replace />;

  const onSubmit = async (values: LoginForm) => {
    setError(null);
    try {
      await login(values.email, values.password);
      navigate("/");
    } catch (reason) {
      setError(
        axios.isAxiosError(reason) && reason.response?.status === 401
          ? "Email ou mot de passe incorrect."
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
      <Card sx={{ width: "100%", maxWidth: 440, borderRadius: 3 }}>
        <CardContent sx={{ p: 5 }}>
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
            <Button
              type="submit"
              variant="contained"
              size="large"
              disabled={isSubmitting}
            >
              {isSubmitting ? "Connexion…" : "Se connecter"}
            </Button>
          </Stack>
        </CardContent>
      </Card>
    </Box>
  );
}
