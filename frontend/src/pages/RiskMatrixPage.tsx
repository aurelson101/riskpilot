import { useQuery } from "@tanstack/react-query";
import {
  Alert,
  Box,
  Card,
  CardContent,
  Chip,
  CircularProgress,
  FormControl,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  Typography,
} from "@mui/material";
import { useState } from "react";
import { api } from "../api/client";
import type { RiskLevel, RiskMatrix } from "../api/types";

const colors: Record<RiskLevel, string> = {
  LOW: "#43a047",
  MODERATE: "#fbc02d",
  HIGH: "#fb8c00",
  CRITICAL: "#e53935",
};
const labels: Record<RiskLevel, string> = {
  LOW: "Faible",
  MODERATE: "Modéré",
  HIGH: "Élevé",
  CRITICAL: "Critique",
};

export function RiskMatrixPage() {
  const [scoreType, setScoreType] = useState<"gross" | "current" | "residual">(
    "current",
  );
  const [selected, setSelected] = useState<string | null>(null);
  const query = useQuery({
    queryKey: ["risk-matrix", scoreType],
    queryFn: async () =>
      (await api.get<RiskMatrix>(`/risk-matrix?scoreType=${scoreType}`)).data,
  });
  if (query.isLoading) return <CircularProgress />;
  if (query.isError || !query.data)
    return <Alert severity="error">Impossible de charger la matrice.</Alert>;
  const selectedCell = query.data.cells.find(
    (cell) => `${cell.impact}-${cell.likelihood}` === selected,
  );

  return (
    <Stack spacing={3}>
      <Stack
        direction={{ xs: "column", sm: "row" }}
        justifyContent="space-between"
        gap={2}
      >
        <Stack>
          <Typography variant="h4" fontWeight={750}>
            Matrice des risques 5 × 5
          </Typography>
          <Typography color="text.secondary">
            Cliquez sur une cellule pour afficher ses scénarios.
          </Typography>
        </Stack>
        <FormControl size="small" sx={{ minWidth: 210 }}>
          <InputLabel>Type d’évaluation</InputLabel>
          <Select
            label="Type d’évaluation"
            value={scoreType}
            onChange={(event) => {
              setScoreType(event.target.value as typeof scoreType);
              setSelected(null);
            }}
          >
            <MenuItem value="gross">Risque brut</MenuItem>
            <MenuItem value="current">Risque actuel</MenuItem>
            <MenuItem value="residual">Risque résiduel</MenuItem>
          </Select>
        </FormControl>
      </Stack>
      <Stack direction="row" gap={1} flexWrap="wrap">
        {Object.entries(labels).map(([level, label]) => (
          <Chip
            key={level}
            size="small"
            label={label}
            sx={{ bgcolor: colors[level as RiskLevel], color: "white" }}
          />
        ))}
      </Stack>
      <Stack
        direction={{ xs: "column", lg: "row" }}
        spacing={3}
        alignItems="stretch"
      >
        <Card variant="outlined" sx={{ flex: 1 }}>
          <CardContent>
            <Box
              sx={{
                display: "grid",
                gridTemplateColumns: "36px repeat(5, minmax(64px, 1fr))",
                gap: 1,
                alignItems: "stretch",
              }}
            >
              <Box />
              {[1, 2, 3, 4, 5].map((value) => (
                <Typography key={value} textAlign="center" fontWeight={700}>
                  {value}
                </Typography>
              ))}
              {[5, 4, 3, 2, 1].flatMap((impact) => [
                <Typography
                  key={`label-${impact}`}
                  alignSelf="center"
                  fontWeight={700}
                >
                  {impact}
                </Typography>,
                ...[1, 2, 3, 4, 5].map((likelihood) => {
                  const cell = query.data.cells.find(
                    (item) =>
                      item.impact === impact && item.likelihood === likelihood,
                  )!;
                  const id = `${impact}-${likelihood}`;
                  return (
                    <Box
                      component="button"
                      key={id}
                      onClick={() => setSelected(id)}
                      aria-label={`Impact ${impact}, vraisemblance ${likelihood}, ${cell.count} risque(s)`}
                      sx={{
                        border:
                          selected === id
                            ? "3px solid #102a43"
                            : "1px solid rgba(255,255,255,.7)",
                        borderRadius: 1.5,
                        bgcolor: colors[cell.level],
                        color: "white",
                        minHeight: 76,
                        cursor: "pointer",
                        font: "inherit",
                      }}
                    >
                      <Typography fontWeight={800}>{cell.score}</Typography>
                      <Typography variant="caption">
                        {cell.count} risque(s)
                      </Typography>
                    </Box>
                  );
                }),
              ])}
            </Box>
            <Typography mt={1.5} textAlign="center" variant="caption">
              Vraisemblance →
            </Typography>
          </CardContent>
        </Card>
        <Card variant="outlined" sx={{ width: { lg: 340 } }}>
          <CardContent>
            <Typography variant="h6" fontWeight={700}>
              Détail de la cellule
            </Typography>
            {!selectedCell ? (
              <Typography mt={2} color="text.secondary">
                Sélectionnez une cellule.
              </Typography>
            ) : (
              <Stack mt={2} spacing={1.5}>
                <Typography>
                  Impact {selectedCell.impact} · Vraisemblance{" "}
                  {selectedCell.likelihood}
                </Typography>
                <Chip
                  label={`${selectedCell.score} · ${labels[selectedCell.level]}`}
                  sx={{
                    alignSelf: "flex-start",
                    bgcolor: colors[selectedCell.level],
                    color: "white",
                  }}
                />
                {selectedCell.risks.length === 0 ? (
                  <Typography color="text.secondary">
                    Aucun risque dans cette cellule.
                  </Typography>
                ) : (
                  selectedCell.risks.map((risk) => (
                    <Box
                      key={risk.id}
                      sx={{ p: 1.5, bgcolor: "#f4f7fb", borderRadius: 1 }}
                    >
                      <Typography fontWeight={650}>{risk.title}</Typography>
                      <Typography variant="caption">{risk.status}</Typography>
                    </Box>
                  ))
                )}
              </Stack>
            )}
          </CardContent>
        </Card>
      </Stack>
    </Stack>
  );
}
