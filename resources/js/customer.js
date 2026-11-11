/**
 * Customer wizard runtime (no Alpine — Livewire owns the single Alpine instance).
 * Loads BL-128/BL-143/BL-148 upload resilience + client photo downscale.
 */

import {
    registerLivewireUploadResilience,
    registerLivewireUpdateResilience,
    registerPollPauseWhileBusy,
} from './livewire-resilience';
import { registerClientPhotoDownscale } from './photo-downscale';

registerLivewireUploadResilience();
registerLivewireUpdateResilience();
registerPollPauseWhileBusy();
registerClientPhotoDownscale();

/**
 * BL-161: Vorige mag geen tekstselectie/klembord-side-effect hebben.
 * De klantwizard schrijft nergens naar het klembord; wel kan een geselecteerd
 * invoerveld bij blur/morph per ongeluk in het klembord belanden.
 */
window.__intakeClearWizardSelection = () => {
    try {
        const active = document.activeElement;
        if (active instanceof HTMLElement && typeof active.blur === 'function') {
            active.blur();
        }
    } catch {
        // Soft-fail: navigatie mag nooit blokkeren.
    }
    try {
        const selection = window.getSelection?.();
        selection?.removeAllRanges?.();
    } catch {
        // Soft-fail.
    }
};

document.addEventListener(
    'click',
    (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (! target) {
            return;
        }
        if (target.closest('[data-testid="footer-previous"], [data-testid="footer-previous-link"]')) {
            window.__intakeClearWizardSelection();
        }
    },
    true,
);
