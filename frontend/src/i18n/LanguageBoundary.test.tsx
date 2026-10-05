import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { LanguageBoundary } from "./LanguageBoundary";

afterEach(cleanup);

describe("LanguageBoundary", () => {
  it.each(["fr", "en"] as const)(
    "preserves framework names within %s text and accessible labels",
    (locale) => {
      const label = `${locale === "fr" ? "Supprimer la correspondance" : "Delete mapping"} EBIOS Risk Manager · 2018`;
      render(
        <LanguageBoundary locale={locale}>
          <span>EBIOS Risk Manager · 2018</span>
          <button aria-label={label}>EBIOS Risk Manager</button>
        </LanguageBoundary>,
      );
      expect(screen.getByText("EBIOS Risk Manager · 2018")).toBeVisible();
      expect(screen.getByRole("button", { name: label })).toBeVisible();
    },
  );
  it("preserves a native French copy label and translates it when switching language", () => {
    const { rerender } = render(
      <LanguageBoundary locale="fr">
        <button>Copier la clé</button>
      </LanguageBoundary>,
    );
    expect(screen.getByRole("button", { name: "Copier la clé" })).toBeVisible();
    rerender(
      <LanguageBoundary locale="en">
        <button>Copier la clé</button>
      </LanguageBoundary>,
    );
    expect(screen.getByRole("button", { name: "Copy key" })).toBeVisible();
    rerender(
      <LanguageBoundary locale="fr">
        <button>Copier la clé</button>
      </LanguageBoundary>,
    );
    expect(screen.getByRole("button", { name: "Copier la clé" })).toBeVisible();
  });
});
