import { expect, test } from '@playwright/test';
import {
  clickNext,
  createScenario,
  fillTextAndBlur,
  headingText,
  openCustomer,
  uploadPhoto,
  waitForLivewire,
  waitForPhotoAssessed,
} from '../helpers/app';

async function readProgressPercent(page: import('@playwright/test').Page): Promise<string> {
  const text = ((await page.getByTestId('progress-percent').textContent()) ?? '').replace(/\s+/g, ' ').trim();
  expect(text).toMatch(/^\d+%$/);

  return text;
}

test.describe('Wizard preferred_indoor autosave sticky', () => {
  test('wizard: tekst-autosave verschuift vraag niet (BL-140)', async ({ page, request }) => {
    test.setTimeout(120_000);

    const payload = await createScenario(request, 'room-name-autosave');
    await openCustomer(page, payload);

    // Scenario landt op preferred_indoor (room_name is standaard overgeslagen).
    await expect(page.locator('h1').first()).toContainText(/binnenunit|hangen|voorkeur/i, {
      timeout: 15_000,
    });

    const headingBefore = await headingText(page);
    const percentBefore = await readProgressPercent(page);
    // BL-156: geen "X van Y" meer (adaptief totaal); sticky = zelfde vraag + zelfde %.
    await expect(page.getByText(/Vraag\s+\d+\s+van\s+\d+/i)).toHaveCount(0);

    await fillTextAndBlur(page, 'Boven het bed');
    await expect(page.getByText('Opgeslagen').first()).toBeVisible({ timeout: 10_000 }).catch(() => undefined);

    // Sticky (BL-140): blur must not jump to stopcontact or change progress.
    await expect(page.locator('h1').first()).toContainText(/binnenunit|hangen|voorkeur/i);
    expect(await headingText(page)).toBe(headingBefore);
    expect(await readProgressPercent(page)).toBe(percentBefore);
    await expect(page.getByTestId('step-missing-alert')).toHaveCount(0);
    await expect(page.getByText(/Beantwoord eerst/i)).toHaveCount(0);

    const livewireNext = waitForLivewire(page).catch(() => undefined);
    await page.getByRole('button', { name: /^Volgende$/i }).first().click();
    await livewireNext;

    await expect(page.locator('h1').first()).toContainText(/stopcontact/i, { timeout: 15_000 });
    await expect(page.getByTestId('step-missing-alert')).toHaveCount(0);
    await expect(page.getByText(/Beantwoord eerst/i)).toHaveCount(0);

    await uploadPhoto(page, 'room-overview-good.jpg');
    await waitForPhotoAssessed(page);

    const beforePhotoNext = await headingText(page);
    await clickNext(page);

    await expect(page.getByTestId('step-missing-alert')).toHaveCount(0);
    await expect(page.getByText(/Beantwoord eerst/i)).toHaveCount(0);
    await expect(page.locator('h1').first()).not.toHaveText(beforePhotoNext, { timeout: 15_000 });
  });
});
