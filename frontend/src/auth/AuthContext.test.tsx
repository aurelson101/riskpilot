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
