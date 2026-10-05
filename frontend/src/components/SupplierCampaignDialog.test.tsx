import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { api } from "../api/client";
import { SupplierCampaignDialog } from "./SupplierCampaignDialog";

const identity = vi.hoisted(() => ({
  locale: "fr" as "fr" | "en",
  roles: ["ROLE_RISK_MANAGER"],
}));
vi.mock("../i18n/InterfaceLocaleContext", () => ({
  useInterfaceLocale: () => identity.locale,
}));
vi.mock("../auth/useAuth", () => ({
  useAuth: () => ({
    user: {
      id: 7,
      email: "reviewer@example.test",
      firstName: "Risk",
      lastName: "Manager",
      roles: identity.roles,
    },
  }),
}));
const token = "a".repeat(64);
const supplierLink = `${window.location.origin}/supplier-assessments/${token}`;

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  identity.locale = "fr";
  identity.roles = ["ROLE_RISK_MANAGER"];
});

function showDialog() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const onClose = vi.fn();
  const read = vi.spyOn(api, "get").mockResolvedValue({
    data: [
      {
        id: 9,
        firstName: "Other",
        lastName: "Reviewer",
        email: "other@example.test",
      },
    ],
  });
  render(
    <QueryClientProvider client={client}>
      <SupplierCampaignDialog
        thirdParty={{ id: 41, name: "RiskPilot Supplier" }}
        onClose={onClose}
      />
    </QueryClientProvider>,
  );
  return { client, onClose, read };
}

const createButton = () =>
  screen.getByRole("button", { name: "Créer la campagne" });

function change(label: string, value: string) {
  fireEvent.change(
    screen.getByLabelText(new RegExp(`^${label}\\s*\\*?$`), {
      selector: "input,textarea",
    }),
    { target: { value } },
  );
}

async function createCampaign() {
  const post = vi.spyOn(api, "post").mockResolvedValue({
    data: { id: 99, publicToken: token },
  });
  const shown = showDialog();
  fireEvent.click(createButton());
  await screen.findByRole("textbox", { name: "Lien fournisseur" });
  return { ...shown, post };
}

