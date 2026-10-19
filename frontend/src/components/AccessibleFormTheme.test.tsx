import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { MenuItem, TextField } from "@mui/material";
import { afterEach, expect, it } from "vitest";
import { AccessibleFormTheme } from "./AccessibleFormTheme";

afterEach(cleanup);

it("opens a selection list and keeps its floating label and outline aligned", async () => {
  const changes: string[] = [];
  const { container } = render(
    <AccessibleFormTheme>
      <TextField
        select
        label="Type de dossier"
        value="ALL"
        onChange={(event) => changes.push(event.target.value)}
      >
        <MenuItem value="ALL">Tous les dossiers</MenuItem>
        <MenuItem value="RISKS">Risques</MenuItem>
      </TextField>
    </AccessibleFormTheme>,
  );
  fireEvent.mouseDown(
    screen.getByRole("combobox", { name: "Type de dossier" }),
  );
  fireEvent.click(await screen.findByRole("option", { name: "Risques" }));
  expect(changes).toEqual(["RISKS"]);
  expect(container.querySelector("label")).toHaveAttribute(
    "data-shrink",
    "true",
  );
  expect(container.querySelector("legend")).toHaveStyle({ maxWidth: "100%" });
});

it.each(["date", "datetime-local"])(
  "keeps an empty %s label above the date segments with an open outline notch",
  (type) => {
    const { container } = render(
      <AccessibleFormTheme>
        <TextField
          type={type}
          label="Date vide"
          value=""
          onChange={() => {}}
          slotProps={{ inputLabel: { shrink: true } }}
        />
      </AccessibleFormTheme>,
    );
    expect(screen.getByLabelText("Date vide")).toHaveValue("");
    expect(container.querySelector("label")).toHaveAttribute(
      "data-shrink",
      "true",
    );
    expect(container.querySelector("legend")).toHaveStyle({ maxWidth: "100%" });
  },
);

it("keeps custom select ARIA names without invalid HTML label associations", () => {
  const { container } = render(
    <AccessibleFormTheme>
      <TextField select label="Provider" value="GOOGLE">
        <MenuItem value="GOOGLE">Google</MenuItem>
      </TextField>
    </AccessibleFormTheme>,
  );
  expect(
    screen.getByRole("combobox", { name: /Provider/ }),
  ).toBeInTheDocument();
  expect(container.querySelector("label")).not.toHaveAttribute("for");
});

it("preserves label associations for text inputs and native selects", () => {
  const { container } = render(
    <AccessibleFormTheme>
      <TextField label="Application" />
      <TextField
        select
        label="Status"
        value="ACTIVE"
        slotProps={{ select: { native: true } }}
      >
        <option value="ACTIVE">Active</option>
      </TextField>
    </AccessibleFormTheme>,
  );
  expect(screen.getByLabelText("Application")).toHaveProperty(
    "tagName",
    "INPUT",
  );
  expect(screen.getByLabelText("Status")).toHaveProperty("tagName", "SELECT");
  container
    .querySelectorAll("label[for]")
    .forEach((label) =>
      expect(document.getElementById(label.getAttribute("for")!)).toBeTruthy(),
    );
});
