import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
import { api } from "../../api/client";
import type { ComplianceAssessment } from "../../api/types";
import { TisaxPanel } from "./TisaxPanel";
import { isTisaxAssessment } from "./tisax";

const assessment = {
  id: 12,
  framework: { id: 4, name: "TISAX / VDA ISA", version: "6" },
  scope: { id: 2, name: "Site" },
  assessor: { id: 9, email: "a@example.test", firstName: "A", lastName: "B" },
  assessmentDate: "2026-11-01",
  status: "IN_PROGRESS",
  globalScore: 0,
  resultCount: 21,
  tisax: { level: null, objectives: [], siteScope: null, participantId: null },
} as ComplianceAssessment;

const summary = {
  declarationComplete: false,
  defaultTarget: 3,
  ready: false,
  totals: { total: 21, assessed: 1, meets: 0, blocking: 1, incomplete: 20 },
  modules: [
    {
      name: "Sécurité de l’information",
      total: 15,
      assessed: 1,
      meets: 0,
      blocking: 1,
      incomplete: 14,
      gaps: [
        {
          resultId: 1,
          reference: "1.1",
          title: "Politiques",
          maturity: 2,
          target: 3,
        },
      ],
    },
  ],
};

function show(canEdit = true) {
  vi.spyOn(api, "get").mockResolvedValue({ data: summary });
  render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <TisaxPanel assessment={assessment} canEdit={canEdit} />
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

it("only applies to TISAX frameworks", () => {
  expect(isTisaxAssessment(assessment)).toBe(true);
  expect(
    isTisaxAssessment({
      ...assessment,
      framework: { id: 1, name: "ISO/IEC 27001", version: "2022" },
    }),
  ).toBe(false);
});

it("shows the maturity gaps per module and the incomplete declaration", async () => {
  show();
  expect(
    await screen.findByText(
      /1 écart\(s\) de maturité et 20 exigence\(s\) non évaluée\(s\)/,
    ),
  ).toBeInTheDocument();
  expect(
    screen.getByText(
      /la déclaration \(niveau, objectifs, périmètre\) est incomplète/,
    ),
  ).toBeInTheDocument();
  expect(screen.getByText("1.1 2/3")).toBeInTheDocument();
});

it("saves the AL3 declaration with the assessment identity", async () => {
  show();
  const put = vi.spyOn(api, "put").mockResolvedValue({ data: {} });
  await screen.findByText(/écart\(s\) de maturité/);
  fireEvent.mouseDown(
    screen.getByRole("combobox", { name: "Niveau d’évaluation" }),
  );
  fireEvent.click(await screen.findByRole("option", { name: /AL3/ }));
  fireEvent.click(
    screen.getByRole("checkbox", { name: "Information : protection élevée" }),
  );
  fireEvent.change(screen.getByLabelText("Périmètre et sites"), {
    target: { value: "Site de Stuttgart" },
  });
  fireEvent.change(screen.getByLabelText("Identifiant de participant ENX"), {
    target: { value: "ABC123" },
  });
  fireEvent.click(
    screen.getByRole("button", { name: "Enregistrer la déclaration TISAX" }),
  );
  await waitFor(() =>
    expect(put).toHaveBeenCalledWith("/compliance-assessments/12", {
      frameworkId: 4,
      scopeId: 2,
      assessorId: 9,
      assessmentDate: "2026-11-01",
      status: "IN_PROGRESS",
      tisaxLevel: "AL3",
      tisaxObjectives: ["INFO_HIGH"],
      siteScope: "Site de Stuttgart",
      participantId: "ABC123",
    }),
  );
});

it("hides the save button when the assessment is read-only", async () => {
  show(false);
  await screen.findByText(/écart\(s\) de maturité/);
  expect(
    screen.queryByRole("button", { name: "Enregistrer la déclaration TISAX" }),
  ).not.toBeInTheDocument();
});
