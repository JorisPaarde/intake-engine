/**
 * Fix bundle D: bulk approval blockers + separate example dossier (demo path).
 *
 * Runs with `npm run test:e2e` (tests/e2e/playwright.config.ts → specs/).
 * Requires DEMO_ENABLED and a prepared app (php artisan e2e:prepare --fresh).
 */

import { expect, test, type Page } from '@playwright/test';

async function startPublicDemo(page: Page): Promise<void> {
  await page.goto('/');
  // Homepage CTA is a POST form button, not a link.
  const demoCta = page.getByRole('button', { name: /Probeer de demo|Start demo/i }).first();
  if (!(await demoCta.count())) {
    test.skip(true, 'Public demo CTA not visible in this environment');
  }
  await demoCta.click();
  await page.waitForURL(/dashboard|intakes|demo/i);
}

test.describe('Fix bundle D — approval + example dossier', () => {
  test('demo example dossier opens a separate labelled intake', async ({ page }) => {
    await startPublicDemo(page);

    const newIntake = page.getByRole('link', { name: /Nieuwe opname/i }).first();
    if (await newIntake.count()) {
      await newIntake.click();
      await page.locator('input[name="address_postal_code"]').fill('2011AA');
      await page.locator('input[name="address_house_number"]').fill('12');
      await page.locator('input[name="customer_name"]').fill('Playwright Demo');
      await page.getByRole('button', { name: /Opslaan|Aanmaken|Doorgaan/i }).first().click();
    }

    const installerPath = page.getByRole('button', { name: /Zelf de opname doen/i }).first();
    if (await installerPath.count()) {
      await installerPath.click();
    }

    await expect(page.getByTestId('load-example-dossier')).toBeVisible({ timeout: 15_000 });
    const sourceUrl = page.url();
    await page.getByTestId('load-example-dossier').click();
    await page.waitForURL(/intakes\/\d+\/opname/);
    expect(page.url()).not.toBe(sourceUrl);
    await expect(page.getByText('Voorbeelddossier (demo)')).toBeVisible();
    await expect(page.getByText(/apart gelabeld voorbeelddossier/i)).toBeVisible();
  });

  test('bulk approval shows blockers when uncertainty remains', async ({ page }) => {
    await startPublicDemo(page);

    const newIntake = page.getByRole('link', { name: /Nieuwe opname/i }).first();
    if (await newIntake.count()) {
      await newIntake.click();
      await page.locator('input[name="address_postal_code"]').fill('2011AA');
      await page.locator('input[name="address_house_number"]').fill('1');
      await page.locator('input[name="customer_name"]').fill('Playwright Approval');
      await page.getByRole('button', { name: /Opslaan|Aanmaken|Doorgaan/i }).first().click();
    }

    const installerPath = page.getByRole('button', { name: /Zelf de opname doen/i }).first();
    if (await installerPath.count()) {
      await installerPath.click();
    }

    if (await page.getByTestId('load-example-dossier').count()) {
      await page.getByTestId('load-example-dossier').click();
      await page.waitForURL(/intakes\/\d+\/opname/);
    }

    await page.locator('#workspace-complete').scrollIntoViewIfNeeded();
    await expect(page.getByText('Nog niet klaar om goed te keuren')).toBeVisible();
    await expect(page.getByTestId('approval-blockers')).toBeVisible();
    await expect(page.getByTestId('approve-proposal')).toHaveCount(0);
  });
});
