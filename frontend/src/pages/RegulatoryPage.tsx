import { useQuery } from "@tanstack/react-query";
import {
  Alert,
  Card,
  CardContent,
  Chip,
  Stack,
  Typography,
} from "@mui/material";
import { api } from "../api/client";
type Record = {
  id: number;
  type: string;
  title: string;
  status: string;
  dueAt: string | null;
  expiresAt: string | null;
  owner: { name: string };
  evidence: string[];
};
export function RegulatoryPage() {
  const query = useQuery({
    queryKey: ["regulatory-records"],
    queryFn: async () => (await api.get<Record[]>("/regulatory-records")).data,
  });
  if (query.isError)
    return (
      <Alert severity="error">
        Impossible de charger le registre réglementaire.
      </Alert>
    );
  return (
    <Stack spacing={3}>
      <Stack>
        <Typography variant="h4" fontWeight={750}>
          Vie privée et obligations
        </Typography>
        <Typography color="text.secondary">
          Traitements, AIPD, violations, veille et dérogations
        </Typography>
      </Stack>
      {query.data?.length === 0 && (
        <Alert severity="info">Aucun enregistrement réglementaire.</Alert>
      )}
      <Stack
        sx={{
          display: "grid",
          gridTemplateColumns: { xs: "1fr", md: "repeat(2, 1fr)" },
          gap: 2,
        }}
      >
        {query.data?.map((item) => (
          <Card variant="outlined" key={item.id}>
            <CardContent>
              <Stack spacing={1}>
                <Stack direction="row" justifyContent="space-between">
                  <Typography fontWeight={750}>{item.title}</Typography>
                  <Chip
                    size="small"
                    label={item.status}
                    color={
                      item.status === "APPROVED" || item.status === "COMPLIANT"
                        ? "success"
                        : "default"
                    }
                  />
                </Stack>
                <Typography variant="body2">
                  {item.type} · {item.owner.name}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  Échéance {item.dueAt ?? item.expiresAt ?? "non définie"} ·{" "}
                  {item.evidence.length} preuve(s)
                </Typography>
              </Stack>
            </CardContent>
          </Card>
        ))}
      </Stack>
    </Stack>
  );
}
