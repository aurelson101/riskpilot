import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { api } from "../api/client";
import { IntegrationSettingsPage } from "./IntegrationSettingsPage";

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
      <MemoryRouter>
        <IntegrationSettingsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

function mockLoads() {
  return vi.spyOn(api, "get").mockImplementation(async (url) => ({
    data:
      url === "/v1/integrations"
        ? { items: [] }
        : { provider: "CUSTOM", enabled: false, ready: false },
  }));
}

describe("IntegrationSettingsPage", () => {
  it.each(["tested directory", "another directory", "failed deletion"])(
    "keeps the LDAPS result associated with its directory after deleting %s",
    async (operation) => {
      let items = [1, 2].map((id) => ({
        id,
        name: `Directory ${id}`,
        type: "DIRECTORY",
        provider: "GENERIC",
        configuration: {},
        credentialConfigured: true,
        enabled: false,
      }));
      vi.spyOn(api, "get").mockImplementation(async (url) => ({
        data:
          url === "/v1/integrations"
            ? { items }
            : { provider: "CUSTOM", enabled: false, ready: false },
      }));
      vi.spyOn(api, "post").mockResolvedValue({ data: { matchedEntries: 1 } });
      vi.spyOn(window, "confirm").mockReturnValue(true);
      vi.spyOn(api, "delete").mockImplementation(async (url) => {
        if (operation === "failed deletion") throw new Error("Network failure");
        items = items.filter((item) => url !== `/v1/integrations/${item.id}`);
        return { data: {} };
      });
      renderPage();
      const card = (name: string) =>
        within(screen.getByText(name).closest(".MuiCard-root") as HTMLElement);
      await screen.findByText("Directory 1");
      fireEvent.click(
        card("Directory 1").getByRole("button", { name: "Tester LDAPS" }),
      );
      const result = /LDAPS validé — Directory 1/;
      expect(await screen.findByText(result)).toBeVisible();
      const target =
        operation === "another directory" ? "Directory 2" : "Directory 1";
      await waitFor(() =>
        expect(
          card(target).getByRole("button", { name: "Supprimer" }),
        ).toBeEnabled(),
      );
      fireEvent.click(card(target).getByRole("button", { name: "Supprimer" }));
      if (operation === "failed deletion") {
        expect(await screen.findByText("L’opération a échoué.")).toBeVisible();
      } else {
        await waitFor(() =>
          expect(screen.queryByText(target)).not.toBeInTheDocument(),
        );
      }
      if (operation === "tested directory") {
        expect(screen.queryByText(result)).not.toBeInTheDocument();
      } else {
        expect(screen.getByText(result)).toBeVisible();
      }
    },
  );

  it.each(["failed creation", "successful OIDC diagnostic"])(
    "preserves an unacknowledged API key after %s",
    async (operation) => {
      mockLoads();
      const post = vi.spyOn(api, "post").mockResolvedValueOnce({
        data: { id: 123, secret: "test-only-unacknowledged-key" },
      });
      renderPage();
      fireEvent.change(screen.getByLabelText(/Nom de l’application/), {
        target: { value: "Test application" },
      });
      fireEvent.click(screen.getByRole("button", { name: "Créer la clé API" }));
      expect(
        await screen.findByText("test-only-unacknowledged-key"),
      ).toBeVisible();
      await waitFor(() =>
        expect(
          screen.getByRole("button", { name: "Créer la clé API" }),
        ).toBeEnabled(),
      );

      if (operation === "failed creation") {
        post.mockRejectedValueOnce(new Error("Network failure"));
        fireEvent.change(screen.getByLabelText(/Nom de l’application/), {
          target: { value: "Second application" },
        });
        fireEvent.click(
          screen.getByRole("button", { name: "Créer la clé API" }),
        );
        expect(await screen.findByText("L’opération a échoué.")).toBeVisible();
      } else {
        post.mockResolvedValueOnce({
          data: {
            validated: true,
            issuer: "https://accounts.google.com",
            authorizationCode: true,
            pkceS256: true,
            signingAlgorithms: ["RS256"],
          },
        });
        fireEvent.mouseDown(screen.getByLabelText("Usage"));
        fireEvent.click(
          await screen.findByRole("option", {
            name: "Diagnostic SSO — découverte OIDC",
          }),
        );
        fireEvent.click(
          screen.getByRole("button", { name: "Vérifier la découverte OIDC" }),
        );
        expect(
          await screen.findByText(/Découverte OIDC validée pour/),
        ).toBeVisible();
      }
      expect(screen.getByText("test-only-unacknowledged-key")).toBeVisible();
      fireEvent.click(
        screen.getByRole("button", { name: "J’ai conservé la clé" }),
      );
      expect(
        screen.queryByText("test-only-unacknowledged-key"),
      ).not.toBeInTheDocument();
    },
  );

  it("reports failed loads and retries the selected query", async () => {
    const get = vi
      .spyOn(api, "get")
      .mockRejectedValue(new Error("Network failure"));
    renderPage();
    expect(
      await screen.findByText("Impossible de charger les accès configurés."),
    ).toBeVisible();
    expect(
      await screen.findByText("Impossible de charger l’état de la messagerie."),
    ).toBeVisible();
    get.mockResolvedValue({ data: { items: [] } });
    fireEvent.click(screen.getAllByRole("button", { name: "Réessayer" })[0]);
    expect(
      await screen.findByText("Aucun accès technique configuré."),
    ).toBeVisible();
    expect(
      screen.queryByText("Impossible de charger les accès configurés."),
    ).not.toBeInTheDocument();
    expect(
      screen.getByText("Impossible de charger l’état de la messagerie."),
    ).toBeVisible();
  });

  it("clears a successful discovery when its issuer changes", async () => {
    mockLoads();
    vi.spyOn(api, "post").mockResolvedValue({
      data: {
        validated: true,
        issuer: "https://accounts.google.com",
        authorizationCode: true,
        pkceS256: true,
        signingAlgorithms: ["RS256"],
      },
    });
    renderPage();
    fireEvent.mouseDown(screen.getByLabelText("Usage"));
    fireEvent.click(
      await screen.findByRole("option", {
        name: "Diagnostic SSO — découverte OIDC",
      }),
    );
    fireEvent.click(
      screen.getByRole("button", { name: "Vérifier la découverte OIDC" }),
    );
    expect(
      await screen.findByText(/Découverte OIDC validée pour/),
    ).toBeVisible();
    fireEvent.change(screen.getByLabelText("Émetteur OIDC", { exact: false }), {
      target: { value: "https://different.example" },
    });
    expect(
      screen.queryByText(/Découverte OIDC validée pour/),
    ).not.toBeInTheDocument();
  });

  it("prevents editing the form during an in-flight diagnostic", async () => {
    mockLoads();
    let complete = (_value: unknown) => {};
    const post = vi.spyOn(api, "post").mockImplementation(
      () =>
        new Promise((resolve) => {
          complete = resolve;
        }),
    );
    renderPage();
    fireEvent.mouseDown(screen.getByLabelText("Usage"));
    fireEvent.click(
      await screen.findByRole("option", {
        name: "Diagnostic SSO — découverte OIDC",
      }),
    );
    fireEvent.click(
      screen.getByRole("button", { name: "Vérifier la découverte OIDC" }),
    );
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    expect(screen.getByRole("combobox", { name: "Usage" })).toHaveAttribute(
      "aria-disabled",
      "true",
    );
    expect(
      screen.getByRole("combobox", { name: "Fournisseur d’identité" }),
    ).toHaveAttribute("aria-disabled", "true");
    expect(
      screen.getByLabelText("Émetteur OIDC", { exact: false }),
    ).toBeDisabled();
    complete({
      data: {
        validated: true,
        issuer: "https://accounts.google.com",
        authorizationCode: true,
        pkceS256: true,
        signingAlgorithms: ["RS256"],
      },
    });
    await waitFor(() =>
      expect(
        screen.getByLabelText("Émetteur OIDC", { exact: false }),
      ).toBeEnabled(),
    );
  });
});
