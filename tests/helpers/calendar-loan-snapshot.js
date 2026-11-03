// @ts-check
// A calendar save moves the active loans of every reader whose due date it
// closes (LibraryCalendar::extendDueDates()), not only the ones a suite made.
// snapshotLoans() keeps the active loans as they were before the suite;
// restoreLoans() puts back the ones a calendar change moved during the run
// and removes those history entries, but only the entries this suite's own
// saves wrote: source "calendar" and the operator the suite logs in as. A
// move by the maintenance pass (recorded as the system) or by another
// operator on a shared database is left as it is. Loans the suite created
// are deleted by the suite itself.

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
 * @param {string} operatorEmail the account the suite's calendar saves run as
 */
function restoreLoans(db, snap, operatorEmail) {
  if (!snap) return;
  const operator = parseInt(db(`SELECT COALESCE((SELECT id FROM utenti WHERE email = '${operatorEmail.replace(/'/g, "''")}' LIMIT 1), 0)`) || '0', 10);
  const calendarEvents = `FROM log_modifiche WHERE id > ${snap.maxLog} AND tabella = 'libri' AND utente_id = ${operator}
      AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$._activity.source')) = 'calendar'
      AND CAST(JSON_EXTRACT(dati_nuovi, '$._activity.entity_id') AS UNSIGNED) IN (SELECT id FROM ${snap.table})`;
  const ids = db(`SELECT DISTINCT CAST(JSON_EXTRACT(dati_nuovi, '$._activity.entity_id') AS UNSIGNED) ${calendarEvents}`)
    .split('\n').filter(Boolean).join(',') || '0';
  db(`UPDATE prestiti p JOIN ${snap.table} b ON b.id = p.id SET p.data_scadenza = b.data_scadenza, p.stato = b.stato, p.warning_sent = b.warning_sent WHERE p.id IN (${ids})`);
  db(`DELETE ${calendarEvents}`);
  db(`DROP TABLE IF EXISTS ${snap.table}`);
}

module.exports = { snapshotLoans, restoreLoans };
