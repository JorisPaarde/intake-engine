import Alpine from 'alpinejs';
import { registerDemoGuide } from './demo-guide';
import {
    registerLivewireUploadResilience,
    registerLivewireUpdateResilience,
    registerPollPauseWhileBusy,
} from './livewire-resilience';
import { registerClientPhotoDownscale } from './photo-downscale';
import { BUSY_MESSAGE } from './server-resilience';

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
 * “Beoordeel bij (ruimte)” (BL-147, UX #15.1): na de sprong lichten de velden
 * waar de klanttaak over ging 2 s op. Ids staan in data-highlight-fields.
 */
function registerFieldFlashOnReview() {
    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a[data-highlight-fields]') : null;
        if (!link) {
            return;
        }
        const ids = (link.getAttribute('data-highlight-fields') || '').split(/\s+/).filter(Boolean);
        window.setTimeout(() => {
            ids.forEach((id) => {
                const field = document.getElementById(id);
                if (!field) {
                    return;
                }
                field.classList.add('field-flash');
                window.setTimeout(() => field.classList.remove('field-flash'), 2000);
            });
        }, 50);
    });
}

registerFieldFlashOnReview();

/**
 * Eigen bevestigingsdialoog (BL-147, UX #15.10) i.p.v. native confirm().
 * Trigger: [data-confirm-dialog-open="<dialog-id>"]. Focus start op Annuleren;
 * Esc (native cancel) of een klik buiten de dialoog annuleert.
 */
function registerConfirmDialogs() {
    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) {
            return;
        }

        const trigger = target.closest('[data-confirm-dialog-open]');
        if (trigger) {
            const dialog = document.getElementById(trigger.getAttribute('data-confirm-dialog-open') || '');
            if (!(dialog instanceof HTMLDialogElement) || typeof dialog.showModal !== 'function') {
                return;
            }
            event.preventDefault();
            dialog.showModal();
            dialog.querySelector('[data-confirm-dialog-cancel]')?.focus();
            return;
        }

        if (target.closest('[data-confirm-dialog-cancel]')) {
            target.closest('dialog')?.close();
            return;
        }

        // Klik op de backdrop: het event-doel is de <dialog> zelf.
        if (target instanceof HTMLDialogElement && target.hasAttribute('data-confirm-dialog') && target.open) {
            const rect = target.getBoundingClientRect();
            const inside = event.clientX >= rect.left && event.clientX <= rect.right
                && event.clientY >= rect.top && event.clientY <= rect.bottom;
            if (!inside) {
                target.close();
            }
        }
    });
}

registerConfirmDialogs();

/**
 * Installer photo gallery lightbox (N23): contain thumbnails open an in-page
 * <dialog> with context, Esc/backdrop/Sluiten close, focus return, prev/next.
 */
