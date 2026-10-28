<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Punto unico di verifica dell'idoneità utente ai prestiti (finding M7).
 *
 * Ogni gate di creazione/approvazione di prestiti e prenotazioni (web,
 * admin, API mobile) deve passare da checkUser() PRIMA di scrivere:
 * lo stato utente e la tessera sono verificati solo al login, quindi un
 * utente sospeso dall'admin o con tessera scaduta a sessione aperta
 * continuerebbe altrimenti a richiedere prestiti.
 *
 * Regole (enum utenti.stato: attivo|sospeso|scaduto):
 * - utente inesistente            -> 'user_not_found'
 * - stato diverso da 'attivo'     -> 'user_suspended' (sospeso, che è anche
 *                                    il marcatore di "utente cancellato")
 *                                    oppure 'card_expired' per stato 'scaduto'
 * - tessera scaduta (data_scadenza_tessera < oggi) -> 'card_expired',
 *   solo per tipo_utente standard/premium: admin e staff non hanno tessera
 *   (un trigger DB la azzera).
 *
 * - saldo a debito oltre la soglia (Impostazioni → Prestiti → Multe,
 *   loans.debt_block_threshold; 0/vuoto = nessun blocco) -> 'debt_block'.
 *
 * Il confronto date usa DateHelper::today() così PHP e MySQL concordano
 * sul confine di giornata (vedi docblock di DateHelper::today()).
 */
class LoanEligibility
{
    /**
     * Verifica l'idoneità di un utente a richiedere/ricevere un prestito.
     *
     * @return string|null Codice errore ('user_not_found', 'user_suspended',
     *                     'card_expired', 'debt_block') oppure null se l'utente è idoneo.
     * @phpstan-impure Reads mutable database state; repeated calls can differ.
     */
    public static function checkUser(\mysqli $db, int $userId): ?string
    {
        $stmt = $db->prepare(
            'SELECT stato, tipo_utente, data_scadenza_tessera FROM utenti WHERE id = ?'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            return 'user_not_found';
        }

        if ($user['stato'] !== 'attivo') {
            // 'scaduto' è l'equivalente lato stato della tessera scaduta:
            // stesso codice così il chiamante mostra un messaggio coerente.
            return $user['stato'] === 'scaduto' ? 'card_expired' : 'user_suspended';
        }

        if (
            in_array($user['tipo_utente'], ['standard', 'premium'], true)
            && $user['data_scadenza_tessera'] !== null
            && $user['data_scadenza_tessera'] < DateHelper::today()
        ) {
            return 'card_expired';
        }

        // Debt block (plan §7). Stack hook: FineParameters::forUser() becomes
        // CirculationPolicy::forUser()->debtBlockThreshold (per patron type).
        if (\App\Services\PatronLedger::isDebtBlocked($db, $userId)) {
            return 'debt_block';
        }

        return null;
    }

    /**
     * Messaggio utente localizzato per un codice restituito da checkUser().
     */
    public static function errorMessage(string $code): string
    {
        switch ($code) {
            case 'user_not_found':
                return __('Utente non trovato');
            case 'user_suspended':
                return __('Utente sospeso: prestiti non consentiti');
            case 'card_expired':
                return __('Tessera scaduta: rinnovala per richiedere prestiti');
            // Circulation rules (CirculationPolicy: patron type × material).
            case 'max_loans_reached':
                return __('Hai raggiunto il numero massimo di prestiti attivi consentiti');
            case 'media_limit_reached':
                return __('Hai raggiunto il numero massimo di prestiti per questo tipo di materiale');
            case 'not_loanable':
                return __('Questo materiale è solo in consultazione: non può essere preso in prestito');
            case 'reservations_disabled':
                return __('Le prenotazioni non sono consentite per la tua tipologia di lettore');
            case 'reservation_limit_reached':
                return __('Hai raggiunto il numero massimo di prenotazioni e richieste in attesa');
            case 'debt_block':
                return __('Saldo a debito oltre la soglia consentita: prestiti bloccati fino al pagamento');
            default:
                return __('Utente non idoneo al prestito');
        }
    }

    /**
     * Shared WHERE fragment selecting reservations eligible for promotion, used
     * by both the single-book path (ReservationManager::processBookAvailability)
     * and the batch path (MaintenanceService::processScheduledReservations) so a
     * future edge-case fix cannot drift between them.
     *
     * Matches a real requested start that has arrived — with a '1000-01-01'
     * floor (MySQL's minimum valid DATE) that rejects 0000-00-00 dump rows
     * without embedding the literal a NO_ZERO_DATE server refuses at prepare
     * time — OR a legacy no-start row while its deadline is still today or in
     * the future.
     *
     * Requires TWO positional placeholders bound in order (today, today).
     * $alias is the `prenotazioni` table alias: a fixed literal, never user input.
     */
    public static function promotableReservationWhere(string $alias): string
    {
        return "(
                    ({$alias}.data_inizio_richiesta >= '1000-01-01' AND {$alias}.data_inizio_richiesta <= ?)
                    OR ({$alias}.data_inizio_richiesta IS NULL
                        AND {$alias}.data_scadenza_prenotazione IS NOT NULL
                        AND DATE({$alias}.data_scadenza_prenotazione) >= ?)
                )";
    }

    /**
     * Shared SQL predicate for users that are eligible to receive a loan.
     *
     * This mirrors checkUser() and is intended for queue selection, where
     * filtering before LIMIT avoids head-of-line starvation from suspended or
     * expired patrons. A final checkUser() under the caller's transaction still
     * provides the authoritative race-safe decision.
     *
     * Requires ONE positional placeholder bound to the application date.
     * $alias is a fixed table alias supplied by application code.
     *
     * The debt block reads its threshold from system_settings inside the
     * fragment, so no caller has to bind an extra value: with the setting
     * absent or 0 the NOT EXISTS is trivially true and the predicate is the
     * pre-0.9.0 one. The balance subquery is served by the covering index
     * movimenti_lettore(utente_id, importo).
     * Stack hook: with patron types the threshold comes from the patron's type
     * (falling back to this global setting) — replace debtBlockSql() only.
     */
    public static function eligibleUserWhere(string $alias): string
    {
        return "(
                    {$alias}.stato = 'attivo'
                    AND (
                        {$alias}.tipo_utente NOT IN ('standard', 'premium')
                        OR {$alias}.data_scadenza_tessera IS NULL
                        OR {$alias}.data_scadenza_tessera >= ?
                    )
                    AND " . self::debtBlockSql($alias) . "
                )";
    }

    /**
     * SQL twin of PatronLedger::isDebtBlocked(): true when the user is NOT
     * debt-blocked. $alias is the `utenti` alias, a fixed literal.
     */
    public static function debtBlockSql(string $alias): string
    {
        return "NOT EXISTS (
                        SELECT 1 FROM system_settings dbt
                         WHERE dbt.category = 'loans'
                           AND dbt.setting_key = 'debt_block_threshold'
                           AND dbt.setting_value REGEXP '^[0-9]{1,8}([.,][0-9]{1,2}){0,1}$'
                           AND CAST(REPLACE(dbt.setting_value, ',', '.') AS DECIMAL(10,2)) > 0
                           AND " . \App\Services\PatronLedger::balanceSql($alias . '.id') . " > CAST(REPLACE(dbt.setting_value, ',', '.') AS DECIMAL(10,2))
                    )";
    }
}
