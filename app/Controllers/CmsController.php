<?php

namespace App\Controllers;

use App\Support\ContentSanitizer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CmsController
{
    public function showPage(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        $slug = $args['slug'] ?? 'chi-siamo';

        // Recupera la pagina dal database
        $stmt = $db->prepare("
            SELECT id, slug, title, content, image, meta_description
            FROM cms_pages
            WHERE slug = ? AND is_active = 1
        ");
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $result = $stmt->get_result();
        $page = $result->fetch_assoc();
        $stmt->close();

        if (!$page) {
            $response->getBody()->write('Pagina non trovata');
            return $response->withStatus(404);
        }

        // Passa i dati alla view
        $title = $page['title'];
        $content = ContentSanitizer::normalizeExternalAssets($page['content'] ?? '');
        $image = $page['image'];
        $seoDescription = $page['meta_description'] ?? '';

        ob_start();
        include __DIR__ . '/../Views/frontend/cms-page.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function editHome(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        // Carica tutti i contenuti della home
        $stmt = $db->prepare("
            SELECT id, section_key, title, subtitle, content, button_text, button_link, background_image, is_active
            FROM home_content
            ORDER BY display_order ASC
        ");
        $stmt->execute();
        $result = $stmt->get_result();

        $sections = [];
        while ($row = $result->fetch_assoc()) {
            $sections[$row['section_key']] = $row;
        }
        $stmt->close();

        $title = 'Modifica Homepage - CMS';

        // Include the specific view first
        ob_start();
        include __DIR__ . '/../Views/cms/edit-home.php';
        $content = ob_get_clean();

        // Then include layout which uses $content
        ob_start();
        include __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function updateHome(Request $request, Response $response, \mysqli $db, array $args): Response
    {
        $data = $request->getParsedBody();
        $files = $request->getUploadedFiles();

        // SECURITY FIX: Validate CSRF first, before processing data
        if (!is_array($data) || !isset($data['csrf_token']) || !\App\Support\Csrf::validate($data['csrf_token'])) {
            $_SESSION['error_message'] = 'Token CSRF non valido. Riprova.';
            return $response->withHeader('Location', '/admin/cms/home')->withStatus(302);
        }

        $errors = [];

        // SECURITY: Define sanitization function to prevent XSS
        $sanitizeText = function($text) {
            // Strip any script tags
            $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $text);
            // Strip event handlers (onclick, onerror, etc.)
            $text = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $text);
            // Strip javascript: protocol
            $text = preg_replace('/javascript:/i', '', $text);
            return trim($text);
        };

        // SECURITY: Validate URL function
        $validateUrl = function($url) {
            $url = trim($url);
            if (empty($url)) {
                return true; // Empty URLs are allowed
            }
            // Allow only relative URLs starting with / or valid full URLs
            if (preg_match('/^\/[^\/]/', $url)) {
                return true; // Relative URL
            }
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                return true; // Valid full URL
            }
            return false;
        };

        // Hero section
        if (isset($data['hero'])) {
            $heroData = $data['hero'];

            // SECURITY: Sanitize all text inputs
            $heroData['title'] = $sanitizeText($heroData['title'] ?? '');
            $heroData['subtitle'] = $sanitizeText($heroData['subtitle'] ?? '');
            $heroData['button_text'] = $sanitizeText($heroData['button_text'] ?? '');

            // SECURITY: Validate button URL
            $buttonLink = trim($heroData['button_link'] ?? '');
            if (!empty($buttonLink) && !$validateUrl($buttonLink)) {
                $errors[] = 'Il link del pulsante non è valido. Usa un URL relativo (es. /catalogo) o un URL completo valido.';
            }
            $heroData['button_link'] = $buttonLink;

            $bgImagePath = null;

            // SECURITY: Enhanced file upload validation
            if (isset($files['hero_background']) && $files['hero_background']->getError() === UPLOAD_ERR_OK) {
                $uploadedFile = $files['hero_background'];
                $filename = $uploadedFile->getClientFilename();
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                // SECURITY: Validate file extension
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
                if (!in_array($extension, $allowedExtensions)) {
                    $errors[] = 'Formato immagine non supportato. Usa JPG, PNG o WebP.';
                } else {
                    // SECURITY: Validate file size (max 5MB)
                    if ($uploadedFile->getSize() > 5 * 1024 * 1024) {
                        $errors[] = 'L\'immagine è troppo grande. Max 5MB.';
                    } else {
                        // SECURITY: Validate MIME type with magic number check
                        $tmpPath = $uploadedFile->getStream()->getMetadata('uri');
                        $finfo = new \finfo(FILEINFO_MIME_TYPE);
                        $mimeType = $finfo->file($tmpPath);

                        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
                        if (!in_array($mimeType, $allowedMimes)) {
                            $errors[] = 'Tipo di file non valido. Il file deve essere un\'immagine reale.';
                        } else {
                            // SECURITY: Secure path handling to prevent directory traversal
                            $baseDir = realpath(__DIR__ . '/../../public/uploads');
                            if ($baseDir === false) {
                                error_log("Upload base directory not found");
                                $errors[] = 'Errore di configurazione directory upload.';
                            } else {
                                $targetDir = $baseDir . '/assets';

                                // Create directory if it doesn't exist
                                if (!is_dir($targetDir)) {
                                    mkdir($targetDir, 0755, true);
                                }

                                // SECURITY: Generate cryptographically secure random filename
                                try {
                                    $randomSuffix = bin2hex(random_bytes(8));
                                } catch (\Exception $e) {
                                    error_log("CRITICAL: random_bytes() failed - system entropy exhausted");
                                    $errors[] = 'Errore di sistema. Riprova più tardi.';
                                }

                                if (empty($errors)) {
                                    $newFilename = 'hero_bg_' . $randomSuffix . '.' . $extension;
                                    // Sanitize filename to prevent null byte injection
                                    $newFilename = str_replace("\\0", '', $newFilename);
                                    $uploadPath = $targetDir . '/' . basename($newFilename);

                                    // SECURITY: Verify final path is within allowed directory
                                    $realUploadPath = realpath(dirname($uploadPath));
                                    if ($realUploadPath === false || strpos($realUploadPath, $baseDir) !== 0) {
                                        error_log("Path traversal attempt detected");
                                        $errors[] = 'Percorso file non valido.';
                                    } else {
                                        try {
                                            $uploadedFile->moveTo($uploadPath);
                                            // SECURITY: Set secure file permissions
                                            @chmod($uploadPath, 0644);
                                            $bgImagePath = '/uploads/assets/' . $newFilename;
                                        } catch (\Exception $e) {
                                            error_log("Image upload error: " . $e->getMessage());
                                            $errors[] = 'Errore durante l\'upload dell\'immagine. Riprova.';
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if (empty($errors)) {
                // UPSERT: Insert if not exists, update if exists
                $backgroundImage = null;
                if (isset($heroData['remove_background']) && $heroData['remove_background'] == '1') {
                    $backgroundImage = null;
                } elseif ($bgImagePath) {
                    $backgroundImage = $bgImagePath;
                }

                $stmt = $db->prepare("
                    INSERT INTO home_content (section_key, title, subtitle, button_text, button_link, background_image, is_active, display_order)
                    VALUES ('hero', ?, ?, ?, ?, ?, 1, -2)
                    ON DUPLICATE KEY UPDATE
                        title = VALUES(title),
                        subtitle = VALUES(subtitle),
                        button_text = VALUES(button_text),
                        button_link = VALUES(button_link),
                        background_image = IF(VALUES(background_image) IS NOT NULL OR ? = 1, VALUES(background_image), background_image)
                ");
                $removeBackground = isset($heroData['remove_background']) && $heroData['remove_background'] == '1' ? 1 : 0;
                $stmt->bind_param('sssssi',
                    $heroData['title'],
                    $heroData['subtitle'],
                    $heroData['button_text'],
                    $heroData['button_link'],
                    $backgroundImage,
                    $removeBackground
                );
                $stmt->execute();
                $stmt->close();
            }
        }

        // Features title
        if (isset($data['features_title']) && empty($errors)) {
            $featuresTitle = $data['features_title'];
            // SECURITY: Sanitize inputs
            $title = $sanitizeText($featuresTitle['title'] ?? '');
            $subtitle = $sanitizeText($featuresTitle['subtitle'] ?? '');
            $isActive = isset($featuresTitle['is_active']) ? 1 : 0;

            // UPSERT: Insert if not exists, update if exists
            $stmt = $db->prepare("
                INSERT INTO home_content (section_key, title, subtitle, is_active, display_order)
                VALUES ('features_title', ?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    subtitle = VALUES(subtitle),
                    is_active = VALUES(is_active)
            ");
            $stmt->bind_param('ssi', $title, $subtitle, $isActive);
            $stmt->execute();
            $stmt->close();
        }

        // Features 1-4
        for ($i = 1; $i <= 4; $i++) {
            $key = "feature_{$i}";
            if (isset($data[$key]) && empty($errors)) {
                $feature = $data[$key];
                // SECURITY: Sanitize inputs
                $title = $sanitizeText($feature['title'] ?? '');
                $subtitle = $sanitizeText($feature['subtitle'] ?? '');
                // Content contains FontAwesome class, sanitize to allow only valid classes
                $content = trim($feature['content'] ?? '');
                if (!preg_match('/^(fa[sbrldt]?\s+fa-[\w-]+(\s+fa-[\w-]+)*)$/i', $content)) {
                    $content = 'fas fa-star'; // Default fallback
                }

                // UPSERT: Insert if not exists, update if exists
                $stmt = $db->prepare("
                    INSERT INTO home_content (section_key, title, subtitle, content, is_active, display_order)
                    VALUES (?, ?, ?, ?, 1, ?)
                    ON DUPLICATE KEY UPDATE
                        title = VALUES(title),
                        subtitle = VALUES(subtitle),
                        content = VALUES(content)
                ");
                $stmt->bind_param('ssssi', $key, $title, $subtitle, $content, $i);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Latest books title
        if (isset($data['latest_books_title']) && empty($errors)) {
            $latestBooks = $data['latest_books_title'];
            // SECURITY: Sanitize inputs
            $title = $sanitizeText($latestBooks['title'] ?? '');
            $subtitle = $sanitizeText($latestBooks['subtitle'] ?? '');
            $isActive = isset($latestBooks['is_active']) ? 1 : 0;

            // UPSERT: Insert if not exists, update if exists
            $stmt = $db->prepare("
                INSERT INTO home_content (section_key, title, subtitle, is_active, display_order)
                VALUES ('latest_books_title', ?, ?, ?, 5)
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    subtitle = VALUES(subtitle),
                    is_active = VALUES(is_active)
            ");
            $stmt->bind_param('ssi', $title, $subtitle, $isActive);
            $stmt->execute();
            $stmt->close();
        }

        // Text content section
        if (isset($data['text_content']) && empty($errors)) {
            $textContent = $data['text_content'];
            // SECURITY: Sanitize inputs
            $title = $sanitizeText($textContent['title'] ?? '');
            $content = $textContent['content'] ?? ''; // TinyMCE content - sanitized by TinyMCE
            $isActive = isset($textContent['is_active']) ? 1 : 0;

            // UPSERT: Insert if not exists, update if exists
            $stmt = $db->prepare("
                INSERT INTO home_content (section_key, title, content, is_active, display_order)
                VALUES ('text_content', ?, ?, ?, 4)
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    content = VALUES(content),
                    is_active = VALUES(is_active)
            ");
            $stmt->bind_param('ssi', $title, $content, $isActive);
            $stmt->execute();
            $stmt->close();
        }

        // CTA section
        if (isset($data['cta']) && empty($errors)) {
            $cta = $data['cta'];
            // SECURITY: Sanitize inputs
            $title = $sanitizeText($cta['title'] ?? '');
            $subtitle = $sanitizeText($cta['subtitle'] ?? '');
            $buttonText = $sanitizeText($cta['button_text'] ?? '');
            $buttonLink = trim($cta['button_link'] ?? '');
            $isActive = isset($cta['is_active']) ? 1 : 0;

            // SECURITY: Validate CTA button URL
            if (!empty($buttonLink) && !$validateUrl($buttonLink)) {
                $errors[] = 'Il link del pulsante CTA non è valido.';
            } else {
                // UPSERT: Insert if not exists, update if exists
                $stmt = $db->prepare("
                    INSERT INTO home_content (section_key, title, subtitle, button_text, button_link, is_active, display_order)
                    VALUES ('cta', ?, ?, ?, ?, ?, 6)
                    ON DUPLICATE KEY UPDATE
                        title = VALUES(title),
                        subtitle = VALUES(subtitle),
                        button_text = VALUES(button_text),
                        button_link = VALUES(button_link),
                        is_active = VALUES(is_active)
                ");
                $stmt->bind_param('ssssi', $title, $subtitle, $buttonText, $buttonLink, $isActive);
                $stmt->execute();
                $stmt->close();
            }
        }

        if (!empty($errors)) {
            $_SESSION['error_message'] = implode('<br>', $errors);
        } else {
            $_SESSION['success_message'] = 'Contenuti homepage aggiornati con successo!';
        }

        return $response->withHeader('Location', '/admin/cms/home')->withStatus(302);
    }
}
