import Alpine from 'alpinejs';
import { registerDemoGuide } from './demo-guide';

window.Alpine = Alpine;

registerDemoGuide(Alpine);

/**
 * Dutch HTML5 constraint messages (BL-071). Browser-native English
 * ("Please fill out this field.") is replaced when the document is nl.
 */
function registerDutchFormValidation() {
    const locale = (document.documentElement.lang || '').toLowerCase();
    if (!locale.startsWith('nl')) {
        return;
    }

    const messageFor = (el) => {
        // Native checks only — customError would otherwise stick as "Controleer dit veld."
        if (el.validity.valueMissing) {
            return 'Vul dit veld in.';
        }
        if (el.validity.typeMismatch) {
            if (el.type === 'email') {
                return 'Vul een geldig e-mailadres in.';
            }
            return 'De invoer klopt niet.';
        }
        if (el.validity.patternMismatch) {
            return 'De invoer heeft niet het juiste formaat.';
        }
        if (el.validity.tooShort) {
            return 'De invoer is te kort.';
        }
        if (el.validity.tooLong) {
            return 'De invoer is te lang.';
        }
        if (el.validity.rangeUnderflow || el.validity.rangeOverflow) {
            return 'Kies een geldige waarde.';
        }
        return 'Controleer dit veld.';
    };

    const clearCustomValidity = (el) => {
        if (el instanceof HTMLElement && 'setCustomValidity' in el) {
            el.setCustomValidity('');
        }
    };

    const recomputeCustomValidity = (el) => {
        if (!(el instanceof HTMLElement) || !('setCustomValidity' in el)) {
            return;
        }
        // Clear first so autofilled values are not blocked by a stale customError.
        el.setCustomValidity('');
        if (!el.validity.valid) {
            el.setCustomValidity(messageFor(el));
        }
    };

    document.addEventListener(
        'invalid',
        (event) => {
            recomputeCustomValidity(event.target);
        },
        true,
    );

    ['input', 'change'].forEach((eventName) => {
        document.addEventListener(
            eventName,
            (event) => {
                clearCustomValidity(event.target);
            },
            true,
        );
    });

    // Expose for address-lookup autofill / submit guards (create form).
    window.__intakeClearCustomValidity = clearCustomValidity;
    window.__intakeRecomputeCustomValidity = recomputeCustomValidity;
}

registerDutchFormValidation();

/**
 * Deep-links (#room-12, #demo-customer-task, …) must also work when the
 * target sits in, or carries, an ingeklapt <details>-blok: open the
 * ancestors and any `details[data-open-on-target]` directly in the target.
 */
function registerHashDisclosure() {
    const reveal = () => {
        const id = decodeURIComponent(window.location.hash.slice(1));
        if (!id) {
            return;
        }
        const target = document.getElementById(id);
        if (!target) {
            return;
        }
        for (let el = target.parentElement; el; el = el.parentElement) {
            if (el.tagName === 'DETAILS') {
                el.open = true;
            }
        }
        if (target.tagName === 'DETAILS') {
            target.open = true;
        }
        const inner = target.querySelector('details[data-open-on-target]');
        if (inner) {
            inner.open = true;
        }
    };

    window.addEventListener('hashchange', reveal);
    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a[href*="#"]') : null;
        if (link && link.hash && link.pathname === window.location.pathname && link.hash === window.location.hash) {
            reveal();
        }
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', reveal);
    } else {
        reveal();
    }
}

registerHashDisclosure();

/**
 * Livewire file-upload network timing (BL-116 / P2).
 * Measure start → first livewire-upload-progress at 100%, then call
 * recordNetworkUploadTiming(uploadId, ms) after the server dispatches ai-upload-stored.
 */
