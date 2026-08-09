import { Box, Chip, Stack, Typography } from "@mui/material";

function label(key: string) {
  return key
    .replace(/([a-z0-9])([A-Z])/g, "$1 $2")
    .replaceAll("_", " ")
    .replace(/^./, (value) => value.toUpperCase());
}

function scalar(value: unknown): string {
  if (value === null || value === undefined || value === "")
    return "Non renseigné";
  if (typeof value === "boolean") return value ? "Oui" : "Non";
  return String(value).replaceAll("_", " ");
}

function DetailValue({ value }: { value: unknown }) {
  if (Array.isArray(value)) {
    if (value.length === 0)
      return <Typography color="text.secondary">Aucun élément</Typography>;
    return (
      <Stack direction="row" gap={0.75} flexWrap="wrap">
        {value.map((item, index) =>
          typeof item === "object" && item !== null ? (
            <Box key={index} sx={{ width: "100%", pl: 1.5 }}>
              <RecordDetails details={item as Record<string, unknown>} />
            </Box>
          ) : (
            <Chip
              key={`${String(item)}-${index}`}
              size="small"
              label={scalar(item)}
            />
          ),
        )}
      </Stack>
    );
  }
  if (typeof value === "object" && value !== null)
    return <RecordDetails details={value as Record<string, unknown>} />;
  return <Typography variant="body2">{scalar(value)}</Typography>;
}

export function RecordDetails({
  details,
}: {
  details: Record<string, unknown>;
}) {
  const entries = Object.entries(details);
  if (entries.length === 0)
    return (
      <Typography color="text.secondary">
        Aucun détail complémentaire.
      </Typography>
    );

  return (
    <Stack spacing={1} component="dl" sx={{ m: 0 }}>
      {entries.map(([key, value]) => (
        <Box
          key={key}
          sx={{
            display: "grid",
            gridTemplateColumns: { xs: "1fr", sm: "minmax(140px, 0.35fr) 1fr" },
            gap: 0.5,
          }}
        >
          <Typography component="dt" variant="body2" fontWeight={700}>
            {label(key)}
          </Typography>
          <Box component="dd" sx={{ m: 0, minWidth: 0 }}>
            <DetailValue value={value} />
          </Box>
        </Box>
      ))}
    </Stack>
  );
}
