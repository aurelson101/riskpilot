import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { api } from "../api/client";
import { AuthContext } from "../auth/auth-context";
import { OperationsPage } from "./OperationsPage";

const user = {
  id: 1,
  email: "manager@example.test",
  firstName: "Risk",
  lastName: "Manager",
  roles: ["ROLE_RISK_MANAGER"],
  status: "ACTIVE",
  locale: "fr" as const,
  organization: {
    id: 1,
    name: "Primary",
    description: null,
    status: "ACTIVE",
    riskThresholds: { lowMax: 4, moderateMax: 9, highMax: 16, criticalMax: 25 },
  },
  lastLoginAt: null,
  mfaEnabled: false,
};

function renderPage() {
  return render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <MemoryRouter>
        <AuthContext.Provider
          value={{ token: "token", user, login: vi.fn(), logout: vi.fn() }}
        >
          <OperationsPage />
        </AuthContext.Provider>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

describe("OperationsPage", () => {
  it("rend les tâches navigables et l'état vide", async () => {
    vi.spyOn(api, "get").mockResolvedValueOnce({
      data: {
        items: [
          {
            id: 7,
            title: "Revoir la preuve",
            status: "ACTIVE",
            source: "OPERATIONAL",
            link: "/operations",
            dueAt: null,
            overdue: false,
          },
        ],
      },
    });
    renderPage();

    expect(await screen.findByText("Revoir la preuve")).toBeInTheDocument();
    expect(screen.getByText("OPERATIONAL · Sans échéance")).toBeInTheDocument();
  });

  it("ouvre la création de tâche avec un sélecteur de responsable", async () => {
    vi.spyOn(api, "get").mockImplementation(async (url) => ({
      data: url === "/operations/my-tasks" ? { items: [] } : [],
    }));
    renderPage();

    expect(
      await screen.findByText("Aucune tâche ouverte."),
    ).toBeInTheDocument();
    fireEvent.click(
      screen.getByRole("tab", { name: "Tâches opérationnelles" }),
    );
    fireEvent.click(await screen.findByRole("button", { name: "Créer" }));
    expect(
      screen.getByRole("combobox", { name: "Responsable" }),
    ).toBeInTheDocument();
  });

  it("affiche une erreur de chargement sans exposer un éditeur avancé au gestionnaire", async () => {
    vi.spyOn(api, "get").mockRejectedValue(new Error("Network error"));
    renderPage();

    expect(
      await screen.findByText("L’opération n’a pas pu être terminée."),
    ).toBeInTheDocument();
    expect(
      screen.queryByLabelText("Configuration avancée JSON"),
    ).not.toBeInTheDocument();
  });
});
