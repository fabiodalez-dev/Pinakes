<?php
declare(strict_types=1);

namespace App\Services;

use mysqli;
use App\Support\NotificationService;
use App\Support\SecureLogger;
use App\Models\CopyRepository;
use Exception;

/**
 * Servizio per la riassegnazione automatica delle prenotazioni.
 * Gestisce i casi in cui una copia diventa disponibile/non disponibile
 * e deve essere riassegnata a un'altra prenotazione in coda.
 */
class ReservationReassignmentService
{
    private mysqli $db;
    private NotificationService $notificationService;
    private CopyRepository $copyRepo;
    private bool $externalTransaction = false;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->notificationService = new NotificationService($db);
        $this->copyRepo = new CopyRepository($db);
    }

    /**
     * Indica che le operazioni sono già dentro una transazione esterna.
     * Quando true, il servizio non aprirà/chiuderà transazioni proprie.
     */
    public function setExternalTransaction(bool $external): self
    {
        $this->externalTransaction = $external;
        return $this;
    }

    /**
     * Verifica se siamo già dentro una transazione.
     * Compatible with both MySQL and MariaDB.
     */
    private function isInTransaction(): bool
    {
        if ($this->externalTransaction) {
            return true;
        }
        // MySQL/MariaDB compatible: check autocommit status
        // When in a transaction started with begin_transaction(), autocommit is 0
        $result = $this->db->query("SELECT @@autocommit as ac");
        if ($result) {
            $row = $result->fetch_assoc();
            // autocommit = 0 typically means we're in a transaction
            return (int)($row['ac'] ?? 1) === 0;
        }
        return false;
    }

    /**
     * Inizia una transazione solo se non siamo già in una.
     */
    private function beginTransactionIfNeeded(): bool
    {
        if ($this->isInTransaction()) {
            return false; // Non abbiamo iniziato noi
        }
        $this->db->begin_transaction();
        return true; // Abbiamo iniziato noi
    }

    /**
     * Commit solo se abbiamo iniziato noi la transazione.
     */
    private function commitIfOwned(bool $ownTransaction): void
    {
        if ($ownTransaction) {
            $this->db->commit();
        }
    }

    /**
     * Rollback solo se abbiamo iniziato noi la transazione.
     */
    private function rollbackIfOwned(bool $ownTransaction): void
    {
        if ($ownTransaction) {
            $this->db->rollback();
        }
    }

    /**
     * Riassegna prenotazioni (prestiti con stato='prenotato') a una nuova copia disponibile.
     * Da chiamare quando viene aggiunta una copia o una copia torna disponibile.
     */
    public function reassignOnNewCopy(int $libroId, int $newCopiaId): void
    {
        // 1. Trova prenotazioni che sono "bloccate" (assegnate a copie non disponibili o senza copia)
        // Ordina per data creazione (FIFO)
        $stmt = $this->db->prepare("
            SELECT p.id, p.copia_id, p.utente_id
            FROM prestiti p
            LEFT JOIN copie c ON p.copia_id = c.id
            WHERE p.libro_id = ?
            AND p.stato = 'prenotato'
            AND (p.copia_id IS NULL OR c.stato != 'disponibile')
            AND p.attivo = 1
            ORDER BY p.created_at ASC
            LIMIT 1
        ");
        $stmt->bind_param('i', $libroId);
        $stmt->execute();
        $result = $stmt->get_result();
        $reservation = $result->fetch_assoc();
        $stmt->close();

        if (!$reservation) {
            return;
        }

        // 2. Se abbiamo trovato una prenotazione da sbloccare, proviamo ad assegnarla alla nuova copia
        $ownTransaction = $this->beginTransactionIfNeeded();
        try {
            // Verifica che la nuova copia sia effettivamente disponibile (lock)
            $stmt = $this->db->prepare("SELECT id, stato FROM copie WHERE id = ? FOR UPDATE");
            $stmt->bind_param('i', $newCopiaId);
            $stmt->execute();
            $copyStatus = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$copyStatus || $copyStatus['stato'] !== 'disponibile') {
                $this->rollbackIfOwned($ownTransaction);
                return;
            }

            // Aggiorna il prestito/prenotazione con la nuova copia
            $stmt = $this->db->prepare("
                UPDATE prestiti
                SET copia_id = ?
                WHERE id = ?
            ");
            $stmt->bind_param('ii', $newCopiaId, $reservation['id']);
            $stmt->execute();
            $stmt->close();

            // Aggiorna stato nuova copia a 'prenotato'
            $this->copyRepo->updateStatus($newCopiaId, 'prenotato');

            // Se la prenotazione aveva una vecchia copia assegnata, dobbiamo verificare
            // se quella copia ora deve cambiare stato?
            // Generalmente no, perché se era "bloccata" significa che la vecchia copia
            // era occupata (es. 'prestato') o danneggiata. Quindi il suo stato non cambia.
            // Se fosse stata 'disponibile', non avremmo selezionato la prenotazione come "bloccata".

            $this->commitIfOwned($ownTransaction);

            // Notifica l'utente (DOPO il commit, fuori dalla transazione)
            $this->notifyUserCopyAvailable((int) $reservation['id']);

        } catch (Exception $e) {
            $this->rollbackIfOwned($ownTransaction);
            SecureLogger::error(__('Errore riassegnazione copia'), [
                'libro_id' => $libroId,
                'copia_id' => $newCopiaId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Gestisce la perdita di una copia (es. segnata come persa/danneggiata).
     * Cerca di riassegnare la prenotazione a un'altra copia se possibile.
     */
    public function reassignOnCopyLost(int $copiaId): void
    {
        // Trova se c'era una prenotazione attiva su questa copia
        $stmt = $this->db->prepare("
            SELECT id, libro_id, utente_id
            FROM prestiti
            WHERE copia_id = ?
            AND stato = 'prenotato'
            AND attivo = 1
            LIMIT 1
        ");
        $stmt->bind_param('i', $copiaId);
        $stmt->execute();
        $reservation = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$reservation) {
            return;
        }

        $libroId = (int) $reservation['libro_id'];
        $reservationId = (int) $reservation['id'];
        $excludedCopies = [$copiaId]; // Copie da escludere dalla ricerca
        $maxRetries = 5; // Limite tentativi per evitare loop infiniti

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            // Cerca un'altra copia disponibile per questo libro
            $nextCopyId = $this->findAvailableCopyExcluding($libroId, $excludedCopies);

            if (!$nextCopyId) {
                // Nessuna copia disponibile
                $this->handleNoCopyAvailable($reservationId);
                return;
            }

            // Riassegna
            $ownTransaction = $this->beginTransactionIfNeeded();
            try {
                // Lock della nuova copia e verifica stato (race condition protection)
                $stmt = $this->db->prepare("SELECT id, stato FROM copie WHERE id = ? FOR UPDATE");
                $stmt->bind_param('i', $nextCopyId);
                $stmt->execute();
                $copyStatus = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                // Verifica che la copia sia ancora disponibile (potrebbe essere cambiata)
                if (!$copyStatus || $copyStatus['stato'] !== 'disponibile') {
                    $this->rollbackIfOwned($ownTransaction);
                    // Aggiungi questa copia alle escluse e riprova
                    $excludedCopies[] = $nextCopyId;
                    continue;
                }

                // Aggiorna prenotazione
                $stmt = $this->db->prepare("UPDATE prestiti SET copia_id = ? WHERE id = ?");
                $stmt->bind_param('ii', $nextCopyId, $reservationId);
                $stmt->execute();
                $stmt->close();

                // Aggiorna stato nuova copia
                $this->copyRepo->updateStatus($nextCopyId, 'prenotato');

                $this->commitIfOwned($ownTransaction);

                // Riassegnazione completata con successo
                return;

            } catch (Exception $e) {
                $this->rollbackIfOwned($ownTransaction);
                SecureLogger::error(__('Errore riassegnazione copia persa'), [
                    'copia_id' => $copiaId,
                    'reservation_id' => $reservationId,
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage()
                ]);
                // Aggiungi questa copia alle escluse e riprova
                $excludedCopies[] = $nextCopyId;
            }
        }

        // Esauriti i tentativi
        SecureLogger::warning(__('Esauriti tentativi riassegnazione copia'), [
            'copia_id' => $copiaId,
            'reservation_id' => $reservationId,
            'attempts' => $maxRetries
        ]);
        $this->handleNoCopyAvailable($reservationId);
    }

    /**
     * Gestisce il caso in cui non ci sono copie disponibili per una prenotazione.
     */
    private function handleNoCopyAvailable(int $reservationId): void
    {
        // Meglio impostare copia_id a NULL per indicare "in coda senza copia" o "in attesa"
        // E notificare l'utente che è tornato in lista d'attesa
        $ownTransaction = $this->beginTransactionIfNeeded();
        try {
            $stmt = $this->db->prepare("UPDATE prestiti SET copia_id = NULL WHERE id = ?");
            $stmt->bind_param('i', $reservationId);
            $stmt->execute();
            $stmt->close();

            $this->commitIfOwned($ownTransaction);

            // Notifica DOPO il commit (fuori dalla transazione)
            $this->notifyUserCopyUnavailable($reservationId, 'lost_copy');

        } catch (Exception $e) {
            $this->rollbackIfOwned($ownTransaction);
            SecureLogger::error(__('Errore gestione copia non disponibile'), [
                'reservation_id' => $reservationId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Quando un libro viene restituito, controlla se ci sono prenotazioni in attesa
     * e assegna la copia restituita alla prossima prenotazione.
     */
    public function reassignOnReturn(int $copiaId): void
    {
        // 1. Trova il libro
        $stmt = $this->db->prepare("SELECT libro_id FROM copie WHERE id = ?");
        $stmt->bind_param('i', $copiaId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$res)
            return;
        $libroId = (int) $res['libro_id'];

        // 2. Cerca la prenotazione più vecchia SENZA copia assegnata (o assegnata a copia non disp)
        // Nota: reassignOnNewCopy fa esattamente questo logicamente: prende una copia disponibile (questa)
        // e cerca chi ne ha bisogno.
        $this->reassignOnNewCopy($libroId, $copiaId);
    }

    private function findAvailableCopy(int $libroId, ?int $excludeCopiaId = null): ?int
    {
        $sql = "
            SELECT id
            FROM copie
            WHERE libro_id = ?
            AND stato = 'disponibile'
        ";
        $params = [$libroId];
        $types = "i";

        if ($excludeCopiaId) {
            $sql .= " AND id != ?";
            $params[] = $excludeCopiaId;
            $types .= "i";
        }

        $sql .= " LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $res ? (int) $res['id'] : null;
    }

    /**
     * Trova una copia disponibile escludendo una lista di copie.
     * @param int $libroId ID del libro
     * @param array<int> $excludeCopiaIds Array di ID copie da escludere
     */
    private function findAvailableCopyExcluding(int $libroId, array $excludeCopiaIds): ?int
    {
        $sql = "
            SELECT id
            FROM copie
            WHERE libro_id = ?
            AND stato = 'disponibile'
        ";
        $params = [$libroId];
        $types = "i";

        if (!empty($excludeCopiaIds)) {
            $placeholders = implode(',', array_fill(0, count($excludeCopiaIds), '?'));
            $sql .= " AND id NOT IN ($placeholders)";
            foreach ($excludeCopiaIds as $id) {
                $params[] = $id;
                $types .= "i";
            }
        }

        $sql .= " LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $res ? (int) $res['id'] : null;
    }

    /**
     * Notifica l'utente che la copia prenotata è disponibile per il ritiro.
     */
    private function notifyUserCopyAvailable(int $prestitoId): void
    {
        // Recupera dati necessari per la notifica
        $stmt = $this->db->prepare("
            SELECT p.id, p.utente_id, p.libro_id, p.data_prestito, p.data_scadenza,
                   u.email, u.nome as utente_nome,
                   l.titolo as libro_titolo, l.isbn13, l.isbn10
            FROM prestiti p
            JOIN utenti u ON p.utente_id = u.id
            JOIN libri l ON p.libro_id = l.id
            WHERE p.id = ?
        ");
        $stmt->bind_param('i', $prestitoId);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$data || empty($data['email'])) {
            SecureLogger::warning(__('Impossibile notificare utente: dati mancanti'), [
                'prestito_id' => $prestitoId
            ]);
            return;
        }

        // Recupera autore principale
        $authorStmt = $this->db->prepare("
            SELECT a.nome
            FROM autori a
            JOIN libri_autori la ON a.id = la.autore_id
            WHERE la.libro_id = ?
            ORDER BY la.ruolo = 'principale' DESC
            LIMIT 1
        ");
        $authorStmt->bind_param('i', $data['libro_id']);
        $authorStmt->execute();
        $author = $authorStmt->get_result()->fetch_assoc();
        $authorStmt->close();

        $baseUrl = $this->getBaseUrl();
        $isbn = $data['isbn13'] ?: $data['isbn10'] ?: '';

        $variables = [
            'utente_nome' => $data['utente_nome'] ?: __('Utente'),
            'libro_titolo' => $data['libro_titolo'] ?: __('Libro'),
            'libro_autore' => $author['nome'] ?? __('Autore sconosciuto'),
            'libro_isbn' => $isbn,
            'data_inizio' => $data['data_prestito'] ? date('d/m/Y', strtotime($data['data_prestito'])) : '',
            'data_fine' => $data['data_scadenza'] ? date('d/m/Y', strtotime($data['data_scadenza'])) : '',
            'book_url' => $baseUrl . '/catalogo/libro/' . $data['libro_id'],
            'profile_url' => $baseUrl . '/profilo/prestiti'
        ];

        $sent = $this->notificationService->sendReservationBookAvailable($data['email'], $variables);

        if ($sent) {
            SecureLogger::info(__('Notifica prenotazione disponibile inviata'), [
                'prestito_id' => $prestitoId,
                'utente_id' => $data['utente_id']
            ]);
        } else {
            SecureLogger::warning(__('Invio notifica prenotazione fallito'), [
                'prestito_id' => $prestitoId,
                'utente_id' => $data['utente_id']
            ]);
        }
    }

    /**
     * Notifica l'utente che la copia prenotata non è più disponibile.
     */
    private function notifyUserCopyUnavailable(int $prestitoId, string $reason): void
    {
        // Recupera dati necessari
        $stmt = $this->db->prepare("
            SELECT p.id, p.utente_id, u.email, u.nome as utente_nome,
                   l.titolo as libro_titolo
            FROM prestiti p
            JOIN utenti u ON p.utente_id = u.id
            JOIN libri l ON p.libro_id = l.id
            WHERE p.id = ?
        ");
        $stmt->bind_param('i', $prestitoId);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$data || empty($data['email'])) {
            SecureLogger::warning(__('Impossibile notificare utente copia non disponibile'), [
                'prestito_id' => $prestitoId,
                'reason' => $reason
            ]);
            return;
        }

        // Crea notifica in-app per gli admin
        $reasonText = match ($reason) {
            'lost_copy' => __('La copia assegnata è stata segnalata come persa o danneggiata'),
            'expired' => __('La prenotazione è scaduta'),
            default => __('La copia non è più disponibile')
        };

        $this->notificationService->createNotification(
            'general',
            __('Prenotazione: copia non disponibile'),
            \sprintf(
                __('Prenotazione per "%s" (utente: %s) messa in attesa. %s.'),
                $data['libro_titolo'],
                $data['utente_nome'],
                $reasonText
            ),
            null,
            $prestitoId
        );

        SecureLogger::info(__('Notifica copia non disponibile creata'), [
            'prestito_id' => $prestitoId,
            'utente_id' => $data['utente_id'],
            'reason' => $reason
        ]);
    }

    /**
     * Ottiene la URL base dell'applicazione.
     */
    private function getBaseUrl(): string
    {
        $canonicalUrl = $_ENV['APP_CANONICAL_URL'] ?? getenv('APP_CANONICAL_URL') ?: false;
        if ($canonicalUrl !== false) {
            $canonicalUrl = trim((string)$canonicalUrl);
            if ($canonicalUrl !== '' && filter_var($canonicalUrl, FILTER_VALIDATE_URL)) {
                return rtrim($canonicalUrl, '/');
            }
        }

        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        if (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*(:[0-9]{1,5})?$/', $host)) {
            return $protocol . '://' . $host;
        }

        return $protocol . '://localhost';
    }
}
