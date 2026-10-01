import { expect, test } from "@playwright/test";

test("notifications : pagination, clavier, lecture groupée et petits écrans", async ({
  page,
  request,
}, testInfo) => {
  const apiOrigin = process.env.RISKPILOT_TEST_API_URL;
  const login = await request.post(`${apiOrigin ?? ""}/api/auth/login`, {
    data: { email: "admin@riskpilot.local", password: "ChangeMe123!" },
  });
  expect(login.ok()).toBeTruthy();
  const { token } = await login.json();
  await page.addInitScript(
    (accessToken) =>
      sessionStorage.setItem("riskpilot.accessToken", accessToken),
    token,
  );
  if (apiOrigin) {
    await page.route("**/api/**", async (route) => {
      const url = new URL(route.request().url());
      await route.fulfill({
        response: await route.fetch({
          url: `${apiOrigin}${url.pathname}${url.search}`,
        }),
      });
    });
  }
  const items = Array.from({ length: 55 }, (_, id) => ({
    id: id + 1,
    type: "RISK_REVIEW",
    title: `Alerte ${id + 1}`,
    message:
      "Message-très-long-sans-espaces-pour-vérifier-le-retour-à-la-ligne-sur-les-petits-écrans-et-la-lisibilité-des-alertes",
    link: id === 0 ? "https://example.test" : "/risks",
    isRead: false,
    createdAt: "2026-01-01T10:00:00Z",
  }));
  let bulkCount = 0;
  await page.route("**/api/notifications**", async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith("/summary")) {
      await route.fulfill({
        json: {
          total: items.length,
          unread: items.filter((item) => !item.isRead).length,
        },
      });
    } else if (url.pathname.endsWith("/read-all")) {
      bulkCount += 1;
      const unread = items.filter((item) => !item.isRead);
      unread.forEach((item) => {
        item.isRead = true;
      });
      await route.fulfill({ json: { updated: unread.length } });
    } else if (url.pathname.endsWith("/read")) {
      const id = Number(url.pathname.split("/").at(-2));
      const item = items.find((candidate) => candidate.id === id)!;
      item.isRead = true;
      await route.fulfill({ json: item });
    } else {
      const matching = items.filter(
        (item) => url.searchParams.get("unreadOnly") !== "true" || !item.isRead,
      );
      const offset = Number(url.searchParams.get("offset") ?? 0);
      const limit = Number(url.searchParams.get("limit") ?? 50);
      await route.fulfill({
        json: matching.slice(offset, offset + limit),
        headers: { "X-Total-Count": String(matching.length) },
      });
    }
  });
  await page.goto("/notifications");
  await expect(page.getByText("Alerte 1", { exact: true })).toBeVisible();
  const firstCard = page
    .locator(".MuiCard-root")
    .filter({ has: page.getByText("Alerte 1", { exact: true }) });
  await expect(firstCard.getByRole("link")).toHaveCount(0);
  for (const width of [390, 768, 1440]) {
    await page.setViewportSize({ width, height: 1000 });
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      ),
    ).toBeTruthy();
    await page.screenshot({
      path: testInfo.outputPath(`notifications-${width}.png`),
    });
  }
  const mark = firstCard.getByRole("button", {
    name: /^Marquer comme lu$|^Mark as read$/,
  });
  await mark.focus();
  await page.keyboard.press("Enter");
  await expect(firstCard.getByText(/^Nouveau$|^New$/)).toHaveCount(0);
  await page.getByRole("button", { name: /^Suivant$|^Next$/ }).click();
  await expect(page.getByText("Alerte 26", { exact: true })).toBeVisible();
  await page.getByLabel(/Non lues uniquement|Unread only/).check();
  await expect(page.getByText("Alerte 2", { exact: true })).toBeVisible();
  await page
    .getByRole("button", { name: /^Tout marquer comme lu$|^Mark all as read$/ })
    .click();
  await expect(
    page.getByText(
      /^Aucune notification non lue\.$|^No unread notifications\.$/,
    ),
  ).toBeVisible();
  expect(bulkCount).toBe(1);
  await expect(
    page.getByRole("button", {
      name: /^Tout marquer comme lu$|^Mark all as read$/,
    }),
  ).toBeDisabled();
});
