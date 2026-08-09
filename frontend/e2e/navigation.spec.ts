import { expect, test } from "@playwright/test";

test("un seul groupe reste ouvert entre Pilotage et NIS2", async ({
  page,
  request,
}) => {
  const login = await request.post("/api/auth/login", {
    data: {
      email: "admin@riskpilot.local",
      password: "ChangeMe123!",
    },
  });
  expect(login.ok()).toBeTruthy();
  const { token } = (await login.json()) as { token: string };
  await page.addInitScript((accessToken) => {
    sessionStorage.setItem("riskpilot.accessToken", accessToken);
  }, token);

  await page.goto("/operations", { waitUntil: "networkidle" });
  const openMenu = page.getByLabel(/Ouvrir le menu|Open menu/);
  if ((page.viewportSize()?.width ?? 1280) < 900) {
    await expect(openMenu).toBeVisible();
    await openMenu.click();
  }
  const pilotage = page.getByRole("button", { name: /Pilotage|Management/ });
  const compliance = page.getByRole("button", {
    name: /Conformité et contrôles|Compliance and controls/,
  });
  await expect(pilotage).toHaveAttribute("aria-expanded", "true");
  await expect(compliance).toHaveAttribute("aria-expanded", "false");

  await compliance.click();
  await expect(pilotage).toHaveAttribute("aria-expanded", "false");
  await expect(compliance).toHaveAttribute("aria-expanded", "true");
  await page
    .getByRole("button", { name: /Conformité NIS2|NIS2 compliance/ })
    .click();

  await expect(page).toHaveURL(/\/nis2$/);
  if ((page.viewportSize()?.width ?? 1280) < 900) {
    await openMenu.click();
  }
  await expect(
    page.getByRole("button", {
      name: /Conformité et contrôles|Compliance and controls/,
    }),
  ).toHaveAttribute("aria-expanded", "true");
  await expect(
    page.getByRole("button", { name: /Pilotage|Management/ }),
  ).toHaveAttribute("aria-expanded", "false");
  await expect(page.locator('nav [aria-expanded="true"]')).toHaveCount(1);
});
