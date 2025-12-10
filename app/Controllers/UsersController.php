<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use App\Support\Csrf;
use App\Support\NotificationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UsersController
{
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        if ($guard = $this->guardAdminStaff($response)) {
            return $guard;
        }

        // Fetch pending users (suspended, awaiting approval)
        $pendingUsers = [];
        $result = $db->query("
            SELECT id, nome, cognome, email, telefono, created_at, codice_tessera
            FROM utenti
            WHERE stato = 'sospeso'
            ORDER BY created_at DESC
            LIMIT 10
        ");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $pendingUsers[] = $row;
            }
            $result->free();
        }

        ob_start();
        require __DIR__ . '/../Views/utenti/index.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function createForm(Request $request, Response $response): Response
    {
        if ($guard = $this->guardAdminStaff($response)) {
            return $guard;
        }
        ob_start();
        require __DIR__ . '/../Views/utenti/crea_utente.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function store(Request $request, Response $response, mysqli $db): Response
    {
        if ($guard = $this->guardAdminStaff($response)) {
            return $guard;
        }
        $data = (array) $request->getParsedBody();
        $currentUserRole = (string) ($_SESSION['user']['tipo_utente'] ?? '');

        // CSRF validated by CsrfMiddleware

        $nome = trim(strip_tags((string) ($data['nome'] ?? '')));
        $cognome = trim(strip_tags((string) ($data['cognome'] ?? '')));
        $email = trim(strip_tags((string) ($data['email'] ?? '')));
        $telefono = trim(strip_tags((string) ($data['telefono'] ?? '')));
        $requestedRole = (string) ($data['tipo_utente'] ?? $data['ruolo'] ?? 'standard');
        $allowedRoles = ['standard', 'premium'];
        if ($currentUserRole === 'admin') {
            $allowedRoles = ['standard', 'premium', 'staff', 'admin'];
        } elseif ($currentUserRole === 'staff') {
            $allowedRoles = ['standard', 'premium', 'staff'];
        }
        if (!in_array($requestedRole, $allowedRoles, true)) {
            $requestedRole = 'standard';
        }
        $role = $requestedRole;
        $isAdmin = $role === 'admin';

        if ($nome === '' || $cognome === '' || $email === '') {
            return $response->withHeader('Location', '/admin/utenti/crea?error=missing_fields')->withStatus(302);
        }

        // Validate input lengths
        if (strlen($nome) > 100 || strlen($cognome) > 100) {
            return $response->withHeader('Location', '/admin/utenti/crea?error=name_too_long')->withStatus(302);
        }

        if (strlen($email) > 255) {
            return $response->withHeader('Location', '/admin/utenti/crea?error=email_too_long')->withStatus(302);
        }

        if (strlen($telefono) > 20) {
            return $response->withHeader('Location', '/admin/utenti/crea?error=phone_too_long')->withStatus(302);
        }

        if (!$isAdmin && $telefono === '') {
            return $response->withHeader('Location', '/admin/utenti/crea?error=missing_fields')->withStatus(302);
        }

        $indirizzo = trim(strip_tags((string) ($data['indirizzo'] ?? '')));
        $indirizzo = $indirizzo !== '' ? $indirizzo : null;

        $cod_fiscale = strtoupper(trim((string) ($data['cod_fiscale'] ?? '')));
        $cod_fiscale = $cod_fiscale !== '' ? $cod_fiscale : null;

        $dataNascita = trim((string) ($data['data_nascita'] ?? ''));
        $dataNascita = $dataNascita !== '' ? $dataNascita : null;

        $sesso = trim((string) ($data['sesso'] ?? ''));
        $sesso = $sesso !== '' ? $sesso : null;
        if ($sesso !== null && !\in_array($sesso, ['M', 'F', 'Altro'], true)) {
            $sesso = null; // Invalid value, set to null
        }

        $note = trim(strip_tags((string) ($data['note_utente'] ?? '')));
        $note = $note !== '' ? $note : null;

        $codiceTesseraInput = trim((string) ($data['codice_tessera'] ?? ''));
        $dataScadenzaInput = trim((string) ($data['data_scadenza_tessera'] ?? ''));

        if ($isAdmin) {
            $codiceTessera = $this->generateAdminCode($db);
            $dataScadenzaTessera = null;
        } else {
            $codiceTessera = $codiceTesseraInput !== '' ? $codiceTesseraInput : $this->generateTessera($db);
            $dataScadenzaTessera = $dataScadenzaInput !== '' ? $dataScadenzaInput : null;
        }

        $stato = (string) ($data['stato'] ?? ($isAdmin ? 'attivo' : 'attivo'));
        if (!in_array($stato, ['attivo', 'sospeso', 'scaduto'], true)) {
            $stato = 'attivo';
        }

        $rawPassword = (string) ($data['password'] ?? '');
        $passwordHash = $rawPassword !== '' ? password_hash($rawPassword, PASSWORD_DEFAULT) : null;

        $sendSetupEmail = false;
        if ($passwordHash === null || $isAdmin) {
            $passwordHash = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
            $sendSetupEmail = true;
        }

        $tokenReset = null;
        $dataTokenReset = null;
        if ($sendSetupEmail) {
            $tokenReset = bin2hex(random_bytes(32));
            $dataTokenReset = gmdate('Y-m-d H:i:s');
        }

        $telefono = $telefono !== '' ? $telefono : null;
        $emailVerificata = $isAdmin ? 1 : 1; // l'admin crea utenti già verificati

        $stmt = $db->prepare("INSERT INTO utenti (
            nome, cognome, email, telefono, password, indirizzo, cod_fiscale, data_nascita, sesso,
            codice_tessera, data_scadenza_tessera, stato, tipo_utente, note_utente,
            email_verificata, token_reset_password, data_token_reset
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        $types = str_repeat('s', 14) . 'i' . str_repeat('s', 2);
        $stmt->bind_param(
            $types,
            $nome,
            $cognome,
            $email,
            $telefono,
            $passwordHash,
            $indirizzo,
            $cod_fiscale,
            $dataNascita,
            $sesso,
            $codiceTessera,
            $dataScadenzaTessera,
            $stato,
            $role,
            $note,
            $emailVerificata,
            $tokenReset,
            $dataTokenReset
        );

        if (!$stmt->execute()) {
            $stmt->close();
            return $response->withHeader('Location', '/admin/utenti/crea?error=db_error')->withStatus(302);
        }

        $userId = (int) $stmt->insert_id;
        $stmt->close();

        $notifier = new NotificationService($db);

        if ($isAdmin) {
            if ($sendSetupEmail) {
                $notifier->sendAdminInvitation($userId);
            }
        } else {
            // Audit logging for user creation
            \App\Support\SecureLogger::info('New user created', [
                'user_id' => $userId,
                'name' => $nome . ' ' . $cognome,
                'email' => $email,
                'role' => $role,
                'created_by' => $_SESSION['user']['id'] ?? 'unknown',
                'created_by_role' => $_SESSION['user']['tipo_utente'] ?? 'unknown'
            ]);

            if ($sendSetupEmail) {
                $notifier->sendUserPasswordSetup($userId);
            }
            if ($stato === 'attivo') {
                $notifier->sendUserAccountApproved($userId);
            }
        }

        return $response->withHeader('Location', '/admin/utenti?created=1')->withStatus(302);
    }

    public function editForm(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardAdminStaff($response)) {
            return $guard;
        }
        $stmt = $db->prepare("SELECT * FROM utenti WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            return $response->withStatus(404);
        }
        $utente = $result->fetch_assoc();
        $stmt->close();

        ob_start();
        require __DIR__ . '/../Views/utenti/modifica_utente.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function update(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardAdminStaff($response)) {
            return $guard;
        }
        $data = (array) $request->getParsedBody();
        $currentUserRole = (string) ($_SESSION['user']['tipo_utente'] ?? '');
        $currentUserId = (int) ($_SESSION['user']['id'] ?? 0);

        // CSRF validated by CsrfMiddleware

        // IDOR Protection: Staff can only edit themselves, admins can edit anyone
        if ($currentUserRole === 'staff' && $currentUserId !== $id) {
            return $response->withStatus(403);
        }

        $stmt = $db->prepare("SELECT * FROM utenti WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $original = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$original) {
            return $response->withStatus(404);
        }

        $nome = trim(strip_tags((string) ($data['nome'] ?? '')));
        $cognome = trim(strip_tags((string) ($data['cognome'] ?? '')));
        $email = trim(strip_tags((string) ($data['email'] ?? '')));
        $telefono = trim(strip_tags((string) ($data['telefono'] ?? '')));
        $requestedRole = (string) ($data['tipo_utente'] ?? $data['ruolo'] ?? ($original['tipo_utente'] ?? 'standard'));
        $allowedRoles = ['standard', 'premium'];
        if ($currentUserRole === 'admin') {
            $allowedRoles = ['standard', 'premium', 'staff', 'admin'];
        } elseif ($currentUserRole === 'staff') {
            $allowedRoles = ['standard', 'premium', 'staff'];
        }
        if (!in_array($requestedRole, $allowedRoles, true)) {
            $requestedRole = (string) ($original['tipo_utente'] ?? 'standard');
        }
        $role = $requestedRole;
        $isAdmin = $role === 'admin';

        if ($nome === '' || $cognome === '' || $email === '') {
            return $response->withHeader('Location', '/admin/utenti/modifica/' . $id . '?error=missing_fields')->withStatus(302);
        }

        if (!$isAdmin && $telefono === '') {
            return $response->withHeader('Location', '/admin/utenti/modifica/' . $id . '?error=missing_fields')->withStatus(302);
        }

        $indirizzo = trim(strip_tags((string) ($data['indirizzo'] ?? '')));
        $indirizzo = $indirizzo !== '' ? $indirizzo : null;

        $cod_fiscale = strtoupper(trim((string) ($data['cod_fiscale'] ?? '')));
        $cod_fiscale = $cod_fiscale !== '' ? $cod_fiscale : null;

        $dataNascita = trim((string) ($data['data_nascita'] ?? ''));
        $dataNascita = $dataNascita !== '' ? $dataNascita : null;

        $sesso = trim((string) ($data['sesso'] ?? ''));
        $sesso = $sesso !== '' ? $sesso : null;
        if ($sesso !== null && !\in_array($sesso, ['M', 'F', 'Altro'], true)) {
            $sesso = null; // Invalid value, set to null
        }

        $codiceTesseraInput = trim((string) ($data['codice_tessera'] ?? ''));
        $dataScadenzaInput = trim((string) ($data['data_scadenza_tessera'] ?? ''));

        if ($isAdmin) {
            if (($original['tipo_utente'] ?? '') === 'admin' && !empty($original['codice_tessera'])) {
                $codiceTessera = $original['codice_tessera'];
            } else {
                $codiceTessera = $this->generateAdminCode($db);
            }
            $dataScadenzaTessera = null;
        } else {
            if ($codiceTesseraInput !== '') {
                $codiceTessera = $codiceTesseraInput;
            } elseif (!empty($original['codice_tessera'])) {
                $codiceTessera = $original['codice_tessera'];
            } else {
                $codiceTessera = $this->generateTessera($db);
            }

            // Se l'utente cambia da admin/staff a standard/premium, assegna scadenza tessera (1 anno)
            $originalRole = $original['tipo_utente'] ?? '';
            if (in_array($originalRole, ['admin', 'staff'], true) && in_array($role, ['standard', 'premium'], true)) {
                // Cambio da admin/staff a utente normale - assegna scadenza tessera
                $dataScadenzaTessera = date('Y-m-d', strtotime('+1 year'));
            } else {
                $dataScadenzaTessera = $dataScadenzaInput !== '' ? $dataScadenzaInput : null;
            }
        }

        $stato = (string) ($data['stato'] ?? ($original['stato'] ?? 'attivo'));
        if (!in_array($stato, ['attivo', 'sospeso', 'scaduto'], true)) {
            $stato = $original['stato'] ?? 'attivo';
        }

        $note = trim(strip_tags((string) ($data['note_utente'] ?? '')));
        $note = $note !== '' ? $note : null;

        $telefono = $telefono !== '' ? $telefono : null;

        $emailVerificata = (int) ($original['email_verificata'] ?? 0);
        if ($stato === 'attivo') {
            $emailVerificata = 1;
        }

        $passwordHash = (string) ($original['password'] ?? '');
        $tokenReset = $original['token_reset_password'] ?? null;
        $dataTokenReset = $original['data_token_reset'] ?? null;

        $sendSetupEmail = false;
        if (!empty($data['password'])) {
            $newPassword = (string) $data['password'];

            // Validate password complexity
            if (strlen($newPassword) < 8) {
                $_SESSION['error_message'] = __('La password deve essere lunga almeno 8 caratteri.');
                return $response->withHeader('Location', '/admin/utenti/modifica/' . $id . '?error=password_too_short')->withStatus(302);
            }

            if (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
                $_SESSION['error_message'] = __('La password deve contenere almeno una lettera maiuscola, una minuscola e un numero.');
                return $response->withHeader('Location', '/admin/utenti/modifica/' . $id . '?error=password_needs_upper_lower_number')->withStatus(302);
            }

            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $tokenReset = null;
            $dataTokenReset = null;
        }

        $stmt = $db->prepare("UPDATE utenti SET
            nome = ?, cognome = ?, email = ?, telefono = ?, password = ?, indirizzo = ?, cod_fiscale = ?,
            data_nascita = ?, sesso = ?, codice_tessera = ?, data_scadenza_tessera = ?,
            stato = ?, tipo_utente = ?, note_utente = ?, email_verificata = ?,
            token_reset_password = ?, data_token_reset = ?
            WHERE id = ?");

        $types = str_repeat('s', 14) . 'i' . str_repeat('s', 2) . 'i';
        $stmt->bind_param(
            $types,
            $nome,
            $cognome,
            $email,
            $telefono,
            $passwordHash,
            $indirizzo,
            $cod_fiscale,
            $dataNascita,
            $sesso,
            $codiceTessera,
            $dataScadenzaTessera,
            $stato,
            $role,
            $note,
            $emailVerificata,
            $tokenReset,
            $dataTokenReset,
            $id
        );

        $success = $stmt->execute();
        $stmt->close();

        if (!$success) {
            return $response->withHeader('Location', '/admin/utenti/modifica/' . $id . '?error=db_error')->withStatus(302);
        }

        // Audit logging for critical actions
        if (($original['tipo_utente'] ?? '') !== $role) {
            \App\Support\SecureLogger::info('User role changed', [
                'user_id' => $id,
                'old_role' => $original['tipo_utente'] ?? 'unknown',
                'new_role' => $role,
                'changed_by' => $_SESSION['user']['id'] ?? 'unknown',
                'changed_by_role' => $_SESSION['user']['tipo_utente'] ?? 'unknown'
            ]);
        }

        // Check if the current user's role is being changed - if so, regenerate session to prevent session fixation
        $currentUserId = (int) ($_SESSION['user']['id'] ?? 0);
        if ($currentUserId === $id && ($original['tipo_utente'] ?? '') !== $role) {
            // Regenerate session ID and update session data
            session_regenerate_id(true);
            $_SESSION['user']['tipo_utente'] = $role;
            // Also regenerate CSRF token
            \App\Support\Csrf::regenerate();
        }

        $notifier = new NotificationService($db);
        if (($original['stato'] ?? '') !== 'attivo' && $stato === 'attivo') {
            $notifier->sendUserAccountApproved($id);
        }

        if ($isAdmin && ($original['tipo_utente'] ?? '') !== 'admin' && empty($data['password'])) {
            $tokenReset = bin2hex(random_bytes(32));
            $dataTokenReset = gmdate('Y-m-d H:i:s');
            $updateToken = $db->prepare("UPDATE utenti SET token_reset_password = ?, data_token_reset = ? WHERE id = ?");
            $updateToken->bind_param('ssi', $tokenReset, $dataTokenReset, $id);
            $updateToken->execute();
            $updateToken->close();
            $notifier->sendAdminInvitation($id);
        }

        return $response->withHeader('Location', '/admin/utenti?updated=1')->withStatus(302);
    }

    public function delete(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardAdminStaff($response)) {
            return $guard;
        }
        $currentUserRole = (string) ($_SESSION['user']['tipo_utente'] ?? '');
        $currentUserId = (int) ($_SESSION['user']['id'] ?? 0);

        // CSRF validated by CsrfMiddleware

        // IDOR Protection: Staff cannot delete any user, only admins can delete
        // (Staff shouldn't even delete themselves to preserve audit trail)
        if ($currentUserRole !== 'admin') {
            return $response->withStatus(403);
        }

        // Get user details for audit logging before deletion
        $stmt = $db->prepare("SELECT nome, cognome, email, tipo_utente FROM utenti WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $userToDelete = $result->fetch_assoc();
        $stmt->close();

        // Check for any loans (active or not) to preserve history
        $loanCheck = $db->prepare("SELECT COUNT(*) as count FROM prestiti WHERE utente_id = ?");
        $loanCheck->bind_param("i", $id);
        $loanCheck->execute();
        $loanResult = $loanCheck->get_result()->fetch_assoc();
        $loanCheck->close();

        if ($loanResult['count'] > 0) {
            // Cannot delete user with loan history - mark as suspended instead
            $updateStmt = $db->prepare("UPDATE utenti SET stato = 'sospeso', note_utente = CONCAT(IFNULL(note_utente, ''), '\n[ELIMINATO IL ', NOW(), ']') WHERE id = ?");
            $updateStmt->bind_param("i", $id);
            $updateStmt->execute();
            $updateStmt->close();

            // Audit logging
            if ($userToDelete) {
                \App\Support\SecureLogger::info('User marked as deleted (has loan history)', [
                    'user_id' => $id,
                    'user_name' => $userToDelete['nome'] . ' ' . $userToDelete['cognome'],
                    'user_email' => $userToDelete['email'],
                    'loan_count' => $loanResult['count'],
                    'deleted_by' => $_SESSION['user']['id'] ?? 'unknown'
                ]);
            }

            $_SESSION['success_message'] = 'L\'utente ha uno storico prestiti e non può essere eliminato. È stato contrassegnato come sospeso.';
            return $response->withHeader('Location', '/admin/utenti')->withStatus(302);
        }

        // No loans - safe to delete
        $stmt = $db->prepare("DELETE FROM utenti WHERE id = ?");
        $stmt->bind_param("i", $id);
        $success = $stmt->execute();
        $error = $stmt->error;
        $stmt->close();

        if (!$success) {
            $_SESSION['error_message'] = 'Errore durante l\'eliminazione: ' . $error;
            return $response->withHeader('Location', '/admin/utenti?error=db_error')->withStatus(302);
        }

        // Audit logging for user deletion
        if ($userToDelete) {
            \App\Support\SecureLogger::info('User deleted', [
                'deleted_user_id' => $id,
                'deleted_user_name' => $userToDelete['nome'] . ' ' . $userToDelete['cognome'],
                'deleted_user_email' => $userToDelete['email'],
                'deleted_user_role' => $userToDelete['tipo_utente'],
                'deleted_by' => $_SESSION['user']['id'] ?? 'unknown',
                'deleted_by_role' => $_SESSION['user']['tipo_utente'] ?? 'unknown'
            ]);
        }

        $_SESSION['success_message'] = __('Utente eliminato con successo.');
        return $response->withHeader('Location', '/admin/utenti')->withStatus(302);
    }

    public function details(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardAdminStaff($response)) {
            return $guard;
        }
        $stmt = $db->prepare("SELECT * FROM utenti WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            return $response->withStatus(404);
        }
        $utente = $result->fetch_assoc();
        $stmt->close();

        // Fetch user's loan history
        $loanStmt = $db->prepare("
            SELECT p.id, p.data_prestito, p.data_scadenza, p.data_restituzione, p.stato, l.titolo as libro_titolo
            FROM prestiti p
            JOIN libri l ON p.libro_id = l.id
            WHERE p.utente_id = ?
            ORDER BY p.data_prestito DESC
        ");
        $loanStmt->bind_param("i", $id);
        $loanStmt->execute();
        $loanResult = $loanStmt->get_result();
        $prestiti = [];
        while ($row = $loanResult->fetch_assoc()) {
            $prestiti[] = $row;
        }
        $loanStmt->close();

        ob_start();
        require __DIR__ . '/../Views/utenti/dettagli_utente.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    private function generateAdminCode(mysqli $db): string
    {
        do {
            $code = 'ADMIN-' . strtoupper(bin2hex(random_bytes(3)));
            $stmt = $db->prepare("SELECT id FROM utenti WHERE codice_tessera = ? LIMIT 1");
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
        } while ($exists);

        return $code;
    }

    private function generateTessera(mysqli $db): string
    {
        do {
            $random = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
            $tessera = 'T' . $random;
            $stmt = $db->prepare("SELECT id FROM utenti WHERE codice_tessera = ?");
            $stmt->bind_param("s", $tessera);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
        } while ($exists);

        return $tessera;
    }

    /**
     * Approva utente e invia email di attivazione con link di verifica
     * L'utente dovrà cliccare il link per verificare la email e completare l'attivazione
     */
    public function approveAndSendActivation(Request $request, Response $response, mysqli $db, array $args): Response
    {
        // Validate CSRF
        // CSRF validated by CsrfMiddleware

        $userId = (int) $args['id'];

        // Verifica che l'utente esista e sia in stato sospeso
        $stmt = $db->prepare("SELECT id, stato, nome, cognome FROM utenti WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            return $response->withHeader('Location', '/admin/utenti?error=user_not_found')->withStatus(302);
        }

        if ($user['stato'] !== 'sospeso') {
            return $response->withHeader('Location', "/admin/utenti/dettagli/{$userId}?error=not_suspended")->withStatus(302);
        }

        // Genera nuovo token di verifica (valido 7 giorni)
        $token = bin2hex(random_bytes(24)); // 48 caratteri hex
        $db->query("SET SESSION time_zone = '+00:00'");
        $tokenExpiry = gmdate('Y-m-d H:i:s', time() + (7 * 24 * 60 * 60)); // 7 giorni

        // Aggiorna utente: stato = attivo, nuovo token verifica
        $stmt = $db->prepare("
            UPDATE utenti
            SET stato = 'attivo',
                token_verifica_email = ?,
                data_token_verifica = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('ssi', $token, $tokenExpiry, $userId);

        if (!$stmt->execute()) {
            $stmt->close();
            return $response->withHeader('Location', "/admin/utenti/dettagli/{$userId}?error=db_error")->withStatus(302);
        }
        $stmt->close();

        // Invia email di attivazione con link di verifica
        $notifier = new NotificationService($db);
        $notifier->sendUserActivationWithVerification($userId, $token);

        // Redirect con messaggio di successo
        return $response->withHeader(
            'Location',
            "/admin/utenti/dettagli/{$userId}?success=approved_email_sent"
        )->withStatus(302);
    }

    /**
     * Attiva direttamente l'utente senza richiedere verifica email
     * L'utente può fare login immediatamente
     */
    public function activateDirectly(Request $request, Response $response, mysqli $db, array $args): Response
    {
        // Validate CSRF
        // CSRF validated by CsrfMiddleware

        $userId = (int) $args['id'];

        // Verifica che l'utente esista e sia in stato sospeso
        $stmt = $db->prepare("SELECT id, stato, nome, cognome FROM utenti WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            return $response->withHeader('Location', '/admin/utenti?error=user_not_found')->withStatus(302);
        }

        if ($user['stato'] !== 'sospeso') {
            return $response->withHeader('Location', "/admin/utenti/dettagli/{$userId}?error=not_suspended")->withStatus(302);
        }

        // Attiva direttamente: stato = attivo, email_verificata = 1, rimuovi token
        $stmt = $db->prepare("
            UPDATE utenti
            SET stato = 'attivo',
                email_verificata = 1,
                token_verifica_email = NULL,
                data_token_verifica = NULL,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('i', $userId);

        if (!$stmt->execute()) {
            $stmt->close();
            return $response->withHeader('Location', "/admin/utenti/dettagli/{$userId}?error=db_error")->withStatus(302);
        }
        $stmt->close();

        // Invia email di benvenuto
        $notifier = new NotificationService($db);
        $notifier->sendUserAccountApproved($userId);

        // Redirect con messaggio di successo
        return $response->withHeader(
            'Location',
            "/admin/utenti/dettagli/{$userId}?success=activated_directly"
        )->withStatus(302);
    }

    /**
     * Export utenti to CSV format
     */
    public function exportCsv(Request $request, Response $response, mysqli $db): Response
    {
        if ($guard = $this->guardAdminStaff($response)) {
            return $guard;
        }

        // Get filters from query parameters
        $params = $request->getQueryParams();
        $searchText = $params['search_text'] ?? '';
        $roleFilter = $params['role_filter'] ?? '';
        $statusFilter = $params['status_filter'] ?? '';
        $createdFrom = $params['created_from'] ?? '';

        // Build WHERE clause based on filters
        $whereClauses = [];
        $bindTypes = '';
        $bindValues = [];

        // Search text filter (nome, cognome, email)
        if (!empty($searchText)) {
            $whereClauses[] = "(nome LIKE ? OR cognome LIKE ? OR email LIKE ?)";
            $searchParam = "%{$searchText}%";
            for ($i = 0; $i < 3; $i++) {
                $bindTypes .= 's';
                $bindValues[] = $searchParam;
            }
        }

        // Role filter
        if (!empty($roleFilter)) {
            $whereClauses[] = "tipo_utente = ?";
            $bindTypes .= 's';
            $bindValues[] = $roleFilter;
        }

        // Status filter
        if (!empty($statusFilter)) {
            $whereClauses[] = "stato = ?";
            $bindTypes .= 's';
            $bindValues[] = $statusFilter;
        }

        // Created from filter
        if (!empty($createdFrom)) {
            $whereClauses[] = "DATE(created_at) >= ?";
            $bindTypes .= 's';
            $bindValues[] = $createdFrom;
        }

        // Build the query - export ALL fields except sensitive ones (password, tokens)
        $query = "SELECT
            id, codice_tessera, nome, cognome, email, telefono, indirizzo,
            data_nascita, sesso, data_registrazione, data_scadenza_tessera,
            stato, tipo_utente, cod_fiscale, note_utente, email_verificata,
            data_ultimo_accesso, created_at, updated_at
        FROM utenti";

        if (!empty($whereClauses)) {
            $query .= " WHERE " . implode(' AND ', $whereClauses);
        }

        $query .= " ORDER BY id DESC";

        // Execute query with prepared statement if filters are applied
        if (!empty($bindValues)) {
            $stmt = $db->prepare($query);
            $refs = [];
            foreach ($bindValues as $key => $value) {
                $refs[$key] = &$bindValues[$key];
            }
            array_unshift($refs, $bindTypes);
            call_user_func_array([$stmt, 'bind_param'], $refs);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $db->query($query);
        }

        $utenti = [];
        while ($row = $result->fetch_assoc()) {
            $utenti[] = $row;
        }

        if (isset($stmt)) {
            $stmt->close();
        }

        // Generate CSV with ALL available fields
        $headers = [
            'ID',
            'Codice Tessera',
            'Nome',
            'Cognome',
            'Email',
            'Telefono',
            'Indirizzo',
            'Data Nascita',
            'Sesso',
            'Data Registrazione',
            'Data Scadenza Tessera',
            'Stato',
            'Tipo Utente',
            'Codice Fiscale',
            'Note Utente',
            'Email Verificata',
            'Data Ultimo Accesso',
            'Created At',
            'Updated At'
        ];

        $output = "\xEF\xBB\xBF"; // UTF-8 BOM
        $output .= implode(';', $headers) . "\n";

        foreach ($utenti as $utente) {
            $row = [
                $utente['id'] ?? '',
                $utente['codice_tessera'] ?? '',
                $utente['nome'] ?? '',
                $utente['cognome'] ?? '',
                $utente['email'] ?? '',
                $utente['telefono'] ?? '',
                $utente['indirizzo'] ?? '',
                isset($utente['data_nascita']) ? date('d/m/Y', strtotime($utente['data_nascita'])) : '',
                $utente['sesso'] ?? '',
                isset($utente['data_registrazione']) ? date('d/m/Y H:i:s', strtotime($utente['data_registrazione'])) : '',
                isset($utente['data_scadenza_tessera']) ? date('d/m/Y', strtotime($utente['data_scadenza_tessera'])) : '',
                $utente['stato'] ?? '',
                $utente['tipo_utente'] ?? '',
                $utente['cod_fiscale'] ?? '',
                $utente['note_utente'] ?? '',
                ($utente['email_verificata'] ?? 0) == 1 ? 'Sì' : 'No',
                isset($utente['data_ultimo_accesso']) ? date('d/m/Y H:i:s', strtotime($utente['data_ultimo_accesso'])) : '',
                isset($utente['created_at']) ? date('d/m/Y H:i:s', strtotime($utente['created_at'])) : '',
                isset($utente['updated_at']) ? date('d/m/Y H:i:s', strtotime($utente['updated_at'])) : ''
            ];

            // Escape fields for CSV
            $escapedRow = array_map(function ($field) {
                $field = str_replace('"', '""', (string) $field);
                // Only quote if contains semicolon, newline, or quotes
                if (strpos($field, ';') !== false || strpos($field, "\n") !== false || strpos($field, '"') !== false) {
                    return '"' . $field . '"';
                }
                return $field;
            }, $row);

            $output .= implode(';', $escapedRow) . "\n";
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $output);
        rewind($stream);

        $filename = 'utenti_export_' . date('Y-m-d_His') . '.csv';

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withBody(new \Slim\Psr7\Stream($stream));
    }

    private function guardAdminStaff(Response $response): ?Response
    {
        $role = $_SESSION['user']['tipo_utente'] ?? '';
        if (!in_array($role, ['admin', 'staff'], true)) {
            return $response->withStatus(403);
        }
        return null;
    }
}
