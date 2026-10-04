/**
 * Shared hosting resilience for Livewire uploads and update requests (BL-143).
 *
 * Hard rules:
 * - At most 3 attempts per action (no request storm).
 * - Exponential backoff with jitter: ~2 s, ~20 s, ~60 s.
 * - Retry-After header wins when present (capped at 90 s).
 * - Only one retry at a time per page (global queue/lock).
 * - Polling pauses while a 503/retry window is active.
 */

/**
 * Max automatic retries after the first failure (BL-143).
 * Sequence: original → wait ~2s → retry 1 → wait ~20s → retry 2 → wait ~60s → retry 3 → stop.
 * That spans a typical 35–66 s host 503 window without a request storm.
 */
export const MAX_ATTEMPTS = 3;

/** Base delay before each automatic retry (after failure 1, 2, 3). */
export const BACKOFF_BASE_MS = Object.freeze([2_000, 20_000, 60_000]);

export const RETRY_AFTER_CAP_MS = 90_000;

export const BUSY_MESSAGE = 'De server is even druk. Probeer het zo opnieuw.';

export const RETRYING_MESSAGE = 'Even geduld, we proberen het opnieuw.';

/**
 * @param {number} status
 * @returns {boolean}
 */
export function isRetryableStatus(status) {
    const code = Number(status);
    if (! Number.isFinite(code)) {
        return false;
    }
    if (code === 0) {
        return true; // network / aborted connection
    }
    if (code === 408 || code === 429) {
        return true;
    }
    return code >= 500 && code <= 599;
}

/**
 * Parse Retry-After (seconds or HTTP-date) to milliseconds, capped.
 *
 * @param {string|null|undefined} header
 * @param {() => number} [nowMs]
 * @returns {number|null}
 */
export function parseRetryAfterMs(header, nowMs = () => Date.now()) {
    if (header === null || header === undefined) {
        return null;
    }
    const raw = String(header).trim();
    if (raw === '') {
        return null;
    }

    if (/^\d+(\.\d+)?$/.test(raw)) {
        const seconds = Number(raw);
        if (! Number.isFinite(seconds) || seconds < 0) {
            return null;
        }
        return Math.min(Math.round(seconds * 1000), RETRY_AFTER_CAP_MS);
    }

    const dateMs = Date.parse(raw);
    if (Number.isNaN(dateMs)) {
        return null;
    }
    const delta = dateMs - nowMs();
    if (delta <= 0) {
        return 0;
    }
    return Math.min(delta, RETRY_AFTER_CAP_MS);
}

/**
 * Backoff for the next attempt after `failedAttempt` (1-based: first failure → delay before attempt 2).
 *
 * @param {number} failedAttempt 1..MAX_ATTEMPTS
 * @param {{ retryAfterHeader?: string|null, random?: () => number, nowMs?: () => number }} [options]
 * @returns {number} milliseconds to wait before the next attempt
 */
export function computeBackoffMs(failedAttempt, options = {}) {
    const attempt = Math.max(1, Math.min(Number(failedAttempt) || 1, MAX_ATTEMPTS));
    const random = typeof options.random === 'function' ? options.random : Math.random;
    const nowMs = typeof options.nowMs === 'function' ? options.nowMs : () => Date.now();

    const fromHeader = parseRetryAfterMs(options.retryAfterHeader, nowMs);
    if (fromHeader !== null) {
        return fromHeader;
    }

    const base = BACKOFF_BASE_MS[attempt - 1] ?? BACKOFF_BASE_MS[BACKOFF_BASE_MS.length - 1];
    // Full jitter in [base * 0.75, base * 1.25]
    const jitterFactor = 0.75 + random() * 0.5;
    return Math.max(0, Math.round(base * jitterFactor));
}

/**
 * Whether another automatic retry is allowed after `failedAttempt` failures.
 * failedAttempt=1..3 may still retry; failedAttempt>=4 must stop.
 *
 * @param {number} failedAttempt number of failures so far (1 after first fail)
 * @returns {boolean}
 */
export function canAutoRetry(failedAttempt) {
    return Number(failedAttempt) <= MAX_ATTEMPTS;
}

/**
 * Global single-flight retry queue: only one retry wait/execute at a time per page.
 */
export function createRetryGate(options = {}) {
    const delayFn = typeof options.delay === 'function'
        ? options.delay
        : (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    /** @type {Promise<void>} */
    let chain = Promise.resolve();
    let active = false;
    /** @type {Set<(active: boolean) => void>} */
    const listeners = new Set();

    const notify = () => {
        listeners.forEach((listener) => {
            try {
                listener(active);
            } catch {
                // ignore listener errors
            }
        });
    };

    const setActive = (value) => {
        if (active === value) {
            return;
        }
        active = value;
        notify();
        if (typeof document !== 'undefined') {
            document.dispatchEvent(new CustomEvent('intake:server-busy', {
                detail: { active },
            }));
            document.documentElement.dataset.intakeServerBusy = active ? '1' : '0';
        }
    };

    return {
        isActive: () => active,
        onActiveChange(listener) {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
        /**
         * Enqueue exclusive work. Waits `waitMs` inside the lock, then runs `task`.
         *
         * @template T
         * @param {number} waitMs
         * @param {() => Promise<T>|T} task
         * @returns {Promise<T>}
         */
        runExclusive(waitMs, task) {
            const job = chain.then(async () => {
                setActive(true);
                try {
                    const wait = Math.max(0, Number(waitMs) || 0);
                    if (wait > 0) {
                        await delayFn(wait);
                    }
                    return await task();
                } finally {
                    setActive(false);
                }
            });

            // Keep the chain alive even if a job rejects.
            chain = job.then(() => undefined, () => undefined);
            return job;
        },
    };
}

/**
 * Parse Livewire upload-file JSON body for temporary paths.
 *
 * @param {string|null|undefined} raw
 * @returns {string[]|null}
 */
export function parseUploadPaths(raw) {
    if (typeof raw !== 'string' || raw.trim() === '') {
        return null;
    }
    try {
        const json = JSON.parse(raw);
        const paths = json?.paths;
        if (! Array.isArray(paths) || paths.length === 0) {
            return null;
        }
        return paths;
    } catch {
        return null;
    }
}

/**
 * Empty/invalid HTTP 2xx on upload-file (BL-128) — treat as retryable.
 *
 * @param {number} status
 * @param {string|null|undefined} responseText
 * @returns {boolean}
 */
export function isEmptyOrInvalidUploadSuccess(status, responseText) {
    const code = Number(status);
    if (! Number.isFinite(code) || String(code)[0] !== '2') {
        return false;
    }
    return parseUploadPaths(responseText) === null;
}

/**
 * Whether a Livewire poll action should be cancelled while a 503 retry window is active.
 *
 * @param {boolean} serverBusy
 * @param {string|null|undefined} metadataType
 * @returns {boolean}
 */
export function shouldPausePoll(serverBusy, metadataType) {
    return Boolean(serverBusy) && metadataType === 'poll';
}
