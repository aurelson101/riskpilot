import { SearchOutlined } from "@mui/icons-material";
import {
  Alert,
  Button,
  Card,
  CardActionArea,
  CardContent,
  Chip,
  CircularProgress,
  MenuItem,
  Pagination,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import { useState, type FormEvent } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { api } from "../api/client";
import { useInterfaceLocale } from "../i18n/InterfaceLocaleContext";

const kinds = {
  RISK: ["Risques", "Risks"],
  ACTION: ["Actions", "Actions"],
  CONTROL: ["Mesures", "Controls"],
  DOCUMENT: ["Documents", "Documents"],
  THIRD_PARTY: ["Tiers", "Third parties"],
} as const;
type Kind = keyof typeof kinds;
type Result = {
  type: Kind;
  id: number;
  title: string;
  subtitle: string;
  link: string;
};
type Results = {
  items: Result[];
  total: number;
  pages: number;
  page: number;
  limit: number;
  counts: Record<Kind, number>;
};

function highlighted(text: string, query: string) {
  const index = text.toLocaleLowerCase().indexOf(query.toLocaleLowerCase());
  if (index < 0) return text;
  return (
    <>
      {text.slice(0, index)}
      <mark>{text.slice(index, index + query.length)}</mark>
      {text.slice(index + query.length)}
    </>
  );
}

export function SearchPage() {
  const locale = useInterfaceLocale();
  const en = locale === "en";
  const [params, setParams] = useSearchParams();
  const query = (params.get("q") ?? "").trim();
  const [draft, setDraft] = useState({ query, text: query });
  const input = draft.query === query ? draft.text : query;
  const setInput = (text: string) => setDraft({ query, text });
  const type = params.get("type") ?? "";
  const selectedType = type in kinds ? (type as Kind) : "";
  const sort = params.get("sort") === "title" ? "title" : "relevance";
  const requestedPage = Number(params.get("page") ?? 1);
  const page =
    Number.isInteger(requestedPage) &&
    requestedPage >= 1 &&
    requestedPage <= 1000
      ? requestedPage
      : 1;
  const limit = params.get("limit") === "50" ? 50 : 20;
  const valid = query.length >= 2 && query.length <= 160;
  const results = useQuery({
    queryKey: ["global-search", query, selectedType, sort, page, limit],
    enabled: valid,
    staleTime: 30_000,
    gcTime: 120_000,
    refetchOnWindowFocus: false,
    retry: false,
    queryFn: async ({ signal }) =>
      (
        await api.get<Results>("/search", {
          params: {
            q: query,
            type: selectedType || undefined,
            sort,
            page,
            limit,
          },
          signal,
        })
      ).data,
  });
  const update = (values: Record<string, string>, resetPage = true) => {
    const next = new URLSearchParams(params);
    if (resetPage) next.delete("page");
    Object.entries(values).forEach(([key, value]) =>
      value ? next.set(key, value) : next.delete(key),
    );
    setParams(next);
  };
  const clear = () => {
    setInput("");
    setParams({});
  };
  const submit = (event: FormEvent) => {
    event.preventDefault();
    update({ q: input.trim() });
  };

  return (
    <Stack spacing={3}>
      <div>
        <Typography variant="h4" fontWeight={800}>
          Recherche transverse
        </Typography>
        <Typography color="text.secondary">
          Risques, actions, mesures, documents et tiers visibles selon vos
          droits
        </Typography>
      </div>
      <form
        onSubmit={submit}
        onKeyDown={(event) => {
          if (event.key === "Escape") clear();
        }}
      >
        <Stack direction={{ xs: "column", sm: "row" }} gap={1}>
          <TextField
            fullWidth
            label="Rechercher"
            value={input}
            onChange={(event) => setInput(event.target.value)}
            inputProps={{ minLength: 2, maxLength: 160 }}
            helperText={
              en
                ? "2–160 characters · Escape to clear"
                : "2–160 caractères · Échap pour effacer"
            }
          />
          <Button
            type="submit"
            variant="contained"
            startIcon={<SearchOutlined />}
            disabled={
              input.trim().length < 2 ||
              input.trim().length > 160 ||
              results.isFetching
            }
          >
            Rechercher
          </Button>
          <Button onClick={clear} disabled={!input && !query}>
            Effacer
          </Button>
        </Stack>
      </form>
      <Stack direction={{ xs: "column", sm: "row" }} gap={2}>
        <TextField
          select
          label="Type de dossier"
          value={selectedType}
          onChange={(event) => update({ type: event.target.value })}
          sx={{ minWidth: 180 }}
        >
          <MenuItem value="">Tous les dossiers</MenuItem>
          {Object.entries(kinds).map(([key, label]) => (
            <MenuItem key={key} value={key}>
              {label[en ? 1 : 0]}
              {results.data?.counts
                ? ` (${results.data.counts[key as Kind]})`
                : ""}
            </MenuItem>
          ))}
        </TextField>
        <TextField
          select
          label="Trier les résultats"
          value={sort}
          onChange={(event) => update({ sort: event.target.value })}
          sx={{ minWidth: 180 }}
        >
          <MenuItem value="relevance">Pertinence</MenuItem>
          <MenuItem value="title">Ordre alphabétique</MenuItem>
        </TextField>
        <TextField
          select
          label="Résultats par page"
          value={limit}
          onChange={(event) => update({ limit: event.target.value })}
          sx={{ minWidth: 180 }}
        >
          <MenuItem value={20}>20</MenuItem>
          <MenuItem value={50}>50</MenuItem>
        </TextField>
      </Stack>
      {!query && (
        <Alert severity="info">
          Saisissez un mot-clé pour retrouver vos dossiers GRC.
        </Alert>
      )}
      {query && !valid && (
        <Alert severity="warning">
          La recherche doit contenir entre 2 et 160 caractères.
        </Alert>
      )}
      {results.isFetching && (
        <Stack direction="row" spacing={2} role="status">
          <CircularProgress size={22} />
          <Typography>Recherche en cours…</Typography>
        </Stack>
      )}
      {results.isError && (
        <Alert
          severity="error"
          action={
            <Button onClick={() => void results.refetch()}>Réessayer</Button>
          }
        >
          La recherche n’a pas pu être effectuée.
        </Alert>
      )}
      {valid && results.data && (
        <Typography role="status">
          {en
            ? `${results.data.total} results · page ${results.data.page}/${results.data.pages}`
            : `${results.data.total} résultats · page ${results.data.page}/${results.data.pages}`}
        </Typography>
      )}
      {valid &&
        results.data?.items.map((item) => (
          <Card key={`${item.type}-${item.id}`} variant="outlined">
            <CardActionArea
              component={Link}
              to={
                {
                  RISK: "/risks",
                  ACTION: "/actions",
                  CONTROL: "/compliance",
                  DOCUMENT: "/isms-documents",
                  THIRD_PARTY: "/third-parties",
                }[item.type] ?? "/search"
              }
            >
              <CardContent>
                <Stack
                  direction={{ xs: "column", sm: "row" }}
                  justifyContent="space-between"
                  gap={2}
                >
                  <Stack sx={{ minWidth: 0, overflowWrap: "anywhere" }}>
                    <Typography fontWeight={750}>
                      {highlighted(item.title, query)}
                    </Typography>
                    <Typography variant="body2" color="text.secondary">
                      {highlighted(
                        item.subtitle || (en ? "No details" : "Aucun détail"),
                        query,
                      )}
                    </Typography>
                  </Stack>
                  <Chip
                    sx={{ alignSelf: "flex-start", flexShrink: 0 }}
                    label={kinds[item.type]?.[en ? 1 : 0] ?? item.type}
                  />
                </Stack>
              </CardContent>
            </CardActionArea>
          </Card>
        ))}
      {valid && results.data?.total === 0 && (
        <Alert severity="info">
          Aucun résultat accessible. Essayez un autre mot-clé ou un autre type
          de dossier.
        </Alert>
      )}
      {valid && (results.data?.pages ?? 0) > 1 && (
        <Pagination
          aria-label={
            en ? "Search results pages" : "Pages des résultats de recherche"
          }
          page={results.data?.page ?? page}
          count={results.data?.pages ?? 1}
          disabled={results.isFetching}
          onChange={(_, value) => update({ page: String(value) }, false)}
          sx={{ "& .MuiPagination-ul": { justifyContent: "center" } }}
        />
      )}
    </Stack>
  );
}
