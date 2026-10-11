import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { api } from "../api/client";
import { UsersPage } from "./UsersPage";

vi.mock("../auth/useAuth", () => ({
  useAuth: () => ({ user: { id: 1, roles: ["ROLE_ADMIN"] } }),
}));
vi.mock("../components/confirmation-context", () => ({
  useConfirmation: () => vi.fn().mockResolvedValue(true),
}));

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

function renderPage() {
  render(
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
      <UsersPage />
    </QueryClientProvider>,
  );
}

describe("UsersPage", () => {
  it("shows MFA coverage and requires explicit confirmation for recovery", async () => {
    const users = [
      {
        id: 1,
        email: "actor@example.test",
        firstName: "Alice",
        lastName: "Admin",
        roles: ["ROLE_ADMIN"],
        status: "ACTIVE",
        mfaEnabled: true,
        organization: { id: 1, name: "Example" },
      },
      {
        id: 2,
        email: "target@example.test",
        firstName: "Tara",
        lastName: "Admin",
        roles: ["ROLE_ADMIN"],
        status: "ACTIVE",
        mfaEnabled: true,
        organization: { id: 1, name: "Example" },
      },
      {
        id: 3,
        email: "risk@example.test",
        firstName: "Rita",
        lastName: "Risk",
        roles: ["ROLE_RISK_MANAGER"],
        status: "ACTIVE",
        mfaEnabled: false,
        organization: { id: 1, name: "Example" },
      },
    ];
    vi.spyOn(api, "get").mockResolvedValue({ data: users });
    const post = vi.spyOn(api, "post").mockResolvedValue({ data: {} });
    renderPage();

    expect(
      await screen.findByText("MFA actif : 2/3 comptes actifs"),
    ).toBeVisible();
    expect(
      screen.getByText("1 compte(s) privilégié(s) sans MFA"),
    ).toBeVisible();
    const targetRow = screen.getByText("target@example.test").closest("tr")!;
    fireEvent.click(
      within(targetRow).getByRole("button", { name: "Modifier" }),
    );

    const reset = screen.getByRole("button", {
      name: "Désactiver le MFA et révoquer les sessions",
    });
    expect(reset).toBeDisabled();
    fireEvent.change(
      screen.getByLabelText("Votre mot de passe administrateur"),
      {
        target: { value: "StrongPassword123!" },
      },
    );
    fireEvent.change(screen.getByLabelText("Confirmer l’adresse email"), {
      target: { value: "target@example.test" },
    });
    fireEvent.click(reset);

    await waitFor(() =>
      expect(post).toHaveBeenCalledWith("/users/2/mfa/disable", {
        currentPassword: "StrongPassword123!",
        confirmation: "target@example.test",
      }),
    );
    expect(
      await screen.findByText("MFA désactivé et sessions révoquées."),
    ).toBeVisible();
  });
});
