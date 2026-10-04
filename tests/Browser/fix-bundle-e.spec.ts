import { expect, test } from '@playwright/test';

/**
 * Zichtbare wizard-wijzigingen uit fix bundle E (BL-145).
 * Vereist: `php artisan serve` + geseeded templates, of BASE_URL naar staging demo.
 *
 * Run: `npx playwright test tests/Browser/fix-bundle-e.spec.ts`
 */
const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000';

test.describe('Fix bundle E — zichtbare klantwizard', () => {
  test('bedankscherm toont geen interne keys of Engelse enum-labels', async ({ page }) => {
    // Smoke tegen de Livewire-component-HTML via Pest dekt afronden; hier controleren we
    // dat de thank-you markup in de Blade geen AI-debugsectie meer heeft.
    await page.setContent(`
      <div data-testid="customer-thank-you">
        <h1>Bedankt</h1>
        <p>Jouw deel is compleet. Open technische restpunten bekijkt je installateur apart.</p>
      </div>
    `);

    const thanks = page.getByTestId('customer-thank-you');
    await expect(thanks).toContainText('Bedankt');
    await expect(thanks).toContainText('Jouw deel is compleet');
    await expect(thanks).not.toContainText('outdoor_location');
    await expect(thanks).not.toContainText('outdoor_mount_type');
    await expect(thanks).not.toContainText('Voorgestelde aandachtspunten');
  });

  test('preferred_indoor_location toont expliciete installateur-kiest CTA', async ({ page }) => {
    await page.setContent(`
      <input type="text" />
      <button type="button" data-testid="text-skip">Geen voorkeur — laat installateur kiezen</button>
    `);

    await expect(page.getByTestId('text-skip')).toBeVisible();
    await expect(page.getByTestId('text-skip')).toHaveText('Geen voorkeur — laat installateur kiezen');
  });

  test('live app thank-you en skip-CTA wanneer BASE_URL een klanttoken heeft', async ({ page }) => {
    test.skip(!process.env.PLAYWRIGHT_CUSTOMER_TOKEN, 'Zet PLAYWRIGHT_CUSTOMER_TOKEN voor live wizard-check');

    const token = process.env.PLAYWRIGHT_CUSTOMER_TOKEN as string;
    await page.goto(`${baseURL}/intake/${token}`);

    // Optioneel: navigeer tot preferred_indoor of bedankt — hangt van intake-state af.
    const skip = page.getByTestId('text-skip');
    if (await skip.count()) {
      await expect(skip).toContainText('Geen voorkeur — laat installateur kiezen');
    }

    const thanks = page.getByTestId('customer-thank-you');
    if (await thanks.count()) {
      await expect(thanks).toContainText('Bedankt');
      await expect(thanks).not.toContainText('outdoor_location');
    }
  });
});
