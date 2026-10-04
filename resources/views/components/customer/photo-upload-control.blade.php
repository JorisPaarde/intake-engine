@props([
    'composite',
    'wireModel',
    'inputId',
    'remainingSlots',
    'maxUploadKb',
    'uploadHardMaxBytes' => 15728640,
    'uploadHardMaxMegapixels' => 24,
    'uploadTooLargeMessage' => 'Deze foto is te groot. Probeer een andere foto of maak een nieuwe.',
    'tone' => 'intake',
    'helpExtra' => null,
    'uploadPhase' => '',
    'uploadPhaseMessage' => '',
    'uploadPhaseComposite' => '',
    'pendingAssessUploadIds' => [],
    'assessmentUiReleased' => [],
])

@php
    $isAssessing = $uploadPhase === 'assessing' && $uploadPhaseComposite === $composite;
    $isFailed = $uploadPhase === 'failed' && $uploadPhaseComposite === $composite;
    $hasPending = ! empty($pendingAssessUploadIds[$composite] ?? []);
    $quietPoll = in_array($composite, $assessmentUiReleased ?? [], true);
    $pollInterval = $quietPoll ? '5s' : '2s';

    $labelClass = $tone === 'followup'
        ? 'flex min-h-12 cursor-pointer flex-col items-center justify-center gap-1 rounded-md border border-dashed border-brand-fog bg-brand-mist/40 px-4 py-5 text-center'
        : 'flex min-h-12 cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-[#dde2da] bg-[#eef1ec] px-4 py-5 text-center';
    $titleClass = $tone === 'followup' ? 'text-sm font-semibold text-brand-ink' : 'text-sm font-semibold text-[#18201d]';
    $hintClass = $tone === 'followup' ? 'text-xs text-brand-ink/55' : 'text-xs text-[#5e6862]';
    $progressClass = $tone === 'followup' ? 'mt-2 text-sm font-medium text-brand-sea' : 'mt-2 text-sm font-medium text-[var(--tenant-primary)]';
    $retryingClass = $tone === 'followup' ? 'mt-2 text-sm text-brand-ink/55' : 'mt-2 text-sm text-[#5e6862]';
    $errorClass = $tone === 'followup' ? 'mt-2 space-y-1 text-sm font-medium text-brand-ember' : 'mt-2 space-y-1 text-sm font-medium text-[#a84832]';
    $linkClass = $tone === 'followup' ? 'mt-1 text-sm font-semibold text-brand-sea underline disabled:opacity-60' : 'mt-1 text-sm font-semibold text-[var(--tenant-primary)] underline disabled:opacity-60';
    $phaseClass = $tone === 'followup' ? 'mt-2 space-y-1 text-sm font-medium text-brand-sea' : 'mt-2 space-y-1 text-sm font-medium text-[var(--tenant-primary)]';
    $phaseHintClass = $tone === 'followup' ? 'text-xs font-normal text-brand-ink/55' : 'text-xs font-normal text-[#5e6862]';
    $bagErrorClass = $tone === 'followup' ? 'mt-2 text-sm text-brand-ember' : 'mt-2 text-sm text-[#a84832]';
@endphp

