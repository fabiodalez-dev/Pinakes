/**
 * copy-scanner.js — In-browser camera barcode scanner for per-copy inventory codes.
 *
 * Phase 2 of physical-copy tracking. Decodes Code128 (and common 1D/QR fallbacks)
 * from a live camera preview and fills the adjacent copy-code text input.
 *
 * Wiring: any `<button data-copy-scan data-copy-scan-target="INPUT_ID">` on the page
 * opens the scanner modal. On a successful decode the value is written to the target
 * input (fill-only — never auto-submits) and `input`/`change` events are dispatched.
 *
 * CSP / self-hosting: the zxing-wasm `.wasm` binary is emitted by webpack as an
 * `asset/resource` (same-origin under /assets) and the library is pointed at it via
 * `locateFile`, so the default jsDelivr CDN fetch (blocked by `connect-src 'self'`)
 * is never used. Running the wasm additionally requires `'wasm-unsafe-eval'` in the
 * CSP `script-src` (added to public/.htaccess and public/index.php).
 */

import { prepareZXingModule, readBarcodes } from 'zxing-wasm/reader';
// Webpack emits this as asset/resource → same-origin URL under /assets (see webpack.config.js).
import wasmUrl from 'zxing-wasm/reader/zxing_reader.wasm';

// Point zxing-wasm at the self-hosted wasm instead of its default CDN URL.
prepareZXingModule({
  overrides: {
    locateFile: (path, prefix) => (path.endsWith('.wasm') ? wasmUrl : prefix + path),
  },
});

// Barcode formats to try. Code128 is what the per-copy labels use; the rest are
// cheap fallbacks so the same scanner also reads ISBN/EAN and QR codes.
const READ_FORMATS = ['Code128', 'EAN-13', 'EAN-8', 'Code39', 'QRCode'];

// i18n with English fallbacks. Views set window.copyScannerI18n via __() so the
// strings stay translatable in all 4 locales.
function t(key) {
  const dict = (typeof window !== 'undefined' && window.copyScannerI18n) || {};
  const fallbacks = {
    title: 'Scan barcode',
    instruction: 'Point the camera at the barcode',
    starting: 'Starting camera…',
    cancel: 'Cancel',
    permissionDenied: 'Cannot access the camera. Check your browser permissions.',
    noCamera: 'No camera found on this device.',
    unsupported: 'This browser does not support camera access.',
    genericError: 'Unable to start the scanner.',
  };
  return (dict && dict[key]) || fallbacks[key] || key;
}

/**
 * Open the scanner and resolve with the decoded string, or null if cancelled.
 * Guarantees the MediaStream is stopped on success, cancel, or error.
 * @returns {Promise<string|null>}
 */
