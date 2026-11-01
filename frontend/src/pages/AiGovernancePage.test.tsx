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
import { AiGovernancePage } from "./AiGovernancePage";

const auth = vi.hoisted(() => ({ roles: ["ROLE_ADMIN"] as string[] }));
vi.mock("../auth/useAuth", () => ({
  useAuth: () => ({ user: { id: 1, roles: auth.roles, locale: "fr" } }),
}));

const systems = [
  {
    id: 1,
    name: "Copilote RiskPilot",
    purpose: "Assistance GRC",
    provider: "MISTRAL",
    model: "mistral-large-latest",
    dataCategories: [],
    riskLevel: "LIMITED",
    status: "ACTIVE",
    humanOversight: "Confirmation humaine",
    reviewAt: null,
    builtIn: true,
    owner: { id: 1, name: "Alice Admin" },
    approvedAssessment: false,
  },
  {
    id: 2,
    name: "Tri des CV",
    purpose: "Présélection",
    provider: "Interne",
    model: null,
    dataCategories: ["CV"],
    riskLevel: "HIGH",
    status: "ACTIVE",
    humanOversight: "Validation recruteur",
    reviewAt: null,
    builtIn: false,
    owner: { id: 1, name: "Alice Admin" },
    approvedAssessment: true,
  },
];
const assessment = {
  id: 5,
  systemId: 2,
  status: "IN_REVIEW",
  affectedGroups: "Candidats",
  benefits: "Gain",
  harms: "Biais",
  mitigations: "Revue",
  residualRisk: "MEDIUM",
  author: { id: 9, name: "Rita" },
  reviewer: null,
  approvedAt: null,
};

function show() {
  vi.spyOn(api, "get").mockImplementation(async (url: string) => {
    if (url === "/ai-governance/systems") return { data: systems };
    if (url.endsWith("/impact-assessments")) return { data: [assessment] };
    if (url === "/ai-governance/mandatory-documents")
      return {
        data: {
          missing: 1,
          items: [
            {
              key: "POLICY",
              clause: "5.2",
              title: { fr: "Politique", en: "Policy" },
              covered: false,
              documents: [],
            },
          ],
        },
      };
    return { data: [] };
  });
  render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <AiGovernancePage />
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  auth.roles = ["ROLE_ADMIN"];
});

it("lists the register with the built-in copilot and the impact assessment status", async () => {
  show();
  expect(await screen.findByText("Copilote RiskPilot")).toBeInTheDocument();
  expect(screen.getByText("RiskPilot")).toBeInTheDocument();
  expect(screen.getByText("Haut risque")).toBeInTheDocument();
  expect(screen.getByText("À réaliser")).toBeInTheDocument();
  expect(
    screen.getByRole("button", { name: "Ajouter un système d’IA" }),
  ).toBeInTheDocument();
});

it("creates a system through the API", async () => {
  show();
  const post = vi.spyOn(api, "post").mockResolvedValue({ data: {} });
  fireEvent.click(
    await screen.findByRole("button", { name: "Ajouter un système d’IA" }),
  );
  fireEvent.change(screen.getByLabelText(/^Nom/), {
    target: { value: "Assistant RH" },
  });
  fireEvent.change(screen.getByLabelText(/^Finalité/), {
    target: { value: "Rédaction d’annonces" },
  });
  fireEvent.change(screen.getByLabelText(/^Fournisseur/), {
    target: { value: "Mistral" },
  });
  fireEvent.click(screen.getByRole("button", { name: "Enregistrer" }));
  await waitFor(() =>
    expect(post).toHaveBeenCalledWith(
      "/ai-governance/systems",
      expect.objectContaining({
        name: "Assistant RH",
        purpose: "Rédaction d’annonces",
        provider: "Mistral",
        riskLevel: "LIMITED",
        ownerId: 1,
      }),
    ),
  );
});

it("prevents the author from approving their own assessment but allows another administrator", async () => {
  show();
  const post = vi.spyOn(api, "post").mockResolvedValue({ data: {} });
  fireEvent.click(
    (await screen.findAllByRole("button", { name: "Évaluations" }))[1],
  );
  const approve = await screen.findByRole("button", { name: "Approuver" });
  expect(approve).toBeEnabled();
  fireEvent.click(approve);
  await waitFor(() =>
    expect(post).toHaveBeenCalledWith(
      "/ai-governance/impact-assessments/5/approve",
    ),
  );
});

it("hides write actions from a read-only user and shows missing mandatory documents", async () => {
  auth.roles = ["ROLE_VIEWER"];
  show();
  await screen.findByText("Copilote RiskPilot");
  expect(
    screen.queryByRole("button", { name: "Ajouter un système d’IA" }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole("tab", { name: "Documents obligatoires" }));
  expect(await screen.findByText("Manquant")).toBeInTheDocument();
  expect(
    screen.getByText(
      /1 information\(s\) documentée\(s\) obligatoire\(s\) sans document correspondant/,
    ),
  ).toBeInTheDocument();
});
