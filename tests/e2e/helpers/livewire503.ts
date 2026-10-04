import type { Page, Route } from '@playwright/test';

export type Livewire503Options = {
  /** How many Livewire update/upload calls should fail before passthrough. */
  failCount: number;
  /** Include Retry-After header on 503 responses. */
  retryAfterSeconds?: number | null;
  /** Restrict to update, upload-file, or both. */
  targets?: Array<'update' | 'upload-file'>;
};

/**
 * Intercept Livewire endpoints and return 503 for the first N matching calls.
 * Counts only matching requests; later calls continue to the real server.
 */
export async function installLivewire503Simulation(
  page: Page,
  options: Livewire503Options,
): Promise<{ getFailHits: () => number; getPassHits: () => number; inFlight: () => number }> {
  const targets = options.targets ?? ['update', 'upload-file'];
  let failHits = 0;
  let passHits = 0;
  let inFlight = 0;

  const matches = (url: string): boolean => {
    const u = url.toLowerCase();
    const isLivewire = u.includes('/livewire');
    if (! isLivewire) {
      return false;
    }
    if (targets.includes('update') && u.includes('/update')) {
      return true;
    }
    if (targets.includes('upload-file') && u.includes('/upload-file')) {
      return true;
    }
    // Livewire 3/4 may use /livewire/update without extra segments.
    if (targets.includes('update') && /\/livewire\/?$/.test(u.split('?')[0] ?? '')) {
      return true;
    }
    return false;
  };

  await page.route('**/livewire/**', async (route: Route) => {
    const url = route.request().url();
    if (! matches(url) || route.request().method() === 'GET') {
      await route.continue();
      return;
    }

    if (failHits < options.failCount) {
      failHits += 1;
      inFlight += 1;
      const headers: Record<string, string> = {
        'content-type': 'text/html; charset=UTF-8',
      };
      if (options.retryAfterSeconds != null) {
        headers['retry-after'] = String(options.retryAfterSeconds);
      }
      await route.fulfill({
        status: 503,
        headers,
        body: 'Service Unavailable',
      });
      inFlight -= 1;
      return;
    }

    passHits += 1;
    await route.continue();
  });

  return {
    getFailHits: () => failHits,
    getPassHits: () => passHits,
    inFlight: () => inFlight,
  };
}