function registerPhotoLightbox() {
    /** @type {HTMLElement|null} */
    let lastTrigger = null;
    /** @type {string|null} */
    let activeGroup = null;
    /** @type {number} */
    let activeIndex = 0;

    const dialog = () => document.getElementById('photo-lightbox');

    const fills = (root) => ({
        heading: root.querySelector('[data-photo-lightbox-heading]'),
        caption: root.querySelector('[data-photo-lightbox-caption]'),
        label: root.querySelector('[data-photo-lightbox-label]'),
        image: root.querySelector('[data-photo-lightbox-image]'),
        original: root.querySelector('[data-photo-lightbox-original]'),
        prev: root.querySelector('[data-photo-lightbox-prev]'),
        next: root.querySelector('[data-photo-lightbox-next]'),
        close: root.querySelector('[data-photo-lightbox-close]'),
    });

    const triggersInGroup = (group) =>
        Array.from(document.querySelectorAll(`[data-photo-lightbox-open][data-photo-group="${CSS.escape(group)}"]`));

    const fillFromTrigger = (root, trigger) => {
        const els = fills(root);
        const heading = trigger.getAttribute('data-photo-heading') || '';
        const caption = trigger.getAttribute('data-photo-caption') || '';
        const label = trigger.getAttribute('data-photo-label') || '';
        const src = trigger.getAttribute('data-photo-src') || '';
        const original = trigger.getAttribute('data-photo-original') || src;

        if (els.heading) {
            els.heading.textContent = heading;
        }
        if (els.caption) {
            els.caption.textContent = caption;
        }
        if (els.label) {
            if (label) {
                els.label.textContent = label;
                els.label.classList.remove('hidden');
            } else {
                els.label.textContent = '';
                els.label.classList.add('hidden');
            }
        }
        if (els.image instanceof HTMLImageElement) {
            els.image.src = src;
            els.image.alt = caption || heading;
        }
        if (els.original instanceof HTMLAnchorElement) {
            els.original.href = original;
        }

        activeGroup = trigger.getAttribute('data-photo-group');
        activeIndex = Number.parseInt(trigger.getAttribute('data-photo-index') || '0', 10) || 0;
        lastTrigger = trigger;

        const siblings = activeGroup ? triggersInGroup(activeGroup) : [];
        const multi = siblings.length > 1;
        if (els.prev instanceof HTMLButtonElement) {
            els.prev.disabled = !multi;
            els.prev.hidden = !multi;
        }
        if (els.next instanceof HTMLButtonElement) {
            els.next.disabled = !multi;
            els.next.hidden = !multi;
        }
    };

    const openFrom = (trigger) => {
        const root = dialog();
        if (!(root instanceof HTMLDialogElement) || typeof root.showModal !== 'function') {
            return;
        }
        fillFromTrigger(root, trigger);
        root.showModal();
        fills(root).close?.focus();
    };

    const navigate = (delta) => {
        const root = dialog();
        if (!(root instanceof HTMLDialogElement) || !root.open || !activeGroup) {
            return;
        }
        const siblings = triggersInGroup(activeGroup);
        if (siblings.length < 2) {
            return;
        }
        const nextIndex = (activeIndex + delta + siblings.length) % siblings.length;
        const nextTrigger = siblings[nextIndex];
        if (nextTrigger instanceof HTMLElement) {
            fillFromTrigger(root, nextTrigger);
            fills(root).close?.focus();
        }
    };

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) {
            return;
        }

        const trigger = target.closest('[data-photo-lightbox-open]');
        if (trigger instanceof HTMLElement) {
            event.preventDefault();
            openFrom(trigger);
            return;
        }

        if (target.closest('[data-photo-lightbox-close]')) {
            dialog()?.close();
            return;
        }

        if (target.closest('[data-photo-lightbox-prev]')) {
            event.preventDefault();
            navigate(-1);
            return;
        }

        if (target.closest('[data-photo-lightbox-next]')) {
            event.preventDefault();
            navigate(1);
            return;
        }

        const root = dialog();
        if (target instanceof HTMLDialogElement && target.hasAttribute('data-photo-lightbox') && target.open) {
            const rect = target.getBoundingClientRect();
            const inside = event.clientX >= rect.left && event.clientX <= rect.right
                && event.clientY >= rect.top && event.clientY <= rect.bottom;
            if (!inside) {
                target.close();
            }
        } else if (root instanceof HTMLDialogElement && root.open && target === root) {
            root.close();
        }
    });

    document.addEventListener('keydown', (event) => {
        const root = dialog();
        if (!(root instanceof HTMLDialogElement) || !root.open) {
            return;
        }
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            navigate(-1);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            navigate(1);
        }
    });

    document.addEventListener('close', (event) => {
        if (!(event.target instanceof HTMLDialogElement) || !event.target.hasAttribute('data-photo-lightbox')) {
            return;
        }
        lastTrigger?.focus();
    }, true);
}

registerPhotoLightbox();

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
 * BL-128/BL-143: upload-file + Livewire update resilience (retry gate, backoff, poll pause).
 * Implementation lives in livewire-resilience.js / server-resilience.js.
 */
registerLivewireUploadResilience();
registerLivewireUpdateResilience();
registerPollPauseWhileBusy();

/**
 * BL-128/BL-143/BL-148: client photo downscale (shared module).
 */
registerClientPhotoDownscale();


/**
 * Dutch messages for non-retryable Livewire failures (419 etc.).
 * Retryable 5xx/network are owned by livewire-resilience.js (BL-143).
 */
function registerLivewireDutchRequestErrors() {
    const messageForStatus = (status) => {
        if (status === 419) {
            return 'Je sessie is verlopen. Vernieuw de pagina en probeer opnieuw.';
        }
        if (status === 503 || status === 502 || status === 504) {
            return BUSY_MESSAGE;
        }
        if (status >= 500) {
            return BUSY_MESSAGE;
        }
        if (status === 0) {
            return 'Geen verbinding. Controleer je netwerk en probeer opnieuw.';
        }
        return 'De aanvraag lukte niet. Probeer het opnieuw.';
    };

    const bind = () => {
        if (typeof Livewire === 'undefined' || typeof Livewire.hook !== 'function') {
            return;
        }
        if (window.__intakeDutchRequestErrorsBound) {
            return;
        }
        window.__intakeDutchRequestErrorsBound = true;

        Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                const code = typeof status === 'number' ? status : 0;
                // Retryable statuses: resilience module handles preventDefault + retry.
                if (code === 0 || code === 408 || code === 429 || (code >= 500 && code <= 599)) {
                    return;
                }
                if (typeof preventDefault === 'function') {
                    preventDefault();
                }
                document.dispatchEvent(new CustomEvent('intake:livewire-request-failed', {
                    detail: {
                        status: code,
                        message: messageForStatus(code),
                    },
                }));
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
