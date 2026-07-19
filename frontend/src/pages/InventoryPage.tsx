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

type InventoryKind =
  "scopes" | "assets" | "threats" | "vulnerabilities" | "security-controls";
type InventoryItem = Record<string, unknown> & {
  id: number;
  name: string;
  status?: string;
};

const configurations: Record<
  InventoryKind,
  {
    title: string;
    subtitle: string;
    columns: Array<{ key: string; label: string }>;
  }
> = {
  scopes: {
    title: "Périmètres",
    subtitle: "Structure organisationnelle et technique",
    columns: [
      { key: "type", label: "Type" },
      { key: "owner", label: "Responsable" },
    ],
  },
  assets: {
    title: "Actifs",
    subtitle: "Inventaire des actifs métier et techniques",
    columns: [
      { key: "type", label: "Type" },
      { key: "scope", label: "Périmètre" },
      { key: "criticality", label: "Criticité" },
      { key: "owner", label: "Responsable" },
    ],
  },
  threats: {
    title: "Menaces",
    subtitle: "Catalogue des menaces applicables",
    columns: [
      { key: "category", label: "Catégorie" },
      { key: "source", label: "Source" },
    ],
  },
  vulnerabilities: {
    title: "Vulnérabilités",
    subtitle: "Faiblesses et actifs affectés",
    columns: [
      { key: "category", label: "Catégorie" },
      { key: "severity", label: "Sévérité" },
      { key: "affectedAssets", label: "Actifs affectés" },
    ],
  },
  "security-controls": {
    title: "Mesures de sécurité",
    subtitle: "Mesures existantes et efficacité déclarée",
    columns: [
      { key: "category", label: "Catégorie" },
      { key: "effectiveness", label: "Efficacité (%)" },
      { key: "implementationStatus", label: "Déploiement" },
      { key: "owner", label: "Responsable" },
    ],
  },
};

function renderValue(value: unknown): string {
  if (null === value || undefined === value) return "—";
  if (Array.isArray(value))
    return (
      value
        .map((item) =>
          typeof item === "object" && item && "name" in item
            ? String(item.name)
            : String(item),
        )
        .join(", ") || "—"
    );
  if (typeof value === "object") {
    if ("firstName" in value && "lastName" in value)
      return `${String(value.firstName)} ${String(value.lastName)}`;
    if ("name" in value) return String(value.name);
  }
  return String(value);
}

export function InventoryPage({ kind }: { kind: InventoryKind }) {
  const config = configurations[kind];
  const query = useQuery({
    queryKey: [kind],
    queryFn: async () => (await api.get<InventoryItem[]>(`/${kind}`)).data,
  });
  if (query.isLoading) return <CircularProgress />;
  if (query.isError)
    return (
      <Alert severity="error">
        Impossible de charger {config.title.toLowerCase()}.
      </Alert>
    );
  return (
    <Stack spacing={3}>
      <Stack>
        <Typography variant="h4" fontWeight={750}>
          {config.title}
        </Typography>
        <Typography color="text.secondary">
          {config.subtitle} · {query.data?.length ?? 0} élément(s)
        </Typography>
      </Stack>
      <Card variant="outlined">
        <CardContent>
          <Table aria-label={config.title}>
            <TableHead>
              <TableRow>
                <TableCell>Nom</TableCell>
                {config.columns.map((column) => (
                  <TableCell key={column.key}>{column.label}</TableCell>
                ))}
                {kind !== "security-controls" && <TableCell>Statut</TableCell>}
              </TableRow>
            </TableHead>
            <TableBody>
              {query.data?.map((item) => (
                <TableRow key={item.id} hover>
                  {kind !== "security-controls" && (
                    <TableCell>
                      <Typography fontWeight={650}>{item.name}</Typography>
                    </TableCell>
                  )}
                  {config.columns.map((column) => (
                    <TableCell key={column.key}>
                      {renderValue(item[column.key])}
                    </TableCell>
                  ))}
                  <TableCell>
                    <Chip
                      size="small"
                      label={item.status}
                      color={
                        item.status === "ACTIVE" || item.status === "OPEN"
                          ? "success"
                          : "default"
                      }
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
