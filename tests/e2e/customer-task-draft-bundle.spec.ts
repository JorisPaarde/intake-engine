import { expect, test, type Page } from '@playwright/test';

/**
 * BL-145 — conceptlijst: twee taken bundelen, klanttekst tonen, één ronde versturen.
 *
 * Start lokaal of tegen staging met:
 *   APP_URL=http://127.0.0.1:8000 npx playwright test tests/e2e/customer-task-draft-bundle.spec.ts
 *
 * Vereist: ingelogde installateur-sessie via storageState of demo-flow hieronder.
 */

const baseURL = process.env.APP_URL ?? 'http://127.0.0.1:8000';

async function openInstallerWorkspaceViaDemo(page: Page): Promise<string> {
  await page.goto(`${baseURL}/`);
  const demo = page.getByRole('link', { name: /Probeer de demo/i }).or(
    page.getByRole('button', { name: /Probeer de demo/i }),
  );
  await demo.first().click();
  await page.waitForURL(/\/dashboard|\/intakes/);

  // Demo landt op dashboard; maak een nieuwe opname zoals de installateur.
  const createLink = page.getByRole('link', { name: /Nieuwe opname/i });
  if (await createLink.isVisible().catch(() => false)) {
    await createLink.click();
  } else {
    await page.goto(`${baseURL}/intakes/create`);
  }

  await page.getByLabel(/Postcode/i).fill('2037GR');
  await page.getByLabel(/Huisnummer/i).fill('273');
  await page.getByLabel(/Naam klant|Klantnaam/i).fill('Testklant Bundel');
  const reason = page.getByLabel(/Beschrijf wat de klant wil|Wat de klant wil/i);
  await reason.fill(
    'Airco op zolder 15 m² zonder bekende hoogte, en meterkastfoto ontbreekt voor de stroomtoevoer.',
  );
  await page.getByRole('button', { name: /Opslaan|Aanmaken|Doorgaan/i }).first().click();

  // Rolkeuze: zelf / installateur-pad.
  const installerPath = page.getByRole('button', { name: /Zelf|Installateur|Ik doe de opname/i }).first();
  if (await installerPath.isVisible({ timeout: 8000 }).catch(() => false)) {
    await installerPath.click();
  }

  await page.waitForURL(/\/opname|\/workspace|\/intakes\/\d+/);
  const url = page.url();
  if (!url.includes('/opname')) {
    const workspaceLink = page.getByRole('link', { name: /Opname|Werkplek|Doorgaan/i }).first();
    if (await workspaceLink.isVisible().catch(() => false)) {
      await workspaceLink.click();
    }
  }

  await expect(page.locator('body')).toContainText(/Opname|Alle onderdelen|Ruimte/i);
  return page.url();
}

test.describe('Customer task draft bundle (BL-145)', () => {
  test('shows draft list with two editable prompts and sends one round', async ({ page }) => {
    test.setTimeout(180_000);
    await openInstallerWorkspaceViaDemo(page);

    // Prepare height + fusebox drafts via direct prepare URLs extracted from page links when present,
    // otherwise seed through the prepare endpoint using the intake id in the URL.
    const match = page.url().match(/\/intakes\/(\d+)/);
    expect(match).not.toBeNull();
    const intakeId = match![1];

    const heightPrompt = 'Meet of noteer de hoogte van Zolder 1.';
    const fuseboxPrompt =
      'Maak een duidelijke foto van de meterkast; maak de groepenkast volledig leesbaar. De installateur beoordeelt de aansluiting.';

    await page.goto(
      `${baseURL}/intakes/${intakeId}/opname/customer-tasks/prepare?type=text&prompt=${encodeURIComponent(heightPrompt)}&decision_area_key=capacity`,
    );
    await page.waitForURL(new RegExp(`/intakes/${intakeId}/opname`));

    await page.goto(
      `${baseURL}/intakes/${intakeId}/opname/customer-tasks/prepare?type=photo&prompt=${encodeURIComponent('Maak een duidelijke foto van de meterkast. Daaruit volgt 1- of 3-fase.')}&decision_area_key=power`,
    );
    await page.waitForURL(new RegExp(`/intakes/${intakeId}/opname`));

    const draftSection = page.locator('#demo-customer-task');
    await expect(draftSection).toBeVisible();
    await expect(page.getByTestId('customer-task-draft-list')).toBeVisible();
    await expect(page.getByTestId('customer-task-draft-preview')).toHaveCount(2);
    await expect(draftSection).toContainText(heightPrompt);
    await expect(draftSection).toContainText('groepenkast volledig leesbaar');
    await expect(draftSection).toContainText('installateur beoordeelt de aansluiting');
    await expect(draftSection).not.toContainText('Daaruit volgt 1- of 3-fase');
    await expect(draftSection).not.toContainText('handmatig controleren');

    const prompts = page.getByTestId('customer-task-draft-prompt');
    await expect(prompts).toHaveCount(2);
    await expect(prompts.nth(0)).toHaveValue(heightPrompt);
    await expect(prompts.nth(1)).toHaveValue(fuseboxPrompt);

    await page.getByTestId('customer-task-draft-send').click();
    await expect(page.locator('[role="status"], body')).toContainText(/Klanttaak|Klantweergave|geactiveerd|gemaild/i);

    // Eén ronde met twee taken: klantlink zichtbaar of status AwaitingCustomer.
    await expect(page.getByRole('link', { name: /Klantweergave/i })).toBeVisible();
  });
});
