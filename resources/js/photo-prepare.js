/**
 * Client-side photo prep before Livewire upload (BL-128 / BL-143 / staging intake 82).
 * Must never hang: every attempt resolves. Large files MUST be downscaled or the
 * prep fails closed — never upload the full original after a timeout (staging 82:
 * 2.2 MB progressive JPEG stayed in livewire-tmp after an 8s soft-fail).
 *
 * Demotest 8 okt: never pass placeholder square targets to createImageBitmap —
 * read real dimensions from image headers first so aspect ratio is preserved.
 */

export const MAX_LONG_EDGE = 2000;
export const JPEG_QUALITY = 0.85;
export const SKIP_BELOW_BYTES = 900 * 1024;
/** Soft deadline for the whole prep; large phone JPEGs need more than 8s. */
export const DOWNSCALE_TIMEOUT_MS = 20_000;
/** Hard safety net (must match config/intake.php defaults). */
export const HARD_MAX_BYTES = 15 * 1024 * 1024;
export const HARD_MAX_MEGAPIXELS = 24;
export const TOO_LARGE_MESSAGE = 'Deze foto is te groot. Probeer een andere foto of maak een nieuwe.';
/** Max relative difference between source and output aspect ratios. */
export const ASPECT_RATIO_TOLERANCE = 0.01;

/**
 * Map Livewire file wire:model prefix → client-originals property (intake + follow-up).
 *
 * @param {string} model
 * @returns {{ filesPrefix: string, originalsProperty: string, composite: string }|null}
 */
export function wireModelUploadTargets(model) {
    const value = String(model || '');
    const map = [
        { filesPrefix: 'photoFiles.', originalsProperty: 'photoClientOriginals' },
        { filesPrefix: 'followUpPhotoFiles.', originalsProperty: 'followUpPhotoClientOriginals' },
    ];
    for (const entry of map) {
        if (value.startsWith(entry.filesPrefix)) {
            return {
                filesPrefix: entry.filesPrefix,
                originalsProperty: entry.originalsProperty,
                composite: value.slice(entry.filesPrefix.length),
            };
        }
    }

    return null;
}

/**
 * @param {number} bytes
 * @param {number} [limit]
 * @returns {boolean}
 */
export function exceedsHardByteLimit(bytes, limit = HARD_MAX_BYTES) {
    return typeof bytes === 'number' && bytes > limit;
}

/**
 * @param {number|null|undefined} width
 * @param {number|null|undefined} height
 * @param {number} [limit]
 * @returns {boolean}
 */
export function exceedsHardMegapixelLimit(width, height, limit = HARD_MAX_MEGAPIXELS) {
    const w = Number(width) || 0;
    const h = Number(height) || 0;
    if (w <= 0 || h <= 0) {
        return false;
    }

    return (w * h) / 1_000_000 > limit;
}

/**
 * @param {number} originalWidth
 * @param {number} originalHeight
 * @param {number} maxLongEdge
 * @returns {{ width: number, height: number, scale: number }}
 */
export function computeTargetSize(originalWidth, originalHeight, maxLongEdge = MAX_LONG_EDGE) {
    const width = Math.max(1, Math.round(originalWidth) || 1);
    const height = Math.max(1, Math.round(originalHeight) || 1);
    const longEdge = Math.max(width, height);
    const scale = longEdge > maxLongEdge ? (maxLongEdge / longEdge) : 1;

    return {
        width: Math.max(1, Math.round(width * scale)),
        height: Math.max(1, Math.round(height * scale)),
        scale,
    };
}

/**
 * @param {number} sourceWidth
 * @param {number} sourceHeight
 * @param {number} outputWidth
 * @param {number} outputHeight
 * @param {number} [tolerance]
 * @returns {boolean}
 */
export function aspectRatiosMatch(sourceWidth, sourceHeight, outputWidth, outputHeight, tolerance = ASPECT_RATIO_TOLERANCE) {
    const sw = Number(sourceWidth) || 0;
    const sh = Number(sourceHeight) || 0;
    const ow = Number(outputWidth) || 0;
    const oh = Number(outputHeight) || 0;
    if (sw <= 0 || sh <= 0 || ow <= 0 || oh <= 0) {
        return false;
    }
    const sourceRatio = sw / sh;
    const outputRatio = ow / oh;

    return Math.abs(sourceRatio - outputRatio) / sourceRatio <= tolerance;
}

