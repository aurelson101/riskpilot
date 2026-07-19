import { useEffect, useState, type FormEvent } from "react";
import { useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Button,
  Card,
  CardContent,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import axios from "axios";
import { api } from "../api/client";
import type { User } from "../api/types";
import { useAuth } from "../auth/useAuth";

export function ProfilePage() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [firstName, setFirstName] = useState("");
  const [lastName, setLastName] = useState("");
  const [email, setEmail] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState(false);

  useEffect(() => {
    if (!user) return;
    setFirstName(user.firstName);
    setLastName(user.lastName);
    setEmail(user.email);
  }, [user]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setSuccess(false);
    try {
      const { data } = await api.put<User>("/me", {
        firstName,
        lastName,
        email,
      });
      queryClient.setQueryData(["me"], data);
      setSuccess(true);
    } catch (caught) {
      const message = axios.isAxiosError<{ message?: string }>(caught)
        ? caught.response?.data?.message
        : undefined;
      setError(message ?? "Impossible d’enregistrer le profil.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Stack spacing={3} maxWidth={720}>
      <Stack>
        <Typography variant="h4" fontWeight={750}>
          Mon profil
        </Typography>
        <Typography color="text.secondary">
          Modifiez les informations utilisées dans RiskPilot.
        </Typography>
      </Stack>
      <Card variant="outlined">
        <CardContent>
          <Stack component="form" spacing={2.5} onSubmit={submit}>
            {success && (
              <Alert severity="success">Profil mis à jour avec succès.</Alert>
            )}
            {error && <Alert severity="error">{error}</Alert>}
            <Stack direction={{ xs: "column", sm: "row" }} spacing={2}>
              <TextField
                label="Prénom"
                value={firstName}
                onChange={(event) => setFirstName(event.target.value)}
                required
                fullWidth
                inputProps={{ maxLength: 100 }}
              />
              <TextField
                label="Nom"
                value={lastName}
                onChange={(event) => setLastName(event.target.value)}
                required
                fullWidth
                inputProps={{ maxLength: 100 }}
              />
            </Stack>
            <TextField
              label="Adresse email"
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              required
              fullWidth
              inputProps={{ maxLength: 180 }}
            />
            <Button type="submit" variant="contained" disabled={saving}>
              {saving ? "Enregistrement…" : "Enregistrer les modifications"}
            </Button>
          </Stack>
        </CardContent>
      </Card>
    </Stack>
  );
}
