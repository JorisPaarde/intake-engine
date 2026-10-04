import { expect, test } from '@playwright/test';
import {
  advanceUntil,
  createScenario,
  openCustomer,
  uploadPhoto,
} from '../helpers/app';
import { installLivewire503Simulation } from '../helpers/livewire503';

test.describe('Livewire 503 after upload', () => {
  test('finding: 503 on update after upload retries calmly then offers Opnieuw proberen', async ({
    page,
    request,
  }) => {
    // Finding: Livewire 503 calm retry with "Even geduld, we proberen het opnieuw",
    // max 3 attempts, Retry-After honored, no parallel retries — pending upload-robustness PR.
    test.fail(
      true,
      'finding: Livewire 503 calm retry (Even geduld…) — not on main yet (upload-robustness PR)',
    );

    const payload = await createScenario(request, 'fusebox-upload');
    await openCustomer(page, payload);
    await advanceUntil(page, /meterkast|groepenkast/i, 40);

    await page.clock.install();

    const sim = await installLivewire503Simulation(page, {
      failCount: 3,
      retryAfterSeconds: 2,
      targets: ['update'],
    });

    await uploadPhoto(page, 'room-overview-good.jpg');

    await expect(page.getByText(/Even geduld, we proberen het opnieuw/i)).toBeVisible({
      timeout: 15_000,
    });

    expect(sim.inFlight()).toBeLessThanOrEqual(1);

    await page.clock.fastForward(2500);
    await page.clock.fastForward(2500);
    await page.clock.fastForward(2500);

    expect(sim.getFailHits()).toBe(3);

    await expect(page.getByText(/niet bereikbaar|probeer het opnieuw|lukte niet/i)).toBeVisible();
    const retry = page.getByRole('button', { name: /Opnieuw proberen/i });
    await expect(retry).toBeVisible();
    await expect(retry).toBeEnabled();

    await retry.click();
    await expect(page.getByTestId('photo-receipt-status')).toContainText(/Beoordeeld|Ontvangen/, {
      timeout: 60_000,
    });
  });
});
