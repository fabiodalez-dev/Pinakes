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
 * Il confronto date usa DateHelper::today() così PHP e MySQL concordano
 * sul confine di giornata (vedi docblock di DateHelper::today()).
 */
class LoanEligibility
{
    /**
     * Verifica l'idoneità di un utente a richiedere/ricevere un prestito.
     *
     * @return string|null Codice errore ('user_not_found', 'user_suspended',
     *                     'card_expired') oppure null se l'utente è idoneo.
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
            default:
                return __('Utente non idoneo al prestito');
        }
    }
}
