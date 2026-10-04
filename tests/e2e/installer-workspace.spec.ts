import { expect, test, type Page } from '@playwright/test';

/**
 * Installer workspace UI polish (fix bundle F):
 * - Volgende stap summary + CTA share one missing-info source
 * - Outcome form separates Locatiebezoek nodig vs uitgevoerd
 *
 * Requires a running app (php artisan serve) and seeded credentials:
 *   PLAYWRIGHT_EMAIL / PLAYWRIGHT_PASSWORD
 *   PLAYWRIGHT_WORKSPACE_PATH (e.g. /intakes/1/opname) optional; otherwise creates via demo/login flow helpers.
 */

async function login(page: Page): Promise<void> {
  const email = process.env.PLAYWRIGHT_EMAIL;
  const password = process.env.PLAYWRIGHT_PASSWORD;
  test.skip(!email || !password, 'Set PLAYWRIGHT_EMAIL and PLAYWRIGHT_PASSWORD');

  await page.goto('/login');
  await page.getByLabel(/e-?mail/i).fill(email!);
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button', { name: /log in|inloggen/i }).click();
  await expect(page).not.toHaveURL(/login/);
}

test.describe('Installer workspace CTA alignment', () => {
  test('primary summary and CTA label point at the same missing info', async ({ page }) => {
    const workspacePath = process.env.PLAYWRIGHT_WORKSPACE_PATH;
    test.skip(!workspacePath, 'Set PLAYWRIGHT_WORKSPACE_PATH to an intake workspace URL path');

    await login(page);
    await page.goto(workspacePath!);

    const summary = page.getByTestId('primary-step-summary');
    const cta = page.getByTestId('primary-step-cta');
    await expect(summary).toBeVisible();
    await expect(cta).toBeVisible();

    const summaryText = (await summary.innerText()).toLowerCase();
    const ctaText = (await cta.innerText()).toLowerCase();
    const href = await cta.getAttribute('href');

    // Text and link must agree: foto gap → foto CTA; multi-split gap → proposal CTA.
    if (summaryText.includes('foto') || summaryText.includes('rondom')) {
      expect(ctaText).toMatch(/foto/);
      expect(href).toBe('#demo-placements');
      expect(ctaText).not.toMatch(/multi-split/);
    } else if (summaryText.includes('multi-split') || summaryText.includes('singles')) {
      expect(ctaText).toMatch(/multi-split|singles/);
      expect(href).toBe('#demo-proposal');
    }
  });
});

test.describe('Installer workspace outcome form', () => {
  test('Locatiebezoek result does not auto-tick uitgevoerd; Later invullen updates after save', async ({ page }) => {
    const workspacePath = process.env.PLAYWRIGHT_WORKSPACE_PATH;
    test.skip(!workspacePath, 'Set PLAYWRIGHT_WORKSPACE_PATH to an intake workspace URL path');

    await login(page);
    await page.goto(`${workspacePath}#workspace-outcome`);

    const outcome = page.getByTestId('workspace-outcome');
    await expect(outcome).toBeVisible();

    const summaryLabel = page.getByTestId('outcome-summary-label');
    const initialLabel = await summaryLabel.innerText();
    if (!initialLabel.includes('Opgeslagen')) {
      expect(initialLabel).toMatch(/Later invullen/);
    }

    await page.getByTestId('outcome-result').selectOption('site_visit');
    const occurred = page.getByTestId('outcome-site-visit-occurred');
    await expect(occurred).not.toBeChecked();

    // Pick one reason (required when visit needed).
    const reason = outcome.locator('input[name="site_visit_reasons[]"]').first();
    await reason.check();

    await outcome.getByRole('button', { name: /Uitkomst opslaan/i }).click();
    await expect(page.getByRole('status')).toContainText(/locatiebezoek nodig/i);
    await expect(page.getByRole('status')).not.toContainText(/tijd- en ritbesparing telt nu mee/i);

    await page.goto(`${workspacePath}#workspace-outcome`);
    await expect(page.getByTestId('outcome-summary-label')).toContainText(/Opgeslagen/);
    await expect(page.getByTestId('outcome-site-visit-occurred')).not.toBeChecked();
  });
});
