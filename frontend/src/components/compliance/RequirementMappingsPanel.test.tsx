import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { api } from "../../api/client";
import { RequirementMappingsPanel } from "./RequirementMappingsPanel";

const identity = vi.hoisted(() => ({
  roles: ["ROLE_VIEWER"],
  locale: "fr" as "fr" | "en",
}));

vi.mock("../../auth/useAuth", () => ({
  useAuth: () => ({ user: { roles: identity.roles } }),
}));
vi.mock("../../i18n/InterfaceLocaleContext", () => ({
  useInterfaceLocale: () => identity.locale,
}));

const frameworks = [
  {
    id: 1,
    name: "ISO 27001",
    version: "2022",
    description: null,
    publisher: "ISO",
    status: "ACTIVE",
    requirementCount: 2,
  },
  {
    id: 2,
    name: "NIS2",
    version: "2022",
    description: null,
    publisher: "UE",
    status: "ACTIVE",
    requirementCount: 1,
  },
];

const requirements = {
  1: [
    {
      id: 11,
      frameworkId: 1,
      reference: "A.5.1",
      title: "Politiques de sécurité",
      description: null,
      category: "Organisation",
      parentRequirementId: null,
      status: "ACTIVE",
    },
    {
      id: 12,
      frameworkId: 1,
      reference: "A.5.2",
      title: "Rôles et responsabilités",
      description: null,
      category: "Organisation",
      parentRequirementId: null,
      status: "ACTIVE",
    },
  ],
  2: [
    {
      id: 21,
      frameworkId: 2,
      reference: "21.2.a",
      title: "Politiques de cybersécurité",
      description: null,
      category: "Gouvernance",
      parentRequirementId: null,
      status: "ACTIVE",
    },
  ],
};

const mapping = {
  id: 91,
  source: { id: 11, reference: "A.5.1", framework: "ISO 27001 2022" },
  target: { id: 21, reference: "21.2.a", framework: "NIS2 2022" },
  coveragePercent: 80,
  inheritEvidence: false,
  rationale: "Objectifs de gouvernance proches.",
  direction: "SOURCE_TO_TARGET",
  createdBy: { id: 7, name: "Risk Manager" },
  createdAt: "2026-10-04T10:00:00+00:00",
};

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  identity.roles = ["ROLE_VIEWER"];
  identity.locale = "fr";
});

function renderPanel(
  client = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  }),
) {
  render(
    <QueryClientProvider client={client}>
      <RequirementMappingsPanel />
    </QueryClientProvider>,
  );
}

function mockReads(items: (typeof mapping)[] = []) {
  return vi.spyOn(api, "get").mockImplementation(async (url) => {
    if (url === "/requirement-mappings") return { data: items };
    if (url === "/frameworks") return { data: frameworks };
    if (url === "/frameworks/1/requirements") {
      return { data: requirements[1] };
    }
    if (url === "/frameworks/2/requirements") {
      return { data: requirements[2] };
    }
    throw new Error(`Unexpected GET ${url}`);
  });
}

async function selectOption(label: string, option: RegExp) {
  const input = screen.getByRole("combobox", { name: label });
  await waitFor(() => expect(input).toBeEnabled());
  fireEvent.mouseDown(input);
  fireEvent.click(input);
  fireEvent.click(await screen.findByRole("option", { name: option }));
}

async function findMappingRow() {
  return screen.findByText((_, element) =>
    Boolean(
      element?.tagName === "P" &&
      element.textContent?.includes("ISO 27001 2022 A.5.1") &&
      element.textContent.includes("NIS2 2022 21.2.a"),
    ),
  );
}

async function openCreateDialog() {
  fireEvent.click(
    screen.getByRole("button", { name: "Relier deux exigences" }),
  );
  await screen.findByRole("dialog", { name: "Relier deux exigences" });
}

