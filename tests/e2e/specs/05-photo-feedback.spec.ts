import { expect, test } from '@playwright/test';
import {
  advanceUntil,
  createScenario,
  openCustomer,
  uploadPhoto,
  waitForPhotoAssessed,
} from '../helpers/app';

test.describe('Photo feedback', () => {
  test('finding: no duplicate feedback; feedback clears on delete; no stale feedback on back/forth', async ({
    page,
    request,
  }) => {
    const payload = await createScenario(request, 'feedback');
    await openCustomer(page, payload);

    const confirm = page.getByRole('button', { name: /Klopt, verder/i });
    if (await confirm.count()) {
      await confirm.first().click();
    }

    await advanceUntil(page, /ruimte|overzicht|foto|woonkamer/i, 25);

    await uploadPhoto(page, 'too-small-400.jpg');
    await expect(page.getByText(/klein|resolutie|scherper|duidelijker/i).first()).toBeVisible({
      timeout: 60_000,
    });

    const hintMatches = page.getByText(/klein|resolutie|scherper|duidelijker/i);
    expect(await hintMatches.count()).toBeGreaterThan(0);
    expect(await hintMatches.count()).toBeLessThanOrEqual(3);

    await page.getByRole('button', { name: /Verwijderen/i }).first().click();
    await expect(page.getByText(/klein|resolutie|scherper|duidelijker/i)).toHaveCount(0);

    await uploadPhoto(page, 'room-overview-good.jpg');
    await waitForPhotoAssessed(page);
    await expect(page.getByText(/klein|resolutie|scherper|duidelijker/i)).toHaveCount(0);

    await page.getByRole('button', { name: /^Volgende$/ }).click();
    await page.waitForTimeout(400);
    if (await page.getByRole('button', { name: /Vorige|Terug/i }).count()) {
      await page.getByRole('button', { name: /Vorige|Terug/i }).first().click();
      await expect(page.getByTestId('photo-receipt-status')).toContainText('Beoordeeld');
      await expect(page.getByText(/klein|resolutie|scherper|duidelijker/i)).toHaveCount(0);
    }
  });
});
