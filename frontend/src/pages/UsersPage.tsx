import { useQuery } from "@tanstack/react-query";
import {
  Alert,
  Card,
  CardContent,
  Chip,
  CircularProgress,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from "@mui/material";
import { api } from "../api/client";
import type { User } from "../api/types";

const roleLabels: Record<string, string> = {
  ROLE_SUPER_ADMIN: "Super admin",
  ROLE_ADMIN: "Admin",
  ROLE_RISK_MANAGER: "Risk manager",
  ROLE_ACTION_OWNER: "Responsable d’action",
  ROLE_AUDITOR: "Auditeur",
  ROLE_VIEWER: "Lecteur",
};

export function UsersPage() {
  const users = useQuery({
    queryKey: ["users"],
    queryFn: async () => (await api.get<User[]>("/users")).data,
  });
  if (users.isLoading) return <CircularProgress />;
  if (users.isError)
    return (
      <Alert severity="error">Impossible de charger les utilisateurs.</Alert>
    );
  const count = users.data?.length ?? 0;
  return (
    <Stack spacing={3}>
      <Stack>
        <Typography variant="h4" fontWeight={750}>
          Utilisateurs
        </Typography>
        <Typography color="text.secondary">
          {count} utilisateur{count > 1 ? "s" : ""} visible
          {count > 1 ? "s" : ""} dans votre périmètre.
        </Typography>
      </Stack>
      <Card variant="outlined">
        <CardContent>
          <Table aria-label="Utilisateurs de l’organisation">
            <TableHead>
              <TableRow>
                <TableCell>Utilisateur</TableCell>
                <TableCell>Organisation</TableCell>
                <TableCell>Rôles</TableCell>
                <TableCell>Statut</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {users.data?.map((user) => (
                <TableRow key={user.id} hover>
                  <TableCell>
                    <Typography fontWeight={650}>
                      {user.firstName} {user.lastName}
                    </Typography>
                    <Typography variant="body2" color="text.secondary">
                      {user.email}
                    </Typography>
                  </TableCell>
                  <TableCell>{user.organization.name}</TableCell>
                  <TableCell>
                    <Stack direction="row" gap={0.5} flexWrap="wrap">
                      {user.roles.map((role) => (
                        <Chip
                          key={role}
                          size="small"
                          label={roleLabels[role] ?? role}
                        />
                      ))}
                    </Stack>
                  </TableCell>
                  <TableCell>
                    <Chip
                      size="small"
                      color={user.status === "ACTIVE" ? "success" : "default"}
                      label={user.status === "ACTIVE" ? "Actif" : "Inactif"}
                    />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </Stack>
  );
}
