import {
  Alert,
  Button,
  Card,
  CardContent,
  Checkbox,
  CircularProgress,
  FormControlLabel,
  Stack,
  Typography,
} from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { api } from "../api/client";
type Matrix = { permissions: string[]; roles: Record<string, string[]> };
export function RbacSettingsPage() {
  const client = useQueryClient();
  const query = useQuery({
    queryKey: ["rbac-settings"],
    queryFn: async () => (await api.get<Matrix>("/settings/rbac")).data,
  });
  const [roleOverrides, setRoleOverrides] = useState<Record<
    string,
    string[]
  > | null>(null);
  const roles = roleOverrides ?? query.data?.roles ?? {};
  const save = useMutation({
    mutationFn: () => api.put("/settings/rbac", { roles }),
    onSuccess: async () =>
      client.invalidateQueries({ queryKey: ["rbac-settings"] }),
  });
  const toggle = (role: string, permission: string) =>
    setRoleOverrides((override) => {
      const current = override ?? query.data?.roles ?? {};
      return {
        ...current,
        [role]: current[role]?.includes(permission)
          ? current[role].filter((item) => item !== permission)
          : [...(current[role] ?? []), permission],
      };
    });
  if (query.isPending)
    return <CircularProgress aria-label="Chargement des permissions RBAC" />;
  if (query.isError)
    return (
      <Alert severity="error">
        Impossible de charger les permissions RBAC.
      </Alert>
    );
  return (
    <Stack spacing={3}>
      <div>
        <Typography variant="h4" fontWeight={800}>
          Rôles et permissions
        </Typography>
        <Typography color="text.secondary">
          Matrice configurable par organisation, compatible avec les rôles
          existants
        </Typography>
      </div>
      {save.isError && (
        <Alert severity="error">La matrice n’a pas pu être enregistrée.</Alert>
      )}
      {Object.entries(roles).map(([role, permissions]) => (
        <Card key={role}>
          <CardContent>
            <Typography variant="h6">
              {role.replace("ROLE_", "").replaceAll("_", " ")}
            </Typography>
            <Stack direction="row" flexWrap="wrap">
              {query.data?.permissions.map((permission) => (
                <FormControlLabel
                  key={permission}
                  sx={{ width: { xs: "100%", md: "31%" } }}
                  control={
                    <Checkbox
                      checked={permissions.includes(permission)}
                      onChange={() => toggle(role, permission)}
                    />
                  }
                  label={permission}
                />
              ))}
            </Stack>
          </CardContent>
        </Card>
      ))}
      <Button
        variant="contained"
        disabled={save.isPending || !query.data}
        onClick={() => save.mutate()}
      >
        Enregistrer les permissions
      </Button>
    </Stack>
  );
}
