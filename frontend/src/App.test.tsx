import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router-dom";
import App from "./App";
import { AuthProvider } from "./auth/AuthContext";
import { api, TOKEN_STORAGE_KEY } from "./api/client";

afterEach(() => {
  cleanup();
  sessionStorage.clear();
  vi.restoreAllMocks();
});

describe("App", () => {
  it("affiche la connexion RiskPilot", () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <MemoryRouter initialEntries={["/login"]}>
          <AuthProvider>
            <App />
          </AuthProvider>
        </MemoryRouter>
      </QueryClientProvider>,
    );
    expect(
      screen.getByRole("heading", { name: "RiskPilot" }),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Connexion à votre espace GRC"),
    ).toBeInTheDocument();
  });

  it("revient à la connexion quand le JWT stocké est expiré", async () => {
    sessionStorage.setItem(TOKEN_STORAGE_KEY, "expired-token");
    vi.spyOn(api, "get").mockRejectedValueOnce(new Error("Unauthorized"));
    render(
      <QueryClientProvider client={new QueryClient()}>
        <MemoryRouter initialEntries={["/"]}>
          <AuthProvider>
            <App />
          </AuthProvider>
        </MemoryRouter>
      </QueryClientProvider>,
    );
    await waitFor(() =>
      expect(sessionStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull(),
    );
    expect(
      await screen.findByText("Connexion à votre espace GRC"),
    ).toBeInTheDocument();
  });
});
