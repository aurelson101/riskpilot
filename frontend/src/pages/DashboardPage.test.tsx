import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { api } from "../api/client";
import { DashboardPage } from "./DashboardPage";

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe("DashboardPage exports", () => {
  it("télécharge le classeur Excel authentifié depuis le bouton Actions", async () => {
    const get = vi.spyOn(api, "get");
    get.mockResolvedValueOnce({
      data: {
        summary: {
          totalRisks: 1,
          criticalRisks: 0,
          highRisks: 1,
          overdueActions: 0,
          dueActions: 1,
          globalCompliance: 80,
        },
        riskLevels: { LOW: 0, MODERATE: 0, HIGH: 1, CRITICAL: 0 },
        actionStatuses: { OPEN: 1 },
        complianceByFramework: { NIS2: 80 },
        topRisks: [],
        dueActions: [],
      },
    });
    get.mockResolvedValueOnce({
      data: new Blob(["xlsx"]),
      headers: {
        "content-disposition": 'attachment; filename="actions-2026-08-23.xlsx"',
      },
    });
    vi.stubGlobal("URL", {
      createObjectURL: vi.fn(() => "blob:export"),
      revokeObjectURL: vi.fn(),
    });
    const click = vi
      .spyOn(HTMLAnchorElement.prototype, "click")
      .mockImplementation(() => undefined);

    render(
      <QueryClientProvider client={new QueryClient()}>
        <MemoryRouter>
          <DashboardPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click(
      await screen.findByRole("button", { name: "Actions Excel" }),
    );
    await waitFor(() =>
      expect(get).toHaveBeenCalledWith("/exports/actions.xlsx", {
        responseType: "blob",
      }),
    );
    expect(click).toHaveBeenCalledOnce();
    expect(
      screen.queryByText(/n’a pas pu être généré/),
    ).not.toBeInTheDocument();
  });
});
