<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use App\Support\RouteTranslator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UserWishlistController
{
    public function page(Request $request, Response $response, mysqli $db): Response
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user || empty($user['id'])) { return $response->withHeader('Location', RouteTranslator::route('login'))->withStatus(302); }
        $uid = (int)$user['id'];
        $sql = "SELECT l.id, l.titolo, l.copertina_url, l.copie_disponibili
                FROM wishlist w JOIN libri l ON l.id=w.libro_id
                WHERE w.utente_id=? ORDER BY w.id DESC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($r = $res->fetch_assoc()) { $items[] = $r; }
        $stmt->close();

        $title = 'I miei preferiti - Biblioteca';
        ob_start();
        require __DIR__ . '/../Views/profile/wishlist.php';
        $content = ob_get_clean();
        include __DIR__ . '/../Views/user_layout.php';
        return $response;
    }
    public function status(Request $request, Response $response, mysqli $db): Response
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user || empty($user['id'])) {
            $response->getBody()->write(json_encode(['favorite' => false]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        $q = $request->getQueryParams();
        $libroId = (int)($q['libro_id'] ?? 0);
        $fav = false;
        if ($libroId > 0) {
            $stmt = $db->prepare('SELECT 1 FROM wishlist WHERE utente_id=? AND libro_id=? LIMIT 1');
            $uid = (int)$user['id'];
            $stmt->bind_param('ii', $uid, $libroId);
            $stmt->execute();
            $fav = (bool)$stmt->get_result()->num_rows;
            $stmt->close();
        }
        $response->getBody()->write(json_encode(['favorite' => $fav]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function toggle(Request $request, Response $response, mysqli $db): Response
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user || empty($user['id'])) {
            return $response->withStatus(401);
        }
        $data = (array)($request->getParsedBody() ?? []);
        $token = (string)($data['csrf_token'] ?? '');
        if (!\App\Support\Csrf::validate($token)) {
            return $response->withStatus(400);
        }
        $libroId = (int)($data['libro_id'] ?? 0);
        if ($libroId <= 0) { return $response->withStatus(422); }
        $uid = (int)$user['id'];
        // Check current
        $stmt = $db->prepare('SELECT 1 FROM wishlist WHERE utente_id=? AND libro_id=? LIMIT 1');
        $stmt->bind_param('ii', $uid, $libroId); $stmt->execute();
        $exists = (bool)$stmt->get_result()->num_rows; $stmt->close();
        if ($exists) {
            $stmt = $db->prepare('DELETE FROM wishlist WHERE utente_id=? AND libro_id=?');
            $stmt->bind_param('ii', $uid, $libroId);
            $stmt->execute();
            $stmt->close();
            $payload = ['favorite' => false];
        } else {
            $stmt = $db->prepare('INSERT INTO wishlist (utente_id, libro_id) VALUES (?, ?)');
            $stmt->bind_param('ii', $uid, $libroId);
            $stmt->execute();
            $stmt->close();
            $payload = ['favorite' => true];
        }
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
