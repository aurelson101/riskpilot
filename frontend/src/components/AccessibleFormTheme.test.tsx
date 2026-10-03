import { cleanup, render, screen } from "@testing-library/react";
import { MenuItem, TextField } from "@mui/material";
import { afterEach, expect, it } from "vitest";
import { AccessibleFormTheme } from "./AccessibleFormTheme";

afterEach(cleanup);

it("keeps custom select ARIA names without invalid HTML label associations", () => {
  const { container } = render(<AccessibleFormTheme><TextField select label="Provider" value="GOOGLE"><MenuItem value="GOOGLE">Google</MenuItem></TextField></AccessibleFormTheme>);
  expect(screen.getByRole("combobox", { name: /Provider/ })).toBeInTheDocument();
  expect(container.querySelector("label")).not.toHaveAttribute("for");
});

it("preserves label associations for text inputs and native selects", () => {
  const { container } = render(<AccessibleFormTheme><TextField label="Application" /><TextField select label="Status" value="ACTIVE" slotProps={{ select: { native: true } }}><option value="ACTIVE">Active</option></TextField></AccessibleFormTheme>);
  expect(screen.getByLabelText("Application")).toHaveProperty("tagName", "INPUT");
  expect(screen.getByLabelText("Status")).toHaveProperty("tagName", "SELECT");
  container.querySelectorAll("label[for]").forEach(label => expect(document.getElementById(label.getAttribute("for")!)).toBeTruthy());
});
