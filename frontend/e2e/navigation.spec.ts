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

test("le radar de conformité reste lisible et synthétique", async ({
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

  await page.goto("/compliance", { waitUntil: "networkidle" });
  await page
    .locator("main")
    .getByText(/Cadre Cyber Démonstration|Cyber Demonstration Framework/)
    .first()
    .click();

  const radar = page.getByRole("img", {
    name: /Toile d’araignée des résultats de conformité|Compliance results radar chart/,
  });
  await expect(radar).toBeVisible();
  await expect(
    page.getByText(/Points faibles \(0–2\)|Weak points \(0–2\)/),
  ).toBeVisible();
  await expect(
    page.getByText(/Points forts \(4–5\)|Strong points \(4–5\)/),
  ).toBeVisible();
});

test("le statut d’une évaluation peut être modifié", async ({
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

  await page.goto("/compliance", { waitUntil: "networkidle" });
  await page
    .locator("main")
    .getByText(/Cadre Cyber Démonstration|Cyber Demonstration Framework/)
    .first()
    .click();

  const status = page.getByRole("combobox", {
    name: /État de l’évaluation|Assessment status/,
  });
  const originalStatus = (await status.textContent()) ?? "";
  const startsCompleted = /Terminée|Completed/.test(originalStatus);
  const targetStatus = startsCompleted
    ? /En cours|In progress/
    : /Terminée|Completed/;
  const restoreStatus = startsCompleted
    ? /Terminée|Completed/
    : /En cours|In progress/;

  await status.click();
  await page.getByRole("option", { name: targetStatus }).click();
  await expect(status).toHaveText(targetStatus);

  // Restore the demo fixture so this test remains repeatable.
  await status.click();
  await page.getByRole("option", { name: restoreStatus }).click();
  await expect(status).toHaveText(restoreStatus);
});

test("la création annonce les points ajoutés automatiquement", async ({
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

  await page.goto("/compliance", { waitUntil: "networkidle" });
  await page
    .getByRole("button", { name: /Lancer une évaluation|Start an evaluation/ })
    .click();
  await page.getByRole("dialog").getByRole("combobox").first().click();
  await page
    .getByRole("option", {
      name: /Cadre Cyber Démonstration|Cyber Demonstration Framework/,
    })
    .click();

  await expect(
    page.getByText(
      /5 points actifs seront ajoutés automatiquement|5 active points will be added automatically/,
    ),
  ).toBeVisible();
});

test("le copilote conformité explique sa configuration sans modifier les données", async ({
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

  await page.goto("/compliance", { waitUntil: "networkidle" });
  await page
    .locator("main")
    .getByText(/Cadre Cyber Démonstration|Cyber Demonstration Framework/)
    .first()
    .click();
  await page
    .getByRole("button", { name: /Copilote IA|AI copilot/ })
    .first()
    .click();

  await expect(
    page.getByText(/Le copilote IA est désactivé|The AI copilot is disabled/),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: /Fermer|Close/ }),
  ).toBeVisible();
});

test("les expérimentations orientent clairement vers le copilote IA", async ({
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

  await page.goto("/experiments", { waitUntil: "networkidle" });
  const copilotLink = page.getByRole("link", {
    name: /Ouvrir le copilote IA|Open AI copilot/,
  });
  await expect(copilotLink).toBeVisible();
  await copilotLink.click();
  await expect(page).toHaveURL(/\/compliance$/);
});
