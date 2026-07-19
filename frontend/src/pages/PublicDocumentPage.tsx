import { LockOutlined, ShieldOutlined } from "@mui/icons-material";
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  Container,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import axios from "axios";
import { useEffect, useState, type FormEvent } from "react";
import { useParams } from "react-router-dom";
import { api } from "../api/client";

interface SharedDocument {
  title: string;
  category: string;
  classification: string;
  status: string;
  content: string;
  version: number;
  updatedAt: string;
}

export function PublicDocumentPage() {
  const { token } = useParams();
  const [passwordRequired, setPasswordRequired] = useState(false);
  const [password, setPassword] = useState("");
  const [document, setDocument] = useState<SharedDocument | null>(null);
  const [error, setError] = useState("");
  useEffect(() => {
    api
      .get(`/public/documents/${token}`)
      .then(({ data }) => {
        setPasswordRequired(data.passwordRequired);
        if (data.document) setDocument(data.document);
      })
      .catch(() => setError("Ce lien est invalide, révoqué ou expiré."));
  }, [token]);
  const unlock = async (event: FormEvent) => {
    event.preventDefault();
    setError("");
    try {
      const { data } = await api.post(`/public/documents/${token}`, {
        password,
      });
      setDocument(data.document);
      setPasswordRequired(false);
    } catch (caught) {
      setError(
        axios.isAxiosError(caught) && caught.response?.status === 403
          ? "Mot de passe incorrect."
          : "Impossible d’ouvrir ce document.",
      );
    }
  };
  return (
    <Box minHeight="100vh" bgcolor="#f4f7fb" py={{ xs: 3, md: 8 }}>
      <Container maxWidth="md">
        <Stack direction="row" alignItems="center" gap={1.5} mb={3}>
          <ShieldOutlined color="primary" fontSize="large" />
          <Typography variant="h5" fontWeight={750}>
            RiskPilot · Partage sécurisé
          </Typography>
        </Stack>
        {error && (
          <Alert severity="error" sx={{ mb: 2 }}>
            {error}
          </Alert>
        )}
        {passwordRequired && (
          <Card>
            <CardContent>
              <Box component="form" onSubmit={unlock}>
                <Stack spacing={2} alignItems="flex-start">
                  <LockOutlined color="primary" />
                  <Typography variant="h5">Document protégé</Typography>
                  <Typography color="text.secondary">
                    Saisissez le mot de passe communiqué par l’expéditeur.
                  </Typography>
                  <TextField
                    autoFocus
                    type="password"
                    label="Mot de passe"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                    fullWidth
                  />
                  <Button type="submit" variant="contained">
                    Ouvrir
                  </Button>
                </Stack>
              </Box>
            </CardContent>
          </Card>
        )}
        {document && (
          <Card>
            <CardContent sx={{ p: { xs: 2, md: 5 } }}>
              <Typography
                variant="h3"
                sx={{ fontSize: { xs: "1.8rem", md: "2.5rem" } }}
                fontWeight={750}
              >
                {document.title}
              </Typography>
              <Stack direction="row" gap={1} flexWrap="wrap" my={2}>
                <Chip label={document.category} color="primary" />
                <Chip label={document.classification} />
                <Chip label={`Version ${document.version}`} />
              </Stack>
              <Typography variant="caption" color="text.secondary">
                Mis à jour le{" "}
                {new Date(document.updatedAt).toLocaleString("fr-FR")}
              </Typography>
              <Box
                sx={{
                  mt: 4,
                  whiteSpace: "pre-wrap",
                  overflowWrap: "anywhere",
                  lineHeight: 1.75,
                }}
              >
                {document.content}
              </Box>
            </CardContent>
          </Card>
        )}
      </Container>
    </Box>
  );
}
