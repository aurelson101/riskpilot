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
  localStorage.clear();
  vi.restoreAllMocks();
});

describe("App", () => {
  it("affiche la connexion RiskPilot", async () => {
    localStorage.setItem("riskpilot.interfaceLocale", "fr");
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

  it("conserve l’anglais sur les pages publiques", async () => {
    localStorage.setItem("riskpilot.interfaceLocale", "en");
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
      await screen.findByText("Sign in to your GRC workspace"),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Sign in" })).toBeInTheDocument();
  });

  it("revient à la connexion quand le JWT stocké est expiré", async () => {
    localStorage.setItem("riskpilot.interfaceLocale", "fr");
    sessionStorage.setItem(TOKEN_STORAGE_KEY, "expired-token");
    vi.spyOn(api, "get").mockRejectedValueOnce(new Error("Unauthorized"));
    vi.spyOn(api, "post").mockRejectedValueOnce(new Error("Unauthorized"));
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
    vi.spyOn(api, "get").mockImplementation(async (url) => ({
      data:
        url === "/isms-documents"
          ? [
              {
                id: 10,
                title: "Politique de sécurité",
                category: "Politique interne",
              },
              {
                id: 11,
                title: "Document au nom réservé",
                category: "Publications récentes",
              },
            ]
          : url === "/me/sessions"
            ? []
            : {
                id: 1,
                email: "admin@example.test",
                firstName: "Alice",
                lastName: "Admin",
                roles: ["ROLE_ADMIN"],
                status: "ACTIVE",
                locale: "fr",
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
    }));
    const updateProfile = vi.spyOn(api, "put").mockResolvedValue({
      data: {
        id: 1,
        email: "admin@example.test",
        firstName: "Alice",
        lastName: "Admin",
        roles: ["ROLE_ADMIN"],
        status: "ACTIVE",
        locale: "en",
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
    expect(screen.getByText("Indicateurs")).toBeInTheDocument();
    expect(screen.getByText("Actifs matériels")).toBeInTheDocument();
    expect(screen.getByText("Colonnes des actions")).toBeInTheDocument();
    fireEvent.mouseDown(screen.getByLabelText("Langue de l’interface"));
    fireEvent.click(await screen.findByRole("option", { name: "Anglais" }));
    fireEvent.click(
      screen.getByRole("button", { name: "Enregistrer les modifications" }),
    );
    await waitFor(() =>
      expect(updateProfile).toHaveBeenCalledWith("/me", {
        firstName: "Alice",
        lastName: "Admin",
        email: "admin@example.test",
        locale: "en",
      }),
    );
    expect(await screen.findByText("My profile and MFA")).toBeInTheDocument();
    expect(localStorage.getItem("riskpilot.interfaceLocale")).toBe("en");
    expect(screen.getByText("Email")).toBeInTheDocument();
    expect(screen.getByText("Indicators")).toBeInTheDocument();
    expect(screen.getByText("Hardware assets")).toBeInTheDocument();
    fireEvent.click(screen.getByText("ISMS documents"));
    expect(await screen.findByText("Politique interne")).toBeInTheDocument();
    expect(screen.getAllByText("Recent publications")).toHaveLength(1);
    fireEvent.click(screen.getByRole("button", { name: /Collapse/ }));
    await waitFor(() =>
      expect(screen.queryByText("My profile and MFA")).not.toBeInTheDocument(),
    );
    expect(screen.queryByLabelText("Open menu")).not.toBeInTheDocument();
  });
});
