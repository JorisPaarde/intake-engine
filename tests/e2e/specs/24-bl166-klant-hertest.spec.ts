import { expect, test, type Page } from '@playwright/test';
import path from 'node:path';
import { createScenario, headingText, openCustomer, waitForLivewire } from '../helpers/app';

const artifacts = '/opt/cursor/artifacts';

async function shot(page: Page, name: string, width: 390 | 1440, fullPage = true): Promise<void> {
  await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
  await page.waitForTimeout(200);
  await page.screenshot({
    path: path.join(artifacts, `${name}-${width}.png`),
    fullPage,
  });
}

async function forceThankYou(page: Page): Promise<void> {
  await page.waitForSelector('[wire\\:id], [wire\\:snapshot]', { timeout: 15_000 });
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
    await component.set('completed', true);
  });
  await expect(page.getByTestId('customer-thank-you')).toBeVisible({ timeout: 10_000 });
}

test.describe('BL-166 klant-hertest screenshots', () => {
  test('bedankscherm, huur, bedrijfspand, teksten (390 + 1440)', async ({ page, request }) => {
    test.setTimeout(180_000);

    // 1) Bedankscherm
    const thankYou = await createScenario(request, 'bl166-thank-you');
    await openCustomer(page, thankYou);
    await forceThankYou(page);
    await expect(page.getByTestId('thank-you-timeline')).toContainText(/werkdagen/i);
    await expect(page.getByTestId('thank-you-summary')).toBeVisible();
    await shot(page, 'bl166-thank-you', 390);
    await shot(page, 'bl166-thank-you', 1440);

    // 2) Huur / verhuurder
    const landlord = await createScenario(request, 'bl166-landlord');
    await openCustomer(page, landlord);
    await expect(page.getByText(/Weet je verhuurder ervan/i).first()).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText('Ja, die vindt het goed', { exact: true })).toBeVisible();
    await expect(page.getByText('Nee, die is tegen', { exact: true })).toBeVisible();
    const notAsked = page.getByText('Nog niet gevraagd', { exact: true });
    await expect(notAsked).toBeVisible();
    // Scroll zodat de derde optie boven de sticky Volgende valt; viewport-shot (fullPage plakt sticky over content).
    await notAsked.evaluate((el) => {
      el.scrollIntoView({ block: 'center', inline: 'nearest' });
    });
    await page.waitForTimeout(150);
    await shot(page, 'bl166-landlord', 390, false);
    await shot(page, 'bl166-landlord', 1440, false);

    // 3) Bedrijfspand
    const commercial = await createScenario(request, 'bl166-commercial');
    await openCustomer(page, commercial);
    await expect(page.getByTestId('known-property-intro')).toContainText(/pandgegevens/i);
    await expect(page.getByTestId('non-residential-note')).toBeVisible();
    await expect(page.getByTestId('known-housing-facts')).toContainText(/Pandtype|Bedrijfspand/i);
    await expect(page.getByTestId('known-housing-facts')).not.toContainText(/Energielabel/i);
    await shot(page, 'bl166-commercial', 390);
    await shot(page, 'bl166-commercial', 1440);

    // 4) Slaapkamer-binnenunit (geen bank)
    const bedroom = await createScenario(request, 'bl166-bedroom-indoor');
    await openCustomer(page, bedroom);
    await expect(page.locator('h1').first()).toContainText(/binnenunit|hangen/i, { timeout: 15_000 });
    const bedroomHelp = ((await page.locator('[data-testid="wizard-step-heading"], .flex-1').first().textContent()) ?? '')
      + ((await page.locator('main, [data-testid="customer-wizard-shell"]').textContent()) ?? '');
    expect(bedroomHelp.toLowerCase()).toMatch(/bed/);
    expect(bedroomHelp.toLowerCase()).not.toMatch(/boven de bank/);
    await shot(page, 'bl166-bedroom-indoor', 390);
    await shot(page, 'bl166-bedroom-indoor', 1440);

    // 5) Geluid-help
    const noise = await createScenario(request, 'bl166-noise');
    await openCustomer(page, noise);
    await expect(page.getByText(/geluidsgevoelig|geluidgevoelig/i).first()).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText(/buren|slaapkamer/i).first()).toBeVisible();
    await shot(page, 'bl166-noise', 390);
    await shot(page, 'bl166-noise', 1440);

    // 6) Merk één keer
    const closing = await createScenario(request, 'bl166-closing');
    await openCustomer(page, closing);
    await expect(page.getByText(/Merk, planning en opmerkingen/i).first()).toBeVisible({ timeout: 15_000 });
    const closingHtml = await page.content();
    const merkHits = (closingHtml.toLowerCase().match(/zonder merkvoorkeur kiest de installateur wat het beste past/g) ?? []).length;
    expect(merkHits).toBe(1);
    await shot(page, 'bl166-closing', 390);
    await shot(page, 'bl166-closing', 1440);
  });
});

declare global {
  interface Window {
    Livewire?: {
      find: (id: string) => { set: (key: string, value: unknown) => Promise<void> } | undefined;
    };
  }
}

// silence unused import when heading helper is not needed in all branches
void headingText;
void waitForLivewire;
