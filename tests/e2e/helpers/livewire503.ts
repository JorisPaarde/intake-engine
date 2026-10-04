import type { Page, Route } from '@playwright/test';

export type Livewire503Options = {
  /** How many Livewire update/upload calls should fail before passthrough. */
  failCount: number;
  /** Include Retry-After header on 503 responses. */
  retryAfterSeconds?: number | null;
  /** Restrict to update, upload-file, or both. */
  targets?: Array<'update' | 'upload-file'>;
  /**
   * When set, only fail Livewire update POSTs whose JSON body mentions one of
   * these call methods (e.g. `_finishUpload`). Ignores polls / unrelated updates.
   */
  callMethods?: string[];
};

/**
 * Livewire 3/4 serves scripted endpoints as `/livewire-{hash}/update` and
 * `/livewire-{hash}/upload-file` (not always `/livewire/update`).
 */
function isLivewireUrl(url: string): boolean {
  try {
    const path = new URL(url).pathname.toLowerCase();

    return path.includes('/livewire');
  } catch {
    return url.toLowerCase().includes('/livewire');
  }
}

function bodyMentionsCallMethod(postData: string | null, methods: string[]): boolean {
  if (! postData || methods.length === 0) {
    return true;
  }
  try {
    const json = JSON.parse(postData) as {
      components?: Array<{ calls?: Array<{ method?: string }> }>;
    };
    for (const component of json.components ?? []) {
      for (const call of component.calls ?? []) {
        if (call.method && methods.includes(call.method)) {
          return true;
        }
      }
    }
  } catch {
    // Fall through to substring match for non-JSON / alternate shapes.
  }

  return methods.some((method) => (postData ?? '').includes(`"${method}"`) || (postData ?? '').includes(`'${method}'`));
}

/**
 * Intercept Livewire endpoints and return 503 for the first N matching calls.
 * Counts only matching requests; later calls continue to the real server.
 */
export async function installLivewire503Simulation(
  page: Page,
  options: Livewire503Options,
): Promise<{ getFailHits: () => number; getPassHits: () => number; inFlight: () => number }> {
  const targets = options.targets ?? ['update', 'upload-file'];
  const callMethods = options.callMethods ?? [];
  let failHits = 0;
  let passHits = 0;
  let inFlight = 0;

  const matches = (url: string, postData: string | null): boolean => {
    if (! isLivewireUrl(url)) {
      return false;
    }
    const u = url.toLowerCase();
    if (targets.includes('update') && u.includes('/update')) {
      return bodyMentionsCallMethod(postData, callMethods);
    }
    if (targets.includes('upload-file') && u.includes('/upload-file')) {
      return true;
    }

    return false;
  };

  // Match both `/livewire/...` and `/livewire-{hash}/...`.
  await page.route(/\/livewire/i, async (route: Route) => {
    const request = route.request();
    const url = request.url();
    if (! matches(url, request.postData()) || request.method() === 'GET') {
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
