import { expect, test, type Page } from '@playwright/test';
import path from 'node:path';
import {
  advanceUntil,
  createScenario,
  openCustomer,
  uploadPhoto,
  waitForLivewire,
} from '../helpers/app';

const artifacts = '/opt/cursor/artifacts';

async function shot(page: Page, name: string, width: 390 | 1440): Promise<void> {
  await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
  await page.waitForTimeout(200);
  await page.screenshot({
    path: path.join(artifacts, `${name}-${width}.png`),
    fullPage: true,
  });
}

async function forceCompleted(page: Page): Promise<void> {
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

/**
 * Soft-timeout UI zonder echte 8s-wacht: pending ids + assessmentUiReleased
 * (gemockte trage check). Fake AI is sync en klaar vóór 8s; Pest dekt de timer.
 */
async function forceSoftTimeoutUi(page: Page): Promise<void> {
  await page.waitForSelector('[wire\\:id], [wire\\:snapshot]', { timeout: 15_000 });
  await page.waitForTimeout(400);

  const ok = await page.evaluate(async () => {
    const root = document.querySelector('[wire\\:id]') as HTMLElement | null;
    const id = root?.getAttribute('wire:id');
    if (! id || ! window.Livewire) {
      return { ok: false, reason: 'no-livewire' };
    }
    const component = window.Livewire.find(id);
    if (! component) {
      return { ok: false, reason: 'find-failed' };
    }

    const thumb = document.querySelector('[data-testid="photo-thumb-status"][data-upload-id]');
    const uploadId = thumb ? Number(thumb.getAttribute('data-upload-id') || '0') : 0;
    if (! uploadId) {
      return { ok: false, reason: 'no-upload-id' };
    }

    let composite = 'room-1__room_photos';
    const poll = document.querySelector(
      '[data-testid="assessment-poll"][data-poll-composite], [data-testid="bg-assessment-poll"][data-poll-composite]',
    );
    if (poll) {
      composite = poll.getAttribute('data-poll-composite') || composite;
    }

    try {
      await component.call('e2eForcePhotoSoftTimeoutUi', composite, uploadId);
      return { ok: true, composite, uploadId };
    } catch (e) {
      const message = e instanceof Error ? e.message : JSON.stringify(e);
      return { ok: false, reason: message };
    }
  });

  expect(ok, JSON.stringify(ok)).toMatchObject({ ok: true });
  await waitForLivewire(page).catch(() => undefined);
  await expect(page.getByTestId('photo-soft-timeout-panel')).toBeVisible({ timeout: 10_000 });
}

test.describe('BL-169 photo + thank-you UX', () => {
  test('screenshots: empty photo, reject, soft-timeout, thank-you (390 + 1440)', async ({
    page,
    request,
  }) => {
    test.setTimeout(240_000);

    // 1) Lege fotostap
    const empty = await createScenario(request, 'feedback');
    await openCustomer(page, empty);
    const confirm = page.getByRole('button', { name: /Klopt, verder/i });
    if (await confirm.count()) {
      await confirm.first().click();
    }
    await advanceUntil(page, /ruimte|overzicht|foto|woonkamer/i, 25);
    await expect(page.getByTestId('photo-upload-button')).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText(/Maak of kies een foto/i).first()).toBeVisible();
    await shot(page, 'bl169-photo-empty', 390);
    await shot(page, 'bl169-photo-empty', 1440);

    // 2) Afkeurstaat
    await uploadPhoto(page, 'too-small-400.jpg');
    await expect(page.getByTestId('photo-mismatch-panel')).toBeVisible({ timeout: 60_000 });
    await expect(page.getByTestId('photo-retry-primary')).toBeVisible();
    await expect(page.getByTestId('photo-accept-mismatch')).toBeVisible();
    await expect(page.getByText(/Niet de gevraagde foto/i)).toHaveCount(0);
    await shot(page, 'bl169-photo-reject', 390);
    await shot(page, 'bl169-photo-reject', 1440);

    // 3) Nog aan het nakijken (soft-timeout) — gemockte trage check via Livewire state
    await page.getByRole('button', { name: /Verwijderen/i }).first().click();
    await expect(page.getByTestId('photo-mismatch-panel')).toHaveCount(0, { timeout: 15_000 });
    await uploadPhoto(page, 'room-overview-good.jpg');
    await expect(page.locator('[data-testid="photo-thumb-status"]').first()).toBeVisible({
      timeout: 20_000,
    });
    await forceSoftTimeoutUi(page);
    await expect(page.getByText(/We kijken je foto nog na/i)).toBeVisible();
    await shot(page, 'bl169-photo-soft-timeout', 390);
    await shot(page, 'bl169-photo-soft-timeout', 1440);

    // 4) Bedankscherm
    const thankYou = await createScenario(request, 'bl166-thank-you');
    await openCustomer(page, thankYou);
    await forceCompleted(page);
    await expect(page.getByTestId('customer-thank-you')).toContainText(/Je aanvraag is verstuurd/i);
    await expect(page.getByTestId('thank-you-check')).toBeVisible();
    await expect(page.getByTestId('wizard-progress')).toHaveCount(0);
    await shot(page, 'bl169-thank-you', 390);
    await shot(page, 'bl169-thank-you', 1440);
  });

  test('8s soft-timeout: Volgende beschikbaar terwijl check pending blijft (gemockte trage check)', async ({
    page,
    request,
  }) => {
    test.setTimeout(120_000);
    const payload = await createScenario(request, 'feedback');
    await openCustomer(page, payload);
    const confirm = page.getByRole('button', { name: /Klopt, verder/i });
    if (await confirm.count()) {
      await confirm.first().click();
    }
    await advanceUntil(page, /ruimte|overzicht|foto|woonkamer/i, 25);
    await uploadPhoto(page, 'room-overview-good.jpg');
    await expect(page.locator('[data-testid="photo-thumb-status"]').first()).toBeVisible({
      timeout: 20_000,
    });
    await forceSoftTimeoutUi(page);
    await expect(page.getByTestId('photo-soft-timeout-panel')).toContainText(/We kijken je foto nog na/i);
    await expect(page.getByTestId('next-button')).toBeVisible();
    await expect(page.getByTestId('next-button')).toBeEnabled();
  });
});
