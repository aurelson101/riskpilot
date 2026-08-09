import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";
import { enToFr, frToEn, type Locale } from "../src/i18n/translations";

const allRoutes = [
  "/",
  "/risks",
  "/actions",
  "/operations",
  "/decision",
  "/experiments",
  "/analysis-workspace",
  "/ebios",
  "/indicators",
  "/annual-reports",
  "/notifications",
  "/compliance",
  "/nis2",
  "/third-parties",
  "/resilience",
  "/regulatory",
  "/risk-matrix",
  "/scopes",
  "/assets",
  "/assets/hardware",
  "/assets/software",
  "/assets/information",
  "/threats",
  "/vulnerabilities",
  "/security-controls",
  "/isms-documents",
  "/reports/executive",
  "/profile",
  "/administration/users",
  "/administration/organizations",
  "/administration/audit-logs",
  "/administration/email-settings",
  "/administration/integrations",
  "/administration/rbac",
  "/administration/action-fields",
] as const;
const routes = process.env.PLAYWRIGHT_ROUTE
  ? allRoutes.filter((route) => route === process.env.PLAYWRIGHT_ROUTE)
  : allRoutes;

const invariant = new Set([
  "RiskPilot",
  "MFA",
  "KPI",
  "KRI",
  "ISO 27001",
  "NIS2",
  "DORA",
  "SAML",
  "SCIM",
  "OIDC",
  "CSV",
  "API",
]);

async function visibleValues(page: Page) {
  return page.evaluate(() => {
    const visible = (element: Element) => {
      const style = getComputedStyle(element);
      return style.display !== "none" && style.visibility !== "hidden";
    };
    const values: string[] = [];
    const walker = document.createTreeWalker(
      document.body,
      NodeFilter.SHOW_TEXT,
    );
    let node;
    while ((node = walker.nextNode())) {
      const text = node.nodeValue?.replace(/\s+/g, " ").trim();
      if (text && node.parentElement && visible(node.parentElement))
        values.push(text);
    }
    for (const element of document.querySelectorAll(
      "[aria-label],[placeholder],[title]",
    )) {
      if (!visible(element)) continue;
      for (const attribute of ["aria-label", "placeholder", "title"]) {
        const value = element.getAttribute(attribute)?.trim();
        if (value) values.push(value);
      }
    }
    return [...new Set(values)];
  });
}

for (const locale of ["fr", "en"] as const) {
  test(`${locale.toUpperCase()} — ${routes.length} routes, responsive et accessibles`, async ({
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
    const profileResponse = await request.get("/api/me", {
      headers: { Authorization: `Bearer ${token}` },
    });
    expect(profileResponse.ok()).toBeTruthy();
    const profile = await profileResponse.json();
    const update = await request.put("/api/me", {
      headers: { Authorization: `Bearer ${token}` },
      data: {
        firstName: profile.firstName,
        lastName: profile.lastName,
        email: profile.email,
        locale,
      },
    });
    expect(update.ok()).toBeTruthy();

    await page.addInitScript(
      ({ accessToken, interfaceLocale }) => {
        sessionStorage.setItem("riskpilot.accessToken", accessToken);
        localStorage.setItem(
          "riskpilot.interfaceLocale",
          interfaceLocale as Locale,
        );
      },
      { accessToken: token, interfaceLocale: locale },
    );

    const runtimeErrors: string[] = [];
    page.on("console", (message) => {
      if (message.type() === "error") runtimeErrors.push(message.text());
    });
    page.on("pageerror", (error) => runtimeErrors.push(error.message));
    page.on("response", (response) => {
      if (response.status() >= 500)
        runtimeErrors.push(`${response.status()} ${response.url()}`);
    });

    const forbidden = locale === "fr" ? enToFr : frToEn;
    for (const route of routes) {
      await page.goto(route, { waitUntil: "networkidle" });
      await expect(page.locator("html")).toHaveAttribute("lang", locale);
      await expect(page.locator("body")).not.toContainText("RisquePilot");

      const values = await visibleValues(page);
      const untranslated = values.filter(
        (value) =>
          forbidden.has(value) &&
          !invariant.has(value) &&
          forbidden.get(value) !== value,
      );
      expect(untranslated, `${route}: wrong-language values`).toEqual([]);

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth > window.innerWidth + 1,
      );
      expect(overflow, `${route}: horizontal overflow`).toBeFalsy();

      const accessibility = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"])
        // The existing MUI palette contrast is tracked separately. Every other
        // serious or critical WCAG violation remains blocking.
        .disableRules(["color-contrast"])
        .analyze();
      const blocking = accessibility.violations.filter((violation) =>
        ["serious", "critical"].includes(violation.impact ?? ""),
      );
      expect(blocking, `${route}: serious accessibility issues`).toEqual([]);
    }
    expect(runtimeErrors).toEqual([]);
  });
}

test("la génération d'un rapport affiche le résultat et permet son export", async ({
  page,
  request,
}) => {
  const login = await request.post("/api/auth/login", {
    data: { email: "admin@riskpilot.local", password: "ChangeMe123!" },
  });
  expect(login.ok()).toBeTruthy();
  const { token } = (await login.json()) as { token: string };
  await page.addInitScript((accessToken) => {
    sessionStorage.setItem("riskpilot.accessToken", accessToken);
  }, token);

  await page.goto("/decision", { waitUntil: "networkidle" });
  await page
    .getByRole("tab", { name: /Rapports gouvernés|Governed reports/ })
    .click();
  await page
    .getByRole("button", { name: /Générer le rapport|Generate report/ })
    .first()
    .click();

  await expect(
    page.getByText(/Rapport généré :|Report generated:/),
  ).toBeVisible();
  const downloadPromise = page.waitForEvent("download");
  await page
    .getByRole("button", { name: /Télécharger JSON|Download JSON/ })
    .click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toMatch(/^riskpilot-report-\d+\.json$/);
});
