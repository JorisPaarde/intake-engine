import { expect, test } from '@playwright/test';
import { createScenario, openCustomer } from '../helpers/app';

test.describe('Klanttest run 3: labels, teller, Vorige/klembord', () => {
  test('geen zichtbare Vraag N; voortgang % daalt niet na Volgende', async ({ page, request }) => {
    const payload = await createScenario(request, 'progress-empty');
    await openCustomer(page, payload);

    await expect(page.getByTestId('step-counter')).toBeVisible();
    await expect(page.getByTestId('step-counter')).toHaveClass(/sr-only/);
    await expect(page.locator('[data-testid="wizard-step-heading"]')).not.toContainText(/Vraag \d+/);

    const percent = page.getByTestId('progress-percent');
    const before = Number.parseInt(((await percent.textContent()) ?? '0').replace('%', ''), 10);

    const next = page.getByTestId('next-button');
    if (await next.count()) {
      const field = page.locator('textarea, input[type="text"], input[type="number"]').first();
      if (await field.count()) {
        await field.fill('3 kamers van 3 bij 5');
        await field.blur();
        await page.getByText('Opgeslagen').first().waitFor({ state: 'visible', timeout: 10_000 }).catch(() => undefined);
      }
      await next.click();
      await page.waitForTimeout(800);
    }

    const after = Number.parseInt(((await percent.textContent()) ?? '0').replace('%', ''), 10);
    expect(after).toBeGreaterThanOrEqual(before);
    await expect(page.locator('[data-testid="wizard-step-heading"]')).not.toContainText(/Vraag \d+/);
  });

  test('Vorige overschrijft het klembord niet', async ({ page, request, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    const payload = await createScenario(request, 'progress-empty');
    await openCustomer(page, payload);

    // Zorg dat Vorige zichtbaar is: vul eerste stap en ga door.
    const field = page.locator('textarea, input[type="text"], input[type="number"]').first();
    if (await field.count()) {
      await field.fill('Klembord regressie');
      await field.blur();
      await page.getByText('Opgeslagen').first().waitFor({ state: 'visible', timeout: 10_000 }).catch(() => undefined);
    }
    const next = page.getByTestId('next-button');
    if (await next.count()) {
      await next.click();
      await page.waitForTimeout(800);
    }

    const previous = page.getByTestId('footer-previous');
    test.skip((await previous.count()) === 0, 'Geen Vorige op deze stap');

    await page.evaluate(async () => {
      await navigator.clipboard.writeText('BL163-CLIPBOARD-MARKER');
    });

    await previous.click();
    await page.waitForTimeout(500);

    const clip = await page.evaluate(async () => navigator.clipboard.readText());
    expect(clip).toBe('BL163-CLIPBOARD-MARKER');
  });
});
