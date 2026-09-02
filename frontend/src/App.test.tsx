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

  it("simplifie les menus, regroupe le profil et permet de réduire la navigation", async () => {
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
    const riskMenu = screen.getByRole("button", {
      name: "Gestion des risques",
    });
    const steeringMenu = screen.getByRole("button", { name: "Pilotage" });
    const complianceMenu = screen.getByRole("button", {
      name: "Conformité et contrôles",
    });
    expect(riskMenu).toHaveAttribute("aria-expanded", "false");
    expect(steeringMenu).toHaveAttribute("aria-expanded", "false");
    expect(complianceMenu).toHaveAttribute("aria-expanded", "false");
    fireEvent.click(riskMenu);
    expect(await screen.findByText("Registre des risques")).toBeInTheDocument();
    expect(screen.getByText("Périmètres")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Pilotage" }));
    expect(await screen.findByText("Indicateurs")).toBeInTheDocument();
    expect(riskMenu).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByText("Registre des risques")).not.toBeInTheDocument();
    fireEvent.click(
      screen.getByRole("button", { name: "Conformité et contrôles" }),
    );
    expect(await screen.findByText("Mesures de sécurité")).toBeInTheDocument();
    expect(steeringMenu).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByText("Indicateurs")).not.toBeInTheDocument();
    expect(screen.getByText("Conformité NIS2")).toBeInTheDocument();
    expect(screen.getByText("Tiers et fournisseurs")).toBeInTheDocument();
    expect(screen.getByText("Incidents et continuité")).toBeInTheDocument();
    expect(screen.getByText("Actifs")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Actifs" })).not.toHaveAttribute(
      "aria-expanded",
    );
    expect(screen.queryByText("Tous les actifs")).not.toBeInTheDocument();
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
    expect(
      await screen.findByText("My profile", { selector: "h4" }),
    ).toBeInTheDocument();
    expect(localStorage.getItem("riskpilot.interfaceLocale")).toBe("en");
    expect(screen.getByText("Compliance and controls")).toBeInTheDocument();
    expect(screen.getByText("Assets")).toBeInTheDocument();
    expect(screen.queryByText("All assets")).not.toBeInTheDocument();
    expect(screen.queryByText("Hardware assets")).not.toBeInTheDocument();
    const ismsItem = await screen.findByRole("button", {
      name: "ISMS documents",
    });
    expect(ismsItem).not.toHaveAttribute("aria-expanded");
    expect(screen.queryByText("Recent publications")).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: /Collapse/ }));
    expect(screen.queryByLabelText("Open menu")).not.toBeInTheDocument();
  });

  it("bloque une route d’administration lors d’un accès direct sans rôle", async () => {
    localStorage.setItem("riskpilot.interfaceLocale", "en");
    sessionStorage.setItem(TOKEN_STORAGE_KEY, "viewer-token");
    const get = vi.spyOn(api, "get").mockImplementation(async (url) => ({
      data:
        url === "/isms-documents"
          ? []
          : {
              id: 2,
              email: "viewer@example.test",
              firstName: "Victor",
              lastName: "Viewer",
              roles: ["ROLE_VIEWER"],
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
    }));
    render(
      <QueryClientProvider client={new QueryClient()}>
        <MemoryRouter initialEntries={["/administration/users"]}>
          <AuthProvider>
            <App />
          </AuthProvider>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(
      await screen.findByText(
        "Access denied. Your role does not allow you to open this page.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Users" }),
    ).not.toBeInTheDocument();
    expect(get).not.toHaveBeenCalledWith("/users");
  });
});