/**
 * @param {number} orientation EXIF orientation 1–8
 * @returns {boolean}
 */
export function orientationSwapsAxes(orientation) {
    const value = Number(orientation) || 1;

    return value >= 5 && value <= 8;
}

/**
 * Read width/height from JPEG / PNG / WebP headers (first ~64 KB).
 * Applies EXIF orientation 5–8 by swapping axes so dimensions match
 * createImageBitmap(..., { imageOrientation: 'from-image' }).
 *
 * @param {ArrayBuffer|Uint8Array} bytes
 * @returns {{ width: number, height: number, orientation: number }|null}
 */
export function readImageSizeFromBytes(bytes) {
    const view = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
    if (view.length < 24) {
        return null;
    }

    // PNG: 89 50 4E 47 … IHDR
    if (view[0] === 0x89 && view[1] === 0x50 && view[2] === 0x4E && view[3] === 0x47) {
        if (view.length < 24) {
            return null;
        }
        const width = readUint32BE(view, 16);
        const height = readUint32BE(view, 20);
        if (width < 1 || height < 1) {
            return null;
        }

        return { width, height, orientation: 1 };
    }

    // WebP: RIFF….WEBP
    if (view[0] === 0x52 && view[1] === 0x49 && view[2] === 0x46 && view[3] === 0x46
        && view[8] === 0x57 && view[9] === 0x45 && view[10] === 0x42 && view[11] === 0x50) {
        return readWebpSize(view);
    }

    // JPEG: FF D8
    if (view[0] === 0xFF && view[1] === 0xD8) {
        return readJpegSize(view);
    }

    return null;
}

/**
 * @param {File|Blob} file
 * @param {number} [maxBytes]
 * @returns {Promise<{ width: number, height: number, orientation: number }|null>}
 */
export async function readImageSizeFromFile(file, maxBytes = 65_536) {
    if (! file || typeof file.slice !== 'function') {
        return null;
    }

    try {
        const slice = file.slice(0, Math.max(64, maxBytes));
        const buffer = await slice.arrayBuffer();

        return readImageSizeFromBytes(buffer);
    } catch {
        return null;
    }
}

/**
 * @param {File} file
 * @param {number} originalWidth
 * @param {number} originalHeight
 * @returns {boolean}
 */
export function shouldDownscale(file, originalWidth, originalHeight) {
    if (! file || typeof file.size !== 'number') {
        return false;
    }
    const type = String(file.type || '').toLowerCase();
    if (type.includes('heic') || type.includes('heif') || ! type.startsWith('image/')) {
        return false;
    }
    const longEdge = Math.max(originalWidth || 0, originalHeight || 0);
    if (longEdge <= 0) {
        return file.size > SKIP_BELOW_BYTES;
    }

    return longEdge > MAX_LONG_EDGE || file.size > SKIP_BELOW_BYTES;
}

/**
 * True when uploading the given file without a successful downscale would be unsafe
 * (staging intake 82: full 2.2 MB progressive JPEG in livewire-tmp).
 *
 * @param {File} file
 * @returns {boolean}
 */
export function mustDownscaleOrFail(file) {
    if (! file || typeof file.size !== 'number') {
        return false;
    }
    const type = String(file.type || '').toLowerCase();
    if (type.includes('heic') || type.includes('heif') || ! type.startsWith('image/')) {
        return false;
    }

    return file.size > SKIP_BELOW_BYTES;
}

/**
 * @template T
 * @param {Promise<T>} promise
 * @param {number} ms
 * @param {string} [label]
 * @returns {Promise<T>}
 */
export function withTimeout(promise, ms, label = 'timeout') {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error(label)), Math.max(1, ms));
        promise.then(
            (value) => {
                clearTimeout(timer);
                resolve(value);
            },
            (error) => {
                clearTimeout(timer);
                reject(error);
            },
        );
    });
}

/**
 * @param {HTMLCanvasElement|OffscreenCanvas} canvas
 * @param {string} name
 * @param {number} [quality]
 * @returns {Promise<File|null>}
 */
