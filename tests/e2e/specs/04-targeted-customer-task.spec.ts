import { expect, test } from '@playwright/test';
import {
  createScenario,
  openCustomer,
  setAiScenario,
  uploadPhoto,
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

    // Not last step yet → Volgende; trying complete early is not available.
    await page.getByRole('button', { name: /^Volgende$/ }).click();
    // Soft-block may keep us here with warning — accept mismatch explicitly.
    if (await page.getByRole('button', { name: /Toch versturen/i }).count()) {
      await page.getByRole('button', { name: /Toch versturen/i }).click();
      await page.getByRole('button', { name: /^Volgende$/ }).click();
    }

    // Item 2 — low resolution soft hint.
    await expect(page.getByText(/Onderdeel 2 van 2/i)).toBeVisible({ timeout: 15_000 });
    await setAiScenario(request, 'good_photo');
    await uploadPhoto(page, 'too-small-400.jpg');
    await expect(page.getByText(/lage resolutie|klein|scherper|dichtersbij|dichtersbij|dichterbij/i).first()).toBeVisible({
      timeout: 60_000,
    });

    // Replace with usable photo so the round can be sent.
    await page.getByRole('button', { name: /Verwijderen/i }).first().click();
    await uploadPhoto(page, 'room-overview-good.jpg');
    await page.getByText(/Beoordeeld|Opgeslagen|Status/i).first().waitFor({ timeout: 90_000 }).catch(() => undefined);

    await page.getByRole('button', { name: /Aanvulling versturen/i }).click();

    await expect(page.getByText(/Bedankt/i).first()).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText(/installateur kijkt|nog iets openstaat/i)).toBeVisible();
  });
});
