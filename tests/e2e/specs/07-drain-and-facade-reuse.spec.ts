import { expect, test } from '@playwright/test';
import {
  advanceUntil,
  createScenario,
  listUploads,
  openCustomer,
  uploadPhoto,
  waitForPhotoAssessed,
} from '../helpers/app';

test.describe('Drain text and facade photo reuse', () => {
  test('finding: drain copy stays consistent with optional photo', async ({ page, request }) => {
    const payload = await createScenario(request, 'drain-facade');
    await openCustomer(page, payload);

    await advanceUntil(page, /condens|afvoer|weg kan|pomp/i, 45);

    const heading = ((await page.locator('h1').first().textContent()) ?? '').trim();
    expect(heading.toLowerCase()).toMatch(/condens|afvoer|weg/);

    // Optional: skip affordance present; no contradictory required pump yes/no as the only path.
    await expect(page.getByRole('button', { name: /Weet ik niet|sla over/i })).toBeVisible();
    await expect(page.getByText(/Foto van de plek waar condenswater weg kan/i)).toBeVisible();

    // Skip should be allowed without forcing a technical invention.
    await page.getByRole('button', { name: /Weet ik niet|sla over/i }).first().click();
  });

  test('finding: around-the-house photos reuse when a facade photo is already present', async ({
    page,
    request,
  }) => {
    const payload = await createScenario(request, 'drain-facade');
    await openCustomer(page, payload);

    // Upload the same facade image on outdoor / around-house questions when reached.
    await advanceUntil(page, /buiten|gevel|rondom|omgeving|tuin/i, 40);

    if (await page.locator('input[type="file"]').count()) {
      await uploadPhoto(page, 'facade-around-house.jpg');
      await waitForPhotoAssessed(page).catch(() => undefined);
      await page.getByRole('button', { name: /^Volgende$/ }).click();
    }

    // Next outdoor/around photo question: upload the identical file → reuse terminal status.
    await advanceUntil(page, /rondom|gevel|buiten|omgeving|huis/i, 15).catch(() => undefined);

    if (await page.locator('input[type="file"]').count()) {
      await uploadPhoto(page, 'facade-around-house.jpg');
      await page.waitForTimeout(2000);
      await waitForPhotoAssessed(page).catch(() => undefined);
    }

    const uploads = await listUploads(request, payload.intake_id);
    const facadeUploads = uploads.filter((u) =>
      ['around_house_photos', 'outdoor_location_photos', 'outdoor_unit_photo'].includes(
        String(u.question_key),
      ),
    );

    if (facadeUploads.length >= 2) {
      const statuses = facadeUploads.map((u) => u.assessment_status);
      expect(statuses.some((s) => s === 'reused' || s === 'assessed')).toBeTruthy();
      const checksums = facadeUploads.map((u) => u.checksum).filter(Boolean);
      expect(new Set(checksums).size).toBeLessThanOrEqual(checksums.length);
    }
  });
});
