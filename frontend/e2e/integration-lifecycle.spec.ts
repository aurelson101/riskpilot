import { expect, test } from "@playwright/test";

test("les accès techniques restent lisibles et empêchent les opérations concurrentes", async ({
  page,
  request,
}, testInfo) => {
  const apiOrigin = process.env.RISKPILOT_TEST_API_URL;
  const login = await request.post(`${apiOrigin ?? ""}/api/auth/login`, {
    data: { email: "admin@riskpilot.local", password: "ChangeMe123!" },
  });
  expect(login.ok()).toBeTruthy();
  const { token } = await login.json();
  await page.addInitScript((accessToken) => {
    sessionStorage.setItem("riskpilot.accessToken", accessToken);
    Object.defineProperty(navigator, "clipboard", {
      configurable: true,
      value: {
        writeText: async () => {
          throw new Error("Clipboard blocked");
        },
      },
    });
  }, token);
  if (apiOrigin) {
    await page.route("**/api/**", async (route) => {
      const url = new URL(route.request().url());
      const response = await route.fetch({
        url: `${apiOrigin}${url.pathname}${url.search}`,
      });
      await route.fulfill({ response });
    });
  }
  let item = {
    id: 999999,
    type: "API_KEY",
    provider: "GENERIC",
    name: "Application-de-synchronisation-avec-un-nom-très-long-pour-vérifier-les-petits-écrans",
    configuration: { scopes: ["actions:read"] },
    credentialPrefix: "rp_api_key_a",
    credentialConfigured: true,
    credentialExpiresAt: "2099-01-01T00:00:00+00:00",
    credentialExpired: false,
    lastUsedAt: null,
    enabled: true,
  };
  let rotationCount = 0;
  let finishRotation = () => {};
  const rotationGate = new Promise<void>((resolve) => {
    finishRotation = resolve;
  });
  await page.route("**/api/v1/integrations**", async (route) => {
    if (route.request().url().endsWith("/rotate")) {
      rotationCount += 1;
      await rotationGate;
      await route.fulfill({
        json: { ...item, secret: "fixture-secret-for-browser-test" },
      });
    } else if (route.request().url().endsWith("/revoke")) {
      item = { ...item, credentialConfigured: false, enabled: false };
      await route.fulfill({ json: item });
    } else {
      await route.fulfill({ json: { items: [item] } });
    }
  });
  page.on("dialog", (dialog) => dialog.accept());
  await page.goto("/administration/integrations");
  await expect(page.getByText(item.name, { exact: true })).toBeVisible();
  for (const width of [390, 768, 1440]) {
    await page.setViewportSize({ width, height: 1000 });
    await expect(
      page.getByRole("button", { name: /^Renouveler$|^Rotate key$/ }),
    ).toBeVisible();
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      ),
    ).toBeTruthy();
    await page.screenshot({
      path: testInfo.outputPath(`integrations-${width}.png`),
      fullPage: true,
    });
  }
  const rotate = page.getByRole("button", {
    name: /^Renouveler$|^Rotate key$/,
  });
  await rotate.click();
  await expect(rotate).toBeDisabled();
  await expect(
    page.getByRole("button", { name: /^Révoquer$|^Revoke$/ }),
  ).toBeDisabled();
  await rotate.dispatchEvent("click");
  expect(rotationCount).toBe(1);
  finishRotation();
  await expect(
    page.getByText("fixture-secret-for-browser-test", { exact: true }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: /^Copier la clé$|^Copy key$/ })
    .click();
  await expect(
    page.getByText(
      /La copie automatique est indisponible|Automatic copying is unavailable/,
    ),
  ).toBeVisible();
  await page.getByRole("button", { name: /^Révoquer$|^Revoke$/ }).click();
  await expect(
    page.getByText("fixture-secret-for-browser-test", { exact: true }),
  ).toHaveCount(0);
  await expect(page.getByText(/^Révoquée$|^Revoked$/)).toBeVisible();
  await expect(
    page.getByRole("button", { name: /^Activer$|^Enable$/ }),
  ).toBeDisabled();
});