export async function canvasToJpegFile(canvas, name, quality = JPEG_QUALITY) {
    const toBlob = () => new Promise((resolve) => {
        if (typeof canvas.convertToBlob === 'function') {
            canvas.convertToBlob({ type: 'image/jpeg', quality })
                .then((blob) => resolve(blob || null))
                .catch(() => resolve(null));
            return;
        }
        if (typeof canvas.toBlob === 'function') {
            canvas.toBlob((blob) => resolve(blob || null), 'image/jpeg', quality);
            return;
        }
        resolve(null);
    });

    const blob = await withTimeout(toBlob(), 5_000, 'toblob-timeout');
    if (! blob || blob.size <= 0) {
        return null;
    }
    const base = String(name || 'foto').replace(/\.[^.]+$/, '');

    return new File([blob], `${base}.jpg`, { type: 'image/jpeg', lastModified: Date.now() });
}

/**
 * Decode + resize via createImageBitmap when available (better with progressive JPEG).
 * When target has both width and height, both are passed. When only one is known
 * (header unreadable), pass a single long-edge resize so the browser keeps aspect.
 *
 * @param {File} file
 * @param {{ width?: number, height?: number, longEdgeOnly?: boolean }} target
 * @returns {Promise<{ bitmap: ImageBitmap, originalWidth: number, originalHeight: number }>}
 */
export async function decodeWithCreateImageBitmap(file, target) {
    if (typeof createImageBitmap !== 'function') {
        throw new Error('createImageBitmap-unavailable');
    }

    const options = {
        resizeQuality: 'medium',
        imageOrientation: 'from-image',
    };

    const width = Math.round(Number(target?.width) || 0);
    const height = Math.round(Number(target?.height) || 0);
    const longEdgeOnly = Boolean(target?.longEdgeOnly);

    if (longEdgeOnly && Math.max(width, height) > 0) {
        // Single-dimension resize preserves aspect ratio in supporting browsers.
        options.resizeWidth = Math.max(width, height);
    } else if (width > 0 && height > 0) {
        options.resizeWidth = width;
        options.resizeHeight = height;
    }

    let bitmap;
    try {
        bitmap = await createImageBitmap(file, options);
    } catch {
        try {
            bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        } catch {
            bitmap = await createImageBitmap(file);
        }
    }

    const originalWidth = Math.max(1, bitmap.width || 0);
    const originalHeight = Math.max(1, bitmap.height || 0);

    return { bitmap, originalWidth, originalHeight };
}

/**
 * @param {File} file
 * @param {string} reason
 * @param {number|null} [originalWidth]
 * @param {number|null} [originalHeight]
 * @returns {{ file: File, originalWidth: number|null, originalHeight: number|null, downscaled: boolean, reason: string, failed: boolean }}
 */
function failClosed(file, reason, originalWidth = null, originalHeight = null) {
    return {
        file,
        originalWidth,
        originalHeight,
        downscaled: false,
        reason,
        failed: true,
    };
}

/**
 * @param {number} width
 * @param {number} height
 * @returns {HTMLCanvasElement|OffscreenCanvas}
 */
function defaultCreateCanvas(width, height) {
    if (typeof OffscreenCanvas !== 'undefined') {
        return new OffscreenCanvas(width, height);
    }
    if (typeof document !== 'undefined' && typeof document.createElement === 'function') {
        return Object.assign(document.createElement('canvas'), { width, height });
    }
    throw new Error('canvas-unavailable');
}

/**
 * @param {File} file
 * @param {{ loadImage?: (file: File) => Promise<CanvasImageSource & { naturalWidth?: number, width?: number, naturalHeight?: number, height?: number }>, createBitmap?: typeof decodeWithCreateImageBitmap, toJpeg?: typeof canvasToJpegFile, createCanvas?: typeof defaultCreateCanvas, now?: () => number, readSize?: typeof readImageSizeFromFile }} [deps]
 * @returns {Promise<{ file: File, originalWidth: number|null, originalHeight: number|null, downscaled: boolean, reason?: string, failed?: boolean }>}
 */
