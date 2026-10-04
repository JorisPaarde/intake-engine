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
