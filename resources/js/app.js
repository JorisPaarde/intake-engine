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

    document.addEventListener(
        'invalid',
        (event) => {
            const el = event.target;
            if (!(el instanceof HTMLElement) || !('setCustomValidity' in el)) {
                return;
            }
            el.setCustomValidity(messageFor(el));
        },
        true,
    );

    document.addEventListener(
        'input',
        (event) => {
            const el = event.target;
            if (el instanceof HTMLElement && 'setCustomValidity' in el) {
                el.setCustomValidity('');
            }
        },
        true,
    );
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
 * recordNetworkUploadTiming(uploadId, ms) on the owning component after
 * the upload row exists (via livewire:commit / upload finish handshake).
 */
function registerLivewireUploadTiming() {
    /** @type {Map<string, {startedAt: number, networkMs: number|null, componentId: string|null}>} */
    const pending = new Map();

    const keyFor = (event) => {
        const input = event.target instanceof Element ? event.target : null;
        const name = input?.getAttribute?.('wire:model') || input?.getAttribute?.('name') || 'default';
        const root = input?.closest?.('[wire\\:id]');
        const id = root?.getAttribute?.('wire:id') || 'unknown';
        return id + '::' + name;
    };

    document.addEventListener('livewire-upload-start', (event) => {
        const key = keyFor(event);
        const root = event.target instanceof Element ? event.target.closest('[wire\\:id]') : null;
        pending.set(key, {
            startedAt: performance.now(),
            networkMs: null,
            componentId: root?.getAttribute?.('wire:id') || null,
        });
    });

    document.addEventListener('livewire-upload-progress', (event) => {
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

    const deliver = (componentId, ms) => {
        if (!componentId || ms === null || typeof Livewire === 'undefined') {
            return;
        }
        try {
            const component = Livewire.find(componentId);
            if (component && typeof component.call === 'function') {
                // Pass ms; server pairs it with the latest saved upload id.
                component.call('queueNetworkUploadTiming', ms);
            }
        } catch {
            // Soft-fail: timing is diagnostic only.
        }
    };

    document.addEventListener('livewire-upload-finish', (event) => {
        const key = keyFor(event);
        const entry = pending.get(key);
        if (!entry) {
            return;
        }
        if (entry.networkMs === null) {
            entry.networkMs = Math.max(0, Math.round(performance.now() - entry.startedAt));
        }
        deliver(entry.componentId, entry.networkMs);
        pending.delete(key);
    });

    document.addEventListener('livewire-upload-error', (event) => {
        pending.delete(keyFor(event));
    });
}

registerLivewireUploadTiming();

Alpine.start();
