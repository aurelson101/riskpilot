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
  monthlyTokenBudget: 500000,
  dailyCallLimit: 200,
  termsAcknowledged: true,
  termsVersion: "2026-11",
  termsAcknowledgedAt: "2026-11-01T10:00:00+00:00",
  models: ["gpt-5-mini", "gpt-4.1-mini", "gpt-4.1"],
  updatedAt: null,
};
const usage = {
  usage: {
    calls: 12,
    succeeded: 10,
    failed: 1,
    refused: 1,
    inputTokens: 900,
    outputTokens: 300,
  },
  monthlyTokenBudget: 500000,
  dailyCallLimit: 200,
  dailyCalls: 3,
  promptVersion: "2026-11-01",
};

function mockGet(current: typeof settings = settings) {
  return vi.spyOn(api, "get").mockImplementation(async (url: string) => ({
    data: url === "/settings/ai/usage" ? usage : current,
  }));
}

function showPanel() {
  mockGet();
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

it("shows the validation message returned when an AI key is rejected", async () => {
  showPanel();
  vi.spyOn(api, "put").mockRejectedValue({
    isAxiosError: true,
    response: { data: { message: "La clé API est invalide." } },
  });
  const save = await screen.findByRole("button", {
    name: "Enregistrer la configuration IA",
  });
  fireEvent.submit(save.closest("form")!);
  expect(
    await screen.findByText("La clé API est invalide."),
  ).toBeInTheDocument();
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
  mockGet(updated);
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

it.each(["Mistral AI", "Google Gemini"])(
  "discards an entered key when switching to %s and disables activation",
  async (provider) => {
    showPanel();
    const key = await screen.findByLabelText(
      "Nouvelle clé API (vide pour conserver)",
    );
    fireEvent.change(key, { target: { value: "test-only-key" } });
    fireEvent.mouseDown(
      screen.getByRole("combobox", { name: "Fournisseur IA" }),
    );
    fireEvent.click(await screen.findByRole("option", { name: provider }));
    expect(screen.getByLabelText("Clé API", { exact: false })).toHaveValue("");
    expect(
      screen.getByRole("switch", {
        name: "Activer le copilote IA pour l’organisation",
      }),
    ).not.toBeChecked();
    expect(
      screen.getByRole("button", { name: "Tester la génération IA" }),
    ).toBeDisabled();
  },
);

it("offers Gemini models while keeping generation disabled for unsaved changes", async () => {
  showPanel();
  await screen.findByRole("button", { name: "Tester la génération IA" });
  fireEvent.mouseDown(screen.getByRole("combobox", { name: "Fournisseur IA" }));
  fireEvent.click(await screen.findByRole("option", { name: "Google Gemini" }));
  expect(screen.getByRole("combobox", { name: "Modèle" })).toHaveTextContent(
    "gemini-3.5-flash-lite",
  );
  fireEvent.mouseDown(screen.getByRole("combobox", { name: "Modèle" }));
  expect(
    await screen.findByRole("option", { name: "gemini-3.1-flash-lite" }),
  ).toBeInTheDocument();
  expect(
    screen.getByRole("option", { name: "gemini-2.5-pro" }),
  ).toBeInTheDocument();
});

it("shows the monthly usage and offers no custom endpoint provider", async () => {
  showPanel();
  expect(
    await screen.findByText(/Ce mois-ci : 12 appel\(s\)/),
  ).toBeInTheDocument();
  fireEvent.mouseDown(screen.getByRole("combobox", { name: "Fournisseur IA" }));
  expect(
    screen.queryByRole("option", { name: "Endpoint compatible" }),
  ).not.toBeInTheDocument();
});

it("blocks activation until the usage terms are acknowledged", async () => {
  mockGet({ ...settings, enabled: false, termsAcknowledged: false });
  render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <AiCopilotSettingsPanel />
    </QueryClientProvider>,
  );
  const activation = await screen.findByRole("switch", {
    name: "Activer le copilote IA pour l’organisation",
  });
  expect(activation).toBeDisabled();
  fireEvent.click(screen.getByRole("switch", { name: /DPA/ }));
  expect(activation).toBeEnabled();
});
