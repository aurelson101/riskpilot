import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Card,
  CardContent,
  Chip,
  CircularProgress,
  Stack,
  Typography,
} from "@mui/material";
import { api } from "../api/client";
import type { Notification } from "../api/types";

export function NotificationsPage() {
  const client = useQueryClient();
  const query = useQuery({
    queryKey: ["notifications"],
    queryFn: async () => (await api.get<Notification[]>("/notifications")).data,
  });
  const read = useMutation({
    mutationFn: (id: number) => api.put(`/notifications/${id}/read`),
    onSuccess: () => client.invalidateQueries({ queryKey: ["notifications"] }),
  });
  if (query.isLoading) return <CircularProgress />;
  if (query.isError)
    return (
      <Alert severity="error">Impossible de charger les notifications.</Alert>
    );
  return (
    <Stack spacing={3}>
      <Stack>
        <Typography variant="h4" fontWeight={750}>
          Notifications
        </Typography>
        <Typography color="text.secondary">
          Affectations, échéances et alertes de risque
        </Typography>
      </Stack>
      <Stack spacing={1.5}>
        {query.data?.map((item) => (
          <Card
            key={item.id}
            variant="outlined"
            onClick={() => !item.isRead && read.mutate(item.id)}
            sx={{
              cursor: item.isRead ? "default" : "pointer",
              bgcolor: item.isRead ? "white" : "#eef5ff",
            }}
          >
            <CardContent>
              <Stack direction="row" justifyContent="space-between" gap={2}>
                <Stack>
                  <Typography fontWeight={700}>{item.title}</Typography>
                  <Typography>{item.message}</Typography>
                  <Typography variant="caption" color="text.secondary">
                    {new Date(item.createdAt).toLocaleString("fr-FR")}
                  </Typography>
                </Stack>
                {!item.isRead && (
                  <Chip size="small" color="primary" label="Nouveau" />
                )}
              </Stack>
            </CardContent>
          </Card>
        ))}
      </Stack>
    </Stack>
  );
}
