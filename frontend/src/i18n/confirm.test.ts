import { afterEach, describe, expect, it, vi } from "vitest";
import { confirmLocalized } from "./confirm";

afterEach(() => vi.restoreAllMocks());

describe("confirmLocalized", () => {
  it("utilise le message de la langue active", () => {
    const confirm = vi.spyOn(window, "confirm").mockReturnValue(true);

    expect(
      confirmLocalized("en", {
        fr: "Supprimer cet élément ?",
        en: "Delete this item?",
      }),
    ).toBe(true);
    expect(confirm).toHaveBeenCalledWith("Delete this item?");
  });
});
