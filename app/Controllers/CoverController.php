<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CoverController
{
    /**
     * Get the uploads directory path for covers
     * Consistent with LibriController::getCoversUploadPath()
     */
    private function getCoversUploadPath(): string
    {
        return __DIR__ . '/../../public/uploads/copertine';
    }

    /**
     * Get the relative URL path for covers (from web root)
     * Consistent with LibriController::getCoversUrlPath()
     */
    private function getCoversUrlPath(): string
    {
        return '/uploads/copertine';
    }

    public function download(Request $request, Response $response): Response
    {
        // Get raw input data
        $input = $request->getBody()->getContents();
        $data = json_decode($input, true);

        // If JSON decode failed, try to get parsed body
        if ($data === null) {
            $data = (array) $request->getParsedBody();
        }

        $coverUrl = trim($data['cover_url'] ?? '');
        // CSRF validated by CsrfMiddleware

        // Validate input
        if (empty($coverUrl)) {
            $response->getBody()->write(json_encode(['error' => __('Parametro cover_url mancante.')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        if (!filter_var($coverUrl, FILTER_VALIDATE_URL)) {
            $response->getBody()->write(json_encode(['error' => __('URL non valido.')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $initialUrl = $this->assertUrlAllowed($coverUrl);
        } catch (\RuntimeException $e) {
            // Log detailed error internally but don't expose to client
            error_log("Cover URL validation failed: " . $e->getMessage());
            $response->getBody()->write(json_encode(['error' => __('URL non valido o non permesso.')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Log sanitized request data once URL validated
        $parsedUrl = parse_url($initialUrl);
        \App\Support\SecureLogger::info('Cover download request', [
            'url' => $initialUrl,
            'domain' => $parsedUrl['host'] ?? ''
        ]);

        // Set upload directory (consistent with LibriController)
        $uploadDir = $this->getCoversUploadPath() . '/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                $response->getBody()->write(json_encode(['error' => __('Impossibile creare la cartella di upload.')]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
        }
        try {
            [$imageData] = $this->downloadCover($initialUrl);
        } catch (\RuntimeException $e) {
            // Log detailed error internally but don't expose to client
            error_log("Cover download failed: " . $e->getMessage());
            $response->getBody()->write(json_encode(['error' => __('Errore nel download della copertina.')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(502);
        }

        if (strlen($imageData) > 5 * 1024 * 1024) {
            $response->getBody()->write(json_encode(['error' => __('File troppo grande. Dimensione massima 5MB.')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $imageInfo = @getimagesizefromstring($imageData);
        if ($imageInfo === false) {
            $response->getBody()->write(json_encode(['error' => __('File non valido o corrotto.')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Validate dimensions/pixel count BEFORE decoding to prevent OOM/DoS
        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];
        $maxPixels = 20_000_000; // ~20MP - adjust based on server memory limits
        if ($width <= 0 || $height <= 0 || ($width * $height) > $maxPixels) {
            $response->getBody()->write(json_encode(['error' => __('Immagine troppo grande da processare.')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $mimeType = $imageInfo['mime'];
        $extension = match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            default => null,
        };

        if ($extension === null) {
            $response->getBody()->write(json_encode(['error' => __('Tipo di file non supportato. Solo JPEG e PNG sono consentiti.')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $image = imagecreatefromstring($imageData);
        if ($image === false) {
            $response->getBody()->write(json_encode(['error' => __('Impossibile processare l\'immagine.')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $maxWidth = 2000;
        $maxHeight = 2000;
        // width and height already extracted and validated above
        $targetResource = $image;
        if ($width > $maxWidth || $height > $maxHeight) {
            $ratio = min($maxWidth / $width, $maxHeight / $height);
            $newWidth = max(1, (int) round($width * $ratio));
            $newHeight = max(1, (int) round($height * $ratio));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            if ($resized === false) {
                imagedestroy($image);
                throw new \RuntimeException(__('Impossibile creare l\'immagine ridimensionata.'));
            }
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $targetResource = $resized;
        }

        $filename = uniqid('copertina_', true) . '.' . $extension;
        // Sanitize filename to prevent null byte injection (uniqid() is safe, but defensive check)
        $filename = str_replace("\0", '', $filename);
        $filepath = $uploadDir . $filename;

        $saveResult = match ($extension) {
            'png' => imagepng($targetResource, $filepath, 9),
            default => imagejpeg($targetResource, $filepath, 85),
        };

        imagedestroy($targetResource);

        if (!$saveResult) {
            $response->getBody()->write(json_encode(['error' => __('Errore nel salvataggio dell\'immagine.')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        // Set proper file permissions
        chmod($filepath, 0644);

        // Build relative URL of the saved file (consistent with LibriController)
        $fileUrl = $this->getCoversUrlPath() . '/' . $filename;

        $response->getBody()->write(json_encode(['file_url' => $fileUrl]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Download cover from URL without PSR-7 dependencies
     * Can be called directly from other controllers
     *
     * @param string $coverUrl The URL of the cover to download
     * @return array{filename: string, file_url: string} The saved cover filename and URL
     * @throws \RuntimeException If download or processing fails
     */
    public function downloadFromUrl(string $coverUrl): array
    {
        // Validation
        if (empty($coverUrl)) {
            throw new \RuntimeException(__('Parametro cover_url mancante.'));
        }

        if (!filter_var($coverUrl, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException(__('URL non valido.'));
        }

        $initialUrl = $this->assertUrlAllowed($coverUrl);

        // Log sanitized request data once URL validated
        $parsedUrl = parse_url($initialUrl);
        \App\Support\SecureLogger::info('Cover download request', [
            'url' => $initialUrl,
            'domain' => $parsedUrl['host'] ?? ''
        ]);

        // Set upload directory (consistent with LibriController)
        $uploadDir = $this->getCoversUploadPath() . '/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new \RuntimeException(__('Impossibile creare la cartella di upload.'));
            }
        }

        [$imageData] = $this->downloadCover($initialUrl);

        if (strlen($imageData) > 5 * 1024 * 1024) {
            throw new \RuntimeException(__('File troppo grande. Dimensione massima 5MB.'));
        }

        $imageInfo = @getimagesizefromstring($imageData);
        if ($imageInfo === false) {
            throw new \RuntimeException(__('File non valido o corrotto.'));
        }

        // Validate dimensions/pixel count BEFORE decoding to prevent OOM/DoS
        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];
        $maxPixels = 20_000_000; // ~20MP - adjust based on server memory limits
        if ($width <= 0 || $height <= 0 || ($width * $height) > $maxPixels) {
            throw new \RuntimeException(__('Immagine troppo grande da processare.'));
        }

        $mimeType = $imageInfo['mime'];
        $extension = match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            default => null,
        };

        if ($extension === null) {
            throw new \RuntimeException(__('Tipo di file non supportato. Solo JPEG e PNG sono consentiti.'));
        }

        $image = imagecreatefromstring($imageData);
        if ($image === false) {
            throw new \RuntimeException(__('Impossibile processare l\'immagine.'));
        }

        $maxWidth = 2000;
        $maxHeight = 2000;
        // width and height already extracted and validated above
        $targetResource = $image;
        if ($width > $maxWidth || $height > $maxHeight) {
            $ratio = min($maxWidth / $width, $maxHeight / $height);
            $newWidth = max(1, (int) round($width * $ratio));
            $newHeight = max(1, (int) round($height * $ratio));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            if ($resized === false) {
                imagedestroy($image);
                throw new \RuntimeException(__('Impossibile creare l\'immagine ridimensionata.'));
            }
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $targetResource = $resized;
        }

        $filename = uniqid('copertina_', true) . '.' . $extension;
        // Sanitize filename to prevent null byte injection (uniqid() is safe, but defensive check)
        $filename = str_replace("\0", '', $filename);
        $filepath = $uploadDir . $filename;

        $saveResult = match ($extension) {
            'png' => imagepng($targetResource, $filepath, 9),
            default => imagejpeg($targetResource, $filepath, 85),
        };

        imagedestroy($targetResource);

        if (!$saveResult) {
            throw new \RuntimeException(__('Errore nel salvataggio dell\'immagine.'));
        }

        // Set proper file permissions
        chmod($filepath, 0644);

        // Build relative URL of the saved file (consistent with LibriController)
        $fileUrl = $this->getCoversUrlPath() . '/' . $filename;

        return [
            'filename' => $filename,
            'file_url' => $fileUrl
        ];
    }

    /**
     * Ensure the URL uses HTTPS and resolves to public IPs only. The host allow-list
     * was removed (issue #173); the boundary is assertPublicDns() + per-hop IP pinning
     * in downloadCover(), which is what actually prevents reaching internal services.
     */
    private function assertUrlAllowed(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('URL malformato');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new \RuntimeException('Solo HTTPS consentito');
        }

        $host = strtolower($parts['host']);

        // Issue #173: the exact-host allow-list kept blocking legitimate covers whose
        // CDN host is dynamic (covers.openlibrary.org 302s to archive.org /
        // iaNNNNNN.us.archive.org). We trust any public HTTPS host here; the real SSRF
        // boundary is assertPublicDns() below, which rejects private/reserved IPs (and
        // is re-checked on every redirect hop by downloadCover()).
        if ($host === '') {
            throw new \RuntimeException('URL malformato');
        }

        $this->assertPublicDns($host);

        return $url;
    }

    /**
     * Download remote cover following safe redirects.
     *
     * @return array{0:string,1:string}
     */
    private function downloadCover(string $url): array
    {
        $currentUrl = $url;
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            $this->assertUrlAllowed($currentUrl);

            // Pin the connection to the validated public IP: assertPublicDns() resolves
            // and validates, but cURL would re-resolve on connect — a DNS rebind in
            // between could reach a different (internal) address. CURLOPT_RESOLVE stops
            // that by forcing cURL to use the exact IP we just checked, per hop.
            $pinHost = strtolower((string) (parse_url($currentUrl, PHP_URL_HOST) ?? ''));
            $pinIp = \App\Support\SsrfGuard::resolvePinnedIp($pinHost);
            if ($pinIp === null) {
                throw new \RuntimeException('Host non risolvibile a un IP pubblico');
            }

            $ch = curl_init($currentUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_RESOLVE => ["$pinHost:443:$pinIp", "$pinHost:80:$pinIp"],
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'BibliotecaCoverBot/1.0',
            ]);

            $rawResponse = curl_exec($ch);
            if ($rawResponse === false) {
                $err = curl_error($ch) ?: 'Errore sconosciuto';
                curl_close($ch);
                throw new \RuntimeException('Errore download immagine: ' . $err);
            }

            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($rawResponse, 0, $headerSize);
            $body = substr($rawResponse, $headerSize);
            curl_close($ch);

            if ($status >= 300 && $status < 400) {
                if ($redirects === 3) {
                    throw new \RuntimeException('Troppe redirezioni durante il download');
                }
                $location = $this->extractRedirectLocation($headers);
                if ($location === null) {
                    throw new \RuntimeException('Redirezione priva di header Location');
                }
                $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
                continue;
            }

            if ($status < 200 || $status >= 400) {
                throw new \RuntimeException('Download fallito con status HTTP ' . $status);
            }

            return [$body, $currentUrl];
        }

        throw new \RuntimeException('Troppe redirezioni durante il download');
    }

    private function extractRedirectLocation(string $headers): ?string
    {
        if (preg_match('/^Location:\s*(.+)$/im', $headers, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            throw new \RuntimeException('Redirezione non valida');
        }

        if (filter_var($location, FILTER_VALIDATE_URL)) {
            $this->assertUrlAllowed($location);
            return $location;
        }

        if ($location[0] === '#') {
            throw new \RuntimeException('Redirezione non consentita');
        }

        if (str_contains($location, '..')) {
            throw new \RuntimeException('Redirezione non valida');
        }

        $baseParts = parse_url($baseUrl);
        if (!$baseParts || !isset($baseParts['scheme'], $baseParts['host'])) {
            throw new \RuntimeException('URL di base non valido');
        }

        $path = $baseParts['path'] ?? '/';
        if ($location[0] === '/') {
            $newPath = $location;
        } else {
            $lastSlash = strrpos($path, '/');
            $dir = $lastSlash === false ? '/' : substr($path, 0, $lastSlash + 1);
            if ($dir === '') {
                $dir = '/';
            }
            $newPath = rtrim($dir, '/') . '/' . ltrim($location, '/');
        }

        $url = $baseParts['scheme'] . '://' . $baseParts['host'];
        if (!empty($baseParts['port'])) {
            $url .= ':' . $baseParts['port'];
        }
        return rtrim($url, '/') . '/' . ltrim($newPath, '/');
    }

    private function assertPublicDns(string $host): void
    {
        $ips = gethostbynamel($host) ?: [];
        $aaaaRecords = dns_get_record($host, DNS_AAAA) ?: [];

        if (!$ips && !$aaaaRecords) {
            throw new \RuntimeException('Impossibile risolvere il dominio richiesto');
        }

        foreach ($ips as $ip) {
            if (!\App\Support\SsrfGuard::isPublicIp($ip)) {
                throw new \RuntimeException('IP privato non consentito');
            }
        }

        foreach ($aaaaRecords as $record) {
            $ipv6 = $record['ipv6'] ?? null;
            if ($ipv6 && !\App\Support\SsrfGuard::isPublicIp((string) $ipv6)) {
                throw new \RuntimeException('IP privato non consentito');
            }
        }
    }

    // NOTE (issue #173): the previous exact-host ALLOWED_DOMAINS list was removed.
    // It blocked legitimate covers whose CDN host is dynamic (covers.openlibrary.org
    // 302s to archive.org / iaNNNNNN.us.archive.org). Cover fetches now accept any
    // public HTTPS host; SSRF is bounded by assertPublicDns() (private/reserved IPs
    // rejected) and re-checked on every redirect hop in downloadCover().
}
