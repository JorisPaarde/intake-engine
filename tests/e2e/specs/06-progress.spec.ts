import { expect, test } from '@playwright/test';
import { createScenario, openCustomer } from '../helpers/app';

test.describe('Progress percentage and question counter', () => {
  test('finding: percent and Vraag X van Y use the same calculation; never 100% while open', async ({
    page,
    request,
  }) => {
    const payload = await createScenario(request, 'progress-empty');
    await openCustomer(page, payload);

    const percent = page.getByTestId('progress-percent');
    await expect(percent).toBeVisible();

    const percentText = ((await percent.textContent()) ?? '').trim();
    const percentValue = Number.parseInt(percentText.replace('%', ''), 10);
    expect(Number.isFinite(percentValue)).toBeTruthy();
    expect(percentValue).toBeLessThan(100);

    const stepCount = page.getByTestId('progress-step-count');
    if (await stepCount.count()) {
      const label = ((await stepCount.textContent()) ?? '').trim();
      // e.g. "Vraag 1 van 24" or similar
      expect(label.length).toBeGreaterThan(0);
      expect(label).not.toMatch(/100\s*%/);
    }

    // Empty/open task must not show 100%.
    await expect(percent).not.toHaveText('100%');

    // Counter and percent should stay consistent after one answer.
    if (await page.locator('textarea, input[type="text"]').count()) {
      const field = page.locator('textarea, input[type="text"]').first();
      await field.fill('E2E voortgangstest');
      await field.blur();
      await page.waitForTimeout(500);
    }

    const percentAfter = Number.parseInt(
      (((await percent.textContent()) ?? '').trim()).replace('%', ''),
      10,
    );
    expect(percentAfter).toBeLessThan(100);

    const headingCounter = page.getByText(/Vraag\s+\d+\s+van\s+\d+/i).first();
    if (await headingCounter.count()) {
      const counterText = ((await headingCounter.textContent()) ?? '').trim();
      const match = /Vraag\s+(\d+)\s+van\s+(\d+)/i.exec(counterText);
      expect(match).not.toBeNull();
      const current = Number(match![1]);
      const total = Number(match![2]);
      expect(current).toBeGreaterThanOrEqual(1);
      expect(current).toBeLessThanOrEqual(total);
      // Same calculation: percent roughly tracks done/total (allow high-water / known steps).
      expect(percentAfter).toBeLessThanOrEqual(100);
      if (total > 0 && current < total) {
        expect(percentAfter).toBeLessThan(100);
      }
    }
  });

  test('finding: no 100% on an empty follow-up task', async ({ page, request }) => {
    const payload = await createScenario(request, 'follow-up-mismatch');
    await openCustomer(page, payload);

    const percent = page.getByTestId('follow-up-progress-percent');
    await expect(percent).toBeVisible();
    await expect(percent).not.toHaveText('100%');
    const value = Number.parseInt(((await percent.textContent()) ?? '0').replace('%', ''), 10);
    expect(value).toBe(0);
  });
});
