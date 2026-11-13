import { expect, test, type Page } from '@playwright/test';
import path from 'node:path';
import {
  clickNext,
  createScenario,
  headingText,
  openCustomer,
  waitForLivewire,
} from '../helpers/app';

const artifacts = '/opt/cursor/artifacts';

async function shot(page: Page, name: string, width: 390 | 1440, fullPage = true): Promise<void> {
  await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
  await page.waitForTimeout(200);
  await page.screenshot({
    path: path.join(artifacts, `${name}-${width}.png`),
    fullPage,
  });
}

test.describe('BL-169 UX wizard fixes', () => {
  test('stap 1, known-summary, merkstap, afronden + validatie (390 + 1440)', async ({
    page,
    request,
  }) => {
    test.setTimeout(180_000);

    // 1) Stap 1 begintoestand: pandregel zonder dubbel adres, géén foutmelding
    const step1 = await createScenario(request, 'bl169-step1');
    await openCustomer(page, step1);
    await expect(page.getByTestId('known-property-intro')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId('known-property-intro')).toContainText(/bouwjaar/i);
    await expect(page.getByTestId('known-property-intro')).not.toContainText(/Lepelstraat/i);
    await expect(page.getByText(/Lepelstraat 1,\s*2011 RD Haarlem/)).toHaveCount(1);
    await expect(page.getByTestId('request-reason-field')).toBeVisible();
    await expect(page.getByTestId('request-reason-field')).toHaveAttribute(
      'placeholder',
      'Bijv. airco in woonkamer en slaapkamer, alleen koelen',
    );
    await expect(page.getByTestId('field-required-error')).toHaveCount(0);
    await expect(page.getByTestId('non-residential-note')).toHaveCount(0);
    await expect(page.getByTestId('energy-label-note')).toHaveCount(0);

    await shot(page, 'bl169-stap1', 390);
    await shot(page, 'bl169-stap1', 1440);

    await page.getByTestId('next-button').click();
    await waitForLivewire(page).catch(() => undefined);
    await expect(page.getByTestId('field-required-error')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('field-required-error')).toHaveText(
      'Beantwoord eerst deze verplichte vraag.',
    );
    await expect(page.locator('h1').first()).toContainText(/reden|aanvraag/i);

    // 2) Known-summary: één Ruimtes-rij + kaart sluit om inhoud
    const known = await createScenario(request, 'bl169-known-summary');
    await openCustomer(page, known);
    await expect(page.getByText('Dit hebben we al uit je aanvraag')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId('known-summary-help')).toHaveText('De rest vragen we hierna.');
    await expect(page.getByTestId('wizard-intro')).toHaveCount(0);

    // Label "Ruimtes" (niet de vraag "Hoeveel ruimtes…").
    const roomsRow = page
      .getByTestId('known-summary-item')
      .filter({ has: page.getByText('Ruimtes', { exact: true }) });
    await expect(roomsRow).toHaveCount(1);
    await expect(roomsRow).toContainText(/Woonkamer,\s*Slaapkamer/);
    const labels = await page.getByTestId('known-summary-item').allTextContents();
    expect(labels.join(' | ')).not.toMatch(/Hoe heet deze ruimte/i);

    const cardBox = await page.getByTestId('known-summary-card').boundingBox();
    expect(cardBox).not.toBeNull();
    // Kaart mag niet veel leeg vlak onder de lijst hebben (flex-1 was ~hele viewport).
    expect(cardBox?.height ?? 0).toBeLessThan(360);

    const editBtn = roomsRow.getByTestId('known-summary-edit');
    const box = await editBtn.boundingBox();
    expect(box).not.toBeNull();
    expect((box?.height ?? 0) >= 44).toBeTruthy();

    // Shot vóór Wijzigen = begintoestand van het overzicht.
    await shot(page, 'bl169-known-summary', 390);
    await shot(page, 'bl169-known-summary', 1440);

    await editBtn.click();
    await waitForLivewire(page).catch(() => undefined);
    await expect(page.locator('h1').first()).toBeVisible();
    await clickNext(page);
    await expect(page.getByText('Dit hebben we al uit je aanvraag')).toBeVisible({ timeout: 15_000 });

    // 3) Merkstap: radio+select; Opgeslagen gescheiden van %; balk dekt geen opties
    const closing = await createScenario(request, 'bl169-closing');
    await openCustomer(page, closing);
    await expect(page.getByText(/Merk, planning en opmerkingen/i).first()).toBeVisible({
      timeout: 15_000,
    });
    await expect(page.getByTestId('brand-preference-mode')).toBeVisible();
    await expect(page.getByTestId('brand-mode-none')).toBeChecked();

    await page.getByTestId('brand-mode-prefer').click();
    await waitForLivewire(page).catch(() => undefined);
    await expect(page.getByTestId('brand-preference-select')).toBeVisible();

    await expect(page.getByTestId('save-message')).toContainText('Opgeslagen', { timeout: 10_000 });
    const statusText = (await page.getByTestId('progress-status').innerText()).replace(/\s+/g, ' ');
    expect(statusText).toMatch(/Opgeslagen · \d+%/);
    expect(/Opgeslagen\d/.test(statusText)).toBeFalsy();

    const select = page.getByTestId('brand-preference-select');
    await select.evaluate((el) => el.scrollIntoView({ block: 'end' }));
    await page.waitForTimeout(150);
    const selectBox = await select.boundingBox();
    const footerBox = await page.getByTestId('customer-wizard-footer').boundingBox();
    expect(selectBox).not.toBeNull();
    expect(footerBox).not.toBeNull();
    expect((selectBox?.y ?? 0) + (selectBox?.height ?? 0)).toBeLessThanOrEqual(
      (footerBox?.y ?? 0) + 1,
    );

    await shot(page, 'bl169-merkstap', 390, false);
    await shot(page, 'bl169-merkstap', 1440, false);

    // 4) Afronden begintoestand: géén validatie tot Versturen
    const afronden = await createScenario(request, 'bl169-afronden');
    await openCustomer(page, afronden);
    await expect(page.getByText('Klaar om te versturen?')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText('Laatste opmerkingen en toestemming')).toHaveCount(0);
    await expect(page.getByTestId('must-accept-checkbox')).toContainText(
      'Ik heb alles naar waarheid ingevuld.',
    );
    await expect(page.getByTestId('complete-button')).toContainText(/Versturen naar/i);
    await expect(page.getByTestId('must-accept-refusal')).toHaveCount(0);

    await shot(page, 'bl169-afronden', 390);
    await shot(page, 'bl169-afronden', 1440);

    await page.getByTestId('complete-button').click();
    await waitForLivewire(page).catch(() => undefined);
    await expect(page.getByTestId('must-accept-refusal')).toHaveText('Vink dit aan om te versturen.');
    await expect(page.getByTestId('step-missing-alert')).toHaveCount(0);
    await expect(page.getByText('Beantwoord eerst deze verplichte vraag.')).toHaveCount(0);
  });
});

void headingText;
