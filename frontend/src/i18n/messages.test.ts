import { describe, expect, it } from "vitest";
import { translate } from "./messages";

describe("typed translations", () => {
  it("translates a key and interpolates its values", () => {
    expect(
      translate("en", "confirmation.archiveRisk", {
        name: "Supplier access",
      }),
    ).toBe("Archive “Supplier access”?");
  });

  it("provides both interface languages", () => {
    expect(translate("fr", "common.cancel")).toBe("Annuler");
    expect(translate("en", "common.cancel")).toBe("Cancel");
  });
});
