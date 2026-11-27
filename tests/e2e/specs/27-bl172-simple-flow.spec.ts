import { expect, test } from '@playwright/test';
import {
  answerCurrentStep,
  clickNext,
  createScenario,
  headingText,
  openCustomer,
  uploadPhoto,
  waitForLivewire,
  waitForPhotoAssessed,
} from '../helpers/app';

/**
 * BL-172/174 (airco v35): aanvraag → kamerfoto + gevelkant → buitenunit → meterkast → wensen
 * → controlepagina “Dit hebben we gevonden” (+ correctie) → versturen.
 */
test.describe('BL-172 eenvoudige klantopname', () => {
  test('korte flow op 390 px tot het bedankscherm', async ({ page, request }) => {
    test.setTimeout(240_000);
    await page.setViewportSize({ width: 390, height: 844 });

    const payload = await createScenario(request, 'bl172-simple-flow');
    await openCustomer(page, payload);

    // 1. Aanvraag in eigen woorden (al ingevuld in het scenario).
    await expect(page.locator('h1')).toHaveText(/Wat wil je laten doen\?/);
    await expect(page.getByTestId('dictation-hint')).toBeVisible();
    await clickNext(page);

    // 2. Kamerfoto met gevelkant op hetzelfde scherm (kamertal komt uit de tekst).
    await expect(page.locator('h1')).toHaveText(/Foto van de ruimte/);
    const facade = page.getByTestId('companion-room_facade_side');
    await expect(facade).toBeVisible();
    const saved = waitForLivewire(page).catch(() => undefined);
    await facade.getByText('Achterkant').click();
    await saved;
    await expect(page.locator('h1')).toHaveText(/Foto van de ruimte/);
    await expect(facade.getByLabel('Achterkant')).toBeChecked();
    await uploadPhoto(page, 'room-overview-good.jpg');
    await waitForPhotoAssessed(page);
    await page.screenshot({ path: 'tests/e2e/test-results/bl172-room-photo-390.png', fullPage: true });
    await clickNext(page);

    // 3. Buitenunitplek.
    await expect(page.locator('h1')).toHaveText(/buitenunit/i);
    await uploadPhoto(page, 'facade-around-house.jpg');
    await waitForPhotoAssessed(page);
    await clickNext(page);

    // 4. Meterkast.
    await expect(page.locator('h1')).toHaveText(/meterkast/i);
    await uploadPhoto(page, 'fusebox-12mp-progressive.jpg');
    await waitForPhotoAssessed(page);
    await clickNext(page);

    // 5. Wensen: voorbeeldvragen zonder koelen/verwarmen (al bekend).
    await expect(page.locator('h1')).toHaveText(/Heb je nog wensen\?/);
    const prompts = page.getByTestId('wish-prompts');
    await expect(prompts).toBeVisible();
    await expect(prompts).toContainText(/app/);
    await expect(prompts).not.toContainText(/koelen/);
    const wishes = page.getByTestId('bundle-textarea-additional_comments');
    await wishes.fill('Graag bediening via een app. Binnenunit boven de deur.');
    const blurSaved = waitForLivewire(page).catch(() => undefined);
    await wishes.blur();
    await blurSaved;
    await page.screenshot({ path: 'tests/e2e/test-results/bl172-wishes-390.png', fullPage: true });
    await clickNext(page);

    // 6. Controlepagina “Dit hebben we gevonden” (BL-174) + correctie.
    for (let i = 0; i < 3 && ! /Dit hebben we gevonden/.test(await headingText(page)); i++) {
      await answerCurrentStep(page, { preferSkipPhotos: false });
    }
    await expect(page.locator('h1')).toHaveText(/Dit hebben we gevonden/);
    const findings = page.getByTestId('review-findings');
    await expect(findings).toContainText('Je ruimtes');
    await expect(page.getByTestId('review-photos').locator('img')).toHaveCount(3);
    await findings.getByRole('button', { name: 'Klopt niet?' }).first().click();
    const corrections = page.getByTestId('customer-corrections');
    await expect(corrections).toBeFocused();
    await corrections.pressSequentially('graag nog even nakijken');
    const correctionSaved = waitForLivewire(page).catch(() => undefined);
    await corrections.blur();
    await correctionSaved;
    await expect(page.locator('h1')).toHaveText(/Dit hebben we gevonden/);
    await page.screenshot({ path: 'tests/e2e/test-results/bl174-review-390.png', fullPage: true });

    // 7. Versturen.
    for (let i = 0; i < 5; i++) {
      if (await page.getByTestId('customer-thank-you').count()) {
        break;
      }
      expect(await headingText(page)).not.toMatch(/Dit weten we al|Merk, planning/i);
      await answerCurrentStep(page, { preferSkipPhotos: false });
    }

    await expect(page.getByTestId('customer-thank-you')).toBeVisible();
  });
});
