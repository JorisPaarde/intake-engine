import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    BACKOFF_BASE_MS,
    BUSY_MESSAGE,
    MAX_ATTEMPTS,
    RETRY_AFTER_CAP_MS,
    RETRYING_MESSAGE,
    canAutoRetry,
    computeBackoffMs,
    createRetryGate,
    isEmptyOrInvalidUploadSuccess,
    isRetryableStatus,
    parseRetryAfterMs,
    parseUploadPaths,
    shouldPausePoll,
} from './server-resilience.js';

describe('server-resilience (BL-143)', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('allows at most 3 automatic retries after failures', () => {
        expect(MAX_ATTEMPTS).toBe(3);
        expect(canAutoRetry(1)).toBe(true);
        expect(canAutoRetry(2)).toBe(true);
        expect(canAutoRetry(3)).toBe(true);
        expect(canAutoRetry(4)).toBe(false);
    });

    it('uses backoff bases ~2s, ~20s, ~60s in order', () => {
        expect(BACKOFF_BASE_MS).toEqual([2_000, 20_000, 60_000]);

        const fixed = (n) => () => n;
        // random=0 → 0.75× base; random=1 → 1.25× base
        expect(computeBackoffMs(1, { random: fixed(0) })).toBe(1_500);
        expect(computeBackoffMs(1, { random: fixed(1) })).toBe(2_500);
        expect(computeBackoffMs(2, { random: fixed(0) })).toBe(15_000);
        expect(computeBackoffMs(2, { random: fixed(1) })).toBe(25_000);
        expect(computeBackoffMs(3, { random: fixed(0) })).toBe(45_000);
        expect(computeBackoffMs(3, { random: fixed(1) })).toBe(75_000);
    });

    it('honors Retry-After seconds and caps at 90s', () => {
        expect(parseRetryAfterMs('12')).toBe(12_000);
        expect(parseRetryAfterMs('120')).toBe(RETRY_AFTER_CAP_MS);
        expect(computeBackoffMs(1, { retryAfterHeader: '8', random: () => 0 })).toBe(8_000);
        expect(computeBackoffMs(2, { retryAfterHeader: '200', random: () => 0 })).toBe(90_000);
    });

    it('honors Retry-After HTTP-date relative to now', () => {
        const now = 1_700_000_000_000;
        const header = new Date(now + 15_000).toUTCString();
        expect(parseRetryAfterMs(header, () => now)).toBe(15_000);

        const far = new Date(now + 120_000).toUTCString();
        expect(parseRetryAfterMs(far, () => now)).toBe(90_000);
    });

    it('Retry-After takes precedence over exponential backoff', () => {
        const delay = computeBackoffMs(3, {
            retryAfterHeader: '5',
            random: () => 1,
        });
        expect(delay).toBe(5_000);
        expect(delay).not.toBe(75_000);
    });

    it('runs only one exclusive retry at a time (global lock)', async () => {
        const gate = createRetryGate({
            delay: (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
        });

        const order = [];
        const first = gate.runExclusive(2_000, async () => {
            order.push('a-start');
            await new Promise((resolve) => setTimeout(resolve, 1_000));
            order.push('a-end');
            return 'a';
        });
        const second = gate.runExclusive(0, async () => {
            order.push('b-start');
            order.push('b-end');
            return 'b';
        });

        // Flush the microtask that starts the exclusive chain.
        await Promise.resolve();
        expect(gate.isActive()).toBe(true);

        await vi.advanceTimersByTimeAsync(2_000);
        expect(order).toEqual(['a-start']);

        await vi.advanceTimersByTimeAsync(1_000);
        await first;
        await second;

        expect(order).toEqual(['a-start', 'a-end', 'b-start', 'b-end']);
        expect(gate.isActive()).toBe(false);
    });

    it('marks retryable HTTP statuses for 5xx/network/429/408 only', () => {
        expect(isRetryableStatus(0)).toBe(true);
        expect(isRetryableStatus(408)).toBe(true);
        expect(isRetryableStatus(429)).toBe(true);
        expect(isRetryableStatus(500)).toBe(true);
        expect(isRetryableStatus(503)).toBe(true);
        expect(isRetryableStatus(419)).toBe(false);
        expect(isRetryableStatus(422)).toBe(false);
        expect(isRetryableStatus(200)).toBe(false);
    });

    it('detects empty/invalid upload-file success bodies', () => {
        expect(isEmptyOrInvalidUploadSuccess(200, '')).toBe(true);
        expect(isEmptyOrInvalidUploadSuccess(200, '{}')).toBe(true);
        expect(isEmptyOrInvalidUploadSuccess(200, '{"paths":[]}')).toBe(true);
        expect(isEmptyOrInvalidUploadSuccess(200, '{"paths":["tmp/a"]}')).toBe(false);
        expect(isEmptyOrInvalidUploadSuccess(503, '{"paths":["tmp/a"]}')).toBe(false);
        expect(parseUploadPaths('{"paths":["x"]}')).toEqual(['x']);
    });

    it('exposes calm Dutch copy for retrying and exhausted states', () => {
        expect(RETRYING_MESSAGE).toBe('Even geduld, we proberen het opnieuw.');
        expect(BUSY_MESSAGE).toBe('De server is even druk. Probeer het zo opnieuw.');
    });

    it('pauses wire:poll while a 503 retry window is active', () => {
        expect(shouldPausePoll(true, 'poll')).toBe(true);
        expect(shouldPausePoll(true, 'model.live')).toBe(false);
        expect(shouldPausePoll(false, 'poll')).toBe(false);
    });
});
