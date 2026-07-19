import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import App from "./App";

describe("App", () => {
  it("affiche le socle RiskPilot", () => {
    render(<App />);

    expect(
      screen.getByRole("heading", { name: "RiskPilot" }),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Socle technique opérationnel"),
    ).toBeInTheDocument();
  });
});
