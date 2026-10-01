import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { api } from "../api/client";
import { NotificationsPage } from "./NotificationsPage";

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

function renderPage() {
  return render(
    <QueryClientProvider
      client={
        new QueryClient({
          defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
          },
        })
      }
    >
      <MemoryRouter>
        <NotificationsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const notification = {
  id: 1,
  title: "Revoir le risque",
  message: "Échéance proche",
  type: "RISK_REVIEW",
  link: "/risks?review=1",
  isRead: false,
  createdAt: "2026-01-01T10:00:00Z",
};

describe("NotificationsPage", () => {
  it.each([
    null,
    "https://example.test",
    "//example.test",
    "/\\example.test",
    "/risks\n",
    "javascript:alert(1)",
  ])("hides unsafe destination %s", async (link) => {
    vi.spyOn(api, "get").mockImplementation(async (url) => ({
      data:
        url === "/notifications/summary"
          ? { total: 1, unread: 1 }
          : [{ ...notification, link }],
      headers: { "x-total-count": "1" },
    }));
    renderPage();
    expect(await screen.findByText("Revoir le risque")).toBeVisible();
    expect(
      screen.queryByRole("link", { name: "Ouvrir le dossier" }),
    ).not.toBeInTheDocument();
  });

  it("shows an explicit empty state and disables unnecessary bulk reads", async () => {
    vi.spyOn(api, "get").mockImplementation(async (url) => ({
      data: url === "/notifications/summary" ? { total: 0, unread: 0 } : [],
      headers: { "x-total-count": "0" },
    }));
    renderPage();
    expect(await screen.findByText("Aucune notification.")).toBeVisible();
    expect(
      screen.getByRole("button", { name: "Tout marquer comme lu" }),
    ).toBeDisabled();
    fireEvent.click(screen.getByLabelText("Non lues uniquement"));
    expect(
      await screen.findByText("Aucune notification non lue."),
    ).toBeVisible();
  });

  it("exposes a keyboard action and blocks repeated reads while reporting a failure", async () => {
    vi.spyOn(api, "get").mockImplementation(async (url) => ({
      data:
        url === "/notifications/summary"
          ? { total: 1, unread: 1 }
          : [notification],
      headers: { "x-total-count": "1" },
    }));
    let rejectRead = (_error: Error) => {};
    const put = vi.spyOn(api, "put").mockImplementation(
      () =>
        new Promise((_resolve, reject) => {
          rejectRead = reject;
        }),
    );
    renderPage();
    const button = await screen.findByRole("button", {
      name: "Marquer comme lu",
    });
    expect(
      screen.getByRole("link", { name: "Ouvrir le dossier" }),
    ).toHaveAttribute("href", "/risks?review=1");
    fireEvent.click(button);
    fireEvent.click(button);
    await waitFor(() => expect(put).toHaveBeenCalledTimes(1));
    expect(button).toBeDisabled();
    rejectRead(new Error("Network failure"));
    expect(
      await screen.findByText(
        "Impossible de marquer les notifications comme lues. Réessayez.",
      ),
    ).toBeVisible();
    await waitFor(() => expect(button).toBeEnabled());
    expect(screen.getByText("Nouveau")).toBeVisible();
  });

  it("loads older notifications using a bounded offset", async () => {
    const get = vi.spyOn(api, "get").mockImplementation(async (url) => ({
      data:
        url === "/notifications/summary"
          ? { total: 55, unread: 55 }
          : [notification],
      headers: { "x-total-count": "55" },
    }));
    renderPage();
    fireEvent.click(await screen.findByRole("button", { name: "Suivant" }));
    await waitFor(() =>
      expect(get).toHaveBeenCalledWith("/notifications", {
        params: { limit: 25, offset: 25, unreadOnly: false },
      }),
    );
    expect(await screen.findByText("2 / 3")).toBeVisible();
  });
});