function scanBarcode() {
  return new Promise((resolve) => {
    let stream = null;
    let rafId = null;
    let scanning = true;
    let lastScan = 0;
    let settled = false;

    // --- Build modal ---------------------------------------------------------
    const overlay = document.createElement('div');
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', t('title'));
    overlay.style.cssText =
      'position:fixed;inset:0;z-index:99999;display:flex;flex-direction:column;' +
      'align-items:center;justify-content:center;background:rgba(0,0,0,0.85);padding:1rem;';

    const box = document.createElement('div');
    box.style.cssText =
      'position:relative;width:100%;max-width:520px;background:#111827;border-radius:0.75rem;' +
      'overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,0.5);';

    const header = document.createElement('div');
    header.style.cssText =
      'display:flex;align-items:center;justify-content:space-between;padding:0.75rem 1rem;color:#fff;';
    const title = document.createElement('span');
    title.textContent = t('title');
    title.style.cssText = 'font-weight:600;font-size:0.95rem;';
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', t('cancel'));
    closeBtn.textContent = '✕';
    closeBtn.style.cssText =
      'background:transparent;border:0;color:#fff;font-size:1.25rem;line-height:1;cursor:pointer;padding:0.25rem 0.5rem;';
    header.appendChild(title);
    header.appendChild(closeBtn);

    const videoWrap = document.createElement('div');
    videoWrap.style.cssText = 'position:relative;width:100%;background:#000;aspect-ratio:4/3;';
    const video = document.createElement('video');
    video.setAttribute('playsinline', '');
    video.setAttribute('muted', '');
    video.muted = true;
    video.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;';
    // Scan frame guide
    const frame = document.createElement('div');
    frame.style.cssText =
      'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);' +
      'width:75%;height:38%;border:3px solid rgba(255,255,255,0.9);border-radius:0.5rem;' +
      'box-shadow:0 0 0 9999px rgba(0,0,0,0.25);pointer-events:none;';
    videoWrap.appendChild(video);
    videoWrap.appendChild(frame);

    const status = document.createElement('div');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.style.cssText =
      'padding:0.75rem 1rem;color:#e5e7eb;font-size:0.85rem;text-align:center;min-height:1.5rem;';
    status.textContent = t('starting');

    const footer = document.createElement('div');
    footer.style.cssText = 'padding:0 1rem 1rem;display:flex;justify-content:center;';
    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.textContent = t('cancel');
    cancelBtn.style.cssText =
      'padding:0.5rem 1.25rem;background:#374151;color:#fff;border:0;border-radius:0.5rem;' +
      'font-weight:500;cursor:pointer;';
    footer.appendChild(cancelBtn);

    box.appendChild(header);
    box.appendChild(videoWrap);
    box.appendChild(status);
    box.appendChild(footer);
    overlay.appendChild(box);

    // --- Teardown ------------------------------------------------------------
    function cleanup() {
      scanning = false;
      if (rafId) {
        cancelAnimationFrame(rafId);
        rafId = null;
      }
      if (stream) {
        stream.getTracks().forEach((track) => track.stop());
        stream = null;
      }
      video.srcObject = null;
      document.removeEventListener('keydown', onKeydown);
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
    }

    function finish(value) {
      if (settled) return;
      settled = true;
      cleanup();
      resolve(value);
    }

    function onKeydown(e) {
      if (e.key === 'Escape') finish(null);
    }

    closeBtn.addEventListener('click', () => finish(null));
    cancelBtn.addEventListener('click', () => finish(null));
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) finish(null);
    });
    document.addEventListener('keydown', onKeydown);

    document.body.appendChild(overlay);

    // --- Camera + decode loop ------------------------------------------------
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      status.textContent = t('unsupported');
      return;
    }

    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d', { willReadFrequently: true });

    async function tick(now) {
      if (!scanning) return;
      // Throttle decode attempts (~4/s) to keep the main thread responsive.
      if (video.readyState >= 2 && video.videoWidth > 0 && now - lastScan > 250) {
        lastScan = now;
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        try {
          const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
          const results = await readBarcodes(imageData, {
            formats: READ_FORMATS,
            tryHarder: true,
          });
          if (!scanning) return;
          const hit = results.find((r) => r.isValid && r.text);
          if (hit) {
            finish(hit.text.trim());
            return;
          }
        } catch (err) {
          // Decode failures on a given frame are expected; keep scanning.
        }
      }
      rafId = requestAnimationFrame(tick);
    }

    navigator.mediaDevices
      .getUserMedia({ video: { facingMode: 'environment' }, audio: false })
      .then((mediaStream) => {
        if (settled) {
          // Cancelled before the camera came up — release it immediately.
          mediaStream.getTracks().forEach((track) => track.stop());
          return;
        }
        stream = mediaStream;
        video.srcObject = mediaStream;
        return video.play().then(() => {
          status.textContent = t('instruction');
          rafId = requestAnimationFrame(tick);
        });
      })
      .catch((err) => {
        if (settled) return;
        // getUserMedia may have already resolved (stream assigned) before video.play()
        // rejected (e.g. AbortError, hardware yanked mid-stream). Release the camera
        // so the LED goes off instead of staying on until the user hits Cancel.
        if (stream) {
          stream.getTracks().forEach((track) => track.stop());
          stream = null;
          video.srcObject = null;
        }
        let msg = t('genericError');
        if (err && (err.name === 'NotAllowedError' || err.name === 'SecurityError')) {
          msg = t('permissionDenied');
        } else if (err && (err.name === 'NotFoundError' || err.name === 'OverconstrainedError' || err.name === 'DevicesNotFoundError')) {
          msg = t('noCamera');
        }
        status.textContent = msg;
        // Leave the modal open so the user can read the message and close it manually.
      });
  });
}

function fillTarget(input, value) {
  input.value = value;
  input.dispatchEvent(new Event('input', { bubbles: true }));
  input.dispatchEvent(new Event('change', { bubbles: true }));
  try {
    input.focus();
  } catch (_e) {
    /* ignore */
  }
}

function init() {
  const buttons = document.querySelectorAll('[data-copy-scan]');
  buttons.forEach((btn) => {
    if (btn.dataset.copyScanBound === '1') return;
    btn.dataset.copyScanBound = '1';
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      const targetId = btn.getAttribute('data-copy-scan-target');
      const input = targetId ? document.getElementById(targetId) : null;
      if (!input) return;
      const code = await scanBarcode();
      if (code) fillTarget(input, code);
    });
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}

export { scanBarcode };