export async function preparePhotoForUpload(file, deps = {}) {
    const type = String(file?.type || '').toLowerCase();
    if (type.includes('heic') || type.includes('heif') || ! type.startsWith('image/')) {
        return { file, originalWidth: null, originalHeight: null, downscaled: false, reason: 'skip-type', failed: false };
    }

    const loadImage = deps.loadImage || defaultLoadImage;
    const createBitmap = deps.createBitmap || decodeWithCreateImageBitmap;
    const toJpeg = deps.toJpeg || canvasToJpegFile;
    const createCanvas = deps.createCanvas || defaultCreateCanvas;
    const readSize = deps.readSize || readImageSizeFromFile;
    const requireDownscale = mustDownscaleOrFail(file);

    const work = (async () => {
        let originalWidth = 0;
        let originalHeight = 0;

        // Cheap header probe — avoids full decode of progressive JPEGs (staging 82)
        // and supplies real aspect for resizeWidth/resizeHeight.
        try {
            const header = await readSize(file);
            if (header && header.width > 1 && header.height > 1) {
                originalWidth = header.width;
                originalHeight = header.height;
            }
        } catch {
            // Fall through.
        }

        if (! requireDownscale && originalWidth <= 1 && typeof createImageBitmap === 'function') {
            try {
                const probe = await createImageBitmap(file, { imageOrientation: 'from-image' });
                originalWidth = Math.max(1, probe?.width || 0);
                originalHeight = Math.max(1, probe?.height || 0);
                if (probe && typeof probe.close === 'function') {
                    probe.close();
                }
            } catch {
                // Fall through to forced target decode.
            }
        }

        if (originalWidth > 1 && originalHeight > 1 && ! shouldDownscale(file, originalWidth, originalHeight)) {
            return {
                file,
                originalWidth,
                originalHeight,
                downscaled: false,
                reason: 'small-enough',
                failed: false,
            };
        }

        const hasSourceDims = originalWidth > 1 && originalHeight > 1;
        const target = hasSourceDims
            ? computeTargetSize(originalWidth, originalHeight)
            : { width: MAX_LONG_EDGE, height: 0, scale: 1 };

        let source = null;
        let srcW = originalWidth;
        let srcH = originalHeight;

        try {
            const decodeTarget = hasSourceDims
                ? { width: target.width, height: target.height }
                : { width: MAX_LONG_EDGE, height: 0, longEdgeOnly: true };
            const decoded = await createBitmap(file, decodeTarget);
            source = decoded.bitmap;

            if (hasSourceDims) {
                srcW = originalWidth;
                srcH = originalHeight;
            } else {
                // Header missing: bitmap may already be long-edge-capped; treat as source.
                srcW = Math.max(1, decoded.originalWidth);
                srcH = Math.max(1, decoded.originalHeight);
            }
        } catch {
            const image = await loadImage(file);
            source = image;
            srcW = Math.max(1, image.naturalWidth || image.width || 0);
            srcH = Math.max(1, image.naturalHeight || image.height || 0);
        }

        if (srcW <= 1 || srcH <= 1) {
            if (source && typeof source.close === 'function') {
                source.close();
            }

            return requireDownscale
                ? failClosed(file, 'unknown-dimensions')
                : { file, originalWidth: null, originalHeight: null, downscaled: false, reason: 'unknown-dimensions', failed: false };
        }

        const finalTarget = computeTargetSize(srcW, srcH);

        if (! requireDownscale && ! shouldDownscale(file, srcW, srcH)) {
            if (source && typeof source.close === 'function') {
                source.close();
            }
            return {
                file,
                originalWidth: srcW,
                originalHeight: srcH,
                downscaled: false,
                reason: 'small-enough',
                failed: false,
            };
        }

        if (! aspectRatiosMatch(srcW, srcH, finalTarget.width, finalTarget.height)) {
            if (source && typeof source.close === 'function') {
                source.close();
            }

            return requireDownscale
                ? failClosed(file, 'aspect-mismatch', srcW, srcH)
                : { file, originalWidth: srcW, originalHeight: srcH, downscaled: false, reason: 'aspect-mismatch', failed: false };
        }

        let canvas;
        try {
            canvas = createCanvas(finalTarget.width, finalTarget.height);
        } catch {
            if (source && typeof source.close === 'function') {
                source.close();
            }
            return requireDownscale
                ? failClosed(file, 'canvas-unavailable', srcW || null, srcH || null)
                : { file, originalWidth: srcW, originalHeight: srcH, downscaled: false, reason: 'canvas-unavailable', failed: false };
        }

        if (! ('width' in canvas) || canvas.width !== finalTarget.width) {
            canvas.width = finalTarget.width;
            canvas.height = finalTarget.height;
        }

        const ctx = typeof canvas.getContext === 'function' ? canvas.getContext('2d') : null;
        if (! ctx) {
            if (source && typeof source.close === 'function') {
                source.close();
            }
            return requireDownscale
                ? failClosed(file, 'no-ctx', srcW || null, srcH || null)
                : { file, originalWidth: srcW, originalHeight: srcH, downscaled: false, reason: 'no-ctx', failed: false };
        }
        if (typeof ctx.fillRect === 'function') {
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, finalTarget.width, finalTarget.height);
        }
        if (typeof ctx.drawImage === 'function') {
            ctx.drawImage(source, 0, 0, finalTarget.width, finalTarget.height);
        }
        if (source && typeof source.close === 'function') {
            source.close();
        }

        if (! aspectRatiosMatch(srcW, srcH, canvas.width, canvas.height)) {
            return requireDownscale
                ? failClosed(file, 'aspect-mismatch', srcW, srcH)
                : { file, originalWidth: srcW, originalHeight: srcH, downscaled: false, reason: 'aspect-mismatch', failed: false };
        }

        const compressed = await toJpeg(canvas, file.name);
        if (! compressed || compressed.size <= 0) {
            return requireDownscale
                ? failClosed(file, 'toblob-empty', srcW || null, srcH || null)
                : { file, originalWidth: srcW, originalHeight: srcH, downscaled: false, reason: 'toblob-empty', failed: false };
        }

        return {
            file: compressed,
            originalWidth: srcW > 1 ? srcW : null,
            originalHeight: srcH > 1 ? srcH : null,
            downscaled: true,
            reason: 'downscaled',
            failed: false,
        };
    })();

    try {
        return await withTimeout(work, DOWNSCALE_TIMEOUT_MS, 'downscale-timeout');
    } catch (error) {
        const reason = error instanceof Error ? error.message : 'downscale-timeout';
        if (requireDownscale) {
            return failClosed(file, reason);
        }

        return {
            file,
            originalWidth: null,
            originalHeight: null,
            downscaled: false,
            reason,
            failed: false,
        };
    }
}

