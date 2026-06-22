<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;
use App\Support\Csrf;
use App\Support\RouteTranslator;
use App\Support\SecureLogger;

/**
 * CSRF Protection Middleware
 * Valida token CSRF su richieste POST/PUT/DELETE/PATCH
 */
class CsrfMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $method = $request->getMethod();

        // Applica protezione CSRF solo su metodi mutating
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            // Guard post_max_size: quando un POST supera post_max_size, PHP
            // scarta TUTTO il body (token CSRF incluso) prima che questo
            // middleware giri. Senza questo controllo l'utente vedrebbe la
            // fuorviante schermata "Errore di Sicurezza" invece della causa
            // reale. Lo intercettiamo e restituiamo un 413 chiaro che indica i
            // limiti di upload PHP del server (regressione manual-upgrade-real:
            // l'upgrade via UI admin carica uno ZIP da ~30 MB).
            if ($this->isPostBodyDiscardedBySizeLimit($request)) {
                return $this->payloadTooLargeResponse($request);
            }

            $token = null;

            // Cerca token in diversi posti
            $parsedBody = $request->getParsedBody();

            // Se il body non è parsato e il Content-Type è JSON, prova a parsarlo
            // Importante: dopo la lettura, rewind dello stream e propagazione del parsed body
            // per permettere ai downstream handler di accedere ai dati
            if (empty($parsedBody)) {
                $contentType = $request->getHeaderLine('Content-Type');
                if (strpos($contentType, 'application/json') !== false) {
                    $bodyRaw = (string) $request->getBody();
                    $decoded = json_decode($bodyRaw, true);
                    if (is_array($decoded)) {
                        $parsedBody = $decoded;
                        // Propaga il parsed body alla request per i downstream handlers
                        $request = $request->withParsedBody($parsedBody);
                    }
                    // Rewind dello stream per permettere letture successive
                    $request->getBody()->rewind();
                }
            }

            if (is_array($parsedBody) && isset($parsedBody['csrf_token'])) {
                $token = $parsedBody['csrf_token'];
            } else {
                // Prova header X-CSRF-Token (per AJAX)
                $headers = $request->getHeader('X-CSRF-Token');
                if (!empty($headers)) {
                    $token = $headers[0];
                }
            }

            // Valida token con dettaglio del motivo
            $csrfValidation = Csrf::validateWithReason($token);
            if (!$csrfValidation['valid']) {
                // Login con sessione scaduta/vuota: l'utente legittimo si sta
                // ri-autenticando dopo che la sessione server-side è scaduta.
                // Invece di saltare del tutto il CSRF (che esporrebbe a login-CSRF),
                // si valida col pattern double-submit cookie: il token del form
                // deve combaciare col cookie 'csrf_login' impostato quando la
                // pagina di login è stata servita. Il cookie è SameSite=Lax, quindi
                // una POST di login forgiata cross-site non lo trasporta → bloccata.
                if ($csrfValidation['reason'] === 'session_expired' && $this->isLoginRequest($request)) {
                    $cookieToken = $request->getCookieParams()['csrf_login'] ?? '';
                    if (
                        is_string($token) && $token !== ''
                        && is_string($cookieToken) && $cookieToken !== ''
                        && hash_equals($cookieToken, $token)
                    ) {
                        return $handler->handle($request);
                    }
                }

                SecureLogger::warning('[CSRF] Validation failed. Reason: ' . $csrfValidation['reason']);

                // Determina se è una richiesta AJAX o form tradizionale
                $isAjax = $this->isAjaxRequest($request);

                if ($isAjax) {
                    // Richiesta AJAX: restituisce JSON
                    $response = new SlimResponse(403);

                    if ($csrfValidation['reason'] === 'session_expired') {
                        $response->getBody()->write(json_encode([
                            'error' => __('La tua sessione è scaduta. Per motivi di sicurezza, ricarica la pagina ed effettua nuovamente l\'accesso'),
                            'code' => 'SESSION_EXPIRED',
                            'redirect' => RouteTranslator::route('login') . '?error=session_expired'
                        ], JSON_UNESCAPED_UNICODE));
                    } else {
                        $response->getBody()->write(json_encode([
                            'error' => __('Errore di sicurezza. Ricarica la pagina e riprova'),
                            'code' => 'CSRF_INVALID'
                        ], JSON_UNESCAPED_UNICODE));
                    }

                    return $response->withHeader('Content-Type', 'application/json');
                } else {
                    // Richiesta form tradizionale: mostra pagina HTML stilizzata.
                    // Passa il motivo alla view: una sessione realmente scaduta
                    // chiede il re-login, un semplice mismatch del token CSRF su
                    // una sessione ancora valida deve solo invitare a ricaricare e
                    // reinviare — senza la fuorviante schermata "Sessione Scaduta".
                    $response = new SlimResponse(403);

                    $csrfReason = $csrfValidation['reason'];

                    ob_start();
                    require __DIR__ . '/../Views/errors/session-expired.php';
                    $html = ob_get_clean();

                    $response->getBody()->write($html);
                    return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
                }
            }
        }

        // Passa la request (potenzialmente modificata con parsedBody) al handler
        return $handler->handle($request);
    }

    /**
     * Determina se la richiesta è il submit del form di login (rotta localizzata).
     * Confronta il path della richiesta con la rotta 'login' tradotta, tollerando
     * un eventuale base path (installazioni in sottocartella).
     */
    private function isLoginRequest(Request $request): bool
    {
        $path = $request->getUri()->getPath();

        // Normalize against the configured base path (sub-folder installs) so we
        // can require EXACT equality with the translated login route — an
        // unanchored suffix match would also accept e.g. /something/accedi.
        $basePath = rtrim(\App\Support\HtmlHelper::getBasePath(), '/');
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . trim($path, '/');
        $loginRoute = '/' . trim(RouteTranslator::route('login'), '/');

        return $loginRoute !== '/' && $path === $loginRoute;
    }

    /**
     * Determina se la richiesta è AJAX
     */
    private function isAjaxRequest(Request $request): bool
    {
        // Header X-Requested-With (jQuery e molti framework)
        $xRequestedWith = $request->getHeaderLine('X-Requested-With');
        if (strtolower($xRequestedWith) === 'xmlhttprequest') {
            return true;
        }

        // Accept header preferisce JSON
        $accept = $request->getHeaderLine('Accept');
        if (strpos($accept, 'application/json') !== false) {
            return true;
        }

        // Content-Type è JSON (tipico di fetch API)
        $contentType = $request->getHeaderLine('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            return true;
        }

        return false;
    }

    /**
     * True quando PHP ha scartato il body del POST perché eccede post_max_size.
     * In quel caso $_POST/$_FILES (e quindi il parsed body / gli uploaded files
     * PSR-7) arrivano vuoti pur con un Content-Length grande, e il token CSRF
     * è perso — va distinto da un vero fallimento CSRF per mostrare la causa
     * reale.
     */
    private function isPostBodyDiscardedBySizeLimit(Request $request): bool
    {
        $contentLength = (int) $request->getHeaderLine('Content-Length');
        if ($contentLength <= 0) {
            return false;
        }

        $postMax = $this->iniBytes((string) ini_get('post_max_size'));
        if ($postMax <= 0) {
            // 0 / non impostato = "nessun limite" in PHP — mai un errore di dimensione.
            return false;
        }
        if ($contentLength <= $postMax) {
            return false;
        }

        // Body davvero scartato: niente parsato e nessun file caricato.
        $parsedBody = $request->getParsedBody();
        $uploaded   = $request->getUploadedFiles();

        return empty($parsedBody) && empty($uploaded);
    }

    /**
     * Converte una dimensione shorthand di php.ini (es. "8M", "520M", "1G") in byte.
     */
    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower($value[strlen($value) - 1]);
        $num  = (int) $value;
        switch ($unit) {
            case 'g':
                $num *= 1024;
                // no break
            case 'm':
                $num *= 1024;
                // no break
            case 'k':
                $num *= 1024;
                // no break
        }
        return $num;
    }

    /**
     * Risposta 413 chiara per un upload oltre il limite — sostituisce la
     * fuorviante pagina CSRF/sessione-scaduta quando PHP ha scartato il body
     * per post_max_size.
     */
    private function payloadTooLargeResponse(Request $request): Response
    {
        $limit   = (string) ini_get('post_max_size');
        $message = __('Il file caricato supera il limite di caricamento del server (post_max_size = %s). Aumenta post_max_size e upload_max_filesize nella configurazione PHP del server e riprova. Su hosting con php-fpm o CGI le direttive php_value in .htaccess vengono ignorate: modifica php.ini o la configurazione del pool php-fpm.', $limit);

        SecureLogger::warning('[Upload] POST rifiutato: Content-Length supera post_max_size (' . $limit . ')');

        $response = new SlimResponse(413);

        if ($this->isAjaxRequest($request) || $this->isMultipartUpload($request)) {
            $response->getBody()->write((string) json_encode([
                'error'   => $message,
                'code'    => 'PAYLOAD_TOO_LARGE',
                'success' => false,
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $title = __('File troppo grande');
        $html  = '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:520px;margin:4rem auto;padding:0 1rem;color:#111827">'
            . '<h1 style="font-size:1.5rem">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p style="color:#6b7280;line-height:1.6">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</body></html>';
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * True quando la richiesta è un upload multipart/form-data.
     */
    private function isMultipartUpload(Request $request): bool
    {
        return stripos($request->getHeaderLine('Content-Type'), 'multipart/form-data') !== false;
    }
}
