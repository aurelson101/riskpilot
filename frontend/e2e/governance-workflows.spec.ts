import { expect, test, type APIRequestContext, type Page } from "@playwright/test";

async function authenticate(page: Page, request: APIRequestContext) {
  const login = await request.post("/api/auth/login", {
    data: { email: "admin@riskpilot.local", password: "ChangeMe123!" },
  });
  expect(login.ok()).toBeTruthy();
  const { token } = (await login.json()) as { token: string };
  await page.addInitScript((accessToken) => {
    sessionStorage.setItem("riskpilot.accessToken", accessToken);
  }, token);
}

test("le plan d’action guide la saisie et conserve les détails secondaires", async ({ page, request }) => {
  await authenticate(page, request);
  await page.goto("/actions", { waitUntil: "networkidle" });
  await page.getByRole("button", { name: /Créer une action|Create action/ }).click();

  const dialog = page.getByRole("dialog");
  await expect(dialog.getByLabel(/Début|Start/)).not.toHaveValue("");
  await expect(dialog.getByLabel(/Échéance|Due date/)).not.toHaveValue("");
  await expect(dialog.getByText(/Informations complémentaires|Additional information/)).toBeVisible();
  await dialog.getByText(/Informations complémentaires|Additional information/).click();
  await expect(dialog.getByLabel(/Référence du ticket|Ticket reference/)).toBeVisible();
  await dialog.getByRole("button", { name: /Annuler|Cancel/ }).click();
});

test("les vues NIS2 et EBIOS rendent les priorités et étapes explicites", async ({ page, request }) => {
  await authenticate(page, request);
  await page.goto("/nis2", { waitUntil: "networkidle" });
  await expect(page.getByRole("heading", { name: /Conformité NIS2|NIS2 compliance/ })).toBeVisible();
  await expect(page.getByRole("link", { name: /Voir les plans|View plans/ })).toBeVisible();

  await page.goto("/ebios", { waitUntil: "networkidle" });
  await expect(page.getByRole("heading", { name: /EBIOS Risk Manager/ })).toBeVisible();
  await expect(
    page.getByText(/Parcours EBIOS RM|EBIOS RM journey|Créez d’abord une analyse|Create an EBIOS analysis first/),
  ).toBeVisible();
});