<div
    @if ($isAssessing || $hasPending)
        wire:poll.{{ $pollInterval }}="pollPendingAssessments"
    @endif
    x-data="{
        timedOut: false,
        timer: null,
        uploadTimedOut: false,
        uploadTimer: null,
        uploadError: '',
        requestError: '',
        uploadProgress: null,
        serverBusy: false,
        autoRetrying: false,
        retryMessage: '',
        retryUntilMs: null,
        retryCountdown: '',
        countdownTimer: null,
        uploadProperty: @js($wireModel),
        inactivityMs: 45000,
        serverWaitMs: 120000,
        clientUploading: false,
        prepBusy: false,
        arm() {
            clearTimeout(this.timer);
            this.timedOut = false;
            if ($wire.uploadPhase === 'assessing' && $wire.uploadPhaseComposite === @js($composite)) {
                this.timer = setTimeout(() => { this.timedOut = true }, 90000);
            }
        },
        clearLivewireUpload() {
            try {
                if (typeof $wire.cancelUpload === 'function') {
                    $wire.cancelUpload(this.uploadProperty);
                }
            } catch (e) {
                // Soft-fail: bag kan al leeg zijn.
            }
        },
        clearCountdown() {
            clearInterval(this.countdownTimer);
            this.countdownTimer = null;
            this.retryCountdown = '';
            this.retryUntilMs = null;
        },
        tickCountdown() {
            if (! this.retryUntilMs) {
                this.retryCountdown = '';
                return;
            }
            const left = Math.max(0, Math.ceil((this.retryUntilMs - Date.now()) / 1000));
            this.retryCountdown = left > 0 ? ('Nog ' + left + 's…') : '';
        },
        startCountdown(waitMs) {
            this.clearCountdown();
            const wait = Math.max(0, Number(waitMs) || 0);
            if (wait <= 0) {
                return;
            }
            this.retryUntilMs = Date.now() + wait;
            this.tickCountdown();
            this.countdownTimer = setInterval(() => this.tickCountdown(), 250);
        },
        armInactivityTimer() {
            clearTimeout(this.uploadTimer);
            this.uploadTimer = setTimeout(() => {
                if (this.serverBusy || this.autoRetrying) {
                    return;
                }
                this.clearLivewireUpload();
                this.uploadTimedOut = true;
                this.uploadError = 'De server is even druk. Probeer het zo opnieuw.';
            }, this.inactivityMs);
        },
        armServerWaitTimer() {
            clearTimeout(this.uploadTimer);
            this.serverBusy = true;
            this.uploadTimer = setTimeout(() => {
                if (this.autoRetrying) {
                    return;
                }
                this.clearLivewireUpload();
                this.uploadTimedOut = true;
                this.serverBusy = false;
                this.uploadError = 'De server is even druk. Probeer het zo opnieuw.';
            }, this.serverWaitMs);
        },
        armUpload() {
            clearTimeout(this.uploadTimer);
            this.uploadTimedOut = false;
            this.uploadError = '';
            this.requestError = '';
            this.uploadProgress = 0;
            this.serverBusy = false;
            this.clientUploading = true;
            this.prepBusy = false;
            this.autoRetrying = false;
            this.retryMessage = '';
            this.clearCountdown();
            this.armInactivityTimer();
        },
        onPrepStart() {
            this.prepBusy = true;
            this.clientUploading = true;
            this.uploadTimedOut = false;
            this.uploadError = '';
            this.uploadProgress = 0;
            this.armInactivityTimer();
        },
        onPrepDone() {
            this.prepBusy = false;
        },
        onPrepFailed(event) {
            this.prepBusy = false;
            this.failUpload(event?.detail?.message
                || 'De server is even druk. Probeer het zo opnieuw.');
        },
        onUploadProgress(event) {
            const detail = event?.detail;
            const progress = typeof detail?.progress === 'number'
                ? detail.progress
                : (typeof detail === 'number' ? detail : null);
            if (typeof progress === 'number') {
                this.uploadProgress = progress;
                if (progress >= 100) {
                    this.armServerWaitTimer();
                    return;
                }
            }
            this.serverBusy = false;
            if (! this.autoRetrying) {
                this.armInactivityTimer();
            }
        },
        onServerBusy() {
            this.armServerWaitTimer();
        },
        onUploadRetrying(event) {
            this.autoRetrying = true;
            this.uploadTimedOut = false;
            this.uploadError = '';
            this.requestError = '';
            this.retryMessage = event?.detail?.message
                || 'Even geduld, we proberen het opnieuw.';
            this.startCountdown(event?.detail?.waitMs);
            this.armServerWaitTimer();
        },
        finishUpload() {
            clearTimeout(this.uploadTimer);
            this.serverBusy = false;
            this.clientUploading = false;
            this.prepBusy = false;
            this.autoRetrying = false;
            this.retryMessage = '';
            this.clearCountdown();
            this.uploadProgress = 100;
            if (! this.uploadTimedOut) {
                this.uploadError = '';
            }
        },
        failUpload(message) {
            clearTimeout(this.uploadTimer);
            this.clearLivewireUpload();
            this.serverBusy = false;
            this.clientUploading = false;
            this.prepBusy = false;
            this.autoRetrying = false;
            this.retryMessage = '';
            this.clearCountdown();
            this.uploadTimedOut = true;
            this.uploadProgress = null;
            this.uploadError = message
                || this.uploadError
                || 'De server is even druk. Probeer het zo opnieuw.';
        },
        retryUpload() {
            this.clearLivewireUpload();
            this.uploadTimedOut = false;
            this.uploadError = '';
            this.requestError = '';
            this.uploadProgress = null;
            this.serverBusy = false;
            this.clientUploading = false;
            this.prepBusy = false;
            this.autoRetrying = false;
            this.retryMessage = '';
            this.clearCountdown();
            const input = document.getElementById(@js($inputId));
            if (input) {
                input.disabled = false;
                input.removeAttribute('disabled');
                input.value = '';
                input.click();
            }
        },
        onRequestFailed(event) {
            const message = event?.detail?.message
                || 'De server is even druk. Probeer het zo opnieuw.';
            if (this.autoRetrying && ! event?.detail?.exhausted) {
                return;
            }
            this.requestError = message;
            this.failUpload(message);
        },
        onUploadFailed(event) {
            this.failUpload(event?.detail?.message
                || 'De server is even druk. Probeer het zo opnieuw.');
        },
    }"
    x-init="
        arm();
        $watch(() => $wire.uploadPhase, () => arm());
        $watch(() => $wire.uploadPhaseComposite, () => arm());
        window.addEventListener('intake:livewire-request-failed', (e) => onRequestFailed(e));
        window.addEventListener('intake:upload-retrying', (e) => onUploadRetrying(e));
        window.addEventListener('intake:upload-empty-response', (e) => onUploadRetrying(e));
        window.addEventListener('intake:upload-retry-succeeded', () => finishUpload());
        window.addEventListener('intake:upload-failed', (e) => onUploadFailed(e));
        window.addEventListener('intake:photo-prep-start', () => onPrepStart());
        window.addEventListener('intake:photo-prep-done', () => onPrepDone());
        window.addEventListener('intake:photo-prep-failed', (e) => onPrepFailed(e));
    "
    x-on:livewire-upload-start="armUpload()"
    x-on:livewire-upload-progress="onUploadProgress($event)"
    x-on:livewire-upload-finish="finishUpload()"
    x-on:livewire-upload-error="failUpload()"
    x-on:livewire-upload-cancel="finishUpload()"
    data-upload-timing="1"
    data-client-downscale="1"
    data-upload-max-bytes="{{ (int) $uploadHardMaxBytes }}"
    data-upload-max-megapixels="{{ $uploadHardMaxMegapixels }}"
    data-upload-too-large="{{ $uploadTooLargeMessage }}"
    {{ $attributes }}
