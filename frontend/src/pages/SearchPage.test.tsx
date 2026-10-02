import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { MemoryRouter, useLocation } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { api } from "../api/client";
import { SearchPage } from "./SearchPage";

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});
function Location() {
  return <output data-testid="url">{useLocation().search}</output>;
}
function show(url = "/search") {
  render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <MemoryRouter initialEntries={[url]}>
        <SearchPage />
        <Location />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}
const results = {
  items: [
    {
      id: 1,
      title: "Cloud risk",
      subtitle: "Cloud service",
      type: "RISK",
      link: "https://example.test",
    },
  ],
  total: 42,
  page: 1,
  pages: 3,
  limit: 20,
  counts: { RISK: 30, ACTION: 12, CONTROL: 0, DOCUMENT: 0, THIRD_PARTY: 0 },
};
describe("GRC search", () => {
  it("does not request an empty query and supports Escape to clear", async () => {
    const get = vi.spyOn(api, "get");
    show();
    expect(get).not.toHaveBeenCalled();
    expect(screen.getByText(/Saisissez un mot-clé/)).toBeVisible();
    fireEvent.change(screen.getByLabelText("Rechercher"), {
      target: { value: "cloud" },
    });
    fireEvent.keyDown(screen.getByLabelText("Rechercher"), { key: "Escape" });
    expect(screen.getByLabelText("Rechercher")).toHaveValue("");
  });
  it("restores URL filters, highlights matches and uses an internal native link", async () => {
    const get = vi.spyOn(api, "get").mockResolvedValue({ data: results });
    show("/search?q=cloud&type=RISK&sort=title&limit=50");
    await waitFor(() => expect(screen.getByRole("link")).toBeVisible());
    expect(screen.getByRole("link")).toHaveAttribute("href", "/risks");
    expect(document.querySelector("mark")).toHaveTextContent("Cloud");
    expect(screen.getByText("42 résultats · page 1/3")).toHaveAttribute(
      "role",
      "status",
    );
    expect(get).toHaveBeenCalledWith(
      "/search",
      expect.objectContaining({
        params: expect.objectContaining({
          q: "cloud",
          type: "RISK",
          sort: "title",
          limit: 50,
        }),
        signal: expect.any(AbortSignal),
      }),
    );
  });
  it("shares the query through the URL and preserves filters when paging", async () => {
    vi.spyOn(api, "get").mockResolvedValue({ data: results });
    show();
    fireEvent.change(screen.getByLabelText("Rechercher"), {
      target: { value: " cloud " },
    });
    fireEvent.click(screen.getByRole("button", { name: "Rechercher" }));
    await screen.findByText("42 résultats · page 1/3");
    expect(screen.getByTestId("url")).toHaveTextContent("q=cloud");
    fireEvent.click(screen.getByRole("button", { name: /page 2/i }));
    expect(screen.getByTestId("url")).toHaveTextContent("page=2");
    fireEvent.click(screen.getByRole("button", { name: "Effacer" }));
    expect(screen.getByTestId("url")).toBeEmptyDOMElement();
  });
  it("offers a retry after an error and an actionable empty state", async () => {
    const get = vi
      .spyOn(api, "get")
      .mockRejectedValueOnce(new Error("offline"))
      .mockResolvedValueOnce({
        data: { ...results, items: [], total: 0, pages: 1 },
      });
    show("/search?q=cloud");
    fireEvent.click(await screen.findByRole("button", { name: "Réessayer" }));
    expect(await screen.findByText(/Essayez un autre mot-clé/)).toBeVisible();
    expect(get).toHaveBeenCalledTimes(2);
  });
});
