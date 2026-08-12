'use strict';

const { execFileSync } = require('child_process');
const path = require('path');

const INSTALL_ROOT = process.env.E2E_INSTALL_ROOT || path.resolve(__dirname, '../..');

/**
 * Return the calendar day used by the application, not the CI host's UTC day.
 * DateHelper honours the configured application timezone.
 */
function appTodayISO() {
  const autoload = path.join(INSTALL_ROOT, 'vendor', 'autoload.php');
  const today = execFileSync(
    'php',
    ['-r', `require ${JSON.stringify(autoload)}; echo \\App\\Support\\DateHelper::today();`],
    { cwd: INSTALL_ROOT, encoding: 'utf-8', timeout: 5_000 },
  ).trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(today)) {
    throw new Error(`DateHelper returned an invalid application date: ${today}`);
  }
  return today;
}

/** Return an application-calendar date offset without local-time/DST drift. */
function appDateOffsetISO(days) {
  const date = new Date(`${appTodayISO()}T12:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

module.exports = { appTodayISO, appDateOffsetISO };