>
    <label
        class="{{ $labelClass }}"
        :class="{ 'pointer-events-none opacity-60': (clientUploading && ! uploadTimedOut) || prepBusy }"
        wire:target="{{ $wireModel }}"
    >
        <span class="{{ $titleClass }}">Foto's maken of kiezen</span>
        <span class="{{ $hintClass }}">
            @if ($helpExtra)
                {{ $helpExtra }}
            @else
                JPEG, PNG, WebP of HEIC · max {{ number_format($maxUploadKb / 1024, 0) }} MB
                · tot {{ $remainingSlots }} {{ $remainingSlots === 1 ? 'foto' : "foto's" }}
                · camera of galerij
            @endif
        </span>
        <input
            id="{{ $inputId }}"
            type="file"
            accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif,image/*"
            multiple
            class="sr-only"
            wire:model="{{ $wireModel }}"
            x-bind:disabled="(clientUploading && ! uploadTimedOut) || prepBusy"
        >
    </label>
    <div
        wire:loading
        wire:target="{{ $wireModel }}"
        class="{{ $progressClass }}"
        data-uploading="1"
        data-testid="upload-progress"
        x-show="! uploadTimedOut"
    >
        <span x-text="
            uploadTimedOut ? '' : (
                autoRetrying
                    ? (retryMessage + (retryCountdown ? (' ' + retryCountdown) : ''))
                    : (
                        serverBusy
                            ? (uploadProgress >= 100 ? 'Bezig op de server…' : 'Uploaden…')
                            : (uploadProgress === null || uploadProgress >= 100 ? 'Uploaden…' : ('Uploaden… ' + uploadProgress + '%'))
                    )
            )
        "></span>
    </div>
    <div
        x-show="autoRetrying && ! uploadTimedOut"
        x-cloak
        class="{{ $retryingClass }}"
        role="status"
        data-testid="upload-retrying"
    >
        <p>
            <span x-text="retryMessage || 'Even geduld, we proberen het opnieuw.'"></span>
            <span class="ml-1 tabular-nums" x-text="retryCountdown"></span>
        </p>
    </div>
    <div
        x-show="uploadTimedOut && uploadError"
        x-cloak
        class="{{ $errorClass }}"
        role="alert"
        data-testid="upload-timeout-error"
    >
        <p x-text="uploadError"></p>
        <button
            type="button"
            class="{{ $linkClass }}"
            x-on:click="retryUpload()"
            data-testid="upload-retry-button"
        >
            Opnieuw proberen
        </button>
    </div>
    <div wire:loading.remove wire:target="{{ $wireModel }}">
        @if ($isAssessing)
            <div class="{{ $phaseClass }}" role="status" data-testid="upload-phase" wire:key="upload-phase-{{ $composite }}-assessing">
                <p>{{ $uploadPhaseMessage }}</p>
                <p class="{{ $phaseHintClass }}">Fase: Foto beoordelen</p>
                <div x-show="timedOut" x-cloak class="mt-1">
                    <button
                        type="button"
                        wire:click="retryFailedUploadPhase"
                        wire:loading.attr="disabled"
                        wire:target="pollPendingAssessments,assessPendingUploads,retryFailedUploadPhase"
                        class="{{ $linkClass }}"
                    >
                        Opnieuw beoordelen
                    </button>
                </div>
            </div>
        @elseif ($isFailed)
            <div class="{{ $phaseClass }}" role="status" data-testid="upload-phase" wire:key="upload-phase-{{ $composite }}-failed">
                <p>{{ $uploadPhaseMessage }}</p>
                <button
                    type="button"
                    wire:click="retryFailedUploadPhase"
                    wire:loading.attr="disabled"
                    wire:target="pollPendingAssessments,assessPendingUploads,retryFailedUploadPhase"
                    class="{{ $linkClass }}"
                >
                    Opnieuw proberen
                </button>
            </div>
        @endif
    </div>
    @error($wireModel)
        <p class="{{ $bagErrorClass }}">{{ $message }}</p>
    @enderror
</div>
