<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\SupplierRepository;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin CRUD for suppliers (`fornitori`), modelled on the publishers pages.
 */
class SuppliersController
{
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        $fornitori = (new SupplierRepository($db))->listWithCounts();
        return $this->render($response, 'index.php', ['fornitori' => $fornitori]);
    }

    public function createForm(Request $request, Response $response): Response
    {
        return $this->render($response, 'form.php', ['fornitore' => null]);
    }

    public function store(Request $request, Response $response, mysqli $db): Response
    {
        $data = SupplierRepository::normalize((array) $request->getParsedBody());
        if ($data['nome'] === null) {
            $_SESSION['error_message'] = __('Il nome del fornitore è obbligatorio.');
            return $response->withHeader('Location', url('/admin/suppliers/create'))->withStatus(302);
        }
        try {
            (new SupplierRepository($db))->create($data);
            $_SESSION['success_message'] = __('Fornitore creato con successo.');
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('SuppliersController::store error', ['exception' => get_class($e), 'code' => $e->getCode()]);
            $_SESSION['error_message'] = __('Impossibile salvare il fornitore. Riprova più tardi.');
        }
        return $response->withHeader('Location', url('/admin/suppliers'))->withStatus(302);
    }

    public function editForm(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $fornitore = (new SupplierRepository($db))->getById($id);
        if ($fornitore === null) {
            return $response->withStatus(404);
        }
        return $this->render($response, 'form.php', ['fornitore' => $fornitore]);
    }

    public function update(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $repo = new SupplierRepository($db);
        if ($repo->getById($id) === null) {
            return $response->withStatus(404);
        }
        $data = SupplierRepository::normalize((array) $request->getParsedBody());
        if ($data['nome'] === null) {
            $_SESSION['error_message'] = __('Il nome del fornitore è obbligatorio.');
            return $response->withHeader('Location', url('/admin/suppliers/edit/' . $id))->withStatus(302);
        }
        try {
            $repo->update($id, $data);
            $_SESSION['success_message'] = __('Fornitore aggiornato con successo.');
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('SuppliersController::update error', ['id' => $id, 'exception' => get_class($e), 'code' => $e->getCode()]);
            $_SESSION['error_message'] = __('Impossibile salvare il fornitore. Riprova più tardi.');
        }
        return $response->withHeader('Location', url('/admin/suppliers'))->withStatus(302);
    }

    public function delete(Request $request, Response $response, mysqli $db, int $id): Response
    {
        try {
            if ((new SupplierRepository($db))->delete($id)) {
                $_SESSION['success_message'] = __('Fornitore eliminato. I libri collegati non hanno più un fornitore.');
            } else {
                $_SESSION['error_message'] = __('Fornitore non trovato.');
            }
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('SuppliersController::delete error', ['id' => $id, 'exception' => get_class($e), 'code' => $e->getCode()]);
            $_SESSION['error_message'] = __('Impossibile eliminare il fornitore. Riprova più tardi.');
        }
        return $response->withHeader('Location', url('/admin/suppliers'))->withStatus(302);
    }

    /** @param array<string, mixed> $data */
    private function render(Response $response, string $view, array $data): Response
    {
        ob_start();
        require __DIR__ . '/../Views/fornitori/' . $view;
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write((string) $html);
        return $response;
    }
}
