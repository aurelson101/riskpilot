import { SearchOutlined } from "@mui/icons-material";
import {
  Alert,
  Button,
  Card,
  CardActionArea,
  CardContent,
  Chip,
  Pagination,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import { useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { api } from "../api/client";

type Result = {
  type: string;
  id: number;
  title: string;
  subtitle: string;
  link: string;
};

export function SearchPage() {
  const navigate = useNavigate();
  const [input, setInput] = useState("");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const results = useQuery({
    queryKey: ["global-search", query, page],
    enabled: query.length >= 2,
    queryFn: async () =>
      (
        await api.get<{ items: Result[]; total: number; pages: number }>(
          `/search?q=${encodeURIComponent(query)}&page=${page}&limit=20`,
        )
      ).data,
  });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    setPage(1);
    setQuery(input.trim());
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
      <form onSubmit={submit}>
        <Stack direction={{ xs: "column", sm: "row" }} gap={1}>
          <TextField
            fullWidth
            label="Rechercher"
            value={input}
            onChange={(event) => setInput(event.target.value)}
            inputProps={{ minLength: 2 }}
          />
          <Button
            type="submit"
            variant="contained"
            startIcon={<SearchOutlined />}
            disabled={input.trim().length < 2}
          >
            Rechercher
          </Button>
        </Stack>
      </form>
      {results.isError && (
        <Alert severity="error">La recherche n’a pas pu être effectuée.</Alert>
      )}
      {results.data?.items.map((item) => (
        <Card key={`${item.type}-${item.id}`}>
          <CardActionArea onClick={() => navigate(item.link)}>
            <CardContent>
              <Stack direction="row" justifyContent="space-between" gap={2}>
                <div>
                  <Typography fontWeight={750}>{item.title}</Typography>
                  <Typography variant="body2" color="text.secondary">
                    {item.subtitle || "Aucun détail"}
                  </Typography>
                </div>
                <Chip label={item.type} />
              </Stack>
            </CardContent>
          </CardActionArea>
        </Card>
      ))}
      {query && results.data?.total === 0 && (
        <Alert severity="info">Aucun résultat accessible.</Alert>
      )}
      {(results.data?.pages ?? 0) > 1 && (
        <Pagination
          page={page}
          count={results.data?.pages ?? 1}
          onChange={(_, value) => setPage(value)}
        />
      )}
    </Stack>
  );
}
