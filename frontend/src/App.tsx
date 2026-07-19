import { ShieldOutlined } from "@mui/icons-material";
import {
  Box,
  Card,
  CardContent,
  Chip,
  Container,
  Stack,
  Typography,
} from "@mui/material";

const modules = [
  "Organisations et périmètres",
  "Actifs et scénarios de risque",
  "Plans d'action",
  "Conformité et référentiels",
];

export default function App() {
  return (
    <Box
      component="main"
      sx={{ minHeight: "100vh", bgcolor: "#f4f7fb", py: 8 }}
    >
      <Container maxWidth="md">
        <Stack spacing={4}>
          <Stack direction="row" spacing={2} alignItems="center">
            <ShieldOutlined sx={{ fontSize: 54, color: "#1769e0" }} />
            <Box>
              <Typography
                variant="h3"
                component="h1"
                fontWeight={750}
                color="#09203f"
              >
                RiskPilot
              </Typography>
              <Typography color="text.secondary">
                Pilotage des risques cyber, de la conformité et des plans
                d’action
              </Typography>
            </Box>
          </Stack>

          <Card variant="outlined" sx={{ borderRadius: 3 }}>
            <CardContent sx={{ p: 4 }}>
              <Chip
                label="Socle technique opérationnel"
                color="success"
                sx={{ mb: 2 }}
              />
              <Typography variant="h5" gutterBottom>
                Bienvenue dans RiskPilot
              </Typography>
              <Typography color="text.secondary" paragraph>
                L’étape 1 initialise l’architecture Symfony, React et
                PostgreSQL. Les modules métier seront activés progressivement
                dans les prochaines étapes.
              </Typography>
              <Stack
                direction={{ xs: "column", sm: "row" }}
                gap={1}
                flexWrap="wrap"
              >
                {modules.map((module) => (
                  <Chip key={module} label={module} variant="outlined" />
                ))}
              </Stack>
            </CardContent>
          </Card>
        </Stack>
      </Container>
    </Box>
  );
}
