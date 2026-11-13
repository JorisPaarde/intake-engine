import { expect, test, type Page } from '@playwright/test';
import path from 'node:path';
import { createScenario, openCustomer, waitForLivewire } from '../helpers/app';

const artifacts = '/opt/cursor/artifacts';

async function shot(page: Page, name: string, width: 390 | 1440, fullPage = true): Promise<void> {
  await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
  await page.waitForTimeout(200);
  await page.screenshot({
    path: path.join(artifacts, `${name}-${width}.png`),
    fullPage,
  });
}

test.describe('BL-169 UX-review embed / landlord / differs', () => {
  test('embed-test, toestemming, verhuurder, differs-link + Opgeslagen (390 + 1440)', async ({ page, request }) => {
    test.setTimeout(180_000);

    // 1) /embed-test — geen grijze tussenlaag / hintregel
    const prepare = await request.post('/__e2e__/embed/prepare');
    expect(prepare.ok()).toBeTruthy();

    await page.goto('/embed-test');
    await expect(page.getByTestId('embed-staging-test-frame')).toBeVisible();
    await expect(page.getByText('Vul het formulier in. Na bevestiging')).toHaveCount(0);
    const iframe = page.frameLocator('#intake-embed-staging-test');
    await expect(iframe.getByRole('heading', { name: 'Start je opname' })).toBeVisible({ timeout: 15_000 });
    await shot(page, 'ux169-embed-test', 390);
    await shot(page, 'ux169-embed-test', 1440);

    // 2) Embed-formulier toestemming + Toev.
    await expect(iframe.getByRole('textbox', { name: 'Toevoeging, optioneel' })).toBeVisible();
    await expect(iframe.locator('label[for="address_house_number_addition"]')).toContainText('Toev.');
    await expect(iframe.getByText(/Ik geef .+ toestemming om mijn gegevens en foto/i)).toBeVisible();
    await expect(iframe.getByRole('link', { name: 'Privacy' })).toBeVisible();
    await expect(iframe.getByText('verwerkingsverantwoordelijke')).toHaveCount(0);
    await expect(iframe.getByText('Zonder dit vinkje')).toHaveCount(0);
    await iframe.locator('#privacy_consent').scrollIntoViewIfNeeded();
    await page.waitForTimeout(150);
    await shot(page, 'ux169-embed-consent', 390, false);
    await shot(page, 'ux169-embed-consent', 1440, false);

    // 3) Verhuurdervraag
    const landlord = await createScenario(request, 'bl166-landlord');
    await openCustomer(page, landlord);
    await expect(page.getByText(/Weet je verhuurder ervan/i).first()).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText('Ja, die vindt het goed', { exact: true })).toBeVisible();
    await expect(page.getByText('Nog niet gevraagd', { exact: true })).toBeVisible();
    await expect(page.getByText('Nee, die is tegen', { exact: true })).toBeVisible();
    await expect(page.getByText('Gegevens over het gebouw')).toHaveCount(0);
    await page.getByText('Nog niet gevraagd', { exact: true }).evaluate((el) => {
      el.scrollIntoView({ block: 'center', inline: 'nearest' });
    });
    await page.waitForTimeout(150);
    await shot(page, 'ux169-landlord', 390, false);
    await shot(page, 'ux169-landlord', 1440, false);

    // 4) Glas/verdieping met link + Opgeslagen in voortgangsregel
    const differs = await createScenario(request, 'bl165-differs-sun-floor-glass');
    await openCustomer(page, differs);

    for (let i = 0; i < 40; i++) {
      const differsCount = await page.getByTestId('shared-differs').count();
      const heading = ((await page.locator('h1').first().textContent()) ?? '').toLowerCase();
      if (differsCount > 0 && (/glas|verdieping|zon/.test(heading))) {
        break;
      }
      await page.getByRole('button', { name: 'Volgende' }).click();
      await waitForLivewire(page).catch(() => undefined);
      await page.waitForTimeout(200);
    }

    await expect(page.getByTestId('shared-differs')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId('shared-differs')).toHaveText(/Verschilt dit per ruimte\?/);
    await expect(page.locator('[data-testid="shared-differs"].border-dashed')).toHaveCount(0);

    await page.evaluate(async () => {
      const root = document.querySelector('[wire\\:id]') as HTMLElement | null;
      const id = root?.getAttribute('wire:id');
      if (! id || ! window.Livewire) {
        throw new Error('Livewire component not found');
      }
      const component = window.Livewire.find(id);
      if (! component) {
        throw new Error('Livewire.find failed');
      }
      await component.set('saveMessage', 'Opgeslagen');
    });
    await expect(page.getByTestId('save-message')).toBeVisible();
    await expect(page.getByTestId('save-message')).toContainText('Opgeslagen');

    await shot(page, 'ux169-differs-opgeslagen', 390, false);
    await shot(page, 'ux169-differs-opgeslagen', 1440, false);
  });
});