describe("SupplierCampaignDialog", () => {
  it.each(["ROLE_VIEWER", "ROLE_USER"])(
    "ne crée ni ne charge un annuaire pour le rôle %s",
    (role) => {
      identity.roles = [role];
      const post = vi.spyOn(api, "post");
      const { read } = showDialog();
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
      expect(read).not.toHaveBeenCalled();
      expect(post).not.toHaveBeenCalled();
    },
  );

  it("permet à l’auditeur de se désigner sans charger l’annuaire interdit", () => {
    identity.roles = ["ROLE_AUDITOR"];
    const { read } = showDialog();
    expect(read).not.toHaveBeenCalled();
    expect(
      screen.getByRole("combobox", { name: /Évaluateur/ }),
    ).toHaveTextContent("Risk Manager");
    expect(createButton()).toBeEnabled();
  });

  it.each(["ROLE_ADMIN", "ROLE_RISK_MANAGER", "ROLE_SUPER_ADMIN"])(
    "charge l’annuaire pour le rôle %s avec une option courante stable",
    async (role) => {
      identity.roles = [role];
      const { read } = showDialog();
      await waitFor(() =>
        expect(read).toHaveBeenCalledWith("/users", {
          signal: expect.any(AbortSignal),
        }),
      );
      expect(
        screen.getByRole("combobox", { name: /Évaluateur/ }),
      ).toHaveTextContent("Risk Manager");
    },
  );

  it("crée un questionnaire personnalisé avec une échéance de fin de journée locale", async () => {
    const post = vi.spyOn(api, "post").mockResolvedValue({
      data: { id: 99, publicToken: token },
    });
    const { client } = showDialog();
    const invalidate = vi.spyOn(client, "invalidateQueries");
    change("Titre de la campagne", "  Évaluation contrat 2027  ");
    change("Version", "2");
    change("Date limite de réponse", "2027-12-31");
    change("Question 1", "  Quels accès sont protégés ?  ");
    change("Poids de la question 1", "5");
    fireEvent.click(createButton());

    await screen.findByRole("textbox", { name: "Lien fournisseur" });
    expect(post).toHaveBeenCalledWith("/third-parties/41/assessments", {
      reviewerId: 7,
      title: "Évaluation contrat 2027",
      version: 2,
      expiresAt: new Date("2027-12-31T23:59:59").toISOString(),
      questions: [
        { id: "q1", label: "Quels accès sont protégés ?", weight: 5 },
        { id: "q2", label: expect.any(String), weight: 1 },
        { id: "q3", label: expect.any(String), weight: 1 },
      ],
    });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["third-parties"] });
    expect(screen.getByText(/Aucun email n’a été envoyé/)).toBeVisible();
    expect(screen.getByText(/lien confidentiel/)).toBeVisible();
    expect(
      screen.queryByRole("button", { name: "Créer la campagne" }),
    ).not.toBeInTheDocument();
  });

  it("garde les IDs stables après suppression et ajout, sans JSON demandé", async () => {
    const post = vi
      .spyOn(api, "post")
      .mockResolvedValue({ data: { publicToken: token } });
    showDialog();
    fireEvent.click(
      screen.getByRole("button", { name: "Supprimer la question 2" }),
    );
    fireEvent.click(
      screen.getByRole("button", { name: "Ajouter une question" }),
    );
    expect(createButton()).toBeDisabled();
    change("Question 3", "Question ajoutée");
    fireEvent.click(createButton());
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    expect(post.mock.calls[0][1]).toMatchObject({
      questions: [
        { id: "q1" },
        { id: "q3" },
        { id: "q4", label: "Question ajoutée", weight: 1 },
      ],
    });
  });

  it.each([
    ["Titre de la campagne", "   "],
    ["Version", "0"],
    ["Version", "1.5"],
    ["Version", "2147483648"],
    ["Date limite de réponse", "2020-01-01"],
    ["Date limite de réponse", "2027-02-30"],
    ["Question 1", "   "],
    ["Question 1", "a".repeat(2001)],
    ["Poids de la question 1", "0"],
    ["Poids de la question 1", "101"],
    ["Poids de la question 1", "1.5"],
  ])("rejette le champ invalide %s = %s sans appel API", (label, value) => {
    const post = vi.spyOn(api, "post");
    showDialog();
    change(label, value);
    expect(createButton()).toBeDisabled();
    const form = screen.getByRole("dialog").querySelector("form");
    fireEvent.submit(form!);
    expect(post).not.toHaveBeenCalled();
  });

  it("empêche la suppression de la dernière question", () => {
    showDialog();
    fireEvent.click(
      screen.getByRole("button", { name: "Supprimer la question 3" }),
    );
    fireEvent.click(
      screen.getByRole("button", { name: "Supprimer la question 2" }),
    );
    expect(
      screen.getByRole("button", { name: "Supprimer la question 1" }),
    ).toBeDisabled();
    expect(createButton()).toBeEnabled();
  });

  it("verrouille le formulaire, la fermeture et les doublons pendant la création", async () => {
    let resolve!: (result: { data: { publicToken: string } }) => void;
    const post = vi.spyOn(api, "post").mockImplementation(
      () =>
        new Promise((done) => {
          resolve = done;
        }),
    );
    const { onClose } = showDialog();
    fireEvent.click(createButton());
    const pending = await screen.findByRole("button", { name: "Création…" });
    expect(pending).toBeDisabled();
    expect(screen.getByRole("button", { name: "Fermer" })).toBeDisabled();
    expect(
      screen.getByRole("textbox", { name: /Titre de la campagne/ }),
    ).toBeDisabled();
    expect(
      screen.getByRole("combobox", { name: /Évaluateur/ }),
    ).toHaveAttribute("aria-disabled", "true");
    expect(
      screen.getByRole("button", { name: "Ajouter une question" }),
    ).toBeDisabled();
    fireEvent.submit(screen.getByRole("dialog").querySelector("form")!);
    fireEvent.keyDown(screen.getByRole("dialog"), { key: "Escape" });
    expect(post).toHaveBeenCalledOnce();
    expect(onClose).not.toHaveBeenCalled();
    resolve({ data: { publicToken: token } });
    await screen.findByRole("textbox", { name: "Lien fournisseur" });
  });

  it("préserve les saisies après erreur et permet une nouvelle tentative", async () => {
    const post = vi
      .spyOn(api, "post")
      .mockRejectedValueOnce(new Error("Unavailable"))
      .mockResolvedValueOnce({ data: { publicToken: token } });
    showDialog();
    change("Titre de la campagne", "Revue conservée");
    fireEvent.click(createButton());
    await screen.findByText(/Vos saisies sont conservées/);
    expect(
      screen.getByRole("textbox", { name: /Titre de la campagne/ }),
    ).toHaveValue("Revue conservée");
    expect(createButton()).toBeEnabled();
    fireEvent.click(createButton());
    await screen.findByRole("textbox", { name: "Lien fournisseur" });
    expect(post).toHaveBeenCalledTimes(2);
  });

  it.each([undefined, "javascript:alert(1)", "bad", "a".repeat(65)])(
    "ne transforme jamais le jeton invalide %s en lien ou seconde campagne",
    async (publicToken) => {
      vi.spyOn(api, "post").mockResolvedValue({ data: { publicToken } });
      showDialog();
      fireEvent.click(createButton());
      await screen.findByText(/aucun lien valide/);
      expect(screen.queryByRole("link")).not.toBeInTheDocument();
      expect(
        screen.queryByRole("button", { name: "Créer la campagne" }),
      ).not.toBeInTheDocument();
    },
  );

  it("ne copie le lien confidentiel qu’après une action explicite, sans le stocker", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, "clipboard", {
      configurable: true,
      value: { writeText },
    });
    const setItem = vi.spyOn(Storage.prototype, "setItem");
    const { client } = await createCampaign();
    expect(writeText).not.toHaveBeenCalled();
    expect(setItem).not.toHaveBeenCalled();
    expect(client.getQueryData(["third-parties"])).toBeUndefined();
    expect(
      screen.getByRole("textbox", { name: "Lien fournisseur" }),
    ).toHaveValue(supplierLink);
    expect(
      screen.getByRole("link", { name: "Ouvrir le questionnaire" }),
    ).toHaveAttribute("href", supplierLink);
    expect(screen.getByRole("link")).toHaveAttribute(
      "rel",
      "noopener noreferrer",
    );
    fireEvent.click(screen.getByRole("button", { name: "Copier le lien" }));
    await screen.findByText("Lien copié.");
    expect(writeText).toHaveBeenCalledWith(supplierLink);
  });

  it("propose la copie manuelle si le presse-papiers échoue", async () => {
    Object.defineProperty(navigator, "clipboard", {
      configurable: true,
      value: { writeText: vi.fn().mockRejectedValue(new Error("Denied")) },
    });
    await createCampaign();
    fireEvent.click(screen.getByRole("button", { name: "Copier le lien" }));
    await screen.findByText(/copiez-le manuellement/);
    expect(
      screen.getByRole("textbox", { name: "Lien fournisseur" }),
    ).toHaveAttribute("readonly");
  });

  it("affiche les formulaires et messages en anglais", async () => {
    identity.locale = "en";
    vi.spyOn(api, "post").mockResolvedValue({ data: { publicToken: token } });
    showDialog();
    expect(
      screen.getByRole("dialog", { name: "New supplier campaign" }),
    ).toBeVisible();
    expect(screen.getByRole("textbox", { name: /Campaign title/ })).toHaveValue(
      "Supplier assessment — RiskPilot Supplier",
    );
    expect(screen.getByRole("textbox", { name: /Question 1/ })).toHaveValue(
      "How do you protect sensitive access with multifactor authentication?",
    );
    fireEvent.click(screen.getByRole("button", { name: "Create campaign" }));
    await screen.findByText(/No email has been sent/);
    expect(screen.getByRole("textbox", { name: "Supplier link" })).toHaveValue(
      supplierLink,
    );
  });

  it("relie chaque label au contrôle correspondant", () => {
    showDialog();
    for (const label of screen
      .getByRole("dialog")
      .querySelectorAll("label[for]")) {
      expect(
        document.getElementById(label.getAttribute("for")!),
      ).not.toBeNull();
    }
    for (const control of screen.getAllByRole("combobox")) {
      for (const labelledBy of control
        .getAttribute("aria-labelledby")!
        .split(" ")) {
        expect(document.getElementById(labelledBy)).not.toBeNull();
      }
    }
  });
});
