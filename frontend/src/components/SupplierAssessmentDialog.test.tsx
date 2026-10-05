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
import { api } from "../api/client";
import { SupplierAssessmentDialog } from "./SupplierAssessmentDialog";

const identity = vi.hoisted(() => ({ locale: "fr" as "fr" | "en" }));

vi.mock("../i18n/InterfaceLocaleContext", () => ({
  useInterfaceLocale: () => identity.locale,
}));

const assessment = {
  id: 73,
  title: "Revue sécurité du prestataire",
  version: 2,
  reviewer: { id: 7, name: "Risk Manager" },
  status: "SUBMITTED",
  expiresAt: "2027-12-31T10:00:00+00:00",
  submittedAt: "2026-10-05T10:00:00+00:00",
  reviewedAt: null as string | null,
  score: 0,
  reviewComment: null as string | null,
  questions: [
    { id: "mfa", label: "Authentification multifacteur", weight: 2 },
    { id: "backup", label: "Sauvegarde hors ligne", weight: 1 },
    { id: "plan", label: "Plan de remédiation", weight: 1 },
  ],
  responses: {
    mfa: true,
    backup: false,
    plan: "Mise à jour des procédures en novembre.",
  },
  evidence: [
    "https://supplier.example.test/attestation.pdf",
    "javascript:alert(document.cookie)",
    "Référence déclarée ISO 27001",
  ],
};

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  identity.locale = "fr";
});

function mockRead(data: unknown = assessment) {
  return vi.spyOn(api, "get").mockResolvedValue({ data });
}

function showDialog(
  props: Partial<Parameters<typeof SupplierAssessmentDialog>[0]> = {},
  client = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  }),
) {
  const onClose = vi.fn();
  const result = render(
    <QueryClientProvider client={client}>
      <SupplierAssessmentDialog
        assessmentId={73}
        canManage
        onClose={onClose}
        {...props}
      />
    </QueryClientProvider>,
  );
  return { ...result, client, onClose };
}

async function ready() {
  await screen.findByText(assessment.title);
}

function fillReview(
  score = "85",
  comment = "Revue humaine et pièces contrôlées.",
) {
  fireEvent.change(
    screen.getByRole("spinbutton", { name: "Score validé (%)" }),
    {
      target: { value: score },
    },
  );
  fireEvent.change(
    screen.getByRole("textbox", { name: "Commentaire de revue" }),
    { target: { value: comment } },
  );
}

