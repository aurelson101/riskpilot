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
import { EmailSettingsPage } from "./EmailSettingsPage";

const settings = {
  provider: "MICROSOFT_365",
  host: "",
  port: 587,
  encryption: "tls",
  username: "",
  passwordConfigured: false,
  senderEmail: "admin@example.test",
  senderName: "RiskPilot",
  replyTo: null,
  enabled: true,
  oauthClientId: "test-client",
  oauthClientSecretConfigured: true,
  oauthTenant: "organizations",
  oauthConnected: true,
  connectedEmail: "admin@example.test",
  ready: true,
};
function showPage() {
  vi.spyOn(api, "get").mockResolvedValue({ data: settings });
  render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <EmailSettingsPage />
    </QueryClientProvider>,
  );
}
afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

it("shows a failed disconnect without discarding the connected account", async () => {
  vi.spyOn(api, "post").mockRejectedValue(new Error("offline"));
  showPage();
  fireEvent.click(await screen.findByRole("button", { name: "Déconnecter" }));
  await screen.findByText("Déconnexion OAuth impossible.");
  expect(
    screen.getByText("Compte connecté : admin@example.test"),
  ).toBeInTheDocument();
  expect(screen.getByRole("button", { name: "Déconnecter" })).toBeEnabled();
});

it("blocks competing requests while disconnect is pending", async () => {
  let finish!: (result: { data: null }) => void;
  const post = vi.spyOn(api, "post").mockImplementation(
    () =>
      new Promise((resolve) => {
        finish = resolve;
      }),
  );
  showPage();
  const disconnect = await screen.findByRole("button", { name: "Déconnecter" });
  fireEvent.click(disconnect);
  expect(disconnect).toBeDisabled();
  expect(
    screen.getByRole("button", { name: "Reconnecter le compte" }),
  ).toBeDisabled();
  expect(
    screen.getByRole("button", { name: "Enregistrer les identifiants OAuth" }),
  ).toBeDisabled();
  fireEvent.click(disconnect);
  expect(post).toHaveBeenCalledTimes(1);
  fireEvent.change(screen.getByLabelText("Client ID OAuth", { exact: false }), {
    target: { value: "edited-during-disconnect" },
  });
  finish({ data: null });
  await screen.findByText("Compte OAuth déconnecté.");
  await waitFor(() =>
    expect(
      screen.queryByText("Compte connecté : admin@example.test"),
    ).not.toBeInTheDocument(),
  );
  expect(
    screen.getByLabelText("Client ID OAuth", { exact: false }),
  ).toHaveValue("edited-during-disconnect");
});

it("preserves unsaved edits by disabling disconnect until they are saved", async () => {
  showPage();
  const disconnect = await screen.findByRole("button", { name: "Déconnecter" });
  fireEvent.change(screen.getByLabelText("Client ID OAuth", { exact: false }), {
    target: { value: "another-client" },
  });
  expect(disconnect).toBeDisabled();
});
