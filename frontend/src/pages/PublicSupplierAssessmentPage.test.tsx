import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { api } from "../api/client";
import { PublicSupplierAssessmentPage } from "./PublicSupplierAssessmentPage";

const identity = vi.hoisted(() => ({ locale: "fr" as "fr" | "en" }));
vi.mock("../i18n/InterfaceLocaleContext", () => ({
  useInterfaceLocale: () => identity.locale,
}));

const token = "a".repeat(64);
const assessment = {
  thirdParty: "Fournisseur fictif",
  title: "Questionnaire sécurité",
  version: 1,
  status: "DRAFT",
  expiresAt: "2099-12-31T10:00:00Z",
  questions: [
    { id: "mfa", label: "Décrivez votre protection des accès", weight: 2 },
    { id: "backup", label: "Décrivez vos sauvegardes", weight: 1 },
  ],
};

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  identity.locale = "fr";
});

function Location() {
  return <output aria-label="Current location">{useLocation().search}</output>;
}

function showPage(path = `/supplier-assessments/${token}`) {
  return render(
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
      <MemoryRouter initialEntries={[path]}>
        <Location />
        <Routes>
          <Route
            path="/supplier-assessments/:token"
            element={<PublicSupplierAssessmentPage />}
          />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

function mockReads(data: unknown = assessment) {
  return vi.spyOn(api, "get").mockResolvedValue({ data });
}

function apiError(status: number) {
  return { isAxiosError: true, response: { status } };
}

async function fillAnswers() {
  fireEvent.change(await screen.findByLabelText(/Votre réponse 1/), {
    target: { value: "  Accès protégés par MFA.  " },
  });
  fireEvent.change(screen.getByLabelText(/Votre réponse 2/), {
    target: { value: "Sauvegardes hors ligne." },
  });
}

describe("PublicSupplierAssessmentPage", () => {
  it("loads a cancellable anonymous questionnaire without automatic submission", async () => {
    const get = mockReads();
    const post = vi.spyOn(api, "post");
    showPage();
    expect(await screen.findByText(assessment.title)).toBeInTheDocument();
    expect(get).toHaveBeenCalledWith(`/public/supplier-assessments/${token}`, {
      signal: expect.any(AbortSignal),
    });
    expect(
      screen.getByText(/1\. Décrivez votre protection/),
    ).toBeInTheDocument();
    expect(screen.getByLabelText(/Votre réponse 1/)).toBeRequired();
    expect(
      screen.getByRole("button", { name: "Envoyer mes réponses" }),
    ).toBeDisabled();
    expect(
      screen.getByText(/ne pourront plus être modifiées/),
    ).toBeInTheDocument();
    expect(screen.queryByRole("link")).not.toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it.each(["invalid", "g".repeat(64), "a".repeat(63)])(
    "rejects malformed token without an API call (%s)",
    async (invalid) => {
      const get = mockReads();
      const post = vi.spyOn(api, "post");
      showPage(`/supplier-assessments/${invalid}`);
      expect(
        await screen.findByText(/Ce lien est invalide/),
      ).toBeInTheDocument();
      expect(get).not.toHaveBeenCalled();
      expect(post).not.toHaveBeenCalled();
      expect(
        screen.queryByRole("button", { name: "Réessayer" }),
      ).not.toBeInTheDocument();
    },
  );

  it("blocks direct submission with missing or whitespace-only answers", async () => {
    mockReads();
    const post = vi.spyOn(api, "post");
    showPage();
    const first = await screen.findByLabelText(/Votre réponse 1/);
    fireEvent.change(first, { target: { value: "   " } });
    fireEvent.change(screen.getByLabelText(/Votre réponse 2/), {
      target: { value: "Réponse" },
    });
    fireEvent.submit(first.closest("form")!);
    expect(post).not.toHaveBeenCalled();
    expect(
      screen.getByText(/Complétez toutes les réponses/),
    ).toBeInTheDocument();
  });

  it("posts trimmed plain-text answers and references, then prevents a second submission", async () => {
    mockReads();
    const post = vi.spyOn(api, "post").mockResolvedValue({
      data: { status: "SUBMITTED", submittedAt: "2026-10-05T12:00:00Z" },
    });
    showPage();
    await fillAnswers();
    fireEvent.change(screen.getByLabelText(/Références complémentaires/), {
      target: { value: "  Dossier interne 42\n\njavascript:alert(1)  " },
    });
    fireEvent.click(
      screen.getByRole("button", { name: "Envoyer mes réponses" }),
    );
    await screen.findByText(/Merci\. Vos réponses/);
    expect(post).toHaveBeenCalledExactlyOnceWith(
      `/public/supplier-assessments/${token}`,
      {
        responses: {
          mfa: "Accès protégés par MFA.",
          backup: "Sauvegardes hors ligne.",
        },
        evidence: ["Dossier interne 42", "javascript:alert(1)"],
      },
    );
    expect(screen.queryByRole("textbox")).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Envoyer mes réponses" }),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole("link")).not.toBeInTheDocument();
  });

  it("renders English without translating supplier-provided content", async () => {
    identity.locale = "en";
    mockReads();
    showPage();
    expect(
      await screen.findByRole("heading", { name: "Supplier questionnaire" }),
    ).toBeInTheDocument();
    expect(await screen.findByLabelText(/Your answer 1/)).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Submit my answers" }),
    ).toBeDisabled();
    expect(screen.getByText(/No files are uploaded/)).toBeInTheDocument();
    expect(screen.getByText(assessment.title)).toBeInTheDocument();
  });

  it.each(["SUBMITTED", "REVIEWED"])(
    "shows already-answered state without a form for %s",
    async (status) => {
      mockReads({ ...assessment, status });
      const post = vi.spyOn(api, "post");
      showPage();
      expect(
        await screen.findByText(/a déjà reçu une réponse/),
      ).toBeInTheDocument();
      expect(screen.queryByRole("textbox")).not.toBeInTheDocument();
      expect(post).not.toHaveBeenCalled();
    },
  );

  it.each([
    { ...assessment, status: "EXPIRED" },
    { ...assessment, expiresAt: "2000-01-01T00:00:00Z" },
  ])(
    "does not allow an expired questionnaire to be answered",
    async (expired) => {
      mockReads(expired);
      showPage();
      expect(
        await screen.findByText(/questionnaire a expiré/),
      ).toBeInTheDocument();
      expect(screen.queryByRole("textbox")).not.toBeInTheDocument();
    },
  );

  it("offers retry for a transient GET failure", async () => {
    const get = vi
      .spyOn(api, "get")
      .mockRejectedValueOnce(apiError(503))
      .mockResolvedValue({ data: assessment });
    showPage();
    fireEvent.click(await screen.findByRole("button", { name: "Réessayer" }));
    expect(await screen.findByLabelText(/Votre réponse 1/)).toBeInTheDocument();
    expect(get).toHaveBeenCalledTimes(2);
  });

  it("shows a friendly 404 without retry or response fields", async () => {
    const get = vi.spyOn(api, "get").mockRejectedValue(apiError(404));
    showPage();
    expect(await screen.findByText(/Ce lien est invalide/)).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Réessayer" }),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole("textbox")).not.toBeInTheDocument();
    expect(get).toHaveBeenCalledTimes(1);
  });

  it("preserves entries after a failed submission and permits manual retry", async () => {
    mockReads();
    const post = vi
      .spyOn(api, "post")
      .mockRejectedValueOnce(apiError(503))
      .mockResolvedValue({ data: { status: "SUBMITTED" } });
    showPage();
    await fillAnswers();
    fireEvent.change(screen.getByLabelText(/Références complémentaires/), {
      target: { value: "Référence déclarative" },
    });
    fireEvent.click(
      screen.getByRole("button", { name: "Envoyer mes réponses" }),
    );
    expect(await screen.findByText(/L’envoi a échoué/)).toBeInTheDocument();
    expect(screen.getByLabelText(/Votre réponse 1/)).toHaveValue(
      "  Accès protégés par MFA.  ",
    );
    expect(screen.getByLabelText(/Références complémentaires/)).toHaveValue(
      "Référence déclarative",
    );
    fireEvent.click(
      screen.getByRole("button", { name: "Envoyer mes réponses" }),
    );
    expect(await screen.findByText(/Merci\. Vos réponses/)).toBeInTheDocument();
    expect(post).toHaveBeenCalledTimes(2);
  });

  it("locks pending input and guards repeated direct form submission", async () => {
    mockReads();
    let resolve!: (value: { data: { status: string } }) => void;
    const post = vi.spyOn(api, "post").mockReturnValue(
      new Promise((done) => {
        resolve = done;
      }),
    );
    showPage();
    await fillAnswers();
    const form = screen.getByLabelText(/Votre réponse 1/).closest("form")!;
    fireEvent.submit(form);
    await waitFor(() =>
      expect(screen.getByLabelText(/Votre réponse 1/)).toBeDisabled(),
    );
    expect(screen.getByLabelText(/Références complémentaires/)).toBeDisabled();
    expect(screen.getByRole("button", { name: "FR" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Envoi…" })).toBeDisabled();
    fireEvent.submit(form);
    expect(post).toHaveBeenCalledTimes(1);
    resolve({ data: { status: "SUBMITTED" } });
    expect(await screen.findByText(/Merci\. Vos réponses/)).toBeInTheDocument();
  });

  it.each([
    Array.from({ length: 11 }, (_, index) => `Référence ${index}`).join("\n"),
    "a".repeat(501),
    "<script>Référence invalide</script>",
  ])("blocks out-of-limit references", async (references) => {
    mockReads();
    const post = vi.spyOn(api, "post");
    showPage();
    await fillAnswers();
    fireEvent.change(screen.getByLabelText(/Références complémentaires/), {
      target: { value: references },
    });
    expect(
      screen.getByRole("button", { name: "Envoyer mes réponses" }),
    ).toBeDisabled();
    fireEvent.submit(screen.getByLabelText(/Votre réponse 1/).closest("form")!);
    expect(post).not.toHaveBeenCalled();
  });

  it("handles prototype-like and numeric legacy question IDs as own response keys", async () => {
    mockReads({
      ...assessment,
      questions: [
        { id: "__proto__", label: "Question A", weight: 1 },
        { id: 12, label: "Question B", weight: 1 },
        { id: "constructor", label: "Question C", weight: 1 },
      ],
    });
    const post = vi
      .spyOn(api, "post")
      .mockResolvedValue({ data: { status: "SUBMITTED" } });
    showPage();
    fireEvent.change(await screen.findByLabelText(/Votre réponse 1/), {
      target: { value: "Réponse A" },
    });
    fireEvent.change(screen.getByLabelText(/Votre réponse 2/), {
      target: { value: "Réponse B" },
    });
    fireEvent.change(screen.getByLabelText(/Votre réponse 3/), {
      target: { value: "Réponse C" },
    });
    fireEvent.click(
      screen.getByRole("button", { name: "Envoyer mes réponses" }),
    );
    await screen.findByText(/Merci\. Vos réponses/);
    const responses = (
      post.mock.calls[0][1] as { responses: Record<string, string> }
    ).responses;
    expect(Object.hasOwn(responses, "__proto__")).toBe(true);
    expect(responses.__proto__).toBe("Réponse A");
    expect(responses["12"]).toBe("Réponse B");
    expect(responses.constructor).toBe("Réponse C");
  });

  it("changes query language while retaining other parameters without resubmitting", async () => {
    mockReads();
    const post = vi.spyOn(api, "post");
    showPage(`/supplier-assessments/${token}?lang=fr&source=invite`);
    await screen.findByLabelText(/Votre réponse 1/);
    fireEvent.click(screen.getByRole("button", { name: "EN" }));
    expect(screen.getByLabelText("Current location")).toHaveTextContent(
      "lang=en&source=invite",
    );
    expect(post).not.toHaveBeenCalled();
  });
});
