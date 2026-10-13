import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  act,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { api } from "../api/client";
import { LoginPage } from "./LoginPage";

const auth = vi.hoisted(() => ({
  token: null,
  login: vi.fn(),
  loginSso: vi.fn(),
}));
vi.mock("../auth/useAuth", () => ({ useAuth: () => auth }));
afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  auth.loginSso.mockReset();
  window.history.replaceState(null, "", "/login");
});
function show() {
  render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <MemoryRouter>
        <LoginPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}
describe("SSO login", () => {
  it("shows only configured provider links", async () => {
    vi.spyOn(api, "get").mockResolvedValue({
      data: {
        items: [
          { id: 7, name: "Microsoft Entreprise", provider: "MICROSOFT_ENTRA" },
        ],
      },
    });
    show();
    expect(
      await screen.findByRole("link", {
        name: "Se connecter avec Microsoft Entreprise",
      }),
    ).toHaveAttribute("href", "/api/auth/sso/7/start");
    expect(auth.loginSso).not.toHaveBeenCalled();
  });
  it("completes the browser handoff once and requests local MFA", async () => {
    window.history.replaceState(null, "", "/login?sso=complete");
    vi.spyOn(api, "get").mockResolvedValue({ data: { items: [] } });
    auth.loginSso.mockResolvedValueOnce(true).mockResolvedValueOnce(false);
    show();
    const input = await screen.findByLabelText("Code MFA ou code de secours", {
      exact: false,
    });
    expect(auth.loginSso).toHaveBeenCalledTimes(1);
    expect(window.location.search).toBe("");
    expect(screen.queryByLabelText("Mot de passe")).not.toBeInTheDocument();
    fireEvent.change(input, { target: { value: "123456" } });
    fireEvent.submit(input.closest("form")!);
    await waitFor(() =>
      expect(auth.loginSso).toHaveBeenLastCalledWith("123456"),
    );
  });
  it("reports a failed callback and retains password login", async () => {
    window.history.replaceState(null, "", "/login?sso=error");
    vi.spyOn(api, "get").mockResolvedValue({ data: { items: [] } });
    show();
    expect(await screen.findByText(/Connexion SSO impossible/)).toBeVisible();
    expect(screen.getByLabelText("Mot de passe")).toBeVisible();
    expect(auth.loginSso).not.toHaveBeenCalled();
  });
  it("locks MFA submissions and the return button during the request", async () => {
    window.history.replaceState(null, "", "/login?sso=complete");
    vi.spyOn(api, "get").mockResolvedValue({ data: { items: [] } });
    let finish!: (value: boolean) => void;
    auth.loginSso.mockResolvedValueOnce(true).mockImplementationOnce(
      () =>
        new Promise<boolean>((resolve) => {
          finish = resolve;
        }),
    );
    show();
    const input = await screen.findByLabelText("Code MFA ou code de secours", {
      exact: false,
    });
    await waitFor(() =>
      expect(
        screen.getByRole("button", { name: "Valider le MFA" }),
      ).toBeEnabled(),
    );
    fireEvent.change(input, { target: { value: "123456" } });
    fireEvent.submit(input.closest("form")!);
    fireEvent.submit(input.closest("form")!);
    expect(auth.loginSso).toHaveBeenCalledTimes(2);
    expect(
      screen.getByRole("button", { name: "Valider le MFA" }),
    ).toBeDisabled();
    expect(
      screen.getByRole("button", { name: "Retour à la connexion" }),
    ).toBeDisabled();
    await act(async () => finish(true));
    expect(
      screen.getByRole("button", { name: "Valider le MFA" }),
    ).toBeEnabled();
  });
  it.each([
    "INVALID_MFA_CODE",
    "SSO_SESSION_INVALID",
    "SSO_MFA_ATTEMPTS_EXCEEDED",
  ])("handles %s without trapping the user", async (code) => {
    window.history.replaceState(null, "", "/login?sso=complete");
    vi.spyOn(api, "get").mockResolvedValue({ data: { items: [] } });
    auth.loginSso
      .mockResolvedValueOnce(true)
      .mockRejectedValueOnce({
        isAxiosError: true,
        response: { status: 401, data: { code, message: "Test SSO failure" } },
      });
    show();
    const input = await screen.findByLabelText("Code MFA ou code de secours", {
      exact: false,
    });
    await waitFor(() =>
      expect(
        screen.getByRole("button", { name: "Valider le MFA" }),
      ).toBeEnabled(),
    );
    fireEvent.change(input, { target: { value: "123456" } });
    fireEvent.submit(input.closest("form")!);
    expect(await screen.findByText("Test SSO failure")).toBeVisible();
    if (code === "INVALID_MFA_CODE") expect(input).toBeVisible();
    else expect(screen.getByLabelText("Mot de passe")).toBeVisible();
  });
  it("distinguishes provider cancellation from failure", async () => {
    window.history.replaceState(null, "", "/login?sso=cancelled");
    vi.spyOn(api, "get").mockResolvedValue({ data: { items: [] } });
    show();
    expect(await screen.findByText(/Connexion SSO annulée/)).toBeVisible();
    expect(screen.getByLabelText("Mot de passe")).toBeVisible();
    expect(auth.loginSso).not.toHaveBeenCalled();
    expect(window.location.search).toBe("");
  });
});
