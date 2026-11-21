import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { AxiosError, AxiosHeaders } from "axios";
import { afterEach, expect, it, vi } from "vitest";
import { api, endSession, TOKEN_STORAGE_KEY } from "../api/client";
import { AuthProvider } from "./AuthContext";
import { useAuth } from "./useAuth";

afterEach(() => {
  cleanup();
  endSession();
  vi.restoreAllMocks();
});
function Session() {
  const auth = useAuth();
  return (
    <>
      <span>{auth.token ?? "logged-out"}</span>
      <button onClick={() => void auth.login("new@example.test", "test")}>
        Change account
      </button>
      <button onClick={() => void auth.loginSso?.()}>SSO login</button>
      <button onClick={() => auth.logout()}>Log out</button>
    </>
  );
}
function show(client: QueryClient) {
  render(
    <QueryClientProvider client={client}>
      <AuthProvider>
        <Session />
      </AuthProvider>
    </QueryClientProvider>,
  );
}
it("keeps the session during a temporary profile service failure", async () => {
  sessionStorage.setItem(TOKEN_STORAGE_KEY, "old.token.value");
  vi.spyOn(api, "get").mockRejectedValue(
    new AxiosError("temporary", "ERR_BAD_RESPONSE", undefined, undefined, {
      status: 503,
      statusText: "Unavailable",
      headers: {},
      config: { headers: new AxiosHeaders() },
      data: {},
    }),
  );
  const post = vi.spyOn(api, "post");
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  show(client);
  await waitFor(() =>
    expect(client.getQueryState(["me"])?.status).toBe("error"),
  );
  expect(screen.getByText("old.token.value")).toBeVisible();
  expect(post).not.toHaveBeenCalled();
});
it("removes cached business data when changing accounts", async () => {
  sessionStorage.setItem(TOKEN_STORAGE_KEY, "old.token.value");
  vi.spyOn(api, "get").mockResolvedValue({ data: { id: 1 } });
  vi.spyOn(api, "post").mockResolvedValue({
    status: 200,
    data: { token: "new.token.value" },
  });
  const client = new QueryClient();
  client.setQueryData(["risks"], ["old organization"]);
  show(client);
  fireEvent.click(screen.getByRole("button", { name: "Change account" }));
  await screen.findByText("new.token.value");
  expect(client.getQueryData(["risks"])).toBeUndefined();
});
it("also ends the identity provider session on a voluntary logout after an SSO login", async () => {
  vi.spyOn(api, "get").mockResolvedValue({ data: { id: 1 } });
  const post = vi.spyOn(api, "post").mockImplementation(async (url: string) =>
    url === "/auth/sso/complete"
      ? {
          status: 200,
          data: {
            token: "sso.token.value",
            sso: {
              logoutUrl: "https://sso.example.test/logout?id_token_hint=x",
            },
          },
        }
      : { status: 204, data: null },
  );
  const assign = vi.fn();
  vi.stubGlobal("location", { ...window.location, assign });
  show(new QueryClient());
  fireEvent.click(screen.getByRole("button", { name: "SSO login" }));
  await screen.findByText("sso.token.value");
  fireEvent.click(screen.getByRole("button", { name: "Log out" }));
  await waitFor(() =>
    expect(assign).toHaveBeenCalledWith(
      "https://sso.example.test/logout?id_token_hint=x",
    ),
  );
  expect(post).toHaveBeenCalledWith("/auth/logout");
  expect(sessionStorage.getItem("riskpilot.sso.logoutUrl")).toBeNull();
  vi.unstubAllGlobals();
});
it("never redirects to a non-HTTPS provider logout address", async () => {
  vi.spyOn(api, "get").mockResolvedValue({ data: { id: 1 } });
  vi.spyOn(api, "post").mockImplementation(async (url: string) =>
    url === "/auth/sso/complete"
      ? {
          status: 200,
          data: {
            token: "sso.token.value",
            sso: { logoutUrl: "javascript:alert(1)" },
          },
        }
      : { status: 204, data: null },
  );
  const assign = vi.fn();
  vi.stubGlobal("location", { ...window.location, assign });
  show(new QueryClient());
  fireEvent.click(screen.getByRole("button", { name: "SSO login" }));
  await screen.findByText("sso.token.value");
  fireEvent.click(screen.getByRole("button", { name: "Log out" }));
  await screen.findByText("logged-out");
  expect(assign).not.toHaveBeenCalled();
  vi.unstubAllGlobals();
});