/**
 * @param {File} file
 * @returns {Promise<HTMLImageElement>}
 */
function defaultLoadImage(file) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => {
            URL.revokeObjectURL(url);
            resolve(image);
        };
        image.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('image-load-failed'));
        };
        image.src = url;
    });
}

/**
 * @param {Uint8Array} view
 * @param {number} offset
 * @returns {number}
 */
function readUint16BE(view, offset) {
    return (view[offset] << 8) | view[offset + 1];
}

/**
 * @param {Uint8Array} view
 * @param {number} offset
 * @returns {number}
 */
function readUint16LE(view, offset) {
    return view[offset] | (view[offset + 1] << 8);
}

/**
 * @param {Uint8Array} view
 * @param {number} offset
 * @returns {number}
 */
function readUint32BE(view, offset) {
    return ((view[offset] << 24) | (view[offset + 1] << 16) | (view[offset + 2] << 8) | view[offset + 3]) >>> 0;
}

/**
 * @param {Uint8Array} view
 * @param {number} offset
 * @returns {number}
 */
function readUint24LE(view, offset) {
    return view[offset] | (view[offset + 1] << 8) | (view[offset + 2] << 16);
}

/**
 * @param {Uint8Array} view
 * @returns {{ width: number, height: number, orientation: number }|null}
 */
function readJpegSize(view) {
    let offset = 2;
    let orientation = 1;
    let width = 0;
    let height = 0;

    while (offset + 9 < view.length) {
        if (view[offset] !== 0xFF) {
            offset += 1;
            continue;
        }

        // Skip fill bytes.
        while (offset < view.length && view[offset] === 0xFF) {
            offset += 1;
        }
        if (offset >= view.length) {
            break;
        }

        const marker = view[offset];
        offset += 1;

        // Standalone markers without length.
        if (marker === 0xD8 || marker === 0xD9 || (marker >= 0xD0 && marker <= 0xD7)) {
            continue;
        }

        if (offset + 1 >= view.length) {
            break;
        }

        const segmentLength = readUint16BE(view, offset);
        if (segmentLength < 2 || offset + segmentLength > view.length) {
            break;
        }

        // APP1 — EXIF orientation
        if (marker === 0xE1 && segmentLength >= 8) {
            const exifOrientation = readExifOrientation(view, offset + 2, segmentLength - 2);
            if (exifOrientation !== null) {
                orientation = exifOrientation;
            }
        }

        // SOF0–SOF3 / SOF5–SOF7 / SOF9–SOF11 / SOF13–SOF15 (baseline/progressive/etc.)
        const isSof = (marker >= 0xC0 && marker <= 0xC3)
            || (marker >= 0xC5 && marker <= 0xC7)
            || (marker >= 0xC9 && marker <= 0xCB)
            || (marker >= 0xCD && marker <= 0xCF);
        if (isSof && segmentLength >= 7) {
            height = readUint16BE(view, offset + 3);
            width = readUint16BE(view, offset + 5);
            break;
        }

        offset += segmentLength;
    }

    if (width < 1 || height < 1) {
        return null;
    }

    if (orientationSwapsAxes(orientation)) {
        return { width: height, height: width, orientation };
    }

    return { width, height, orientation };
}