describe("SupplierAssessmentDialog", () => {
  it("ne charge rien quand aucune évaluation n'est sélectionnée", () => {
    const read = mockRead();
    showDialog({ assessmentId: null });

    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(read).not.toHaveBeenCalled();
  });

  it.each([
    {
      locale: "fr" as const,
      title: "Évaluation fournisseur",
      yes: "Oui",
      no: "Non",
    },
    {
      locale: "en" as const,
      title: "Supplier assessment",
      yes: "Yes",
      no: "No",
    },
  ])(
    "lit les réponses fournisseur en $locale sans interpréter ses références",
    async ({ locale, title, yes, no }) => {
      identity.locale = locale;
      const read = mockRead();
      showDialog({ canManage: false });
      await ready();

      const dialog = screen.getByRole("dialog", { name: title });
      expect(read).toHaveBeenCalledWith("/supplier-assessments/73", {
        signal: expect.any(AbortSignal),
      });
      expect(
        within(dialog).getByText("Authentification multifacteur"),
      ).toBeVisible();
      expect(within(dialog).getByText(yes, { exact: true })).toBeVisible();
      expect(within(dialog).getByText(no, { exact: true })).toBeVisible();
      expect(within(dialog).getByText(assessment.responses.plan)).toBeVisible();
      for (const reference of assessment.evidence) {
        expect(within(dialog).getByText(reference)).toBeVisible();
      }
      expect(within(dialog).queryByRole("link")).not.toBeInTheDocument();
      expect(within(dialog).queryByRole("spinbutton")).not.toBeInTheDocument();
      expect(within(dialog).queryByRole("textbox")).not.toBeInTheDocument();
      expect(
        within(dialog).queryByRole("button", {
          name: /Valider la revue|Validate review/,
        }),
      ).not.toBeInTheDocument();
    },
  );

  it("distingue une référence fournie d'une preuve vérifiée", async () => {
    mockRead();
    showDialog({ canManage: false });
    await ready();

    expect(
      screen.getByText(
        /(?:non vérifi|ne .*pas.*(?:preuve|conformité)|vérifier)/i,
      ),
    ).toBeVisible();
  });

  it("permet au viewer de fermer la lecture sans envoyer de revue", async () => {
    mockRead();
    const post = vi.spyOn(api, "post");
    const { onClose } = showDialog({ canManage: false });
    await ready();
    fireEvent.click(screen.getByRole("button", { name: "Fermer" }));

    expect(onClose).toHaveBeenCalledOnce();
    expect(post).not.toHaveBeenCalled();
  });

  it("affiche une revue déjà validée, y compris un score nul, sans formulaire", async () => {
    mockRead({
      ...assessment,
      status: "REVIEWED",
      reviewedAt: "2026-10-05T11:00:00+00:00",
      reviewComment: "Les preuves ne permettent pas de valider les contrôles.",
      score: 0,
    });
    showDialog();
    await ready();

    expect(screen.getByText(/0\s*%/)).toBeVisible();
    expect(
      screen.getByText(
        "Les preuves ne permettent pas de valider les contrôles.",
      ),
    ).toBeVisible();
    expect(screen.queryByRole("spinbutton")).not.toBeInTheDocument();
    expect(screen.queryByRole("textbox")).not.toBeInTheDocument();
  });

  it.each(["DRAFT", "SENT", "IN_PROGRESS", "EXPIRED"])(
    "n'autorise pas une revue pour le statut %s",
    async (status) => {
      mockRead({ ...assessment, status });
      showDialog();
      await ready();

      expect(screen.queryByRole("spinbutton")).not.toBeInTheDocument();
      expect(
        screen.queryByRole("button", { name: "Valider la revue" }),
      ).not.toBeInTheDocument();
    },
  );

  it.each(["", "-1", "101", "85.5"])(
    "ne soumet pas un score invalide : %s",
    async (score) => {
      mockRead();
      const post = vi.spyOn(api, "post");
      showDialog();
      await ready();
      fillReview(score);
      const submit = screen.getByRole("button", { name: "Valider la revue" });

      expect(submit).toBeDisabled();
      fireEvent.click(submit);
      fireEvent.submit(screen.getByRole("spinbutton").closest("form")!);
      expect(post).not.toHaveBeenCalled();
    },
  );

  it("exige un commentaire humain non vide", async () => {
    mockRead();
    const post = vi.spyOn(api, "post");
    showDialog();
    await ready();
    fillReview("85", "   ");
    const submit = screen.getByRole("button", { name: "Valider la revue" });

    expect(submit).toBeDisabled();
    fireEvent.click(submit);
    fireEvent.submit(screen.getByRole("spinbutton").closest("form")!);
    expect(post).not.toHaveBeenCalled();
  });

  it("transmet un score entier et le commentaire puis actualise le registre et le détail", async () => {
    mockRead();
    const reviewed = {
      id: assessment.id,
      status: "REVIEWED",
      score: 85,
      reviewComment: "Revue humaine et pièces contrôlées.",
      reviewedAt: "2026-10-05T11:00:00+00:00",
    };
    const post = vi.spyOn(api, "post").mockResolvedValue({ data: reviewed });
    const { client } = showDialog();
    const invalidate = vi.spyOn(client, "invalidateQueries");
    await ready();
    fillReview("85", "  Revue humaine et pièces contrôlées.  ");
    fireEvent.click(screen.getByRole("button", { name: "Valider la revue" }));

    await waitFor(() => {
      expect(post).toHaveBeenCalledWith("/supplier-assessments/73/review", {
        score: 85,
        comment: "Revue humaine et pièces contrôlées.",
      });
      expect(invalidate).toHaveBeenCalledWith({ queryKey: ["third-parties"] });
      expect(invalidate).toHaveBeenCalledWith({
        queryKey: ["supplier-assessment", 73],
      });
    });
  });

  it("préserve les champs après une erreur API et permet un nouvel essai", async () => {
    mockRead();
    const post = vi
      .spyOn(api, "post")
      .mockRejectedValueOnce(new Error("provider-internal-detail"))
      .mockResolvedValueOnce({
        data: {
          ...assessment,
          status: "REVIEWED",
          score: 85,
          reviewComment: "Revue humaine et pièces contrôlées.",
        },
      });
    showDialog();
    await ready();
    fillReview();
    fireEvent.click(screen.getByRole("button", { name: "Valider la revue" }));

    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    await waitFor(() =>
      expect(
        screen.getByRole("button", { name: "Valider la revue" }),
      ).toBeEnabled(),
    );
    expect(
      screen.getByRole("spinbutton", { name: "Score validé (%)" }),
    ).toHaveValue(85);
    expect(
      screen.getByRole("textbox", { name: "Commentaire de revue" }),
    ).toHaveValue("Revue humaine et pièces contrôlées.");
    expect(
      screen.queryByText("provider-internal-detail"),
    ).not.toBeInTheDocument();
    expect(screen.getByText(/Impossible de valider la revue/i)).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Valider la revue" }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
  });

  it("bloque la fermeture et la double soumission pendant la revue", async () => {
    mockRead();
    const post = vi
      .spyOn(api, "post")
      .mockImplementation(() => new Promise(() => {}));
    const { onClose } = showDialog();
    await ready();
    fillReview();
    fireEvent.click(screen.getByRole("button", { name: "Valider la revue" }));
    await waitFor(() => expect(post).toHaveBeenCalledOnce());

    expect(screen.getByRole("button", { name: "Fermer" })).toBeDisabled();
    expect(
      screen.getByRole("button", { name: /Valider la revue|Validation/ }),
    ).toBeDisabled();
    expect(
      screen.getByRole("spinbutton", { name: "Score validé (%)" }),
    ).toBeDisabled();
    expect(
      screen.getByRole("textbox", { name: "Commentaire de revue" }),
    ).toBeDisabled();
    fireEvent.click(screen.getByRole("button", { name: "Fermer" }));
    fireEvent.keyDown(screen.getByRole("dialog"), {
      key: "Escape",
      code: "Escape",
    });
    expect(onClose).not.toHaveBeenCalled();
    expect(post).toHaveBeenCalledOnce();
  });

  it("propose de réessayer le chargement sans révéler l'erreur technique", async () => {
    const read = vi
      .spyOn(api, "get")
      .mockRejectedValueOnce(new Error("database-internal-detail"))
      .mockResolvedValueOnce({ data: assessment });
    showDialog({ canManage: false });

    const retry = await screen.findByRole("button", { name: "Réessayer" });
    expect(
      screen.queryByText("database-internal-detail"),
    ).not.toBeInTheDocument();
    fireEvent.click(retry);
    await ready();
    expect(read).toHaveBeenCalledTimes(2);
  });
});
