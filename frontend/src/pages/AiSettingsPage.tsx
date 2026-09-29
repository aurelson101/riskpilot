import { Stack, Typography } from "@mui/material";
import { AiCopilotSettingsPanel } from "../components/AiCopilotSettingsPanel";

export function AiSettingsPage() {
  return (
    <Stack spacing={2} maxWidth={900}>
      <div>
        <Typography variant="h4" fontWeight={750}>
          Intelligence artificielle
        </Typography>
        <Typography color="text.secondary">
          Fournisseur, modèle, clé et règles d’utilisation du copilote.
        </Typography>
      </div>
      <AiCopilotSettingsPanel />
    </Stack>
  );
}
