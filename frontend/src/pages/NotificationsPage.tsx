import { useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Button,
  Card,
  CardContent,
  Chip,
  CircularProgress,
  FormControlLabel,
  Stack,
  Switch,
  Typography,
} from "@mui/material";
import { Link } from "react-router-dom";
import { api } from "../api/client";
import type { Notification } from "../api/types";
import { useInterfaceLocale } from "../i18n/InterfaceLocaleContext";

function notificationPath(link: string | null): string | null {
  if (
    !link ||
    !link.startsWith("/") ||
    link.startsWith("//") ||
    link.includes("\\") ||
    Array.from(link).some(
      (character) =>
        character.charCodeAt(0) <= 0x20 || character.charCodeAt(0) === 0x7f,
    )
  )
    return null;
  try {
    const url = new URL(link, "https://riskpilot.local");
    return url.origin === "https://riskpilot.local"
      ? url.pathname + url.search + url.hash
      : null;
  } catch {
    return null;
  }
}

export function NotificationsPage() {
  const locale = useInterfaceLocale();
  const client = useQueryClient();
  const [page, setPage] = useState(0);
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [success, setSuccess] = useState(false);
  const pending = useRef(false);
  const pageSize = 25;
  const summary = useQuery({
    queryKey: ["notifications", "summary"],
    queryFn: async () =>
      (
        await api.get<{ total: number; unread: number }>(
          "/notifications/summary",
        )
      ).data,
  });
  const query = useQuery({
    queryKey: ["notifications", "list", page, unreadOnly],
    queryFn: async () => {
      const response = await api.get<Notification[]>("/notifications", {
        params: { limit: pageSize, offset: page * pageSize, unreadOnly },
      });
      return {
        items: response.data,
        total: Number(
          response.headers["x-total-count"] ?? response.data.length,
        ),
      };
    },
  });
  const read = useMutation({
    mutationFn: (id: number | "all") =>
      api.put(
        id === "all" ? "/notifications/read-all" : `/notifications/${id}/read`,
      ),
    onSuccess: async (_data, id) => {
      setSuccess(id === "all");
      if (unreadOnly) setPage(0);
      await client.invalidateQueries({ queryKey: ["notifications"] });
    },
    onSettled: () => {
      pending.current = false;
    },
  });

  function markRead(id: number | "all") {
    if (pending.current) return;
    pending.current = true;
    setSuccess(false);
    read.mutate(id);
  }

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
      <Stack
        direction={{ xs: "column", sm: "row" }}
        spacing={2}
        alignItems={{ sm: "center" }}
      >
        {summary.data && (
          <Typography aria-live="polite">
            {locale === "en"
              ? `${summary.data.unread} unread · ${summary.data.total} total`
              : `${summary.data.unread} non lues · ${summary.data.total} au total`}
          </Typography>
        )}
        <FormControlLabel
          control={
            <Switch
              checked={unreadOnly}
              onChange={(event) => {
                setUnreadOnly(event.target.checked);
                setPage(0);
              }}
            />
          }
          label="Non lues uniquement"
        />
        <Button
          variant="outlined"
          disabled={read.isPending || !summary.data?.unread}
          onClick={() => markRead("all")}
        >
          Tout marquer comme lu
        </Button>
      </Stack>
      {summary.isError && (
        <Alert
          severity="warning"
          action={
            <Button onClick={() => void summary.refetch()}>Réessayer</Button>
          }
        >
          Impossible de charger le compteur des notifications.
        </Alert>
      )}
      {read.isError && (
        <Alert severity="error">
          Impossible de marquer les notifications comme lues. Réessayez.
        </Alert>
      )}
      {success && (
        <Alert severity="success" role="status">
          Notifications marquées comme lues.
        </Alert>
      )}
      {query.isLoading && (
        <CircularProgress aria-label="Chargement de la page" />
      )}
      {query.isError && (
        <Alert
          severity="error"
          action={
            <Button onClick={() => void query.refetch()}>Réessayer</Button>
          }
        >
          Impossible de charger les notifications.
        </Alert>
      )}
      {!query.isLoading && !query.isError && query.data?.items.length === 0 && (
        <Alert severity="info">
          {unreadOnly ? "Aucune notification non lue." : "Aucune notification."}
        </Alert>
      )}
      <Stack spacing={1.5}>
        {query.data?.items.map((item) => (
          <Card
            key={item.id}
            variant="outlined"
            sx={{
              bgcolor: item.isRead ? "white" : "#eef5ff",
              overflowWrap: "anywhere",
            }}
          >
            <CardContent>
              <Stack
                direction={{ xs: "column", sm: "row" }}
                justifyContent="space-between"
                gap={2}
              >
                <Stack sx={{ minWidth: 0, flex: 1 }}>
                  <Typography fontWeight={700}>{item.title}</Typography>
                  <Typography>{item.message}</Typography>
                  <Typography variant="caption" color="text.secondary">
                    {new Date(item.createdAt).toLocaleString(locale)}
                  </Typography>
                </Stack>
                <Stack spacing={1} alignItems="flex-start">
                  {!item.isRead && (
                    <>
                      <Chip size="small" color="primary" label="Nouveau" />
                      <Button
                        disabled={read.isPending}
                        onClick={() => markRead(item.id)}
                      >
                        Marquer comme lu
                      </Button>
                    </>
                  )}
                  {notificationPath(item.link) && (
                    <Button component={Link} to={notificationPath(item.link)!}>
                      Ouvrir le dossier
                    </Button>
                  )}
                </Stack>
              </Stack>
            </CardContent>
          </Card>
        ))}
      </Stack>
      {query.data && query.data.total > pageSize && (
        <Stack
          direction="row"
          spacing={2}
          justifyContent="center"
          alignItems="center"
        >
          <Button
            disabled={page === 0 || query.isFetching || read.isPending}
            onClick={() => setPage(page - 1)}
          >
            Précédent
          </Button>
          <Typography>
            {page + 1} / {Math.ceil(query.data.total / pageSize)}
          </Typography>
          <Button
            disabled={
              (page + 1) * pageSize >= query.data.total ||
              query.isFetching ||
              read.isPending
            }
            onClick={() => setPage(page + 1)}
          >
            Suivant
          </Button>
        </Stack>
      )}
    </Stack>
  );
}