async function fillValidMapping() {
  await selectOption("Référentiel source", /ISO 27001.*2022/);
  await selectOption("Exigence source", /A\.5\.1.*Politiques de sécurité/);
  await selectOption("Référentiel cible", /NIS2.*2022/);
  await selectOption("Exigence cible", /21\.2\.a.*Politiques de cybersécurité/);
}

describe("RequirementMappingsPanel", () => {
  it("keeps cached fields usable during background refreshes", async () => {
    identity.roles = ["ROLE_RISK_MANAGER"];
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    client.setQueryData(["frameworks"], frameworks);
    client.setQueryData(["framework-requirements", "1"], requirements[1]);
    client.setQueryData(["framework-requirements", "2"], requirements[2]);
    vi.spyOn(api, "get").mockImplementation((url) =>
      url === "/requirement-mappings"
        ? Promise.resolve({ data: [] })
        : new Promise(() => {}),
    );

    renderPanel(client);
    await openCreateDialog();
    await selectOption("Référentiel source", /ISO 27001/);
    await selectOption("Exigence source", /A\.5\.1/);
    await selectOption("Référentiel cible", /NIS2/);
    await selectOption("Exigence cible", /21\.2\.a/);

    expect(client.isFetching()).toBe(3);
    expect(
      screen.getByRole("button", { name: "Créer la correspondance" }),
    ).toBeEnabled();
  });
  it("permet au viewer de lire les correspondances sans aucune action", async () => {
    mockReads([mapping]);
    renderPanel();

    expect(await findMappingRow()).toBeVisible();
    expect(
      screen.queryByRole("button", { name: "Relier deux exigences" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /Supprimer la correspondance/ }),
    ).not.toBeInTheDocument();
  });

  it("affiche plus de huit correspondances sans tronquer la liste", async () => {
    const manyMappings = Array.from({ length: 9 }, (_, index) => ({
      ...mapping,
      id: index + 1,
      source: {
        ...mapping.source,
        reference: `A.5.${index + 1}`,
      },
      rationale: `Correspondance ${index + 1}`,
    }));
    mockReads(manyMappings);
    renderPanel();

    expect(await screen.findByText("Correspondance 9")).toBeVisible();
    expect(screen.getAllByText(/Correspondance \d/)).toHaveLength(9);
  });

  it.each(["ROLE_RISK_MANAGER", "ROLE_ADMIN", "ROLE_AUDITOR"])(
    "autorise la création pour %s",
    async (role) => {
      identity.roles = [role];
      mockReads();
      renderPanel();

      expect(
        await screen.findByRole("button", { name: "Relier deux exigences" }),
      ).toBeVisible();
    },
  );

  it("ouvre le formulaire anglais sans suggérer de conformité automatique", async () => {
    identity.roles = ["ROLE_RISK_MANAGER"];
    identity.locale = "en";
    mockReads();
    renderPanel();

    fireEvent.click(
      await screen.findByRole("button", { name: "Map two requirements" }),
    );
    const dialog = await screen.findByRole("dialog", {
      name: "Map two requirements",
    });
    expect(
      within(dialog).getByText(
        "A mapping suggests reusable evidence references. It never copies evidence or makes a requirement automatically compliant.",
      ),
    ).toBeVisible();
    expect(
      within(dialog).getByRole("combobox", { name: "Source framework" }),
    ).toBeVisible();
    expect(
      within(dialog).getByRole("spinbutton", {
        name: /Estimated coverage \(%\)/,
      }),
    ).toHaveValue(100);
    expect(
      within(dialog).getByRole("button", { name: "Create mapping" }),
    ).toBeDisabled();
  });

  it("charge les exigences uniquement après la sélection de chaque référentiel", async () => {
    identity.roles = ["ROLE_RISK_MANAGER"];
    const get = mockReads();
    renderPanel();
    await openCreateDialog();

    expect(get).not.toHaveBeenCalledWith("/frameworks/1/requirements");
    await selectOption("Référentiel source", /ISO 27001.*2022/);
    await waitFor(() =>
      expect(get).toHaveBeenCalledWith(
        "/frameworks/1/requirements",
        expect.objectContaining({ signal: expect.any(AbortSignal) }),
      ),
    );
    await selectOption("Exigence source", /A\.5\.1.*Politiques de sécurité/);

    expect(get).not.toHaveBeenCalledWith("/frameworks/2/requirements");
    await selectOption("Référentiel cible", /NIS2.*2022/);
    await waitFor(() =>
      expect(get).toHaveBeenCalledWith(
        "/frameworks/2/requirements",
        expect.objectContaining({ signal: expect.any(AbortSignal) }),
      ),
    );
    await selectOption(
      "Exigence cible",
      /21\.2\.a.*Politiques de cybersécurité/,
    );
  });

  it("envoie les identifiants, la couverture, la justification et inheritEvidence à false par défaut", async () => {
    identity.roles = ["ROLE_RISK_MANAGER"];
    mockReads();
    const post = vi.spyOn(api, "post").mockResolvedValue({ data: mapping });
    renderPanel();
    await openCreateDialog();
    await fillValidMapping();

    fireEvent.change(
      screen.getByRole("spinbutton", { name: /Couverture.*\(%\)/ }),
      {
        target: { value: "75" },
      },
    );
    fireEvent.change(screen.getByLabelText("Justification"), {
      target: { value: "Contrôles et objectifs partiellement communs." },
    });
    expect(
      screen.getByRole("checkbox", {
        name: "Proposer les références de preuves réutilisables",
      }),
    ).not.toBeChecked();
    fireEvent.click(
      screen.getByRole("button", { name: "Créer la correspondance" }),
    );

    await waitFor(() =>
      expect(post).toHaveBeenCalledWith("/requirement-mappings", {
        sourceRequirementId: 11,
        targetRequirementId: 21,
        coveragePercent: 75,
        inheritEvidence: false,
        rationale: "Contrôles et objectifs partiellement communs.",
      }),
    );
  });

  it("envoie explicitement inheritEvidence à true lorsque l'option est activée", async () => {
    identity.roles = ["ROLE_RISK_MANAGER"];
    mockReads();
    const post = vi.spyOn(api, "post").mockResolvedValue({ data: mapping });
    renderPanel();
    await openCreateDialog();
    await fillValidMapping();

    fireEvent.click(
      screen.getByRole("checkbox", {
        name: "Proposer les références de preuves réutilisables",
      }),
    );
    fireEvent.click(
      screen.getByRole("button", { name: "Créer la correspondance" }),
    );

    await waitFor(() =>
      expect(post).toHaveBeenCalledWith(
        "/requirement-mappings",
        expect.objectContaining({ inheritEvidence: true }),
      ),
    );
  });

  it("bloque une correspondance d'une exigence vers elle-même", async () => {
    identity.roles = ["ROLE_RISK_MANAGER"];
    mockReads();
    const post = vi.spyOn(api, "post");
    renderPanel();
    await openCreateDialog();

    await selectOption("Référentiel source", /ISO 27001.*2022/);
    await selectOption("Exigence source", /A\.5\.1.*Politiques de sécurité/);
    await selectOption("Référentiel cible", /ISO 27001.*2022/);
    const target = screen.getByRole("combobox", { name: "Exigence cible" });
    await waitFor(() => expect(target).toBeEnabled());
    fireEvent.mouseDown(target);
    fireEvent.click(target);
    const sameRequirement = await screen.findByRole("option", {
      name: /A\.5\.1.*Politiques de sécurité/,
    });
    expect(sameRequirement).toHaveAttribute("aria-disabled", "true");
    fireEvent.click(sameRequirement);

    const submit = screen.getByRole("button", {
      name: "Créer la correspondance",
    });
    expect(submit).toBeDisabled();
    fireEvent.click(submit);
    expect(post).not.toHaveBeenCalled();
  });

  it("réinitialise l'exigence quand son référentiel change", async () => {
    identity.roles = ["ROLE_RISK_MANAGER"];
    mockReads();
    renderPanel();
    await openCreateDialog();

    await selectOption("Référentiel source", /ISO 27001.*2022/);
    await selectOption("Exigence source", /A\.5\.1.*Politiques de sécurité/);
    expect(
      screen.getByRole("combobox", { name: "Exigence source" }),
    ).toHaveValue("A.5.1 — Politiques de sécurité");

    await selectOption("Référentiel source", /NIS2.*2022/);
    expect(
      screen.getByRole("combobox", { name: "Exigence source" }),
    ).toHaveValue("");
  });

  it("permet de réessayer après une erreur de chargement", async () => {
    const get = vi
      .spyOn(api, "get")
      .mockRejectedValueOnce(new Error("offline"))
      .mockResolvedValueOnce({ data: [mapping] });
    renderPanel();

    expect(
      await screen.findByText("Impossible de charger les correspondances."),
    ).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Réessayer" }));

    expect(await findMappingRow()).toBeVisible();
    expect(get).toHaveBeenCalledTimes(2);
  });

  it("préserve le formulaire lorsqu'une création concurrente retourne 409", async () => {
    identity.roles = ["ROLE_RISK_MANAGER"];
    mockReads();
    const conflict = Object.assign(new Error("Conflict"), {
      isAxiosError: true,
      response: {
        status: 409,
        data: {
          code: "MAPPING_CONFLICT",
          message:
            "Cette correspondance existe avec une configuration différente.",
        },
      },
    });
    vi.spyOn(api, "post").mockRejectedValue(conflict);
    renderPanel();
    await openCreateDialog();
    await fillValidMapping();
    fireEvent.change(
      screen.getByRole("spinbutton", { name: /Couverture.*\(%\)/ }),
      { target: { value: "60" } },
    );
    fireEvent.change(screen.getByLabelText("Justification"), {
      target: { value: "Justification conservée" },
    });
    fireEvent.click(
      screen.getByRole("checkbox", {
        name: "Proposer les références de preuves réutilisables",
      }),
    );
    fireEvent.click(
      screen.getByRole("button", { name: "Créer la correspondance" }),
    );

    expect(
      await screen.findByText(
        "Cette correspondance existe avec une configuration différente.",
      ),
    ).toBeVisible();
    expect(
      screen.getByRole("spinbutton", { name: /Couverture.*\(%\)/ }),
    ).toHaveValue(60);
    expect(screen.getByLabelText("Justification")).toHaveValue(
      "Justification conservée",
    );
    expect(
      screen.getByRole("combobox", { name: "Exigence source" }),
    ).toHaveValue("A.5.1 — Politiques de sécurité");
    expect(
      screen.getByRole("combobox", { name: "Exigence cible" }),
    ).toHaveValue("21.2.a — Politiques de cybersécurité");
    expect(
      screen.getByRole("checkbox", {
        name: "Proposer les références de preuves réutilisables",
      }),
    ).toBeChecked();
  });

  it("demande confirmation avant de supprimer une correspondance", async () => {
    identity.roles = ["ROLE_RISK_MANAGER"];
    mockReads([mapping]);
    const remove = vi.spyOn(api, "delete").mockResolvedValue({ data: {} });
    renderPanel();

    const deleteButton = await screen.findByRole("button", {
      name: /Supprimer la correspondance ISO 27001 2022 A\.5\.1 vers NIS2 2022 21\.2\.a/,
    });
    fireEvent.click(deleteButton);
    const confirmation = await screen.findByRole("dialog", {
      name: "Supprimer la correspondance ?",
    });
    fireEvent.click(
      within(confirmation).getByRole("button", { name: "Annuler" }),
    );
    expect(remove).not.toHaveBeenCalled();

    fireEvent.click(deleteButton);
    fireEvent.click(
      within(
        await screen.findByRole("dialog", {
          name: "Supprimer la correspondance ?",
        }),
      ).getByRole("button", { name: "Supprimer" }),
    );
    await waitFor(() =>
      expect(remove).toHaveBeenCalledWith("/requirement-mappings/91"),
    );
  });
});
