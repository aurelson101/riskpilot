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
  await page.addInitScript((accessToken) => {
    sessionStorage.setItem("riskpilot.accessToken", accessToken);
  }, token);
}

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
    .locator("main")
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

test("le bouton flottant rend le copilote IA accessible partout", async ({
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
  const launcher = page.getByRole("button", {
    name: /Ouvrir le copilote IA|Open AI copilot/,
  });
  await expect(launcher).toBeVisible();
  await launcher.click();
  await expect(
    page.getByRole("dialog", {
      name: /Copilote IA RiskPilot|RiskPilot AI copilot/,
    }),
  ).toBeVisible();
  await expect(
    page.getByRole("tab", {
      name: /Risque tiers guidé|Guided third-party risk/,
    }),
  ).toBeVisible();
  await expect(
    page.getByRole("tab", {
      name: /Action conformité guidée|Guided compliance action/,
    }),
  ).toBeVisible();
  await expect(
    page.getByRole("tab", { name: /Document ISMS guidé|Guided ISMS document/ }),
  ).toBeVisible();
});

test("le rapport exécutif télécharge un PDF gouverné", async ({
  page,
  request,
}) => {
  await authenticate(page, request, "admin@riskpilot.local");
  await page.goto("/reports/executive");
  const downloadPromise = page.waitForEvent("download");
  await page
    .getByRole("button", {
      name: /Télécharger le rapport PDF gouverné|Download governed PDF report/,
    })
    .click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toBe("riskpilot-executive-report.pdf");
  expect(await download.failure()).toBeNull();
});

test("le mode pilotage prépare réellement le brouillon de risque proposé", async ({
  page,
  request,
}) => {
  let draftCalls = 0;
  await page.route("**/api/copilot/context", async (route) => {
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        enabled: true,
        provider: "MISTRAL",
        model: "codestral-latest",
        notice: "Test",
      }),
    });
  });
  await page.route("**/api/copilot/risk-draft", async (route) => {
    draftCalls += 1;
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        draft: {
          title: "Rançongiciel sur la plateforme cloud",
          description: "Une indisponibilité pourrait interrompre le service.",
          scopeId: 1,
          assetId: 1,
          threatId: 1,
          likelihood: 3,
          impact: 4,
          rationale: "Valeurs à confirmer après revue des contrôles.",
        },
      }),
    });
  });
  await page.route("**/api/copilot", async (route) => {
    if (route.request().method() !== "POST") {
      await route.continue();
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        answer: "Je prépare le brouillon de risque demandé.",
        actions: [
          {
            type: "OPEN_RISK_DRAFT",
            label: "Préparer un brouillon de risque",
          },
        ],
      }),
    });
  });
  await authenticate(page, request, "admin@riskpilot.local");
  await page.goto("/", { waitUntil: "networkidle" });
  await page
    .getByRole("button", { name: /Ouvrir le copilote IA|Open AI copilot/ })
    .click();
  const dialog = page.getByRole("dialog");
  await dialog
    .getByRole("button", { name: /Mode pilotage|Pilot mode/ })
    .click();
  await dialog
    .getByRole("textbox", {
      name: /Que voulez-vous accomplir|What do you want to accomplish/,
    })
    .fill("Crée un risque de rançongiciel sur notre plateforme cloud.");
  await dialog
    .getByRole("checkbox", {
      name: /J’autorise l’envoi|I authorize sending/,
    })
    .check();
  await dialog.getByRole("button", { name: /Envoyer|Send/ }).click();
  await dialog
    .getByRole("button", {
      name: /Préparer un brouillon de risque|Prepare a risk draft/,
    })
    .click();

  await expect(
    dialog.getByRole("textbox", {
      name: /Quel événement redouté|Which feared event/,
    }),
  ).toHaveValue("Rançongiciel sur la plateforme cloud");
  expect(draftCalls).toBe(1);
});

test("le copilote crée des brouillons risque, conformité et ISMS après confirmation", async ({
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
  await page.goto("/", { waitUntil: "networkidle" });
  await page
    .getByRole("button", { name: /Ouvrir le copilote IA|Open AI copilot/ })
    .click();
  const dialog = page.getByRole("dialog");
  const unique = Date.now();

  await dialog
    .getByRole("tab", { name: /Risque tiers guidé|Guided third-party risk/ })
    .click();
  await dialog
    .getByRole("textbox", {
      name: /Quel événement redouté|Which feared event/,
    })
    .fill(`Risque tiers guidé ${unique}`);
  for (const name of [
    /Périmètre concerné|Affected scope/,
    /Actif ou service dépendant|Dependent asset or service/,
    /Menace principale|Main threat/,
    /Responsable du risque|Risk owner/,
  ]) {
    await dialog.getByRole("combobox", { name }).click();
    await page
      .getByRole("option")
      .filter({ hasNotText: /Créez|Create|Aucun|No owner/ })
      .first()
      .click();
  }
  await dialog
    .getByRole("checkbox", {
      name: /J’ai relu le brouillon|I reviewed the draft/,
    })
    .check();
  await dialog
    .getByRole("button", {
      name: /Confirmer et créer le risque|Confirm and create risk/,
    })
    .click();
  await expect(
    dialog.getByText(
      new RegExp(`Risque créé.*${unique}|Risk created.*${unique}`),
    ),
  ).toBeVisible();

  await dialog
    .getByRole("tab", {
      name: /Action conformité guidée|Guided compliance action/,
    })
    .click();
  await dialog
    .getByRole("textbox", { name: /Titre de l’action|Action title/ })
    .fill(`Action conformité guidée ${unique}`);
  await dialog
    .getByRole("textbox", {
      name: /Description et résultat attendu|Description and expected outcome/,
    })
    .fill("Formaliser le contrôle, son responsable et les preuves attendues.");
  for (const name of [
    /Exigence ou écart concerné|Related requirement or gap/,
    /Responsable de l’action|Action owner/,
  ]) {
    await dialog.getByRole("combobox", { name }).click();
    await page.getByRole("option").first().click();
  }
  await dialog
    .getByRole("checkbox", {
      name: /J’ai relu le brouillon|I reviewed the draft/,
    })
    .check();
  await dialog
    .getByRole("button", {
      name: /Confirmer et créer l’action de conformité|Confirm and create compliance action/,
    })
    .click();
  await expect(
    dialog.getByText(
      new RegExp(
        `Action conformité créée.*${unique}|Compliance action created.*${unique}`,
      ),
    ),
  ).toBeVisible();

  await dialog
    .getByRole("tab", { name: /Document ISMS guidé|Guided ISMS document/ })
    .click();
  await dialog
    .getByRole("textbox", { name: /Titre du document|Document title/ })
    .fill(`Document ISMS guidé ${unique}`);
  await dialog
    .getByRole("checkbox", {
      name: /J’ai relu le brouillon|I reviewed the draft/,
    })
    .check();
  await dialog
    .getByRole("button", {
      name: /Confirmer et créer le document ISMS|Confirm and create ISMS document/,
    })
    .click();
  await expect(
    dialog.getByText(
      new RegExp(
        `Document ISMS créé.*${unique}|ISMS document created.*${unique}`,
      ),
    ),
  ).toBeVisible();
});
