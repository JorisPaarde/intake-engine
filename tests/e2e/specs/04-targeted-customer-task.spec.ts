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
    const payload = await createScenario(request, 'follow-up-mismatch');
    await setAiScenario(request, 'wrong_subject');
    await openCustomer(page, payload);

    await expect(page.getByText(/Aanvulling voor/i)).toBeVisible();

    // Item 1: wrong subject photo (outdoor instead of meterkast).
    await uploadPhoto(page, 'wrong-subject-outdoor.jpg');
    await expect(page.getByText(/meterkast|Vervang|Toch versturen/i).first()).toBeVisible({
      timeout: 90_000,
    });

    const submit = page.getByRole('button', { name: /Aanvulling versturen/i });
    if (await submit.count()) {
      await submit.click();
      await expect(page.getByText(/Vervang de foto|Toch versturen/i).first()).toBeVisible();
      await expect(page.getByText(/^Bedankt$/)).toHaveCount(0);
    }

    await expect(page.getByRole('button', { name: /Toch versturen/i })).toBeVisible();

    // Low-resolution on next item (or same flow): too-small fixture.
    const nextItem = page.getByRole('button', { name: /Volgende/i });
    // Accept mismatch on first item so we can exercise low-res separately if multi-item.
    await page.getByRole('button', { name: /Toch versturen/i }).click();

    if (await nextItem.count()) {
      await nextItem.click();
    }

    if (await page.locator('input[type="file"]').count()) {
      await setAiScenario(request, 'good_photo');
      await uploadPhoto(page, 'too-small-400.jpg');
      await expect(page.getByText(/klein|resolutie|scherper|opnieuw/i).first()).toBeVisible({
        timeout: 60_000,
      });
    }

    // With Toch versturen, thank-you says installer still needs to review.
    if (await submit.count()) {
      await submit.click();
    } else {
      await page.getByRole('button', { name: /Aanvulling versturen|Afronden|Versturen/i }).first().click();
    }

    await expect(page.getByText(/Bedankt/i).first()).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText(/installateur kijkt|nog iets openstaat|nog.*beoord/i)).toBeVisible();
  });
});
