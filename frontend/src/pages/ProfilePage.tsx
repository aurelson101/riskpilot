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
import QRCode from "qrcode";
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
  const [mfaPassword, setMfaPassword] = useState("");
  const [mfaCode, setMfaCode] = useState("");
  const [mfaSetup, setMfaSetup] = useState<{
    secret: string;
    provisioningUri: string;
  } | null>(null);
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [mfaQrCode, setMfaQrCode] = useState("");
  const [mfaMessage, setMfaMessage] = useState("");

  useEffect(() => {
    if (!user) return;
    setFirstName(user.firstName);
    setLastName(user.lastName);
    setEmail(user.email);
  }, [user]);

  useEffect(() => {
    if (!mfaSetup) {
      setMfaQrCode("");
      return;
    }
    void QRCode.toDataURL(mfaSetup.provisioningUri, {
      width: 220,
      margin: 1,
    }).then(setMfaQrCode);
  }, [mfaSetup]);

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

  async function startMfa() {
    setError("");
    setMfaMessage("");
    try {
      const { data } = await api.post<{
        secret: string;
        provisioningUri: string;
      }>("/me/mfa/setup", { currentPassword: mfaPassword });
      setMfaSetup(data);
    } catch (caught) {
      setError(
        axios.isAxiosError<{ message?: string }>(caught)
          ? (caught.response?.data?.message ?? "Activation MFA impossible.")
          : "Activation MFA impossible.",
      );
    }
  }

  async function enableMfa() {
    if (!mfaSetup) return;
    try {
      const { data } = await api.post<{ recoveryCodes: string[] }>(
        "/me/mfa/enable",
        {
          currentPassword: mfaPassword,
          secret: mfaSetup.secret,
          code: mfaCode,
        },
      );
      setRecoveryCodes(data.recoveryCodes);
      setMfaSetup(null);
      setMfaCode("");
      setMfaPassword("");
      setMfaMessage("MFA activé. Conservez les codes de secours ci-dessous.");
      await queryClient.invalidateQueries({ queryKey: ["me"] });
    } catch (caught) {
      setError(
        axios.isAxiosError<{ message?: string }>(caught)
          ? (caught.response?.data?.message ?? "Code MFA invalide.")
          : "Code MFA invalide.",
      );
    }
  }

  async function disableMfa() {
    try {
      await api.post("/me/mfa/disable", { currentPassword: mfaPassword });
      setMfaPassword("");
      setRecoveryCodes([]);
      setMfaMessage("MFA désactivé.");
      await queryClient.invalidateQueries({ queryKey: ["me"] });
    } catch (caught) {
      setError(
        axios.isAxiosError<{ message?: string }>(caught)
          ? (caught.response?.data?.message ?? "Désactivation impossible.")
          : "Désactivation impossible.",
      );
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
      <Card variant="outlined">
        <CardContent>
          <Stack spacing={2}>
            <Typography variant="h6">
              Authentification multifacteur (MFA)
            </Typography>
            <Typography color="text.secondary">
              {user?.mfaEnabled
                ? "Le MFA TOTP est activé sur votre compte."
                : "Protégez votre compte avec Google Authenticator, Microsoft Authenticator ou toute application TOTP."}
            </Typography>
            {mfaMessage && <Alert severity="success">{mfaMessage}</Alert>}
            <TextField
              label="Mot de passe actuel"
              type="password"
              value={mfaPassword}
              onChange={(event) => setMfaPassword(event.target.value)}
            />
            {!user?.mfaEnabled && !mfaSetup && (
              <Button
                variant="contained"
                onClick={startMfa}
                disabled={!mfaPassword}
              >
                Configurer le MFA
              </Button>
            )}
            {mfaSetup && (
              <Stack spacing={2}>
                {mfaQrCode && (
                  <img
                    src={mfaQrCode}
                    alt="QR code de configuration MFA"
                    width={220}
                    height={220}
                  />
                )}
                <Alert severity="info">
                  Ajoutez manuellement ce secret dans votre application :{" "}
                  <strong>{mfaSetup.secret}</strong>
                </Alert>
                <TextField
                  label="Code à 6 chiffres"
                  value={mfaCode}
                  onChange={(event) => setMfaCode(event.target.value)}
                  inputProps={{ inputMode: "numeric", maxLength: 6 }}
                />
                <Button variant="contained" onClick={enableMfa}>
                  Vérifier et activer
                </Button>
              </Stack>
            )}
            {user?.mfaEnabled && (
              <Button
                color="error"
                variant="outlined"
                onClick={disableMfa}
                disabled={!mfaPassword}
              >
                Désactiver le MFA
              </Button>
            )}
            {recoveryCodes.length > 0 && (
              <Alert severity="warning">
                Codes de secours à usage unique :<br />
                {recoveryCodes.join(" · ")}
              </Alert>
            )}
          </Stack>
        </CardContent>
      </Card>
    </Stack>
  );
}
