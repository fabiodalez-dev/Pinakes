// Files the web server writes (storage/patron_photos, writable/uploads) keep
// production permissions: 0750 directories and 0640 files owned by the PHP
// user. Locally that user is the one running Playwright; in CI it is
// www-data, so the runner reaches them through passwordless sudo instead.
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

function denied(err) {
  return err && (err.code === 'EACCES' || err.code === 'EPERM');
}

function sudo(args, input) {
  return execFileSync('sudo', ['-n', ...args], { input, stdio: [input === undefined ? 'ignore' : 'pipe', 'pipe', 'pipe'] });
}

/** Whether a server-written file exists, also when the runner cannot traverse its directory. */
function serverFileExists(file) {
  try {
    fs.statSync(file);
    return true;
  } catch (err) {
    if (err.code === 'ENOENT' && fs.existsSync(path.dirname(file))) return false;
    if (!denied(err) && err.code !== 'ENOENT') throw err;
    try { sudo(['test', '-e', file]); return true; } catch (_) { return false; }
  }
}

/** The bytes of a server-written file. */
function readServerFile(file) {
  try {
    return fs.readFileSync(file);
  } catch (err) {
    if (!denied(err)) throw err;
    return sudo(['cat', file]);
  }
}

/** Names in a server-written directory ([] when it does not exist). */
function listServerDir(dir) {
  try {
    return fs.readdirSync(dir);
  } catch (err) {
    if (err.code === 'ENOENT') return [];
    if (!denied(err)) throw err;
    return sudo(['ls', '-1A', dir]).toString('utf-8').split('\n').filter(Boolean);
  }
}

/** Write a file into a server-owned directory and set its mtime. */
function writeServerFile(file, content, mtime) {
  try {
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, content);
    if (mtime) fs.utimesSync(file, mtime, mtime);
  } catch (err) {
    if (!denied(err)) throw err;
    sudo(['tee', file], content);
    if (mtime) sudo(['touch', '-d', mtime.toISOString(), file]);
  }
}

/** Remove a server-written file (no error when it is already gone). */
function removeServerFile(file) {
  try {
    fs.unlinkSync(file);
  } catch (err) {
    if (err.code === 'ENOENT') return;
    if (!denied(err)) throw err;
    try { sudo(['rm', '-f', file]); } catch (_) { /* best effort */ }
  }
}

module.exports = { serverFileExists, readServerFile, listServerDir, writeServerFile, removeServerFile };
