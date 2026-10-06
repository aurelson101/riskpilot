import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { api } from "../api/client";
import * as csv from "../api/csv";
import { ThirdPartiesPage } from "./ThirdPartiesPage";

const identity = vi.hoisted(() => ({
  roles: ["ROLE_VIEWER"],
  locale: "fr" as "fr" | "en",
}));

vi.mock("../auth/useAuth", () => ({
  useAuth: () => ({
    user: {
      id: 9,
      email: "current@example.test",
      firstName: "Current",
      lastName: "User",
      roles: identity.roles,
      locale: identity.locale,
    },
  }),
}));
vi.mock("../i18n/InterfaceLocaleContext", () => ({
  useInterfaceLocale: () => identity.locale,
}));

const users = [
  {
    id: 2,
    email: "owner@example.test",
    firstName: "Olivia",
    lastName: "Owner",
    roles: ["ROLE_RISK_MANAGER"],
    status: "ACTIVE",
    locale: "fr",
  },
  {
    id: 9,
    email: "current@example.test",
    firstName: "Current",
    lastName: "User",
    roles: ["ROLE_RISK_MANAGER"],
    status: "ACTIVE",
    locale: "fr",
  },
];

const thirdParty = {
  id: 41,
  name: "Cloud Payroll",
  contactEmail: "security@payroll.example",
  services: "Externalisation de la paie",
  dataCategories: ["Données clients, sensibles", "Données bancaires"],
  criticality: "CRITICAL",
  status: "ACTIVE",
  contractReference: "CTR-2026-041",
  sla: "Disponibilité 99,95 %",
  dependencies: "API RH et SSO",
  exitPlan: "Export chiffré puis bascule sous 30 jours",
  contractEndsAt: "2020-09-30",
  nextAssessmentAt: "2020-10-01",
  cyberScore: 0,
  certifications: ["ISO 27001, 2022", "SOC 2 Type II"],
  riskSummary: "Concentration et accès à des données sensibles.",
  compensatingMeasures: "Chiffrement, revue trimestrielle et journalisation.",
  owner: { id: 2, name: "Olivia Owner" },
  assessments: [
    {
      id: 71,
      title: "Revue annuelle",
      status: "REVIEWED",
      score: 0,
    },
  ],
};

const unevaluated = {
  ...thirdParty,
  id: 42,
  name: "HR Archive",
  criticality: "LOW",
  contractEndsAt: "2999-09-30",
  nextAssessmentAt: "2999-10-01",
  cyberScore: 86,
  assessments: [
    {
      id: 72,
      title: "Questionnaire envoyé",
      status: "SUBMITTED",
      score: 86,
    },
  ],
};

const terminated = {
  ...thirdParty,
  id: 43,
  name: "Legacy Hosting",
  status: "TERMINATED",
  contractEndsAt: "2020-01-01",
  nextAssessmentAt: "2020-01-02",
};

const highUnevaluated = {
  ...unevaluated,
  id: 44,
  name: "Critical Newcomer",
  criticality: "HIGH",
  assessments: [],
};

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  identity.roles = ["ROLE_VIEWER"];
  identity.locale = "fr";
});

