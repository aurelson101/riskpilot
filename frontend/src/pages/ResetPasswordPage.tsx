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
import { useState, type FormEvent } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { api } from "../api/client";

export function ResetPasswordPage() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const token = params.get("token") ?? "";
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setError("");
    setMessage("");
    if (token && password !== confirmation) {
      setError("Les mots de passe ne correspondent pas.");
      return;
    }
    setLoading(true);
    try {
      if (token) {
        await api.post("/auth/reset-password", { token, password });
        setMessage(
          "Mot de passe modifié. Vous pouvez maintenant vous connecter.",
        );
      } else {
        const { data } = await api.post<{ message: string }>(
          "/auth/forgot-password",
          { email },
        );
        setMessage(data.message);
      }
    } catch (caught) {
      setError(
        axios.isAxiosError<{ message?: string }>(caught)
          ? (caught.response?.data?.message ?? "L’opération a échoué.")
          : "L’opération a échoué.",
      );
    } finally {
      setLoading(false);
    }
  }

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
      <Card sx={{ width: "100%", maxWidth: 480, borderRadius: 3 }}>
        <CardContent sx={{ p: { xs: 3, sm: 5 } }}>
          <Stack component="form" spacing={2.5} onSubmit={submit}>
            <Stack direction="row" spacing={1.5} alignItems="center">
              <ShieldOutlined sx={{ fontSize: 42, color: "#1769e0" }} />
              <Box>
                <Typography variant="h5" component="h1" fontWeight={750}>
                  {token ? "Nouveau mot de passe" : "Récupérer mon compte"}
                </Typography>
                <Typography color="text.secondary">RiskPilot</Typography>
              </Box>
            </Stack>
            {message && <Alert severity="success">{message}</Alert>}
            {error && <Alert severity="error">{error}</Alert>}
            {token ? (
              <>
                <TextField
                  required
                  type="password"
                  label="Nouveau mot de passe"
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  helperText="12 caractères minimum, avec majuscule, minuscule et chiffre."
                />
                <TextField
                  required
                  type="password"
                  label="Confirmer le mot de passe"
                  value={confirmation}
                  onChange={(event) => setConfirmation(event.target.value)}
                />
              </>
            ) : (
              <TextField
                required
                type="email"
                label="Adresse email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
              />
            )}
            <Button
              type="submit"
              variant="contained"
              disabled={loading || Boolean(token && message)}
            >
              {loading
                ? "Traitement…"
                : token
                  ? "Modifier le mot de passe"
                  : "Envoyer le lien"}
            </Button>
            <Button type="button" onClick={() => navigate("/login")}>
              Retour à la connexion
            </Button>
          </Stack>
        </CardContent>
      </Card>
    </Box>
  );
}
