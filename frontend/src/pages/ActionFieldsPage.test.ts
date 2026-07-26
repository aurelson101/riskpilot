import { describe, expect, it } from "vitest";
import { actionFieldKey } from "./actionFieldKey";

describe("actionFieldKey", () => {
  it("builds a valid key from a French label", () => {
    expect(actionFieldKey("Échéance de l’action")).toBe("echeance_de_l_action");
  });

  it("removes an invalid numeric prefix and limits the key length", () => {
    expect(actionFieldKey(`2026 ${"a".repeat(100)}`)).toBe("a".repeat(80));
  });
});
