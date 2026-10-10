import { expect, test } from '@playwright/test';
import {
  advanceUntil,
  createScenario,
  fixture,
  openCustomer,
  uploadPhoto,
} from '../helpers/app';
import { installLivewire503Simulation } from '../helpers/livewire503';

test.describe('Livewire 503 after upload', () => {
  test('503 on update after upload retries calmly then offers Opnieuw proberen', async ({
    page,
    request,
  }) => {
    test.setTimeout(120_000);

    const payload = await createScenario(request, 'fusebox-upload');
    await openCustomer(page, payload);
    await advanceUntil(page, /meterkast|groepenkast/i, 40);

    // Original + 3 auto-retries of _finishUpload all 503 → exhausted UI (BL-143).
    const sim = await installLivewire503Simulation(page, {
      failCount: 4,
      retryAfterSeconds: 1,
      targets: ['update'],
      callMethods: ['_finishUpload'],
    });

    await uploadPhoto(page, 'room-overview-good.jpg');

    await expect(page.getByTestId('upload-retrying')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('upload-retrying')).toContainText(/Even geduld, we proberen het opnieuw/i);

    expect(sim.inFlight()).toBeLessThanOrEqual(1);

    await expect(page.getByTestId('upload-timeout-error')).toBeVisible({
      timeout: 45_000,
    });
    await expect(page.getByTestId('upload-timeout-error')).toContainText(
      /De server is even druk|Probeer het zo opnieuw/i,
    );
    expect(sim.getFailHits()).toBe(4);

    const retry = page.getByTestId('upload-retry-button').or(
      page.getByRole('button', { name: /Opnieuw proberen/i }),
    ).first();
    await expect(retry).toBeVisible();
    await expect(retry).toBeEnabled();

    // Opnieuw proberen re-opens the file picker; choose a photo again (route exhausted → pass-through).
    const chooserPromise = page.waitForEvent('filechooser', { timeout: 10_000 });
    await retry.click();
    try {
      const chooser = await chooserPromise;
      await chooser.setFiles(fixture('room-overview-good.jpg'));
    } catch {
      await uploadPhoto(page, 'room-overview-good.jpg');
    }

    await expect(page.locator('[data-photo-status="1"]').first()).toContainText(
      /Goed te zien|Niet goed te zien|Niet de gevraagde foto|Je installateur kijkt hier zelf naar/i,
      { timeout: 90_000 },
    );
  });
});
