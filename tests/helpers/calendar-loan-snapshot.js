// @ts-check
// A calendar save moves the active loans of every reader whose due date it
// closes (LibraryCalendar::extendDueDates()), not only the ones a suite made.
// snapshotLoans() keeps the active loans as they were before the suite;
// restoreLoans() puts back the ones a calendar change moved during the run
// (history entries with source "calendar") and removes those entries. Loans
// the suite created are deleted by the suite itself.

/**
 * @param {(sql: string) => string} db
 * @param {string} table name of the backup table (unique per run)
 */
function snapshotLoans(db, table) {
  const maxLog = parseInt(db('SELECT COALESCE(MAX(id), 0) FROM log_modifiche') || '0', 10);
  db(`CREATE TABLE ${table} AS SELECT id, data_scadenza, stato, warning_sent FROM prestiti WHERE attivo = 1`);
  return { table, maxLog };
}

/**
 * @param {(sql: string) => string} db
 * @param {{table: string, maxLog: number} | null} snap
 */
function restoreLoans(db, snap) {
  if (!snap) return;
  const calendarEvents = `FROM log_modifiche WHERE id > ${snap.maxLog} AND tabella = 'libri'
      AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$._activity.source')) = 'calendar'
      AND CAST(JSON_EXTRACT(dati_nuovi, '$._activity.entity_id') AS UNSIGNED) IN (SELECT id FROM ${snap.table})`;
  const ids = db(`SELECT DISTINCT CAST(JSON_EXTRACT(dati_nuovi, '$._activity.entity_id') AS UNSIGNED) ${calendarEvents}`)
    .split('\n').filter(Boolean).join(',') || '0';
  db(`UPDATE prestiti p JOIN ${snap.table} b ON b.id = p.id SET p.data_scadenza = b.data_scadenza, p.stato = b.stato, p.warning_sent = b.warning_sent WHERE p.id IN (${ids})`);
  db(`DELETE ${calendarEvents}`);
  db(`DROP TABLE IF EXISTS ${snap.table}`);
}

module.exports = { snapshotLoans, restoreLoans };
