import {
  expect,
  test,
  type APIRequestContext,
  type Page,
} from "@playwright/test";

async function authenticate(
  page: Page,
  request: APIRequestContext,
  email: string,
) {
  const login = await request.post("/api/auth/login", {
    data: { email, password: "ChangeMe123!" },
  });
  expect(login.ok()).toBeTruthy();
  const { token } = (await login.json()) as { token: string };
  await page.goto("/login");
  await page.evaluate((accessToken) => {
    sessionStorage.setItem("riskpilot.accessToken", accessToken);
  }, token);
  await page.goto("/experiments", { waitUntil: "networkidle" });
}

test("les actions P3 suivent les rôles serveur", async ({ page, request }) => {
  await authenticate(page, request, "action.owner@riskpilot.local");
  await expect(
    page.getByRole("button", {
      name: /Créer une proposition|Create a proposal/,
    }),
  ).toHaveCount(0);
  await page.getByRole("tab", { name: /Bibliothèque|Library/ }).click();
  await expect(
    page.getByRole("button", { name: /Créer une ressource|Create a resource/ }),
  ).toHaveCount(0);

  await authenticate(page, request, "risk.manager@riskpilot.local");
  await expect(
    page.getByRole("button", {
      name: /Créer une proposition|Create a proposal/,
    }),
  ).toBeVisible();

  await authenticate(page, request, "admin@riskpilot.local");
  await expect(
    page.getByRole("switch", {
      name: /Activer l’expérimentation|Enable experimentation/,
    }),
  ).toBeVisible();
});
