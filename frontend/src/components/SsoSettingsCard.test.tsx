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

vi.mock("../auth/useAuth", () => ({
  useAuth: () => ({ user: { organization: { id: 1 } } }),
}));
const activeUser = {
  id: 3,
  email: "user@example.test",
  status: "ACTIVE",
  organization: { id: 1 },
};
function renderCard() {
  render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <SsoSettingsCard />
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});
it("saves explicit account mappings and keeps the secret outside configuration", async () => {
  vi.spyOn(api, "get").mockImplementation(async (url) => ({
    data: url === "/users" ? [activeUser] : { items: [] },
  }));
  const post = vi.spyOn(api, "post").mockResolvedValue({ data: { id: 9 } });
  renderCard();
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
  await waitFor(() =>
    expect(
      screen.getByRole("button", { name: "Associer un compte" }),
    ).toBeEnabled(),
  );
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

it("clears provider-specific credentials when switching providers", async () => {
  vi.spyOn(api, "get").mockImplementation(async (url) => ({
    data: url === "/users" ? [activeUser] : { items: [] },
  }));
  renderCard();
  fireEvent.change(
    screen.getByLabelText("Identifiant client", { exact: false }),
    { target: { value: "entra-client" } },
  );
  fireEvent.change(screen.getByLabelText("Secret client", { exact: false }), {
    target: { value: "test-only-entra-secret" },
  });
  await waitFor(() =>
    expect(
      screen.getByRole("button", { name: "Associer un compte" }),
    ).toBeEnabled(),
  );
  fireEvent.click(screen.getByRole("button", { name: "Associer un compte" }));
  fireEvent.mouseDown(
    screen.getByRole("combobox", { name: "Fournisseur SSO" }),
  );
  fireEvent.click(
    await screen.findByRole("option", { name: "Google Workspace" }),
  );
  expect(
    screen.getByLabelText("Identifiant client", { exact: false }),
  ).toHaveValue("");
  expect(screen.getByLabelText("Secret client", { exact: false })).toHaveValue(
    "",
  );
  expect(
    screen.getByLabelText("URL de l’émetteur SSO", { exact: false }),
  ).toHaveValue("https://accounts.google.com");
  expect(
    screen.queryByRole("combobox", { name: /Utilisateur RiskPilot/ }),
  ).not.toBeInTheDocument();
  expect(screen.getByLabelText("Activer la connexion SSO")).not.toBeChecked();
});

it("offers only active users in the current organization", async () => {
  vi.spyOn(api, "get").mockImplementation(async (url) => ({
    data:
      url === "/users"
        ? [
            activeUser,
            {
              ...activeUser,
              id: 4,
              email: "foreign@example.test",
              organization: { id: 2 },
            },
            {
              ...activeUser,
              id: 5,
              email: "disabled@example.test",
              status: "DISABLED",
            },
          ]
        : { items: [] },
  }));
  renderCard();
  await waitFor(() =>
    expect(
      screen.getByRole("button", { name: "Associer un compte" }),
    ).toBeEnabled(),
  );
  fireEvent.click(screen.getByRole("button", { name: "Associer un compte" }));
  fireEvent.mouseDown(
    screen.getByRole("combobox", { name: /Utilisateur RiskPilot/ }),
  );
  expect(
    await screen.findByRole("option", { name: "user@example.test" }),
  ).toBeVisible();
  expect(
    screen.queryByRole("option", { name: "foreign@example.test" }),
  ).not.toBeInTheDocument();
  expect(
    screen.queryByRole("option", { name: "disabled@example.test" }),
  ).not.toBeInTheDocument();
});

it("prevents duplicate users and provider subjects before saving", async () => {
  vi.spyOn(api, "get").mockImplementation(async (url) => ({
    data:
      url === "/users"
        ? [activeUser, { ...activeUser, id: 6, email: "second@example.test" }]
        : { items: [] },
  }));
  const post = vi.spyOn(api, "post");
  renderCard();
  await waitFor(() =>
    expect(
      screen.getByRole("button", { name: "Associer un compte" }),
    ).toBeEnabled(),
  );
  fireEvent.click(screen.getByRole("button", { name: "Associer un compte" }));
  fireEvent.mouseDown(
    screen.getByRole("combobox", { name: /Utilisateur RiskPilot/ }),
  );
  fireEvent.click(
    await screen.findByRole("option", { name: "user@example.test" }),
  );
  fireEvent.click(screen.getByRole("button", { name: "Associer un compte" }));
  fireEvent.mouseDown(
    screen.getAllByRole("combobox", { name: /Utilisateur RiskPilot/ })[1],
  );
  expect(
    await screen.findByRole("option", { name: "user@example.test" }),
  ).toHaveAttribute("aria-disabled", "true");
  fireEvent.click(screen.getByRole("option", { name: "second@example.test" }));
  for (const input of screen.getAllByLabelText("Identifiant Microsoft (oid)", {
    exact: false,
  }))
    fireEvent.change(input, { target: { value: "same-subject" } });
  expect(
    screen.getAllByText("Cet identifiant est déjà associé à un autre compte."),
  ).toHaveLength(2);
  fireEvent.submit(
    screen.getByRole("button", { name: "Enregistrer le SSO" }).closest("form")!,
  );
  expect(await screen.findByText(/Vérifiez les comptes/)).toBeVisible();
  expect(post).not.toHaveBeenCalled();
});

it("requires a fresh secret when editing the client identity", async () => {
  vi.spyOn(api, "get").mockImplementation(async (url) => ({
    data:
      url === "/users"
        ? [activeUser]
        : {
            items: [
              {
                id: 8,
                type: "OIDC",
                name: "Google Entreprise",
                provider: "GOOGLE_WORKSPACE",
                enabled: false,
                configuration: {
                  issuer: "https://accounts.google.com",
                  clientId: "old-client",
                  accountMappings: [],
                },
              },
            ],
          },
  }));
  const put = vi.spyOn(api, "put").mockResolvedValue({ data: { id: 8 } });
  renderCard();
  fireEvent.click(await screen.findByRole("button", { name: "Configurer" }));
  fireEvent.change(
    screen.getByLabelText("Identifiant client", { exact: false }),
    { target: { value: "new-client" } },
  );
  expect(
    screen.getByLabelText("Secret client", { exact: false }),
  ).toBeRequired();
  fireEvent.submit(
    screen.getByRole("button", { name: "Enregistrer le SSO" }).closest("form")!,
  );
  expect(
    await screen.findByText(
      "Saisissez le secret client correspondant à cette configuration SSO.",
    ),
  ).toBeVisible();
  expect(put).not.toHaveBeenCalled();
  fireEvent.change(screen.getByLabelText("Secret client", { exact: false }), {
    target: { value: "test-only-new-secret" },
  });
  fireEvent.submit(
    screen.getByRole("button", { name: "Enregistrer le SSO" }).closest("form")!,
  );
  await waitFor(() =>
    expect(put).toHaveBeenCalledWith(
      "/v1/integrations/8",
      expect.objectContaining({
        credential: "test-only-new-secret",
        configuration: expect.objectContaining({ clientId: "new-client" }),
      }),
    ),
  );
});