/**
 * @param {Uint8Array} view
 * @param {number} start
 * @param {number} length
 * @returns {number|null}
 */
function readExifOrientation(view, start, length) {
    if (length < 14) {
        return null;
    }
    // "Exif\0\0"
    if (view[start] !== 0x45 || view[start + 1] !== 0x78 || view[start + 2] !== 0x69 || view[start + 3] !== 0x66) {
        return null;
    }

    const tiffStart = start + 6;
    const endian = String.fromCharCode(view[tiffStart], view[tiffStart + 1]);
    const little = endian === 'II';
    if (! little && endian !== 'MM') {
        return null;
    }

    const read16 = (offset) => (little ? readUint16LE(view, offset) : readUint16BE(view, offset));
    const read32 = (offset) => {
        if (little) {
            return (view[offset] | (view[offset + 1] << 8) | (view[offset + 2] << 16) | (view[offset + 3] << 24)) >>> 0;
        }

        return readUint32BE(view, offset);
    };

    const ifdOffset = read32(tiffStart + 4);
    const ifdStart = tiffStart + ifdOffset;
    if (ifdStart + 2 > start + length) {
        return null;
    }

    const entryCount = read16(ifdStart);
    for (let i = 0; i < entryCount; i += 1) {
        const entry = ifdStart + 2 + (i * 12);
        if (entry + 12 > start + length) {
            break;
        }
        const tag = read16(entry);
        if (tag !== 0x0112) {
            continue;
        }
        const type = read16(entry + 2);
        const value = type === 3 ? read16(entry + 8) : read32(entry + 8);
        if (value >= 1 && value <= 8) {
            return value;
        }
    }

    return null;
}

/**
 * @param {Uint8Array} view
 * @returns {{ width: number, height: number, orientation: number }|null}
 */
function readWebpSize(view) {
    let offset = 12;
    while (offset + 8 <= view.length) {
        const fourcc = String.fromCharCode(view[offset], view[offset + 1], view[offset + 2], view[offset + 3]);
        // RIFF chunk sizes are little-endian.
        const size = (view[offset + 4] | (view[offset + 5] << 8) | (view[offset + 6] << 16) | (view[offset + 7] << 24)) >>> 0;
        const dataStart = offset + 8;
        const dataEnd = Math.min(view.length, dataStart + size);

        if (fourcc === 'VP8X' && dataStart + 10 <= dataEnd) {
            const width = 1 + readUint24LE(view, dataStart + 4);
            const height = 1 + readUint24LE(view, dataStart + 7);
            if (width > 0 && height > 0) {
                return { width, height, orientation: 1 };
            }
        }

        if (fourcc === 'VP8 ' && dataStart + 10 <= dataEnd) {
            // Lossy bitstream: sync code 9d 01 2a then 16-bit width/height (14 bits used).
            if (view[dataStart + 3] === 0x9D && view[dataStart + 4] === 0x01 && view[dataStart + 5] === 0x2A) {
                const width = readUint16LE(view, dataStart + 6) & 0x3FFF;
                const height = readUint16LE(view, dataStart + 8) & 0x3FFF;
                if (width > 0 && height > 0) {
                    return { width, height, orientation: 1 };
                }
            }
        }

        if (fourcc === 'VP8L' && dataStart + 5 <= dataEnd) {
            // Lossless: signature 0x2f, then 14-bit width-1 / height-1.
            if (view[dataStart] === 0x2F) {
                const bits = view[dataStart + 1] | (view[dataStart + 2] << 8) | (view[dataStart + 3] << 16) | (view[dataStart + 4] << 24);
                const width = (bits & 0x3FFF) + 1;
                const height = ((bits >> 14) & 0x3FFF) + 1;
                if (width > 0 && height > 0) {
                    return { width, height, orientation: 1 };
                }
            }
        }

        // Chunks are padded to even size.
        offset = dataStart + size + (size % 2);
    }

    return null;
}
