// @ts-check
'use strict';

/**
 * Clear the file-based RateLimiter state between E2E steps.
 *
 * Since the security audit the counters live inside the install
 * (<root>/storage/cache/ratelimit), not in the host-shared temp dir. The served
 * app root comes from E2E_APP_ROOT / E2E_INSTALL_ROOT when a suite targets a
 * worktree slot, otherwise the checkout the spec runs from. The legacy temp-dir
 * locations are cleared too, so a run against an older server still resets.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');

function appRoot() {
  return process.env.E2E_APP_ROOT || process.env.E2E_INSTALL_ROOT || path.resolve(__dirname, '..', '..');
}

function emptyDir(dir) {
  try {
    if (!fs.existsSync(dir) || fs.lstatSync(dir).isSymbolicLink()) return;
    for (const f of fs.readdirSync(dir)) {
      if (!f.endsWith('.json')) continue;
      try { fs.unlinkSync(path.join(dir, f)); } catch { /* ignore races */ }
    }
  } catch { /* ignore missing/inaccessible directory */ }
}

function clearRateLimits(root = appRoot()) {
  emptyDir(path.join(root, 'storage', 'cache', 'ratelimit'));
  for (const tmp of new Set([os.tmpdir(), '/var/tmp', '/tmp'])) {
    emptyDir(path.join(tmp, 'pinakes_ratelimit'));
  }
}

module.exports = { clearRateLimits };