function renderPage() {
  render(
    <QueryClientProvider
      client={
        new QueryClient({
          defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
          },
        })
      }
    >
      <MemoryRouter>
        <ThirdPartiesPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

function mockReads(items = [thirdParty]) {
  return vi.spyOn(api, "get").mockImplementation(async (url) => {
    if (url === "/third-parties") return { data: items };
    if (url === "/users") return { data: users };
    throw new Error(`Unexpected GET ${url}`);
  });
}

function thirdPartyCard(name: string) {
  const card = screen.getByText(name).closest(".MuiCard-root");
  if (!card) throw new Error(`Card not found for ${name}`);
  return within(card as HTMLElement);
}

async function openCreateDialog(button = "Ajouter un tiers") {
  fireEvent.click(await screen.findByRole("button", { name: button }));
  return screen.findByRole("dialog", {
    name: button === "Add a third party" ? "New third party" : "Nouveau tiers",
  });
}

async function openEditDialog(name = thirdParty.name) {
  fireEvent.click(
    thirdPartyCard(name).getByRole("button", { name: /Modifier|Edit/ }),
  );
  return screen.findByRole("dialog", {
    name: /Modifier le tiers|Edit third party/,
  });
}

async function selectOption(
  container: HTMLElement,
  label: string | RegExp,
  option: string | RegExp,
) {
  const select = within(container).getByRole("combobox", { name: label });
  await waitFor(() => expect(select).toBeEnabled());
  fireEvent.mouseDown(select);
  fireEvent.click(await screen.findByRole("option", { name: option }));
}

function setField(
  container: HTMLElement,
  label: string | RegExp,
  value: string,
) {
  const accessibleLabel =
    typeof label === "string"
      ? new RegExp(`^${label.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}`)
      : label;
  fireEvent.change(within(container).getByLabelText(accessibleLabel), {
    target: { value },
  });
}

describe("ThirdPartiesPage", () => {
  it("exports all filtered rows beyond the displayed page without capability tokens", async () => {
    const download = vi.spyOn(csv, "downloadCsv").mockImplementation(() => {});
    mockReads(
      Array.from({ length: 13 }, (_, index) => ({
        ...thirdParty,
        id: index + 1,
        name: `Supplier ${index}`,
        assessments: [],
      })),
    );
    renderPage();
    const exportButton = await screen.findByRole("button", {
      name: "Exporter le CSV filtré",
    });
    await waitFor(() => expect(exportButton).toBeEnabled());
    fireEvent.click(exportButton);
    expect(download).toHaveBeenCalledOnce();
    const rows = download.mock.calls[0][1];
    expect(rows).toHaveLength(14);
    expect(rows[0]).toHaveLength(19);
    expect(rows.slice(1).every((row) => row[17] === null)).toBe(true);
    expect(JSON.stringify(rows)).not.toContain("publicToken");
  });

  it("filters reviewed assessments and owners using the visible register", async () => {
    mockReads([
      thirdParty,
      { ...unevaluated, owner: { id: 9, name: "Current User" } },
    ]);
    renderPage();
    await screen.findByText(thirdParty.name);
    await selectOption(
      document.body,
      "Filtrer les évaluations",
      "Non validées",
    );
    expect(screen.queryByText(thirdParty.name)).not.toBeInTheDocument();
    expect(screen.getByText(unevaluated.name)).toBeInTheDocument();
    await selectOption(
      document.body,
      "Filtrer par responsable",
      "Olivia Owner",
    );
    expect(screen.getByText(/Aucun tiers ne correspond/)).toBeInTheDocument();
  });
  it("paginates the register and resets filters", async () => {
    mockReads(
      Array.from({ length: 13 }, (_, index) => ({
        ...thirdParty,
        id: index + 1,
        name: `Supplier ${String(index).padStart(2, "0")}`,
      })),
    );
    renderPage();
    await screen.findByText("Supplier 00");
    expect(screen.queryByText("Supplier 12")).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: /page 2/i }));
    expect(screen.getByText("Supplier 12")).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Rechercher"), {
      target: { value: "no matches" },
    });
    expect(screen.getByText(/Aucun tiers ne correspond/)).toBeInTheDocument();
    fireEvent.click(
      screen.getByRole("button", { name: "Réinitialiser les filtres" }),
    );
    expect(screen.getByText("Supplier 00")).toBeInTheDocument();
  });

  it("searches contact and declared certifications without querying users", async () => {
    const get = mockReads([
      thirdParty,
      {
        ...unevaluated,
        contactEmail: "other@example.test",
        certifications: [],
      },
    ]);
    renderPage();
    await screen.findByText(thirdParty.name);
    fireEvent.change(screen.getByLabelText("Rechercher"), {
      target: { value: "SOC 2" },
    });
    expect(screen.getByText(thirdParty.name)).toBeInTheDocument();
    expect(screen.queryByText(unevaluated.name)).not.toBeInTheDocument();
    expect(get.mock.calls.every(([url]) => url === "/third-parties")).toBe(
      true,
    );
  });
  it("ouvre la création de campagne depuis un tiers actif", async () => {
    identity.roles = ["ROLE_RISK_MANAGER"];
    mockReads([thirdParty, terminated]);
    renderPage();
    fireEvent.click(
      await screen.findByRole("button", {
        name: "Créer une campagne pour Cloud Payroll",
      }),
    );
    expect(await screen.findByRole("dialog")).toBeVisible();
    expect(screen.getByRole("dialog").textContent).toContain("Cloud Payroll");
    expect(
      screen.queryByRole("button", {
        name: "Créer une campagne pour Legacy Hosting",
      }),
    ).not.toBeInTheDocument();
  });

  it("ouvre les réponses d'une évaluation en lecture seule depuis la fiche tiers", async () => {
    const get = mockReads();
    get.mockImplementation(async (url) => {
      if (url === "/third-parties") return { data: [thirdParty] };
      if (url === "/supplier-assessments/71")
        return {
          data: {
            ...thirdParty.assessments[0],
            version: 1,
            reviewer: thirdParty.owner,
            expiresAt: "2027-12-31T23:59:59+00:00",
            submittedAt: "2026-10-01T12:00:00+00:00",
            reviewedAt: "2026-10-02T12:00:00+00:00",
            reviewComment: "Justification de la revue",
            questions: [
              {
                id: "mfa",
                label: "Authentification forte utilisée ?",
                weight: 5,
              },
            ],
            responses: { mfa: true },
            evidence: ["Référence documentaire"],
          },
        };
      throw new Error(`Unexpected GET ${url}`);
    });
    renderPage();
    fireEvent.click(
      await screen.findByRole("button", {
        name: "Consulter l’évaluation Revue annuelle",
      }),
    );
    expect(
      await screen.findByText("Authentification forte utilisée ?"),
    ).toBeVisible();
    expect(screen.getByText("Référence documentaire")).toBeVisible();
    expect(screen.queryByRole("spinbutton")).not.toBeInTheDocument();
    expect(get.mock.calls.some(([url]) => url === "/users")).toBe(false);
  });

  it("laisse le viewer consulter sans actions ni chargement des utilisateurs", async () => {
    const get = mockReads();
    renderPage();

    expect(await screen.findByText("Cloud Payroll")).toBeVisible();
    expect(
      screen.queryByRole("button", { name: "Ajouter un tiers" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /Modifier/ }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /Créer une campagne/ }),
    ).not.toBeInTheDocument();
    expect(get.mock.calls.some(([url]) => url === "/users")).toBe(false);
  });

  it.each([
    "ROLE_SUPER_ADMIN",
    "ROLE_ADMIN",
    "ROLE_RISK_MANAGER",
    "ROLE_AUDITOR",
  ])("autorise les actions de gestion pour %s", async (role) => {
    identity.roles = [role];
    mockReads();
    renderPage();

    expect(
      await screen.findByRole("button", { name: "Ajouter un tiers" }),
    ).toBeVisible();
    await screen.findByText("Cloud Payroll");
    expect(
      thirdPartyCard("Cloud Payroll").getByRole("button", {
        name: /Modifier/,
      }),
    ).toBeVisible();
  });

  it("ne charge jamais /users pour un auditeur, même dans le formulaire", async () => {
    identity.roles = ["ROLE_AUDITOR"];
    const get = mockReads();
    renderPage();

    const dialog = await openCreateDialog();
    expect(
      within(dialog).getByRole("combobox", { name: "Responsable" }),
    ).toBeVisible();
    expect(get.mock.calls.some(([url]) => url === "/users")).toBe(false);
  });

  it("charge les utilisateurs à l'ouverture et expose tous les champs", async () => {
    identity.roles = ["ROLE_RISK_MANAGER"];
    const get = mockReads();
    renderPage();
    await screen.findByText("Cloud Payroll");
    expect(get.mock.calls.some(([url]) => url === "/users")).toBe(false);

    const dialog = await openCreateDialog();
    await waitFor(() =>
      expect(get.mock.calls.some(([url]) => url === "/users")).toBe(true),
    );
    for (const label of [
      /^Nom/,
      /^Contact/,
      /^Services/,
      /^Catégories de données/,
      /^Référence contrat/,
      /^SLA/,
      /^Fin du contrat/,
      /^Prochaine évaluation/,
      /^Certifications déclarées/,
      /^Synthèse du risque/,
      /^Mesures compensatoires/,
      /^Dépendances/,
      /^Plan de sortie/,
    ]) {
      expect(within(dialog).getByLabelText(label)).toBeVisible();
    }
    for (const label of ["Criticité", "Statut", "Responsable"]) {
      expect(
        within(dialog).getByRole("combobox", { name: label }),
      ).toBeVisible();
    }
  });

  it("envoie un POST complet et réinitialise le formulaire après succès", async () => {
    identity.roles = ["ROLE_RISK_MANAGER"];
    mockReads([]);
    const post = vi.spyOn(api, "post").mockResolvedValue({ data: thirdParty });
    renderPage();
    const dialog = await openCreateDialog();

    setField(dialog, "Nom", "New Supplier");
    setField(dialog, "Contact", "soc@supplier.example");
    setField(dialog, "Services", "SOC externalisé");
    setField(dialog, /^Catégories de données/, "Logs, Identifiants");
    setField(dialog, "Référence contrat", "CTR-NEW");
    setField(dialog, "SLA", "Prise en charge 30 minutes");
    setField(dialog, "Fin du contrat", "2028-12-31");
    setField(dialog, "Prochaine évaluation", "2027-06-30");
    setField(dialog, /^Certifications déclarées/, "ISO 27001, SOC 2");
    setField(dialog, "Synthèse du risque", "Accès aux journaux sensibles");
    setField(dialog, "Mesures compensatoires", "Accès JIT et audit mensuel");
    setField(dialog, "Dépendances", "SIEM et VPN");
    setField(dialog, "Plan de sortie", "Export puis révocation des accès");
    await selectOption(dialog, "Criticité", "Élevée");
    await selectOption(dialog, "Statut", "Actif");
    await selectOption(dialog, "Responsable", "Olivia Owner");
    const createButton = within(dialog).getByRole("button", { name: "Créer" });
    await waitFor(() => expect(createButton).toBeEnabled());
    fireEvent.click(createButton);

    await waitFor(() =>
      expect(post).toHaveBeenCalledWith("/third-parties", {
        name: "New Supplier",
        contactEmail: "soc@supplier.example",
        services: "SOC externalisé",
        dataCategories: ["Logs", "Identifiants"],
        criticality: "HIGH",
        status: "ACTIVE",
        ownerId: 2,
        contractReference: "CTR-NEW",
        sla: "Prise en charge 30 minutes",
        dependencies: "SIEM et VPN",
        exitPlan: "Export puis révocation des accès",
        contractEndsAt: "2028-12-31",
        nextAssessmentAt: "2027-06-30",
        certifications: ["ISO 27001", "SOC 2"],
        riskSummary: "Accès aux journaux sensibles",
        compensatingMeasures: "Accès JIT et audit mensuel",
      }),
    );
    await waitFor(() =>
      expect(
        screen.queryByRole("dialog", { name: "Nouveau tiers" }),
      ).not.toBeInTheDocument(),
    );
    const reopened = await openCreateDialog();
    expect(within(reopened).getByLabelText(/^Nom/)).toHaveValue("");
    expect(within(reopened).getByLabelText("Référence contrat")).toHaveValue(
      "",
    );
  });

  it("envoie un PUT complet sans perdre les champs non modifiés", async () => {
    identity.roles = ["ROLE_RISK_MANAGER"];
    mockReads();
    const put = vi.spyOn(api, "put").mockResolvedValue({
      data: { ...thirdParty, name: "Cloud Payroll Europe" },
    });
    renderPage();
    await screen.findByText("Cloud Payroll");
    const dialog = await openEditDialog();
    setField(dialog, "Nom", "Cloud Payroll Europe");
    const saveButton = within(dialog).getByRole("button", {
      name: "Enregistrer",
    });
    await waitFor(() => expect(saveButton).toBeEnabled());
    fireEvent.click(saveButton);

    await waitFor(() =>
      expect(put).toHaveBeenCalledWith("/third-parties/41", {
        name: "Cloud Payroll Europe",
        contactEmail: "security@payroll.example",
        services: "Externalisation de la paie",
        dataCategories: ["Données clients, sensibles", "Données bancaires"],
        criticality: "CRITICAL",
        status: "ACTIVE",
        ownerId: 2,
        contractReference: "CTR-2026-041",
        sla: "Disponibilité 99,95 %",
        dependencies: "API RH et SSO",
        exitPlan: "Export chiffré puis bascule sous 30 jours",
        contractEndsAt: "2020-09-30",
        nextAssessmentAt: "2020-10-01",
        certifications: ["ISO 27001, 2022", "SOC 2 Type II"],
        riskSummary: "Concentration et accès à des données sensibles.",
        compensatingMeasures:
          "Chiffrement, revue trimestrielle et journalisation.",
      }),
    );
  });

  it("réinitialise un brouillon annulé", async () => {
    identity.roles = ["ROLE_RISK_MANAGER"];
    mockReads([]);
    renderPage();
    const dialog = await openCreateDialog();
    setField(dialog, "Nom", "Brouillon temporaire");
    setField(dialog, "Référence contrat", "TEMP-1");
    fireEvent.click(within(dialog).getByRole("button", { name: "Annuler" }));

    const reopened = await openCreateDialog();
    expect(within(reopened).getByLabelText(/^Nom/)).toHaveValue("");
    expect(within(reopened).getByLabelText("Référence contrat")).toHaveValue(
      "",
    );
  });

  it("préserve le formulaire après une erreur d'enregistrement", async () => {
    identity.roles = ["ROLE_RISK_MANAGER"];
    mockReads([]);
    vi.spyOn(api, "post").mockRejectedValue(new Error("offline"));
    renderPage();
    const dialog = await openCreateDialog();
    setField(dialog, "Nom", "Supplier to retry");
    setField(dialog, "Synthèse du risque", "Valeur à conserver");
    const createButton = within(dialog).getByRole("button", { name: "Créer" });
    await waitFor(() => expect(createButton).toBeEnabled());
    fireEvent.click(createButton);

    expect(
      await within(dialog).findByText("Création du tiers impossible."),
    ).toBeVisible();
    expect(within(dialog).getByLabelText(/^Nom/)).toHaveValue(
      "Supplier to retry",
    );
    expect(within(dialog).getByLabelText("Synthèse du risque")).toHaveValue(
      "Valeur à conserver",
    );
  });

  it("recherche et combine les filtres criticité, statut et suivi", async () => {
    identity.roles = ["ROLE_RISK_MANAGER"];
    mockReads([thirdParty, unevaluated, terminated]);
    renderPage();
    await screen.findByText("Cloud Payroll");

    fireEvent.change(screen.getByLabelText("Rechercher"), {
      target: { value: "archive" },
    });
    expect(screen.getByText("HR Archive")).toBeVisible();
    expect(screen.queryByText("Cloud Payroll")).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Rechercher"), {
      target: { value: "" },
    });

    await selectOption(document.body, "Criticité", "Critique");
    expect(screen.getByText("Cloud Payroll")).toBeVisible();
    expect(screen.getByText("Legacy Hosting")).toBeVisible();
    expect(screen.queryByText("HR Archive")).not.toBeInTheDocument();
    await selectOption(document.body, "Statut", "Actif");
    expect(screen.getByText("Cloud Payroll")).toBeVisible();
    expect(screen.queryByText("Legacy Hosting")).not.toBeInTheDocument();

    await selectOption(document.body, "Statut", "Tous");
    await selectOption(document.body, "Criticité", "Toutes");
    fireEvent.click(screen.getByLabelText("À suivre uniquement"));
    expect(screen.getByText("Cloud Payroll")).toBeVisible();
    expect(screen.queryByText("Legacy Hosting")).not.toBeInTheDocument();
    expect(screen.getByText("HR Archive")).toBeVisible();
  });

  it("inclut une évaluation soumise dans les tiers à suivre", async () => {
    mockReads([unevaluated]);
    renderPage();
    await screen.findByText("HR Archive");

    fireEvent.click(screen.getByLabelText("À suivre uniquement"));
    expect(screen.getByText("HR Archive")).toBeVisible();
  });

  it("inclut un tiers élevé non évalué, sauf s'il est terminé", async () => {
    mockReads([
      highUnevaluated,
      {
        ...highUnevaluated,
        id: 45,
        name: "Terminated Newcomer",
        status: "TERMINATED",
      },
    ]);
    renderPage();
    await screen.findByText("Critical Newcomer");

    fireEvent.click(screen.getByLabelText("À suivre uniquement"));
    expect(screen.getByText("Critical Newcomer")).toBeVisible();
    expect(screen.queryByText("Terminated Newcomer")).not.toBeInTheDocument();
  });

  it("signale les échéances dépassées mais jamais pour un tiers terminé", async () => {
    mockReads([thirdParty, terminated]);
    renderPage();
    await screen.findByText("Cloud Payroll");

    const activeCard = thirdPartyCard("Cloud Payroll");
    expect(activeCard.getByText("Contrat expiré le 2020-09-30")).toBeVisible();
    expect(
      activeCard.getByText("Réévaluation en retard depuis le 2020-10-01"),
    ).toBeVisible();
    expect(
      thirdPartyCard("Legacy Hosting").queryByText(/expiré|retard/),
    ).not.toBeInTheDocument();
  });

  it("distingue un score réellement nul d'un tiers non évalué", async () => {
    mockReads([thirdParty, unevaluated]);
    renderPage();
    await screen.findByText("Cloud Payroll");

    expect(
      thirdPartyCard("Cloud Payroll").getByText("Cyberscore 0%"),
    ).toBeVisible();
    expect(thirdPartyCard("HR Archive").getByText("Non évalué")).toBeVisible();
    expect(
      thirdPartyCard("HR Archive").queryByText("Cyberscore 86%"),
    ).not.toBeInTheDocument();
  });

  it("affiche les contrôles, alertes et états d'évaluation en anglais", async () => {
    identity.locale = "en";
    identity.roles = ["ROLE_RISK_MANAGER"];
    mockReads([thirdParty, unevaluated]);
    renderPage();
    await screen.findByText("Cloud Payroll");

    expect(screen.getByLabelText("Search")).toBeVisible();
    expect(screen.getByRole("combobox", { name: "Criticality" })).toBeVisible();
    expect(screen.getByRole("combobox", { name: "Status" })).toBeVisible();
    expect(screen.getByLabelText("Follow-up only")).toBeEnabled();
    expect(screen.getByText("Follow-up only")).toBeVisible();
    expect(
      thirdPartyCard("Cloud Payroll").getByText(
        "Contract expired on 2020-09-30",
      ),
    ).toBeVisible();
    expect(
      thirdPartyCard("Cloud Payroll").getByText(
        "Reassessment overdue since 2020-10-01",
      ),
    ).toBeVisible();
    expect(
      thirdPartyCard("HR Archive").getByText("Not assessed"),
    ).toBeVisible();

    const dialog = await openCreateDialog("Add a third party");
    for (const label of [
      /^Contract reference/,
      /^Contract end/,
      /^Next assessment/,
      /^Declared certifications/,
      /^Risk summary/,
      /^Compensating measures/,
    ]) {
      expect(within(dialog).getByLabelText(label)).toBeVisible();
    }
  });
});
