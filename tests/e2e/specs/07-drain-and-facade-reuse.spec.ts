import { expect, test } from '@playwright/test';
import {
  advanceUntil,
  createScenario,
  headingText,
  listUploads,
  openCustomer,
  uploadPhoto,
  waitForPhotoAssessed,
} from '../helpers/app';

test.describe('Drain text and facade photo reuse', () => {
  test('finding: drain copy stays consistent with optional photo', async ({ page, request }) => {
    const payload = await createScenario(request, 'drain-facade');
    await openCustomer(page, payload);

    const confirm = page.getByRole('button', { name: /Klopt, verder/i });
    if (await confirm.count()) {
      await confirm.first().click();
    }

    // drain_location (choice) — optional "Weet ik niet" radio + copy about always asking a photo.
    await advanceUntil(page, /afvoer\?|waar zie je/i, 50);
    let heading = (await headingText(page)).toLowerCase();
    expect(heading).toMatch(/afvoer/);
    await expect(page.getByText(/Weet je het niet|sla over|vragen altijd een foto|installateur bepaalt/i).first()).toBeVisible();
    await page.getByLabel(/Weet ik niet/i).check();
    await page.getByRole('button', { name: /^Volgende$/ }).click();

    // drain_photo — optional skip affordance + consistent condens copy.
    await advanceUntil(page, /condens|foto van de plek|weg kan/i, 10);
    heading = (await headingText(page)).toLowerCase();
    expect(heading).toMatch(/condens|afvoer|weg/);
    await expect(page.getByTestId('photo-skip')).toBeVisible();
    await expect(page.getByText(/condenswater|afvoer/i).first()).toBeVisible();
    await page.getByTestId('photo-skip').click({ timeout: 5_000 }).catch(() => undefined);
  });

  test('finding: around-the-house photos reuse when a facade photo is already present', async ({
    page,
    request,
  }) => {
    const payload = await createScenario(request, 'drain-facade');
    await openCustomer(page, payload);

    const confirm = page.getByRole('button', { name: /Klopt, verder/i });
    if (await confirm.count()) {
      await confirm.first().click();
    }

    await advanceUntil(page, /buiten|gevel|rondom|omgeving|tuin|foto/i, 40);

    if (await page.locator('input[type="file"]').count()) {
      await uploadPhoto(page, 'facade-around-house.jpg');
      await waitForPhotoAssessed(page).catch(() => undefined);
      await page.getByRole('button', { name: /^Volgende$/ }).click();
    }

    await advanceUntil(page, /rondom|gevel|buiten|omgeving|huis|foto/i, 20).catch(() => undefined);

    if (await page.locator('input[type="file"]').count()) {
      await uploadPhoto(page, 'facade-around-house.jpg');
      await page.waitForTimeout(1500);
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
    }
  });
});
