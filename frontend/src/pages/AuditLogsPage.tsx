import { useQuery } from "@tanstack/react-query";
import {
  Alert,
  Card,
  CardContent,
  Chip,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
  Tab,
  Tabs,
} from "@mui/material";
import { useState } from "react";
import { api } from "../api/client";
import { AuditManagementPanel } from "../components/audit/AuditManagementPanel";

type AuditLog = {
  id: number;
  user: { name: string; email: string } | null;
  action: string;
  entityType: string;
  entityId: string | null;
  ipAddress: string | null;
  createdAt: string;
};

export function AuditLogsPage() {
  const [tab, setTab] = useState(0);
  const query = useQuery({
    queryKey: ["audit-logs"],
    queryFn: async () => (await api.get<AuditLog[]>("/audit-logs")).data,
  });
  if (query.isError)
    return (
      <Alert severity="error">Impossible de charger le journal d’audit.</Alert>
    );
  return (
    <Stack spacing={3}>
      <Stack>
        <Typography variant="h4" fontWeight={750}>
          Journal d’audit
        </Typography>
        <Typography color="text.secondary">
          500 dernières opérations réussies. Les secrets sont automatiquement
          masqués.
        </Typography>
      </Stack>
      <Tabs value={tab} onChange={(_, value) => setTab(value)}>
        <Tab label="Programme & CAPA" />
        <Tab label="Journal technique" />
      </Tabs>
      {tab === 0 && <AuditManagementPanel />}
      {tab === 1 && (
        <Card variant="outlined">
          <CardContent>
            <Table>
              <TableHead>
                <TableRow>
                  <TableCell>Date</TableCell>
                  <TableCell>Utilisateur</TableCell>
                  <TableCell>Action</TableCell>
                  <TableCell>Ressource</TableCell>
                  <TableCell>Adresse IP</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {query.data?.map((log) => (
                  <TableRow key={log.id}>
                    <TableCell>
                      {new Date(log.createdAt).toLocaleString("fr-FR")}
                    </TableCell>
                    <TableCell>
                      {log.user?.name ?? "Compte supprimé"}
                      <Typography display="block" variant="caption">
                        {log.user?.email}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <Chip
                        size="small"
                        label={log.action}
                        color={
                          log.action === "DELETE"
                            ? "error"
                            : log.action === "POST"
                              ? "success"
                              : "default"
                        }
                      />
                    </TableCell>
                    <TableCell>
                      {log.entityType}
                      {log.entityId ? ` #${log.entityId}` : ""}
                    </TableCell>
                    <TableCell>{log.ipAddress ?? "—"}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}
    </Stack>
  );
}
