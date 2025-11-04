<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\DataIntegrity;
use App\Support\NotificationService;
use Exception;

class PrestitiController
{
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        $repo = new \App\Models\LoanRepository($db);
        $prestiti = $repo->listRecent(100);

        // Get pending loans for the dashboard widget
        $stmt = $db->prepare("
            SELECT p.id, p.libro_id, p.utente_id, p.data_prestito, p.data_scadenza, p.created_at,
                   l.titolo as libro_titolo, l.copertina_url,
                   CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email
            FROM prestiti p
            JOIN libri l ON p.libro_id = l.id
            JOIN utenti u ON p.utente_id = u.id
            WHERE p.stato = 'pendente'
            ORDER BY p.created_at DESC
            LIMIT 10
        ");
        $stmt->execute();
        $pending_loans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        ob_start();
        $prestiti = $prestiti; // Make variable available to included file
        $pending_loans = $pending_loans; // Make pending loans variable available to included file
        require __DIR__ . '/../Views/prestiti/index.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function createForm(Request $request, Response $response): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        ob_start();
        require __DIR__ . '/../Views/prestiti/crea_prestito.php';
        $content = ob_get_clean();
        ob_start(); require __DIR__ . '/../Views/layout.php'; $html = ob_get_clean();
        $response->getBody()->write($html); return $response;
    }

    public function store(Request $request, Response $response, mysqli $db): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        $data = (array)$request->getParsedBody();

        // Verifica CSRF token
        $token = $data['csrf_token'] ?? '';
        if (!\App\Support\Csrf::validate($token)) {
            return $response->withHeader('Location', '/admin/prestiti/crea?error=csrf')->withStatus(302);
        }

        // Verifica dati obbligatori
        $utente_id = (int)($data['utente_id'] ?? 0);
        $libro_id = (int)($data['libro_id'] ?? 0);
        $data_prestito = $data['data_prestito'] ?? '';
        $data_scadenza = $data['data_scadenza'] ?? '';
        $note = trim((string)($data['note'] ?? '')) ?: null;

        // Se le date sono vuote, usa i valori di default
        if (empty($data_prestito)) {
            $data_prestito = gmdate('Y-m-d');
        }
        if (empty($data_scadenza)) {
            $data_scadenza = gmdate('Y-m-d', strtotime('+14 days'));
        }

        if ($utente_id <= 0 || $libro_id <= 0) {
            return $response->withHeader('Location', '/admin/prestiti/crea?error=missing_fields')->withStatus(302);
        }

        // Verifica che la data di scadenza sia successiva alla data di prestito
        if (strtotime($data_scadenza) <= strtotime($data_prestito)) {
            return $response->withHeader('Location', '/admin/prestiti/crea?error=invalid_dates')->withStatus(302);
        }

        $db->begin_transaction();

