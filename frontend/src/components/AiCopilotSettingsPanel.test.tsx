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
import { AiCopilotSettingsPanel } from "./AiCopilotSettingsPanel";

const settings = {
  provider: "OPENAI",
  baseUrl: "https://api.openai.com/v1",
  model: "gpt-5-mini",
  apiKeyConfigured: true,
  dataPolicy: "MINIMAL",
  systemPrompt: "",
  enabled: true,
  updatedAt: null,
};

function showPanel() {
  vi.spyOn(api, "get").mockResolvedValue({ data: settings });
  render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <AiCopilotSettingsPanel />
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

it("clears a previous generation result after saving a different model", async () => {
  showPanel();
  vi.spyOn(api, "post").mockResolvedValue({ data: { message: "OK" } });
  const testButton = await screen.findByRole("button", {
    name: "Tester la génération IA",
  });
  fireEvent.click(testButton);
  await screen.findByText("Connexion et génération IA validées.");
  fireEvent.mouseDown(screen.getByRole("combobox", { name: "Modèle" }));
  fireEvent.click(await screen.findByRole("option", { name: "gpt-4.1-mini" }));
  const updated = { ...settings, model: "gpt-4.1-mini" };
  vi.mocked(api.get).mockResolvedValue({ data: updated });
  vi.spyOn(api, "put").mockResolvedValue({ data: updated });
  fireEvent.submit(
    screen
      .getByRole("button", { name: "Enregistrer la configuration IA" })
      .closest("form")!,
  );
  await screen.findByText("Configuration IA enregistrée.");
  await waitFor(() => expect(testButton).toBeEnabled());
  expect(
    screen.queryByText("Connexion et génération IA validées."),
  ).not.toBeInTheDocument();
});

it("requires saving a changed model before testing generation", async () => {
  showPanel();
  const testButton = await screen.findByRole("button", {
    name: "Tester la génération IA",
  });
  expect(testButton).toBeEnabled();
  fireEvent.mouseDown(screen.getByRole("combobox", { name: "Modèle" }));
  fireEvent.click(await screen.findByRole("option", { name: "gpt-4.1-mini" }));
  expect(testButton).toBeDisabled();
});

it("discards an entered key when switching providers and disables activation", async () => {
  showPanel();
  const key = await screen.findByLabelText(
    "Nouvelle clé API (vide pour conserver)",
  );
  fireEvent.change(key, { target: { value: "test-only-key" } });
  fireEvent.mouseDown(screen.getByRole("combobox", { name: "Fournisseur IA" }));
  fireEvent.click(await screen.findByRole("option", { name: "Mistral AI" }));
  expect(screen.getByLabelText("Clé API", { exact: false })).toHaveValue("");
  expect(
    screen.getByRole("switch", {
      name: "Activer le copilote IA pour l’organisation",
    }),
  ).not.toBeChecked();
  expect(
    screen.getByRole("button", { name: "Tester la génération IA" }),
  ).toBeDisabled();
});
