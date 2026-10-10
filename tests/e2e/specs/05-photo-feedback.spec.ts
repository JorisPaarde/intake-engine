import { expect, test } from '@playwright/test';
import {
  advanceUntil,
  createScenario,
  openCustomer,
  uploadPhoto,
  waitForPhotoAssessed,
} from '../helpers/app';

test.describe('Photo feedback', () => {
  test.use({ viewport: { width: 390, height: 844 } });

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
    await expect(page.getByText(/klein|resolutie|scherper|duidelijker|Niet goed te zien/i).first()).toBeVisible({
      timeout: 60_000,
    });

    const hintMatches = page.getByText(/klein|resolutie|scherper|duidelijker/i);
    expect(await hintMatches.count()).toBeGreaterThan(0);
    expect(await hintMatches.count()).toBeLessThanOrEqual(2);

    await expect(page.getByTestId('photo-mismatch-panel')).toBeVisible();
    await expect(page.getByText(/Status:/i)).toHaveCount(0);

    await page.getByRole('button', { name: /Verwijderen/i }).first().click();
    await expect(page.getByText(/klein|resolutie|scherper|duidelijker/i)).toHaveCount(0);

    await uploadPhoto(page, 'room-overview-good.jpg');
    await waitForPhotoAssessed(page);
    await expect(page.getByText(/klein|resolutie|scherper|duidelijker/i)).toHaveCount(0);

    await page.getByRole('button', { name: /^Volgende$/ }).click();
    await page.waitForTimeout(400);
    if (await page.getByRole('button', { name: /Vorige|Terug/i }).count()) {
      await page.getByRole('button', { name: /Vorige|Terug/i }).first().click();
      await expect(page.getByText(/We bekijken je foto/i)).toHaveCount(0);
      await expect(page.getByTestId('upload-phase')).toHaveCount(0);
      await expect(page.locator('[data-photo-status="1"]').first()).toContainText(
        /Goed te zien|Foto ontvangen|Je installateur kijkt hier zelf naar/i,
      );
      await expect(page.getByText(/klein|resolutie|scherper|duidelijker/i)).toHaveCount(0);
    }
  });

  test('back after terminal assessment never shows looking on tiles', async ({ page, request }) => {
    const payload = await createScenario(request, 'feedback');
    await openCustomer(page, payload);

    const confirm = page.getByRole('button', { name: /Klopt, verder/i });
    if (await confirm.count()) {
      await confirm.first().click();
    }

    await advanceUntil(page, /ruimte|overzicht|foto|woonkamer/i, 25);
    await uploadPhoto(page, 'room-overview-good.jpg');
    await waitForPhotoAssessed(page);

    const next = page.getByRole('button', { name: /^Volgende$/ });
    await expect(next).toBeEnabled({ timeout: 20_000 });
    await next.click();
    await page.waitForTimeout(300);
    await page.getByRole('button', { name: /Vorige/i }).first().click();

    await expect(page.getByText(/We bekijken je foto/i)).toHaveCount(0);
    await expect(page.getByText(/Status:/i)).toHaveCount(0);
    await expect(page.locator('[data-photo-status="1"]').first()).toBeVisible();
  });
});
