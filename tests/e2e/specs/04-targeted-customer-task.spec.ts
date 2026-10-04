import { expect, test } from '@playwright/test';
import {
  createScenario,
  openCustomer,
  setAiScenario,
  uploadPhoto,
  waitForLivewire,
  waitForPhotoAssessed,
} from '../helpers/app';

test.describe('Targeted customer task (follow-up)', () => {
  test('finding: wrong + low-res photos block Aanvulling versturen until replace or Toch versturen', async ({
    page,
    request,
  }) => {
    test.setTimeout(180_000);

    const payload = await createScenario(request, 'follow-up-mismatch');
    await setAiScenario(request, 'wrong_subject');
    await openCustomer(page, payload);

    await expect(page.getByText(/Aanvulling voor/i)).toBeVisible();

    // Item 1 — wrong subject blocks completion until Toch versturen / replace.
    await uploadPhoto(page, 'wrong-subject-outdoor.jpg');
    await expect(page.getByTestId('follow-up-mismatch')).toBeVisible({ timeout: 90_000 });
    await expect(page.getByRole('button', { name: /Toch versturen/i })).toBeVisible();

    // Accept mismatch explicitly before navigating away.
    const accept = waitForLivewire(page).catch(() => undefined);
    await page.getByRole('button', { name: /Toch versturen/i }).click();
    await accept;
    await expect(page.getByTestId('follow-up-mismatch')).toHaveCount(0, { timeout: 15_000 });

    const next = waitForLivewire(page).catch(() => undefined);
    await page.getByRole('button', { name: /^Volgende$/ }).click();
    await next;
    await expect(page.getByText(/Onderdeel 2 van 2/i)).toBeVisible({ timeout: 15_000 });

    // Item 2 — low resolution soft hint.
    await setAiScenario(request, 'good_photo');
    await uploadPhoto(page, 'too-small-400.jpg');
    await expect(page.getByText(/lage resolutie|klein|scherper|dichtersbij|dichtersbij|dichterbij/i).first()).toBeVisible({
      timeout: 60_000,
    });

    // Replace with usable photo so the round can be sent.
    const remove = waitForLivewire(page).catch(() => undefined);
    await page.getByRole('button', { name: /Verwijderen/i }).first().click();
    await remove;
    await expect(page.getByText(/Foto's maken of kiezen/i)).toBeVisible({ timeout: 10_000 });

    await uploadPhoto(page, 'room-overview-good.jpg');
    await waitForPhotoAssessed(page);
    await expect(page.getByTestId('follow-up-item-status')).toContainText('Beoordeeld');

    const complete = waitForLivewire(page).catch(() => undefined);
    await page.getByRole('button', { name: /Aanvulling versturen/i }).click();
    await complete;

    await expect(page.getByText(/Bedankt/i).first()).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText(/installateur kijkt|nog iets openstaat/i)).toBeVisible();
  });
});
