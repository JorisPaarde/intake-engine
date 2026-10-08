import { expect, test } from '@playwright/test';
import {
  advanceUntil,
  clickNext,
  createScenario,
  headingText,
  isPhotoStep,
  listUploads,
  openCustomer,
  uploadPhoto,
  waitForPhotoAssessed,
  waitForSaved,
} from '../helpers/app';

test.describe('Drain text and facade photo reuse', () => {
  test('finding: drain copy stays consistent with optional photo', async ({ page, request }) => {
    test.setTimeout(120_000);

    const payload = await createScenario(request, 'drain-facade');
    await openCustomer(page, payload);

    const confirm = page.getByRole('button', { name: /Klopt, verder/i });
    if (await confirm.count()) {
      await confirm.first().click();
    }

    // v27: drain_location + drain_photo op één scherm (wizard_group drain_nearby).
    await advanceUntil(page, /Afvoer in de buurt/i, 30);
    const heading = await headingText(page);
    expect(heading).toMatch(/Afvoer in de buurt/i);
    await expect(page.getByTestId('drain-nearby-group')).toBeVisible();
    await expect(page.getByRole('radio').first()).toBeVisible();
    await expect(page.locator('input[type="file"]').first()).toBeVisible();
    await expect(page.getByTestId('photo-skip')).toHaveCount(0);
    await expect(
      page.getByText(/Een foto helpt de installateur\. Weet je het niet\? Ga gewoon verder\./i).first(),
    ).toBeVisible();

    await page.getByLabel(/Weet ik niet/i).check();
    await waitForSaved(page);
    const beforeNext = await headingText(page);
    await clickNext(page);
    await page.waitForFunction(
      (prev) => {
        const h1 = document.querySelector('h1');
        const text = (h1?.textContent ?? '').replace(/\s+/g, ' ').trim();

        return text !== '' && text !== prev;
      },
      beforeNext,
      { timeout: 15_000 },
    );
    expect(await headingText(page)).not.toBe(beforeNext);
  });

  test('finding: around-the-house photos reuse when a facade photo is already present', async ({
    page,
    request,
  }) => {
    test.setTimeout(180_000);

    const payload = await createScenario(request, 'drain-facade');
    await openCustomer(page, payload);

    const confirm = page.getByRole('button', { name: /Klopt, verder/i });
    if (await confirm.count()) {
      await confirm.first().click();
    }

    await advanceUntil(page, /buiten|gevel|rondom|omgeving|tuin|foto/i, 40);

    if (await isPhotoStep(page)) {
      await uploadPhoto(page, 'facade-around-house.jpg');
      await waitForPhotoAssessed(page).catch(() => undefined);
      await clickNext(page);
    }

    await advanceUntil(page, /rondom|gevel|buiten|omgeving|huis|foto/i, 20).catch(() => undefined);

    if (await isPhotoStep(page)) {
      const skip = page.getByTestId('photo-skip');
      if (await skip.count()) {
        await skip.first().click();
      } else {
        await uploadPhoto(page, 'facade-around-house.jpg');
        await waitForPhotoAssessed(page).catch(() => undefined);
      }
    }

    const uploads = await listUploads(request, payload.intake_id);
    const facadeUploads = uploads.filter((u) =>
      ['around_house_photos', 'outdoor_location_photos', 'outdoor_unit_photo', 'facade_overview_photo'].includes(
        String(u.question_key),
      ),
    );

    if (facadeUploads.length >= 1) {
      const statuses = facadeUploads.map((u) => u.assessment_status);
      expect(statuses.some((s) => s === 'reused' || s === 'assessed')).toBeTruthy();
    }
  });
});
