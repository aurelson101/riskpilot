import { describe, expect, it } from "vitest";
import { csvDocument } from "./csv";

describe("csvDocument", () => {
  it("preserves UTF-8, semicolons, quotes, multiline text, zero and missing values", () => {
    expect(
      csvDocument([["Évaluation; annuelle", 'Texte "cité"', "a\nb", 0, null]]),
    ).toBe('\ufeff"Évaluation; annuelle";"Texte ""cité""";"a\nb";"0";""\r\n');
  });
  it.each([
    "=HYPERLINK(1)",
    "+SUM(1)",
    "-1+2",
    "@SUM(1)",
    "  =1",
    "\ttext",
    "\n=1",
  ])("neutralizes spreadsheet formulas: %j", (value) => {
    expect(csvDocument([[value]])).toBe(`\ufeff"'${value}"\r\n`);
  });
});