        try {
            // Lock the book record to prevent concurrent updates
            $lockStmt = $db->prepare("SELECT id, stato, copie_disponibili FROM libri WHERE id = ? FOR UPDATE");
            $lockStmt->bind_param('i', $libro_id);
            $lockStmt->execute();
            $bookResult = $lockStmt->get_result();
            $book = $bookResult ? $bookResult->fetch_assoc() : null;
            $lockStmt->close();

            if (!$book) {
                $db->rollback();
                return $response->withHeader('Location', '/admin/prestiti/crea?error=book_not_found')->withStatus(302);
            }

            // Check if book is available for loan
            if (strcasecmp((string)$book['stato'], 'disponibile') !== 0 || (int)$book['copie_disponibili'] <= 0) {
                $db->rollback();
                return $response->withHeader('Location', '/admin/prestiti/crea?error=book_not_available')->withStatus(302);
            }

            // Select an available copy
            $copyRepo = new \App\Models\CopyRepository($db);
            $availableCopies = $copyRepo->getAvailableByBookId($libro_id);

            if (empty($availableCopies)) {
                // Recalculate availability and retry
                $integrity = new DataIntegrity($db);
                $integrity->recalculateBookAvailability($libro_id);

                $availableCopies = $copyRepo->getAvailableByBookId($libro_id);
                if (empty($availableCopies)) {
                    $db->rollback();
                    return $response->withHeader('Location', '/admin/prestiti/crea?error=no_copies_available')->withStatus(302);
                }
            }

            // Take the first available copy
            $selectedCopy = $availableCopies[0];

            $processedBy = null;
            if (isset($_SESSION['user']['id'])) {
                $candidateId = (int)$_SESSION['user']['id'];
                if ($candidateId > 0) {
                    $staffCheck = $db->prepare('SELECT id FROM staff WHERE id = ? LIMIT 1');
                    if ($staffCheck) {
                        $staffCheck->bind_param('i', $candidateId);
                        if ($staffCheck->execute()) {
                            $result = $staffCheck->get_result();
                            if ($result && $result->num_rows > 0) {
                                $processedBy = $candidateId;
                            }
                        }
                        $staffCheck->close();
                    }
                }
            }

            // Inserimento del prestito con copia_id
            $stmt = $db->prepare("INSERT INTO prestiti
                (libro_id, copia_id, utente_id, data_prestito, data_scadenza, data_restituzione, stato, sanzione, renewals, processed_by, note, attivo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $data_restituzione = null;
            $stato_prestito = 'in_corso';
            $sanzione = 0.00;
            $renewals = 0;
            $attivo = 1;

            $stmt->bind_param("iiissssdiisi",
                $libro_id, $selectedCopy['id'], $utente_id, $data_prestito, $data_scadenza, $data_restituzione,
                $stato_prestito, $sanzione, $renewals, $processedBy, $note, $attivo);
            $stmt->execute();
            $newLoanId = (int)$db->insert_id;
            $stmt->close();

            // Update copy status to 'prestato'
            $copyRepo->updateStatus($selectedCopy['id'], 'prestato');



            // Commit della transazione
            $db->commit();

            // Allineamento disponibilità e validazione prestito
            try {
                $integrity = new DataIntegrity($db);
                $integrity->recalculateBookAvailability($libro_id);
                if (isset($newLoanId)) {
                    $integrity->validateAndUpdateLoan($newLoanId);
                }
            } catch (\Throwable $e) {
                error_log('DataIntegrity warning (store loan): ' . $e->getMessage());
            }

            // Create in-app notification for new loan
            if (isset($newLoanId)) {
                try {
                    $notificationService = new \App\Support\NotificationService($db);

                    // Get book and user details for notification
                    $infoStmt = $db->prepare("
                        SELECT l.titolo, CONCAT(u.nome, ' ', u.cognome) as utente_nome
                        FROM prestiti p
                        JOIN libri l ON p.libro_id = l.id
                        JOIN utenti u ON p.utente_id = u.id
                        WHERE p.id = ?
                    ");
                    $infoStmt->bind_param('i', $newLoanId);
                    $infoStmt->execute();
                    $loanInfo = $infoStmt->get_result()->fetch_assoc();
                    $infoStmt->close();

                    if ($loanInfo) {
                        $notificationService->createNotification(
                            'general',
                            'Nuovo prestito creato',
                            sprintf('%s ha preso in prestito "%s"', $loanInfo['utente_nome'], $loanInfo['titolo']),
                            '/admin/prestiti',
                            $newLoanId
                        );
                    }
                } catch (\Throwable $e) {
                    error_log('Failed to create loan notification: ' . $e->getMessage());
                }
            }

            return $response->withHeader('Location', '/admin/prestiti?created=1')->withStatus(302);
            
        } catch (Exception $e) {
            $db->rollback();
            // Se il messaggio di errore contiene un riferimento a un prestito già attivo
            if (strpos($e->getMessage(), 'Esiste già un prestito attivo per questo libro') !== false) {
                return $response->withHeader('Location', '/admin/prestiti/crea?error=libro_in_prestito')->withStatus(302);
            } else {
                throw $e;
            }
        }
    }


    public function editForm(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        $repo = new \App\Models\LoanRepository($db);
        $prestito = $repo->getById($id);
        if (!$prestito) { return $response->withStatus(404); }
        ob_start(); require __DIR__ . '/../Views/prestiti/modifica_prestito.php'; $content = ob_get_clean();
        ob_start(); require __DIR__ . '/../Views/layout.php'; $html = ob_get_clean();
        $response->getBody()->write($html); return $response;
    }

    public function update(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        $data = (array)$request->getParsedBody();
        if (!\App\Support\Csrf::validate($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }
        $repo = new \App\Models\LoanRepository($db);
        $processedBy = $_SESSION['user']['id'] ?? null;
        // Whitelist esplicita dei campi modificabili per prevenire mass assignment
        // Note: data_restituzione e attivo sono gestiti dal form "Registra Restituzione" dedicato
        $allowedFields = ['libro_id', 'utente_id', 'data_prestito', 'data_scadenza'];
        $updateData = [];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                switch ($field) {
                    case 'libro_id':
                    case 'utente_id':
                        $updateData[$field] = (int)$data[$field];
                        break;
                    case 'data_prestito':
                    case 'data_scadenza':
                        $updateData[$field] = $data[$field];
                        break;
                }
            }
        }
        $updateData['processed_by'] = $processedBy;

        $repo->update($id, $updateData);
        return $response->withHeader('Location', '/admin/prestiti')->withStatus(302);
    }
    public function close(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        $token = ($request->getParsedBody()['csrf_token'] ?? '') ?: '';
        if (!\App\Support\Csrf::validate($token)) { return $response->withStatus(400); }
        $repo = new \App\Models\LoanRepository($db);
        $repo->close($id);
        return $response->withHeader('Location', '/admin/prestiti')->withStatus(302);
    }

    public function returnForm(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        // Recupera i dati del prestito
        $stmt = $db->prepare("
            SELECT prestiti.id, prestiti.libro_id, libri.titolo, prestiti.utente_id,
                   CONCAT(utenti.nome, ' ', utenti.cognome) as utente_nome,
                   utenti.email as utente_email, utenti.telefono as utente_telefono,
                   prestiti.data_prestito, prestiti.data_scadenza, prestiti.data_restituzione,
                   prestiti.stato, prestiti.note
            FROM prestiti
            LEFT JOIN libri ON prestiti.libro_id = libri.id
            LEFT JOIN utenti ON prestiti.utente_id = utenti.id
            WHERE prestiti.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            return $response->withStatus(404);
        }
        $prestito = $result->fetch_assoc();
        $stmt->close();

        ob_start();
        require __DIR__ . '/../Views/prestiti/restituito_prestito.php';
        $content = ob_get_clean();
        ob_start(); require __DIR__ . '/../Views/layout.php'; $html = ob_get_clean();
        $response->getBody()->write($html); return $response;
    }

    public function processReturn(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        $data = (array)$request->getParsedBody();
        $token = $data['csrf_token'] ?? '';
        if (!\App\Support\Csrf::validate($token)) { return $response->withStatus(400); }

        $nuovo_stato = $data['stato'] ?? '';
        $note = trim((string)($data['note'] ?? '')) ?: null;
        $redirectTo = $this->sanitizeRedirect($data['redirect_to'] ?? null);

        $allowed_status = ['restituito', 'in_ritardo', 'perso', 'danneggiato'];
        if (!in_array($nuovo_stato, $allowed_status)) {
            return $response->withHeader('Location', '/admin/prestiti/restituito/'.$id.'?error=invalid_status')->withStatus(302);
        }

        $data_restituzione = gmdate('Y-m-d');

        // Avvia transazione
        $db->begin_transaction();
        try {
            // Recupera libro_id e copia_id dal prestito
            $stmt = $db->prepare("SELECT libro_id, copia_id FROM prestiti WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $loan = $result->fetch_assoc();
            $stmt->close();

            if (!$loan) {
                $db->rollback();
                return $response->withHeader('Location', '/admin/prestiti?error=loan_not_found')->withStatus(302);
            }

            $libro_id = $loan['libro_id'];
            $copia_id = $loan['copia_id'];

            // Aggiorna il prestito
            $stmt = $db->prepare("UPDATE prestiti SET stato = ?, data_restituzione = ?, note = ?, attivo = 0 WHERE id = ?");
            $stmt->bind_param("sssi", $nuovo_stato, $data_restituzione, $note, $id);
            $stmt->execute();
            $stmt->close();

            // Mappa stato prestito → stato copia
            $copia_stato = match($nuovo_stato) {
                'restituito' => 'disponibile',
                'in_ritardo' => 'prestato',  // Ancora in prestito
                'perso' => 'perso',
                'danneggiato' => 'danneggiato',
                default => 'disponibile'
            };

            // Aggiorna lo stato della copia
            $copyRepo = new \App\Models\CopyRepository($db);
            $copyRepo->updateStatus($copia_id, $copia_stato);

            // Ricalcola le copie disponibili con controlli di integrità
            // Questo aggiornerà anche lo stato del libro basandosi sulle copie
            $integrity = new DataIntegrity($db);
            $integrity->recalculateBookAvailability($libro_id);

            // Valida e aggiorna lo stato del prestito
            $validationResult = $integrity->validateAndUpdateLoan($id);
            if (!$validationResult['success']) {
                error_log("Warning: Loan validation failed for loan {$id}: " . $validationResult['message']);
            }

            // Se copia torna disponibile, gestisci notifiche
            if ($copia_stato === 'disponibile') {
                // Notifica utenti con libro in wishlist
                $notificationService = new NotificationService($db);
                $notificationService->notifyWishlistBookAvailability($libro_id);

                // Processa prenotazioni attive per questo libro
                $reservationManager = new \App\Controllers\ReservationManager($db);
                $reservationManager->processBookAvailability($libro_id);
            }

            $db->commit();
            $_SESSION['success_message'] = 'Prestito aggiornato correttamente.';
            $successUrl = $redirectTo ?? '/admin/prestiti?updated=1';
            return $response->withHeader('Location', $successUrl)->withStatus(302);

        } catch (Exception $e) {
            $db->rollback();
            error_log("Error processing loan return {$id}: " . $e->getMessage());
            if ($redirectTo) {
                $separator = strpos($redirectTo, '?') === false ? '?' : '&';
                return $response->withHeader('Location', $redirectTo . $separator . 'error=update_failed')->withStatus(302);
            }
            return $response->withHeader('Location', '/admin/prestiti/restituito/'.$id.'?error=update_failed')->withStatus(302);
        }
    }

    public function details(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        $stmt = $db->prepare("
            SELECT prestiti.*, libri.titolo AS libro_titolo, 
                   CONCAT(utenti.nome, ' ', utenti.cognome) AS utente_nome,
                   utenti.email AS utente_email,
                   CONCAT(staff.nome, ' ', staff.cognome) AS processed_by_name
            FROM prestiti 
            LEFT JOIN libri ON prestiti.libro_id = libri.id 
            LEFT JOIN utenti ON prestiti.utente_id = utenti.id
            LEFT JOIN utenti staff ON prestiti.processed_by = staff.id
            WHERE prestiti.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            return $response->withStatus(404);
        }
        $prestito = $result->fetch_assoc();
        $stmt->close();

        ob_start();
        require __DIR__ . '/../Views/prestiti/dettagli_prestito.php';
        $content = ob_get_clean();
        ob_start(); require __DIR__ . '/../Views/layout.php'; $html = ob_get_clean();
        $response->getBody()->write($html); return $response;
    }

    private function updateBookAvailability(mysqli $db, int $bookId): void {
        // Update book availability based on active loans
        $stmt = $db->prepare("
            UPDATE libri SET copie_disponibili = copie_totali - (
                SELECT COUNT(*) FROM prestiti
                WHERE libro_id = ? AND stato IN ('in_corso', 'in_ritardo')
            )
            WHERE id = ?
        ");
        $stmt->bind_param('ii', $bookId, $bookId);
        $stmt->execute();
        $stmt->close();
    }

    public function renew(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        $data = (array)$request->getParsedBody();
        $token = $data['csrf_token'] ?? '';

        // Validate CSRF token
        if (!\App\Support\Csrf::validate($token)) {
            return $response->withStatus(400);
        }

        $redirectTo = $this->sanitizeRedirect($data['redirect_to'] ?? null);

        // Get current loan
        $stmt = $db->prepare("
            SELECT id, libro_id, utente_id, data_scadenza, stato, renewals, attivo
            FROM prestiti
            WHERE id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            $errorUrl = $redirectTo ?? '/admin/prestiti';
            $separator = strpos($errorUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', $errorUrl . $separator . 'error=loan_not_found')->withStatus(302);
        }

        $loan = $result->fetch_assoc();
        $stmt->close();

        // Check if loan is active
        if ((int)$loan['attivo'] !== 1) {
            $errorUrl = $redirectTo ?? '/admin/prestiti';
            $separator = strpos($errorUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', $errorUrl . $separator . 'error=loan_not_active')->withStatus(302);
        }

        // Check if loan is overdue
        $isLate = ($loan['stato'] === 'in_ritardo');
        if ($isLate) {
            $errorUrl = $redirectTo ?? '/admin/prestiti';
            $separator = strpos($errorUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', $errorUrl . $separator . 'error=loan_overdue')->withStatus(302);
        }

        // Check renewal limit
        $maxRenewals = 3;
        $currentRenewals = (int)$loan['renewals'];
        if ($currentRenewals >= $maxRenewals) {
            $errorUrl = $redirectTo ?? '/admin/prestiti';
            $separator = strpos($errorUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', $errorUrl . $separator . 'error=max_renewals')->withStatus(302);
        }

        // Start transaction
        $db->begin_transaction();

        try {
            // Calculate new due date (add 14 days)
            $currentDueDate = $loan['data_scadenza'];
            $newDueDate = date('Y-m-d', strtotime($currentDueDate . ' +14 days'));
            $newRenewalCount = $currentRenewals + 1;

            // Update loan
            $updateStmt = $db->prepare("
                UPDATE prestiti
                SET data_scadenza = ?, renewals = ?
                WHERE id = ?
            ");
            $updateStmt->bind_param("sii", $newDueDate, $newRenewalCount, $id);
            $updateStmt->execute();
            $updateStmt->close();

            // Validate and update loan status
            $integrity = new DataIntegrity($db);
            $validationResult = $integrity->validateAndUpdateLoan($id);
            if (!$validationResult['success']) {
                error_log("Warning: Loan validation failed for loan {$id}: " . $validationResult['message']);
            }

            $db->commit();
            $_SESSION['success_message'] = 'Prestito rinnovato correttamente. Nuova scadenza: ' . date('d/m/Y', strtotime($newDueDate));

            $successUrl = $redirectTo ?? '/admin/prestiti';
            $separator = strpos($successUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', $successUrl . $separator . 'renewed=1')->withStatus(302);

        } catch (Exception $e) {
            $db->rollback();
            error_log("Renewal failed for loan {$id}: " . $e->getMessage());

            $errorUrl = $redirectTo ?? '/admin/prestiti';
            $separator = strpos($errorUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', $errorUrl . $separator . 'error=renewal_failed')->withStatus(302);
        }
    }

    private function sanitizeRedirect(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $trimmed = str_replace(["\r", "\n"], '', $path);
        if (strpos($trimmed, '://') !== false) {
            return null; // avoid absolute URLs or protocols
        }

        if (!str_starts_with($trimmed, '/')) {
            return null;
        }

        if (str_starts_with($trimmed, '//')) {
            return null;
        }

        // Collapse multiple slashes to avoid traversal quirks
        $normalized = preg_replace('#/+#', '/', $trimmed);
        return $normalized ?: null;
    }

    private function guardStaffAccess(Response $response): ?Response
    {
        $role = $_SESSION['user']['tipo_utente'] ?? '';
        if (!in_array($role, ['admin', 'staff'], true)) {
            return $response->withStatus(403);
        }
        return null;
    }
}
