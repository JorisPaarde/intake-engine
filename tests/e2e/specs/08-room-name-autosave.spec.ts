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

function questionCounter(page: import('@playwright/test').Page) {
  return page.getByText(/Vraag\s+\d+\s+van\s+\d+/i).first();
}

async function readCounter(page: import('@playwright/test').Page): Promise<string> {
  const text = ((await questionCounter(page).textContent()) ?? '').replace(/\s+/g, ' ').trim();
  expect(text).toMatch(/Vraag\s+\d+\s+van\s+\d+/i);

  return text;
}

test.describe('Wizard kamernaam autosave sticky', () => {
  test('wizard: kamernaam-autosave verschuift vraag niet', async ({ page, request }) => {
    test.setTimeout(120_000);

    const payload = await createScenario(request, 'room-name-autosave');
    await openCustomer(page, payload);

    await expect(page.locator('h1').first()).toContainText(/Hoe heet deze ruimte/i, {
      timeout: 15_000,
    });

    const headingBefore = await headingText(page);
    const counterBefore = await readCounter(page);

    await fillTextAndBlur(page, 'Ouders');
    await expect(page.getByText('Opgeslagen').first()).toBeVisible({ timeout: 10_000 }).catch(() => undefined);

    // Sticky (BL-140): blur must not jump to stopcontact or change the counter.
    await expect(page.locator('h1').first()).toContainText(/Hoe heet deze ruimte/i);
    expect(await headingText(page)).toBe(headingBefore);
    expect(await readCounter(page)).toBe(counterBefore);
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
