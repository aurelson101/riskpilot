import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
import { api } from "../api/client";
import { RegulatoryNotificationsPage } from "./RegulatoryNotificationsPage";

const auth = vi.hoisted(() => ({ roles: ["ROLE_RISK_MANAGER"] as string[] }));
vi.mock("../auth/useAuth", () => ({
  useAuth: () => ({ user: { id: 1, roles: auth.roles, locale: "fr" } }),
}));

const incident = {
  id: 7,
  regime: "AI_ACT_SERIOUS_INCIDENT",
  title: "Décision erronée",
  description: "Refus injustifié",
  awareAt: "2026-11-01T08:00:00+00:00",
  applicable: true,
  status: "OPEN",
  owner: { id: 1, name: "Rita Manager" },
  aiSystem: { id: 3, name: "Scoring" },
  product: null,
  steps: [
    {
      key: "REPORT",
      article: "ART-73.3",
      dueAt: "2026-11-03T08:00:00+00:00",
      doneAt: null,
      status: "OVERDUE",
      late: false,
      provisional: false,
    },
  ],
  worstStatus: "OVERDUE",
};

function show() {
  vi.spyOn(api, "get").mockImplementation(async (url: string) => {
    if (url === "/regulatory-incidents")
      return {
        data: {
          summary: { open: 1, overdue: 1, dueSoon: 0 },
          items: [incident],
        },
      };
    if (url === "/cra/products")
      return {
        data: {
          summary: { products: 1, withGaps: 1, onMarket: 1 },
          items: [
            {
              id: 1,
              name: "Passerelle IoT",
              version: "2.1",
              category: "IMPORTANT_CLASS_II",
              role: "MANUFACTURER",
              status: "ON_MARKET",
              supportEndsAt: "2028-01-01",
              sbomReference: null,
              gaps: ["SBOM_MISSING"],
            },
          ],
        },
      };
    if (url === "/dora/arrangements")
      return {
        data: {
          summary: { arrangements: 1, criticalFunctions: 1, withGaps: 1 },
          items: [
            {
              id: 1,
              thirdParty: { id: 2, name: "Cloud Provider" },
              serviceType: "CLOUD_INFRASTRUCTURE",
              description: "Hébergement",
              supportsCriticalFunction: true,
              providerLei: null,
              status: "ACTIVE",
              gaps: ["PROVIDER_LEI_MISSING"],
            },
          ],
        },
      };
    return { data: [{ id: 2, name: "Cloud Provider" }] };
  });
  render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <RegulatoryNotificationsPage />
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  auth.roles = ["ROLE_RISK_MANAGER"];
});

it("shows the overdue AI Act report with its article and the summary counters", async () => {
  show();
  expect(await screen.findByText("Décision erronée")).toBeInTheDocument();
  expect(screen.getByText("ART-73.3")).toBeInTheDocument();
  expect(screen.getByText("1 en retard")).toBeInTheDocument();
  expect(screen.getByText(/Système : Scoring/)).toBeInTheDocument();
});

it("marks a step as filed by sending the filing date for that step only", async () => {
  show();
  const put = vi.spyOn(api, "put").mockResolvedValue({ data: {} });
  fireEvent.click(
    await screen.findByRole("button", { name: "Marquer déposé" }),
  );
  await waitFor(() => expect(put).toHaveBeenCalledTimes(1));
  const [url, body] = put.mock.calls[0] as [
    string,
    { completed: Record<string, string> },
  ];
  expect(url).toBe("/regulatory-incidents/7");
  expect(Object.keys(body.completed)).toEqual(["REPORT"]);
});

it("hides every write action from a viewer", async () => {
  auth.roles = ["ROLE_VIEWER"];
  show();
  await screen.findByText("Décision erronée");
  expect(
    screen.queryByRole("button", { name: "Déclarer un dossier" }),
  ).not.toBeInTheDocument();
  expect(
    screen.queryByRole("button", { name: "Marquer déposé" }),
  ).not.toBeInTheDocument();
});

it("lists CRA readiness gaps and DORA register gaps in their tabs", async () => {
  show();
  fireEvent.click(await screen.findByRole("tab", { name: "Produits CRA" }));
  expect(
    await screen.findByText("Nomenclature logicielle (SBOM) manquante"),
  ).toBeInTheDocument();
  fireEvent.click(screen.getByRole("tab", { name: "Registre DORA" }));
  expect(
    await screen.findByText("LEI du prestataire manquant"),
  ).toBeInTheDocument();
  expect(
    screen.getByRole("button", { name: "Exporter en CSV" }),
  ).toBeInTheDocument();
});
