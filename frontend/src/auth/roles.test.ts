import { describe, expect, it } from "vitest";
import { hasAnyRole } from "./roles";

describe("hasAnyRole", () => {
  it("accepte uniquement un rôle explicitement autorisé", () => {
    expect(hasAnyRole(["ROLE_USER", "ROLE_VIEWER"], ["ROLE_ADMIN"])).toBe(
      false,
    );
    expect(hasAnyRole(["ROLE_USER", "ROLE_ADMIN"], ["ROLE_ADMIN"])).toBe(true);
  });

  it("gère un profil non chargé", () => {
    expect(hasAnyRole(undefined, ["ROLE_ADMIN"])).toBe(false);
  });
});
