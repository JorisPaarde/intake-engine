import { expect, test } from '@playwright/test';

/**
 * BL-160: iframe auto-resize must shrink when navigating form → address confirm.
 */
test('embed iframe height shrinks after address confirmation step', async ({ page, request }) => {
  const prepare = await request.post('/__e2e__/embed/prepare');
  expect(prepare.ok()).toBeTruthy();

  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto('/embed-test');

  const iframe = page.locator('#intake-embed-staging-test');
  await expect(iframe).toBeVisible();

  const frame = page.frameLocator('#intake-embed-staging-test');
  await expect(frame.getByRole('heading', { name: 'Start je opname' })).toBeVisible();
  await expect(frame.locator('#address_house_number')).toHaveAttribute('type', 'text');

  // Wait for initial auto-resize.
  await page.waitForTimeout(500);
  const heightBefore = await iframe.evaluate((el) => (el as HTMLIFrameElement).getBoundingClientRect().height);
  expect(heightBefore).toBeGreaterThan(400);

  await frame.locator('#customer_name').fill('E2E Embed');
  await frame.locator('#customer_email').fill('e2e.embed@example.com');
  await frame.locator('#address_postal_code').fill('2037GR');
  await frame.locator('#address_house_number').fill('273');
  await frame.locator('#privacy_consent').check();
  await frame.getByTestId('embed-submit').click();

  await expect(frame.getByTestId('embed-address-confirm')).toBeVisible({ timeout: 15_000 });
  await expect(frame.getByTestId('embed-address-summary')).toContainText('Bernadottelaan 273');

  // Allow ResizeObserver + debounce to post the smaller height.
  await page.waitForFunction(
    (prev) => {
      const el = document.getElementById('intake-embed-staging-test') as HTMLIFrameElement | null;
      if (!el) return false;
      return el.getBoundingClientRect().height < prev - 80;
    },
    heightBefore,
    { timeout: 10_000 },
  );

  const heightAfter = await iframe.evaluate((el) => (el as HTMLIFrameElement).getBoundingClientRect().height);
  expect(heightAfter).toBeLessThan(heightBefore - 80);
  // Confirm card is short; leave little grey below Aanpassen.
  expect(heightAfter).toBeLessThan(460);

  // No horizontal overflow on the host page at 390.
  const overflow = await page.evaluate(() => {
    const doc = document.documentElement;
    return doc.scrollWidth - doc.clientWidth;
  });
  expect(overflow).toBeLessThanOrEqual(1);
});
