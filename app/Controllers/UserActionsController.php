<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use App\Support\Csrf;
use App\Support\CsrfHelper;
use App\Controllers\ReservationManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UserActionsController
{
    public function reservationsPage(Request $request, Response $response, mysqli $db): Response
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user || empty($user['id'])) { return $response->withHeader('Location','/login')->withStatus(302); }
        $uid = (int)$user['id'];

        // Richieste di prestito in sospeso
        $sql = "SELECT pr.id, pr.libro_id, pr.data_prestito, pr.data_scadenza, pr.stato, pr.created_at,
                       l.titolo, l.copertina_url
                FROM prestiti pr
                JOIN libri l ON l.id = pr.libro_id
                WHERE pr.utente_id = ? AND pr.stato = 'pendente'
                ORDER BY pr.created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $pendingRequests = [];
        while ($r = $res->fetch_assoc()) { $pendingRequests[] = $r; }
        $stmt->close();

        // Prestiti in corso
        $sql = "SELECT pr.id, pr.libro_id, pr.data_prestito, pr.data_scadenza, pr.stato,
                       l.titolo, l.copertina_url,
                       EXISTS(SELECT 1 FROM recensioni r WHERE r.libro_id = pr.libro_id AND r.utente_id = ?) as has_review
                FROM prestiti pr
                JOIN libri l ON l.id = pr.libro_id
                WHERE pr.utente_id = ? AND pr.attivo = 1 AND pr.stato IN ('prestato', 'in_corso')
                ORDER BY pr.data_scadenza ASC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $uid, $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $activePrestiti = [];
        while ($r = $res->fetch_assoc()) { $activePrestiti[] = $r; }
        $stmt->close();

        // Prenotazioni attive
        $sql = "SELECT p.id, p.libro_id, p.data_prenotazione, p.data_scadenza_prenotazione, p.queue_position, p.stato,
                       l.titolo, l.copertina_url
                FROM prenotazioni p JOIN libri l ON l.id=p.libro_id
                WHERE p.utente_id=? AND p.stato='attiva' ORDER BY p.data_prenotazione DESC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($r = $res->fetch_assoc()) { $items[] = $r; }
        $stmt->close();

        // Storico prestiti (ultimi 20) - solo prestiti conclusi
        $sql = "SELECT pr.id, pr.libro_id, pr.data_prestito, pr.data_restituzione, pr.stato,
                       l.titolo, l.copertina_url,
                       EXISTS(SELECT 1 FROM recensioni r WHERE r.libro_id = pr.libro_id AND r.utente_id = ?) as has_review
                FROM prestiti pr
                JOIN libri l ON l.id = pr.libro_id
                WHERE pr.utente_id = ? AND pr.attivo = 0 AND pr.stato != 'prestato'
                ORDER BY pr.data_restituzione DESC, pr.data_prestito DESC
                LIMIT 20";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $uid, $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $pastPrestiti = [];
        while ($r = $res->fetch_assoc()) { $pastPrestiti[] = $r; }
        $stmt->close();

        // Le mie recensioni
        $sql = "SELECT r.*, l.titolo as libro_titolo, l.copertina_url as libro_copertina
                FROM recensioni r
                JOIN libri l ON l.id = r.libro_id
                WHERE r.utente_id = ?
                ORDER BY r.created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $myReviews = [];
        while ($r = $res->fetch_assoc()) { $myReviews[] = $r; }
        $stmt->close();

        $title = 'I miei prestiti - Biblioteca';
        ob_start();
        require __DIR__ . '/../Views/profile/reservations.php';
        $content = ob_get_clean();
        include __DIR__ . '/../Views/user_layout.php';
        return $response;
    }

    public function cancelReservation(Request $request, Response $response, mysqli $db): Response
    {
        $user = $_SESSION['user'] ?? null; if (!$user || empty($user['id'])) { return $response->withStatus(401); }
        $data = (array)($request->getParsedBody() ?? []);
        if (!\App\Support\Csrf::validate($data['csrf_token'] ?? null)) { return $response->withStatus(400); }
        $rid = (int)($data['reservation_id'] ?? 0); if ($rid<=0) return $response->withStatus(422);
        $uid = (int)$user['id'];
        $stmt = $db->prepare("UPDATE prenotazioni SET stato='annullata' WHERE id=? AND utente_id=?");
        $stmt->bind_param('ii', $rid, $uid); $stmt->execute(); $stmt->close();
        return $response->withHeader('Location','/prenotazioni?canceled=1')->withStatus(302);
    }

    public function changeReservationDate(Request $request, Response $response, mysqli $db): Response
    {
        $user = $_SESSION['user'] ?? null; if (!$user || empty($user['id'])) { return $response->withStatus(401); }
        $data = (array)($request->getParsedBody() ?? []);
        if (!\App\Support\Csrf::validate($data['csrf_token'] ?? null)) { return $response->withStatus(400); }
        $rid = (int)($data['reservation_id'] ?? 0); $date = trim((string)($data['desired_date'] ?? ''));
        if ($rid<=0 || $date==='' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $response->withStatus(422);
        if (strtotime($date) < strtotime(date('Y-m-d'))) return $response->withHeader('Location','/prenotazioni?error=past_date')->withStatus(302);
        $uid = (int)$user['id'];
        $startDt = $date . ' 00:00:00';
        $endDt   = date('Y-m-d', strtotime($date . ' +1 month')) . ' 23:59:59';
        $stmt = $db->prepare("UPDATE prenotazioni SET data_prenotazione=?, data_scadenza_prenotazione=? WHERE id=? AND utente_id=? AND stato='attiva'");
        $stmt->bind_param('ssii', $startDt, $endDt, $rid, $uid); $stmt->execute(); $stmt->close();
        return $response->withHeader('Location','/prenotazioni?updated=1')->withStatus(302);
    }
    public function reservationsCount(Request $request, Response $response, mysqli $db): Response
    {
        $user = $_SESSION['user'] ?? null;
        $count = 0;
        if ($user && !empty($user['id'])) {
            $uid = (int)$user['id'];
            $stmt = $db->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE utente_id=? AND stato='attiva'");
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
        }
        $response->getBody()->write(json_encode(['count'=>$count]));
        return $response->withHeader('Content-Type','application/json');
    }
    public function loan(Request $request, Response $response, mysqli $db): Response
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user || empty($user['id'])) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }
        $data = (array)($request->getParsedBody() ?? []);
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            return $this->back($response, ['loan_error' => 'csrf']);
        }
        $libroId = (int)($data['libro_id'] ?? 0);
        if ($libroId <= 0) {
            return $this->back($response, ['loan_error' => 'invalid']);
        }
        // Use ReservationManager to check availability
        $reservationManager = new ReservationManager($db);
        if (!$reservationManager->isBookAvailableForImmediateLoan($libroId)) {
            return $this->back($response, ['loan_error' => 'not_available']);
        }
        $utenteId = (int)$user['id'];
        $data_prestito = gmdate('Y-m-d');
        $data_scadenza = gmdate('Y-m-d', strtotime('+14 days'));
        $stmt = $db->prepare("INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, attivo) VALUES (?, ?, ?, ?, 'in_corso', 1)");
        $stmt->bind_param('iiss', $libroId, $utenteId, $data_prestito, $data_scadenza);
        if ($stmt->execute()) {
            $stmt->close();
            return $this->back($response, ['loan_success' => 1]);
        }
        $stmt->close();
        return $this->back($response, ['loan_error' => 'db']);
    }

    public function reserve(Request $request, Response $response, mysqli $db): Response
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user || empty($user['id'])) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }
        $data = (array)($request->getParsedBody() ?? []);
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            return $this->back($response, ['reserve_error' => 'csrf']);
        }
        $libroId = (int)($data['libro_id'] ?? 0);
        if ($libroId <= 0) {
            return $this->back($response, ['reserve_error' => 'invalid']);
        }
        $desired = trim((string)($data['desired_date'] ?? ''));
        if ($desired !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $desired)) {
            return $this->back($response, ['reserve_error' => 'invalid_date']);
        }
        if ($desired !== '' && strtotime($desired) < strtotime(date('Y-m-d'))) {
            return $this->back($response, ['reserve_error' => 'past_date']);
        }
        $utenteId = (int)$user['id'];
        // Check if already has an active reservation for this book
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE libro_id=? AND utente_id=? AND stato='attiva'");
        $stmt->bind_param('ii', $libroId, $utenteId); $stmt->execute();
        $exists = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0; $stmt->close();
        if ($exists) {
            return $this->back($response, ['reserve_error' => 'duplicate']);
        }
        // Calculate queue position
        $stmt = $db->prepare("SELECT COALESCE(MAX(queue_position),0)+1 AS pos FROM prenotazioni WHERE libro_id = ? AND stato = 'attiva'");
        $stmt->bind_param('i', $libroId);
        $stmt->execute();
        $res = $stmt->get_result();
        $pos = (int)($res->fetch_assoc()['pos'] ?? 1);
        $start = ($desired !== '') ? $desired : date('Y-m-d');
        $end   = date('Y-m-d', strtotime($start . ' +1 month'));
        $startDt = $start . ' 00:00:00';
        $endDt   = $end . ' 23:59:59';
        $stmt = $db->prepare("INSERT INTO prenotazioni (libro_id, utente_id, queue_position, stato, data_prenotazione, data_scadenza_prenotazione) VALUES (?, ?, ?, 'attiva', ?, ?)");
        $stmt->bind_param('iiiss', $libroId, $utenteId, $pos, $startDt, $endDt);
        if ($stmt->execute()) {
            $stmt->close();
            $params = ['reserve_success' => 1];
            if ($desired !== '') { $params['reserve_date'] = $desired; }
            return $this->back($response, $params);
        }
        $stmt->close();
        return $this->back($response, ['reserve_error' => 'db']);
    }

    private function back(Response $response, array $params): Response
    {
        $qs = http_build_query($params);
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';

        // Validate referer to prevent open redirect and header injection
        if (!$this->isValidReferer($referer)) {
            $referer = '/'; // Fallback to safe default
        }

        $sep = (str_contains($referer, '?') ? '&' : '?');
        return $response->withHeader('Location', $referer . $sep . $qs)->withStatus(302);
    }

    /**
     * Validate referer URL to prevent open redirect attacks
     */
    private function isValidReferer(string $referer): bool
    {
        // Check for CRLF injection
        if (strpos($referer, "\r") !== false || strpos($referer, "\n") !== false) {
            return false;
        }

        // Allow relative URLs starting with /
        if (str_starts_with($referer, '/') && !str_starts_with($referer, '//')) {
            return true;
        }

        // For absolute URLs, validate they're from the same host
        $parsedReferer = parse_url($referer);
        if (!$parsedReferer) {
            return false;
        }

        $currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return isset($parsedReferer['host']) && $parsedReferer['host'] === $currentHost;
    }
}
