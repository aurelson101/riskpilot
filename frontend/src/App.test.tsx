import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
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
  it("affiche la connexion RiskPilot", async () => {
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
      await screen.findByRole("heading", { name: "RiskPilot" }),
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

  it("regroupe le profil dans les paramètres et permet de réduire le menu", async () => {
    sessionStorage.setItem(TOKEN_STORAGE_KEY, "valid-token");
    vi.spyOn(api, "get").mockResolvedValueOnce({
      data: {
        id: 1,
        email: "admin@example.test",
        firstName: "Alice",
        lastName: "Admin",
        roles: ["ROLE_ADMIN"],
        status: "ACTIVE",
        mfaEnabled: false,
        lastLoginAt: null,
        organization: {
          id: 1,
          name: "Demo",
          description: null,
          status: "ACTIVE",
          riskThresholds: {
            lowMax: 4,
            moderateMax: 9,
            highMax: 16,
            criticalMax: 25,
          },
        },
      },
    });
    render(
      <QueryClientProvider client={new QueryClient()}>
        <MemoryRouter initialEntries={["/profile"]}>
          <AuthProvider>
            <App />
          </AuthProvider>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByText("Mon profil et MFA")).toBeInTheDocument();
    expect(screen.getByText("Messagerie")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: /Réduire/ }));
    await waitFor(() =>
      expect(screen.queryByText("Mon profil et MFA")).not.toBeInTheDocument(),
    );
    expect(screen.queryByLabelText("Ouvrir le menu")).not.toBeInTheDocument();
  });
});
