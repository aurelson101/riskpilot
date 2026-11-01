import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { afterEach, expect, it, vi } from "vitest";
import { api } from "../../api/client";
import { Nis2OperationsPanel } from "./Nis2OperationsPanel";

const role = vi.hoisted(() => ({ roles: ["ROLE_ADMIN"] as string[] }));
vi.mock("../../auth/useAuth", () => ({
  useAuth: () => ({ user: { id: 1, roles: role.roles } }),
}));

const overview = {
  entity: { type: null, sector: null, authority: null },
  summary: { significantOpen: 1, overdue: 1, dueSoon: 0, criticalSuppliers: 1 },
  incidents: [
    {
      id: 7,
      title: "Rançongiciel",
      severity: "CRITICAL",
      status: "DETECTED",
      category: "SECURITY",
      significant: true,
      detectedAt: "2026-11-01T08:00:00+00:00",
      worstStatus: "OVERDUE",
      steps: [
        {
          key: "EARLY_WARNING",
          article: "ART-23.4-A",
          dueAt: "2026-11-02T08:00:00+00:00",
          doneAt: null,
          status: "OVERDUE",
          late: false,
          provisional: false,
        },
        {
          key: "NOTIFICATION",
          article: "ART-23.4-B",
          dueAt: "2026-11-04T08:00:00+00:00",
          doneAt: null,
          status: "PENDING",
          late: false,
          provisional: false,
        },
        {
          key: "FINAL_REPORT",
          article: "ART-23.4-D",
          dueAt: "2026-12-04T08:00:00+00:00",
          doneAt: null,
          status: "PENDING",
          late: false,
          provisional: true,
        },
      ],
    },
  ],
  criticalSuppliers: [
    { id: 3, name: "Hébergeur cloud", criticality: "CRITICAL" },
  ],
};

function show() {
  vi.spyOn(api, "get").mockResolvedValue({ data: overview });
  render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <MemoryRouter>
        <Nis2OperationsPanel />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  role.roles = ["ROLE_ADMIN"];
});

it("shows the three notification steps with their status and the critical suppliers", async () => {
  show();
  expect(await screen.findByText("Rançongiciel")).toBeInTheDocument();
  expect(screen.getByText("En retard")).toBeInTheDocument();
  expect(screen.getAllByText("À venir")).toHaveLength(2);
  expect(screen.getByText("Alerte précoce (24 h)")).toBeInTheDocument();
  expect(screen.getByText("Rapport final (1 mois)")).toBeInTheDocument();
  expect(screen.getByText(/au plus tard/)).toBeInTheDocument();
  expect(screen.getByText("Hébergeur cloud · CRITICAL")).toBeInTheDocument();
  expect(
    screen.getByText(
      /1 incident\(s\) significatif\(s\) avec une déclaration en retard/,
    ),
  ).toBeInTheDocument();
});

it("lets an administrator classify the entity and saves it", async () => {
  show();
  const put = vi.spyOn(api, "put").mockResolvedValue({ data: {} });
  await screen.findByText("Rançongiciel");
  expect(
    screen.getByText(/classification NIS2 n’est pas renseignée/),
  ).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText("Secteur d’activité"), {
    target: { value: "Transport" },
  });
  fireEvent.click(
    screen.getByRole("button", { name: "Enregistrer la classification" }),
  );
  await waitFor(() =>
    expect(put).toHaveBeenCalledWith("/nis2/entity", {
      type: null,
      sector: "Transport",
      authority: null,
    }),
  );
});

it("keeps the classification read-only for a non administrator", async () => {
  role.roles = ["ROLE_VIEWER"];
  show();
  await screen.findByText("Rançongiciel");
  expect(screen.getByLabelText("Secteur d’activité")).toBeDisabled();
  expect(
    screen.queryByRole("button", { name: "Enregistrer la classification" }),
  ).not.toBeInTheDocument();
});
