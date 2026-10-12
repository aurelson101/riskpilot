import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
import { api } from "../api/client";
import { SsoSettingsCard } from "./SsoSettingsCard";

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});
it("saves explicit account mappings and keeps the secret outside configuration", async () => {
  vi.spyOn(api, "get").mockImplementation(async (url) => ({
    data:
      url === "/users"
        ? [{ id: 3, email: "user@example.test" }]
        : { items: [] },
  }));
  const post = vi.spyOn(api, "post").mockResolvedValue({ data: { id: 9 } });
  render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <SsoSettingsCard />
    </QueryClientProvider>,
  );
  fireEvent.change(
    screen.getByLabelText("Nom affiché à la connexion", { exact: false }),
    { target: { value: "Entreprise" } },
  );
  fireEvent.change(
    screen.getByLabelText("Identifiant client", { exact: false }),
    { target: { value: "test-client" } },
  );
  fireEvent.change(screen.getByLabelText("Secret client", { exact: false }), {
    target: { value: "test-only-secret" },
  });
  fireEvent.click(screen.getByRole("button", { name: "Associer un compte" }));
  fireEvent.mouseDown(
    screen.getByRole("combobox", { name: /Utilisateur RiskPilot/ }),
  );
  fireEvent.click(
    await screen.findByRole("option", { name: "user@example.test" }),
  );
  fireEvent.change(
    screen.getByLabelText("Identifiant Microsoft (oid)", { exact: false }),
    { target: { value: "11111111-1111-1111-1111-111111111111" } },
  );
  fireEvent.submit(
    screen.getByRole("button", { name: "Enregistrer le SSO" }).closest("form")!,
  );
  await waitFor(() =>
    expect(post).toHaveBeenCalledWith(
      "/v1/integrations",
      expect.objectContaining({
        type: "OIDC",
        enabled: false,
        credential: "test-only-secret",
        configuration: expect.objectContaining({
          accountMappings: [
            { userId: 3, subject: "11111111-1111-1111-1111-111111111111" },
          ],
        }),
      }),
    ),
  );
  const payload = post.mock.calls[0][1] as {
    configuration: Record<string, unknown>;
  };
  expect(payload.configuration).not.toHaveProperty("credential");
  await screen.findByText("Configuration SSO enregistrée.");
  expect(screen.getByLabelText("Secret client", { exact: false })).toHaveValue(
    "",
  );
});