function registerLivewireUploadTiming() {
    /** @type {Map<string, {startedAt: number, networkMs: number|null, componentId: string|null}>} */
    const pending = new Map();

    /** @type {Map<string, number[]>} FIFO network ms per Livewire component */
    const networkMsByComponent = new Map();

    const isTimedInput = (event) => {
        const input = event.target instanceof Element ? event.target : null;
        if (!input) {
            return false;
        }
        return input.closest('[data-upload-timing="1"]') !== null;
    };

    const keyFor = (event) => {
        const input = event.target instanceof Element ? event.target : null;
        const name = input?.getAttribute?.('wire:model') || input?.getAttribute?.('name') || 'default';
        const root = input?.closest?.('[wire\\:id]');
        const id = root?.getAttribute?.('wire:id') || 'unknown';
        return id + '::' + name;
    };

    const enqueueMs = (componentId, ms) => {
        if (!componentId || ms === null) {
            return;
        }
        const queue = networkMsByComponent.get(componentId) ?? [];
        queue.push(ms);
        networkMsByComponent.set(componentId, queue);
    };

    const dequeueAnyMs = () => {
        for (const [componentId, queue] of networkMsByComponent) {
            if (!queue || queue.length === 0) {
                continue;
            }
            const ms = queue.shift();
            if (queue.length === 0) {
                networkMsByComponent.delete(componentId);
            }

            return { componentId, ms: ms ?? null };
        }

        return null;
    };

    document.addEventListener('livewire-upload-start', (event) => {
        if (!isTimedInput(event)) {
            return;
        }
        const key = keyFor(event);
        const root = event.target instanceof Element ? event.target.closest('[wire\\:id]') : null;
        pending.set(key, {
            startedAt: performance.now(),
            networkMs: null,
            componentId: root?.getAttribute?.('wire:id') || null,
        });
    });

    document.addEventListener('livewire-upload-progress', (event) => {
        if (!isTimedInput(event)) {
            return;
        }
        const key = keyFor(event);
        const entry = pending.get(key);
        if (!entry || entry.networkMs !== null) {
            return;
        }
        const detail = event.detail || {};
        const progress = typeof detail.progress === 'number'
            ? detail.progress
            : (typeof detail === 'number' ? detail : null);
        if (progress === 100) {
            entry.networkMs = Math.max(0, Math.round(performance.now() - entry.startedAt));
        }
    });

    document.addEventListener('livewire-upload-finish', (event) => {
        if (!isTimedInput(event)) {
            return;
        }
        const key = keyFor(event);
        const entry = pending.get(key);
        if (!entry) {
            return;
        }
        if (entry.networkMs === null) {
            entry.networkMs = Math.max(0, Math.round(performance.now() - entry.startedAt));
        }
        enqueueMs(entry.componentId, entry.networkMs);
        pending.delete(key);
    });

    document.addEventListener('livewire-upload-error', (event) => {
        if (!isTimedInput(event)) {
            return;
        }
        pending.delete(keyFor(event));
    });

    const bindUploadStoredListener = () => {
        if (typeof Livewire === 'undefined' || typeof Livewire.on !== 'function') {
            return;
        }
        Livewire.on('ai-upload-stored', (payload) => {
            const uploadId = payload?.uploadId ?? payload?.[0]?.uploadId;
            if (!uploadId) {
                return;
            }
            const next = dequeueAnyMs();
            if (!next || next.ms === null) {
                return;
            }
            const component = typeof Livewire.find === 'function'
                ? Livewire.find(next.componentId)
                : null;
            if (!component || typeof component.call !== 'function') {
                return;
            }
            try {
                component.call('recordNetworkUploadTiming', uploadId, next.ms);
            } catch {
                // Soft-fail: timing is diagnostic only.
            }
        });
    };

    document.addEventListener('livewire:init', bindUploadStoredListener);
    if (typeof Livewire !== 'undefined') {
        bindUploadStoredListener();
    }
}

registerLivewireUploadTiming();

/**
 * Dutch Livewire request failures (e.g. LiteSpeed 503) instead of the English overlay.
 * Keeps the page/input; caller UI can offer "Opnieuw proberen".
 */
function registerLivewireDutchRequestErrors() {
    const messageForStatus = (status) => {
        if (status === 419) {
            return 'Je sessie is verlopen. Vernieuw de pagina en probeer opnieuw.';
        }
        if (status === 503 || status === 502 || status === 504) {
            return 'De server is even niet bereikbaar. Probeer het opnieuw.';
        }
        if (status >= 500) {
            return 'Er ging iets mis op de server. Probeer het opnieuw.';
        }
        if (status === 0) {
            return 'Geen verbinding. Controleer je netwerk en probeer opnieuw.';
        }
        return 'De aanvraag lukte niet. Probeer het opnieuw.';
    };

    const dispatchError = (status) => {
        const detail = {
            status: typeof status === 'number' ? status : 0,
            message: messageForStatus(typeof status === 'number' ? status : 0),
        };
        document.dispatchEvent(new CustomEvent('intake:livewire-request-failed', { detail }));
    };

    const bind = () => {
        if (typeof Livewire === 'undefined' || typeof Livewire.hook !== 'function') {
            return;
        }

        Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                if (typeof preventDefault === 'function') {
                    preventDefault();
                }
                dispatchError(status);
            });
        });
    };

    document.addEventListener('livewire:init', bind);
    if (typeof Livewire !== 'undefined') {
        bind();
    }
}

registerLivewireDutchRequestErrors();

Alpine.start();
