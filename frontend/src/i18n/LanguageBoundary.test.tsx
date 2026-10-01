import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { LanguageBoundary } from "./LanguageBoundary";

describe("LanguageBoundary", () => {
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
