<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\RecensioniRepository;
use App\Support\Branding;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FrontendController
{
    public function home(Request $request, Response $response, mysqli $db): Response
    {
        // Carica i contenuti CMS della home (inclusi campi SEO completi)
        $homeContent = [];
        $query_home = "SELECT section_key, title, subtitle, content, button_text, button_link, background_image,
                              seo_title, seo_description, seo_keywords, og_image,
                              og_title, og_description, og_type, og_url,
                              twitter_card, twitter_title, twitter_description, twitter_image,
                              is_active
                       FROM home_content
                       WHERE is_active = 1
                       ORDER BY display_order ASC";
        $result_home = $db->query($query_home);

        if ($result_home) {
            while ($row = $result_home->fetch_assoc()) {
                $homeContent[$row['section_key']] = $row;
            }
        }

        // Query per gli ultimi 10 libri inseriti
        $query_slider = "
            SELECT l.*,
                   (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore,
                   g.nome AS genere
            FROM libri l
            LEFT JOIN generi g ON l.genere_id = g.id
            ORDER BY l.created_at DESC
            LIMIT 10
        ";
        $result_slider = $db->query($query_slider);
        $latest_books = [];

        if ($result_slider) {
            while ($book = $result_slider->fetch_assoc()) {
                $latest_books[] = $book;
            }
        }

        // Costruisci i caroselli partendo dai generi radice (parent_id NULL)
        $genres_with_books = [];
        $allGenres = [];
        $childrenByParent = [];

        $resultAllGenres = $db->query("SELECT id, nome, parent_id FROM generi");
        if ($resultAllGenres) {
            while ($genreRow = $resultAllGenres->fetch_assoc()) {
                $genreRow['id'] = (int)$genreRow['id'];
                $genreRow['parent_id'] = $genreRow['parent_id'] !== null ? (int)$genreRow['parent_id'] : null;
                $allGenres[$genreRow['id']] = $genreRow;

                if ($genreRow['parent_id'] !== null) {
                    $parentId = $genreRow['parent_id'];
                    if (!isset($childrenByParent[$parentId])) {
                        $childrenByParent[$parentId] = [];
                    }
                    $childrenByParent[$parentId][] = $genreRow['id'];
                }
            }
        }

        if (!empty($allGenres)) {
            $rootGenres = array_filter($allGenres, static function ($genre) {
                return $genre['parent_id'] === null;
            });

            usort($rootGenres, static function ($a, $b) {
                return strcmp($a['nome'], $b['nome']);
            });

            foreach ($rootGenres as $rootGenre) {
                $genreIds = $this->collectGenreTreeIds($childrenByParent, (int)$rootGenre['id']);

                if (empty($genreIds)) {
                    continue;
                }

                $genreIdList = implode(',', array_map('intval', array_unique($genreIds)));
                $query_genre_books = "
                    SELECT l.*,
                           (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                            WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore
                    FROM libri l
                    WHERE l.genere_id IN ({$genreIdList})
                    ORDER BY l.created_at DESC
                    LIMIT 4
                ";
                $result_genre_books = $db->query($query_genre_books);

                if ($result_genre_books && $result_genre_books->num_rows > 0) {
                    $genre_books = [];
                    while ($book = $result_genre_books->fetch_assoc()) {
                        $genre_books[] = $book;
                    }

                    $genres_with_books[] = [
                        'genre' => $rootGenre,
                        'books' => $genre_books
                    ];
                }
            }
        }

        $genreCarouselEnabled = $this->isHomeSectionEnabled($db, 'genre_carousel');

        // Build dynamic SEO data from settings and CMS
        $hero = $homeContent['hero'] ?? [];

        // Fetch app settings for SEO fallbacks
        $appName = \App\Support\ConfigStore::get('app.name', 'Pinakes');
        $footerDescription = \App\Support\ConfigStore::get('app.footer_description', '');
        $appLogo = Branding::logo();

        // Build base URL and protocol
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $protocol . '://' . $host;
        $baseUrlNormalized = rtrim($baseUrl, '/');
        $makeAbsolute = static function (string $path) use ($baseUrlNormalized): string {
            if ($path === '') {
                return '';
            }

            if (preg_match('/^https?:\\/\\//i', $path)) {
                return $path;
            }

            return $baseUrlNormalized . '/' . ltrim($path, '/');
        };
        $seoCanonical = $baseUrl . '/';
        $brandLogoUrl = $appLogo !== '' ? $makeAbsolute($appLogo) : '';
        $defaultSocialImage = $makeAbsolute(Branding::socialImage());

        // === Basic SEO Meta Tags ===

        // SEO Title (priority: custom SEO title > hero title > app name)
        $seoTitle = !empty($hero['seo_title']) ? $hero['seo_title'] :
                    (!empty($hero['title']) ? $hero['title'] . ' - ' . $appName : $appName);

        // SEO Description (priority: custom SEO description > hero subtitle > footer description > default)
        $seoDescription = !empty($hero['seo_description']) ? $hero['seo_description'] :
                         (!empty($hero['subtitle']) ? $hero['subtitle'] :
                          ($footerDescription ?: __('Esplora il nostro vasto catalogo di libri, prenota i tuoi titoli preferiti e scopri nuove letture')));

        // SEO Keywords (custom or defaults)
        $seoKeywords = !empty($hero['seo_keywords']) ? $hero['seo_keywords'] :
                       __('biblioteca, prestito libri, catalogo online, scopri libri, prenotazioni');

        // === Open Graph Meta Tags ===

        // OG Title (priority: custom og_title > seo_title > hero title > app name)
        $ogTitle = !empty($hero['og_title']) ? $hero['og_title'] :
                   (!empty($hero['seo_title']) ? $hero['seo_title'] :
                   (!empty($hero['title']) ? $hero['title'] : $appName));

        // OG Description (priority: custom og_description > seo_description > hero subtitle > footer description > default)
        $ogDescription = !empty($hero['og_description']) ? $hero['og_description'] :
                        (!empty($hero['seo_description']) ? $hero['seo_description'] :
                        (!empty($hero['subtitle']) ? $hero['subtitle'] :
                         ($footerDescription ?: __('Esplora il nostro vasto catalogo di libri, prenota i tuoi titoli preferiti e scopri nuove letture'))));

        // OG Type (priority: custom og_type > default 'website')
        $ogType = !empty($hero['og_type']) ? $hero['og_type'] : 'website';

        // OG URL (priority: custom og_url > canonical URL)
        $ogUrl = !empty($hero['og_url']) ? $hero['og_url'] : $seoCanonical;

        // OG Image (priority: custom og_image > hero background > app logo > default cover)
        $ogImage = $defaultSocialImage;
        if (!empty($hero['og_image'])) {
            $ogImage = $makeAbsolute($hero['og_image']);
        } elseif (!empty($hero['background_image'])) {
            $ogImage = $makeAbsolute($hero['background_image']);
        } elseif ($brandLogoUrl !== '') {
            $ogImage = $brandLogoUrl;
        }

        // Keep $seoImage as alias for backward compatibility
        $seoImage = $ogImage;

        // === Twitter Card Meta Tags ===

        // Twitter Card Type (priority: custom twitter_card > default 'summary_large_image')
        $twitterCard = !empty($hero['twitter_card']) ? $hero['twitter_card'] : 'summary_large_image';

        // Twitter Title (priority: custom twitter_title > og_title > seo_title > hero title > app name)
        $twitterTitle = !empty($hero['twitter_title']) ? $hero['twitter_title'] :
                       (!empty($hero['og_title']) ? $hero['og_title'] :
                       (!empty($hero['seo_title']) ? $hero['seo_title'] :
                       (!empty($hero['title']) ? $hero['title'] : $appName)));

        // Twitter Description (priority: custom twitter_description > og_description > seo_description > hero subtitle > footer description > default)
        $twitterDescription = !empty($hero['twitter_description']) ? $hero['twitter_description'] :
                             (!empty($hero['og_description']) ? $hero['og_description'] :
                             (!empty($hero['seo_description']) ? $hero['seo_description'] :
                             (!empty($hero['subtitle']) ? $hero['subtitle'] :
                              ($footerDescription ?: __('Esplora il nostro vasto catalogo di libri, prenota i tuoi titoli preferiti e scopri nuove letture')))));

        // Twitter Image (priority: custom twitter_image > og_image > hero background > app logo > default cover)
        $twitterImage = $defaultSocialImage;
        if (!empty($hero['twitter_image'])) {
            $twitterImage = $makeAbsolute($hero['twitter_image']);
        } elseif (!empty($hero['og_image'])) {
            $twitterImage = $makeAbsolute($hero['og_image']);
        } elseif (!empty($hero['background_image'])) {
            $twitterImage = $makeAbsolute($hero['background_image']);
        } elseif ($brandLogoUrl !== '') {
            $twitterImage = $brandLogoUrl;
        }

        // Social media links
        $socialFacebook = \App\Support\ConfigStore::get('app.social_facebook', '');
        $socialTwitter = \App\Support\ConfigStore::get('app.social_twitter', '');
        $socialInstagram = \App\Support\ConfigStore::get('app.social_instagram', '');
        $socialLinkedin = \App\Support\ConfigStore::get('app.social_linkedin', '');

        // Build Schema.org structured data
        $schemaOrg = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $appName,
            'url' => $baseUrl,
            'description' => $seoDescription,
        ];

        // Add search action if applicable
        $schemaOrg['potentialAction'] = [
            '@type' => 'SearchAction',
            'target' => [
                '@type' => 'EntryPoint',
                'urlTemplate' => $baseUrl . '/catalogo?q={search_term_string}'
            ],
            'query-input' => 'required name=search_term_string'
        ];

        // Add organization schema if logo exists
        if ($brandLogoUrl !== '') {
            $logoUrl = $brandLogoUrl;

            $orgSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => $appName,
                'url' => $baseUrl,
                'logo' => $logoUrl,
            ];

            // Add social media profiles
            $sameAs = [];
            if ($socialFacebook) $sameAs[] = $socialFacebook;
            if ($socialTwitter) $sameAs[] = $socialTwitter;
            if ($socialInstagram) $sameAs[] = $socialInstagram;
            if ($socialLinkedin) $sameAs[] = $socialLinkedin;

            if (!empty($sameAs)) {
                $orgSchema['sameAs'] = $sameAs;
            }

            // Combine schemas
            $seoSchema = json_encode([$schemaOrg, $orgSchema], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        } else {
            $seoSchema = json_encode($schemaOrg, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        // Render template
        ob_start();
        include __DIR__ . '/../Views/frontend/home.php';
        $content = ob_get_clean();

        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function catalog(Request $request, Response $response, mysqli $db): Response
    {
        $params = $request->getQueryParams();

        // Parametri di paginazione
        $limit = 12;
        $page = max(1, (int)($params['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        // Filtri
        $filters = $this->getFilters($params);
        $where_conditions = $this->buildWhereConditions($filters, $db);
        $query_params = $where_conditions['params'];
        $param_types = $where_conditions['types'];

        // Query base senza JOIN con autori per evitare duplicati
        // Include genre parents/grandparents to support filtering at any level
        $base_query = "
            FROM libri l
            LEFT JOIN editori e ON l.editore_id = e.id
            LEFT JOIN generi g ON l.genere_id = g.id
            LEFT JOIN generi gp ON g.parent_id = gp.id
            LEFT JOIN generi gpp ON gp.parent_id = gpp.id
        ";

        if (!empty($where_conditions['conditions'])) {
            $base_query .= " WHERE " . implode(' AND ', $where_conditions['conditions']);
        }

        // Query per il conteggio totale
        $count_query = "SELECT COUNT(DISTINCT l.id) as total " . $base_query;
        $stmt_count = $db->prepare($count_query);
        if (!empty($query_params)) {
            $stmt_count->bind_param($param_types, ...$query_params);
        }
        $stmt_count->execute();
        $total_result = $stmt_count->get_result();
        $total_books = $total_result->fetch_assoc()['total'];
        $total_pages = ceil($total_books / $limit);

        // Query per i libri
        $books_query = "
            SELECT DISTINCT l.*,
                   (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore,
                   e.nome AS editore,
                   g.nome AS genere
            " . $base_query . "
            " . $this->buildOrderBy($filters['sort']) . "
            LIMIT ? OFFSET ?
        ";

        $stmt_books = $db->prepare($books_query);
        $final_params = array_merge($query_params, [$limit, $offset]);
        $final_types = $param_types . 'ii';
        if (!empty($final_params)) {
            $stmt_books->bind_param($final_types, ...$final_params);
        }
        $stmt_books->execute();
        $books_result = $stmt_books->get_result();

        $books = [];
        while ($book = $books_result->fetch_assoc()) {
            $books[] = $book;
        }

        // Ottieni le opzioni per i filtri
        $filter_options = $this->getFilterOptions($db, $filters);

        // Get hierarchical genre display based on current selection
        $genre_display = $this->getDisplayGenres($filter_options['generi'], $filters['genere'] ?? null);

        // Render template
        ob_start();
        // Rendi disponibili tutte le variabili necessarie nel template
        include __DIR__ . '/../Views/frontend/catalog.php';
        $content = ob_get_clean();

        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function catalogAPI(Request $request, Response $response, mysqli $db): Response
    {
        $params = $request->getQueryParams();

        // Parametri di paginazione
        $limit = 12;
        $page = max(1, (int)($params['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        // Filtri
        $filters = $this->getFilters($params);
        $where_conditions = $this->buildWhereConditions($filters, $db);
        $query_params = $where_conditions['params'];
        $param_types = $where_conditions['types'];

        // Query base senza JOIN con autori per evitare duplicati
        // Include genre parents/grandparents to support filtering at any level
        $base_query = "
            FROM libri l
            LEFT JOIN editori e ON l.editore_id = e.id
            LEFT JOIN generi g ON l.genere_id = g.id
            LEFT JOIN generi gp ON g.parent_id = gp.id
            LEFT JOIN generi gpp ON gp.parent_id = gpp.id
        ";

        if (!empty($where_conditions['conditions'])) {
            $base_query .= " WHERE " . implode(' AND ', $where_conditions['conditions']);
        }

        // Query per il conteggio totale
        $count_query = "SELECT COUNT(DISTINCT l.id) as total " . $base_query;
        $stmt_count = $db->prepare($count_query);
        if (!empty($query_params)) {
            $stmt_count->bind_param($param_types, ...$query_params);
        }
        $stmt_count->execute();
        $total_result = $stmt_count->get_result();
        $total_books = $total_result->fetch_assoc()['total'];
        $total_pages = ceil($total_books / $limit);

        // Query per i libri
        $books_query = "
            SELECT DISTINCT l.*,
                   (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore,
                   e.nome AS editore,
                   g.nome AS genere
            " . $base_query . "
            " . $this->buildOrderBy($filters['sort']) . "
            LIMIT ? OFFSET ?
        ";

        $stmt_books = $db->prepare($books_query);
        $final_params = array_merge($query_params, [$limit, $offset]);
        $final_types = $param_types . 'ii';
        if (!empty($final_params)) {
            $stmt_books->bind_param($final_types, ...$final_params);
        }
        $stmt_books->execute();
        $books_result = $stmt_books->get_result();

        $books = [];
        while ($book = $books_result->fetch_assoc()) {
            $books[] = $book;
        }

        // Render only the books grid
        ob_start();
        include __DIR__ . '/../Views/frontend/catalog-grid.php';
        $html = ob_get_clean();

        // Get updated filter options based on current filters
        $filter_options = $this->getFilterOptions($db, $filters);

        // Get hierarchical genre display for correct sidebar rendering
        $genre_display = $this->getDisplayGenres($filter_options['generi'], $filters['genere'] ?? null);

        $data = [
            'html' => $html,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $total_pages,
                'total_books' => $total_books,
                'start' => $offset + 1,
                'end' => min($offset + $limit, $total_books)
            ],
            'filter_options' => $filter_options,
            'genre_display' => $genre_display
        ];

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function bookDetail(Request $request, Response $response, mysqli $db): Response
    {
        $params = $request->getQueryParams();

        // Verifica che l'ID sia presente e valido
        if (!isset($params['id']) || !is_numeric($params['id'])) {
            return $this->render404($response);
        }

        $book_id = (int)$params['id'];

        // Query per recuperare i dettagli completi del libro con gerarchia generi
        $query = "
            SELECT l.*,
                   a.nome AS autore_principale,
                   g.nome AS genere,
                   gp.nome AS genere_parent,
                   gpp.nome AS genere_grandparent,
                   e.nome AS editore
            FROM libri l
            LEFT JOIN libri_autori la ON l.id = la.libro_id AND la.ruolo = 'principale'
            LEFT JOIN autori a ON la.autore_id = a.id
            LEFT JOIN generi g ON l.genere_id = g.id
            LEFT JOIN generi gp ON g.parent_id = gp.id
            LEFT JOIN generi gpp ON gp.parent_id = gpp.id
            LEFT JOIN editori e ON l.editore_id = e.id
            WHERE l.id = ?
            LIMIT 1
        ";

        $stmt = $db->prepare($query);
        $stmt->bind_param("i", $book_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if (!$result || $result->num_rows == 0) {
            return $this->render404($response);
        }

        $book = $result->fetch_assoc();

        // Ensure canonical URL structure (author slug + book slug + ID)
        $canonicalPath = book_url([
            'id' => $book_id,
            'titolo' => $book['titolo'] ?? '',
            'autore_principale' => $book['autore_principale'] ?? '',
            'autori' => $book['autore_principale'] ?? '',
        ]);
        $currentPath = '/' . ltrim($request->getUri()->getPath(), '/');
        if ($currentPath !== $canonicalPath) {
            $queryString = $request->getUri()->getQuery();
            if (!empty($queryString)) {
                $canonicalPath .= '?' . $queryString;
            }

            return $response->withHeader('Location', $canonicalPath)->withStatus(301);
        }

        // Query per ottenere tutti gli autori del libro
        $query_authors = "
            SELECT a.*, la.ruolo
            FROM autori a
            JOIN libri_autori la ON a.id = la.autore_id
            WHERE la.libro_id = ?
            ORDER BY
                CASE la.ruolo
                    WHEN 'principale' THEN 1
                    WHEN 'coautore' THEN 2
                    WHEN 'traduttore' THEN 3
                    ELSE 4
                END
        ";

        $stmt_authors = $db->prepare($query_authors);
        $stmt_authors->bind_param("i", $book_id);
        $stmt_authors->execute();
        $result_authors = $stmt_authors->get_result();

        $authors = [];
        while ($author = $result_authors->fetch_assoc()) {
            $authors[] = $author;
        }

        // Get related books with priority logic
        $related_books = $this->getRelatedBooks($db, $book_id, $book, $authors);

        // Get approved reviews and statistics
        $recensioniRepo = new RecensioniRepository($db);
        $reviews = $recensioniRepo->getApprovedReviewsForBook($book_id);
        $reviewStats = $recensioniRepo->getReviewStats($book_id);

        // Render template
        ob_start();
        include __DIR__ . '/../Views/frontend/book-detail.php';
        $content = ob_get_clean();

        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/html');
    }

    private function render404(Response $response): Response
    {
        ob_start();
        include __DIR__ . '/../Views/errors/404.php';
        $content = ob_get_clean();

        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/html')->withStatus(404);
    }

    private function getFilters(array $params): array
    {
        // Support both 'q' (header form) and 'search' (hero form) parameters
        $searchTerm = $params['q'] ?? $params['search'] ?? '';

        return [
            'search' => $searchTerm,
            'genere' => $params['genere'] ?? '',
            'disponibilita' => $params['disponibilita'] ?? '',
            'editore' => $params['editore'] ?? '',
            'anno_min' => $params['anno_min'] ?? '',
            'anno_max' => $params['anno_max'] ?? '',
            'sort' => $params['sort'] ?? 'newest'
        ];
    }

    private function buildWhereConditions(array $filters, mysqli $db): array
    {
        $conditions = [];
        $params = [];
        $types = '';

        if (!empty($filters['search'])) {
            $search_term = '%' . $filters['search'] . '%';
            // Crea anche una versione con entità HTML per apostrofi
            $search_term_entities = '%' . str_replace("'", "&#039;", $filters['search']) . '%';
            $conditions[] = "(l.titolo LIKE ? OR l.titolo LIKE ? OR EXISTS(SELECT 1 FROM libri_autori la JOIN autori a ON la.autore_id = a.id WHERE la.libro_id = l.id AND (a.nome LIKE ? OR a.nome LIKE ?)) OR e.nome LIKE ? OR e.nome LIKE ?)";
            $params = array_merge($params, [$search_term, $search_term_entities, $search_term, $search_term_entities, $search_term, $search_term_entities]);
            $types .= 'ssssss';
        }

        if (!empty($filters['genere'])) {
            // Search for genre at any level (Level 3, Level 2, or Level 1)
            $conditions[] = "(g.nome = ? OR gp.nome = ? OR gpp.nome = ?)";
            $params[] = $filters['genere'];
            $params[] = $filters['genere'];
            $params[] = $filters['genere'];
            $types .= 'sss';
        }

        if (!empty($filters['editore'])) {
            $conditions[] = "e.nome = ?";
            $params[] = $filters['editore'];
            $types .= 's';
        }

        if ($filters['disponibilita'] === 'disponibile') {
            $conditions[] = "l.stato = 'disponibile'";
        } elseif ($filters['disponibilita'] === 'prestato') {
            $conditions[] = "l.stato = 'prestato'";
        }

        if (!empty($filters['anno_min'])) {
            $conditions[] = "l.anno_pubblicazione >= ?";
            $params[] = $filters['anno_min'];
            $types .= 'i';
        }

        if (!empty($filters['anno_max'])) {
            $conditions[] = "l.anno_pubblicazione <= ?";
            $params[] = $filters['anno_max'];
            $types .= 'i';
        }

        return [
            'conditions' => $conditions,
            'params' => $params,
            'types' => $types
        ];
    }

    private function buildOrderBy(string $sort): string
    {
        switch ($sort) {
            case 'oldest':
                return 'ORDER BY l.created_at ASC';
            case 'title_asc':
                return 'ORDER BY l.titolo ASC';
            case 'title_desc':
                return 'ORDER BY l.titolo DESC';
            case 'author_asc':
                return 'ORDER BY (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id WHERE la.libro_id = l.id AND la.ruolo = \'principale\' LIMIT 1) ASC';
            case 'author_desc':
                return 'ORDER BY (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id WHERE la.libro_id = l.id AND la.ruolo = \'principale\' LIMIT 1) DESC';
            case 'newest':
            default:
                return 'ORDER BY l.created_at DESC';
        }
    }

private function getFilterOptions(mysqli $db, array $filters = []): array
{
    $options = [];
    // ---------- Generi ----------
    // Build filter conditions excluding the current 'genere' filter
    $filtersForGeneri = $filters;
    $filtersForGeneri['genere'] = '';
    $whereGen = $this->buildWhereConditions($filtersForGeneri, $db);
    $conditionsGen = $whereGen['conditions'];
    $paramsGen = $whereGen['params'];
    $typesGen = $whereGen['types'];

    // Query to get all genres with books, including parent/grandparent hierarchy
    // Count books for each genre including descendant genres
    $whereClauseGen = '';
    if (!empty($conditionsGen)) {
        $whereClauseGen = ' AND ' . implode(' AND ', $conditionsGen);
    }

    $queryGeneri = "
        SELECT DISTINCT
               g.id, g.nome, g.parent_id,
               (
                   SELECT COUNT(DISTINCT l.id)
                   FROM libri l
                   LEFT JOIN editori e ON l.editore_id = e.id
                   LEFT JOIN generi gf ON l.genere_id = gf.id
                   LEFT JOIN generi gfp ON gf.parent_id = gfp.id
                   LEFT JOIN generi gfpp ON gfp.parent_id = gfpp.id
                   WHERE (
                       l.genere_id = g.id
                       OR l.genere_id IN (SELECT id FROM generi WHERE parent_id = g.id)
                       OR l.genere_id IN (SELECT gc.id FROM generi gc JOIN generi gp ON gc.parent_id = gp.id WHERE gp.parent_id = g.id)
                   )
                   {$whereClauseGen}
               ) AS cnt
        FROM (
            -- Select all genres that have books or are parents of genres with books
            SELECT DISTINCT g.id FROM generi g
            JOIN libri l ON g.id = l.genere_id
            UNION
            SELECT DISTINCT gp.id FROM generi g
            JOIN generi gp ON g.parent_id = gp.id
            JOIN libri l ON g.id = l.genere_id
            UNION
            SELECT DISTINCT gpp.id FROM generi g
            JOIN generi gp ON g.parent_id = gp.id
            JOIN generi gpp ON gp.parent_id = gpp.id
            JOIN libri l ON g.id = l.genere_id
        ) as genre_ids
        JOIN generi g ON genre_ids.id = g.id
        ORDER BY g.parent_id, g.nome
    ";

    $stmt = $db->prepare($queryGeneri);
    if (!empty($paramsGen)) {
        $stmt->bind_param($typesGen, ...$paramsGen);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $generi_flat = $result->fetch_all(MYSQLI_ASSOC);
    $options['generi'] = $this->buildGenreHierarchy($generi_flat);

    // ---------- Editori ----------
    // Build filter conditions excluding the current 'editore' filter
    $filtersForEditori = $filters;
    $filtersForEditori['editore'] = '';
    $whereEd = $this->buildWhereConditions($filtersForEditori, $db);
    $conditionsEd = $whereEd['conditions'];
    $paramsEd = $whereEd['params'];
    $typesEd = $whereEd['types'];

    $queryEditori = "
        SELECT e.id, e.nome, COUNT(DISTINCT l.id) AS cnt
        FROM editori e
        JOIN libri l ON e.id = l.editore_id
        LEFT JOIN generi g ON l.genere_id = g.id
        LEFT JOIN generi gp ON g.parent_id = gp.id
        LEFT JOIN generi gpp ON gp.parent_id = gpp.id
    ";
    if (!empty($conditionsEd)) {
        // Keep all conditions including genre filter
        // Only editore filter is excluded (via filtersForEditori)
        $queryEditori .= " WHERE " . implode(' AND ', $conditionsEd);
    }
    $queryEditori .= " GROUP BY e.id, e.nome HAVING cnt > 0 ORDER BY e.nome";

    $stmt = $db->prepare($queryEditori);
    if (!empty($paramsEd)) {
        $stmt->bind_param($typesEd, ...$paramsEd);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $options['editori'] = $result->fetch_all(MYSQLI_ASSOC);

    // ---------- Availability Stats ----------
    // Get availability counts based on current filters (excluding availability filter)
    $filtersForAvailability = $filters;
    $filtersForAvailability['disponibilita'] = '';
    $whereAvail = $this->buildWhereConditions($filtersForAvailability, $db);
    $conditionsAvail = $whereAvail['conditions'];
    $paramsAvail = $whereAvail['params'];
    $typesAvail = $whereAvail['types'];

    $availabilityBaseQuery = "
        FROM libri l
        LEFT JOIN editori e ON l.editore_id = e.id
        LEFT JOIN generi g ON l.genere_id = g.id
        LEFT JOIN generi gp ON g.parent_id = gp.id
        LEFT JOIN generi gpp ON gp.parent_id = gpp.id
    ";
    if (!empty($conditionsAvail)) {
        // Keep all conditions except availability filter (which is excluded via filtersForAvailability)
        // Note: The availability filter is never in conditions because it's excluded, so we just use them as-is
        $availabilityBaseQuery .= " WHERE " . implode(' AND ', $conditionsAvail);
    }

    // Count available books
    $queryAvailable = "SELECT COUNT(DISTINCT l.id) as cnt " . $availabilityBaseQuery .
                     (empty($conditionsAvail) ? " WHERE" : " AND") . " l.stato = 'disponibile'";
    $stmt = $db->prepare($queryAvailable);
    if (!empty($paramsAvail)) {
        $stmt->bind_param($typesAvail, ...$paramsAvail);
    }
    $stmt->execute();
    $availableCount = $stmt->get_result()->fetch_assoc()['cnt'];

    // Count borrowed books
    $queryBorrowed = "SELECT COUNT(DISTINCT l.id) as cnt " . $availabilityBaseQuery .
                    (empty($conditionsAvail) ? " WHERE" : " AND") . " l.stato = 'prestato'";
    $stmt = $db->prepare($queryBorrowed);
    if (!empty($paramsAvail)) {
        $stmt->bind_param($typesAvail, ...$paramsAvail);
    }
    $stmt->execute();
    $borrowedCount = $stmt->get_result()->fetch_assoc()['cnt'];

    $options['availability_stats'] = [
        'available' => $availableCount,
        'borrowed' => $borrowedCount,
        'total' => $availableCount + $borrowedCount
    ];

    return $options;
}

    public function homeAPI(Request $request, Response $response, mysqli $db, string $section): Response
    {
        $page = (int)($request->getQueryParams()['page'] ?? 1);
        $limit = 12;
        $offset = ($page - 1) * $limit;

        $html = '';
        $pagination = ['current_page' => $page, 'total_pages' => 1, 'total_books' => 0];

        $books = [];

        switch ($section) {
            case 'latest':
                // Ultimi libri aggiunti
                $query = "
                    SELECT l.*,
                           (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                            WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore,
                           g.nome AS genere
                    FROM libri l
                    LEFT JOIN generi g ON l.genere_id = g.id
                    ORDER BY l.created_at DESC
                    LIMIT ? OFFSET ?
                ";
                $stmt = $db->prepare($query);
                $stmt->bind_param("ii", $limit, $offset);
                $stmt->execute();
                $result = $stmt->get_result();
                break;

            case 'genre':
                $genere_id = (int)($request->getQueryParams()['id'] ?? 0);
                if (!$genere_id) {
                    return $response->withStatus(400);
                }

                $query = "
                    SELECT l.*,
                           (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                            WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore
                    FROM libri l
                    WHERE l.genere_id = ?
                    ORDER BY l.created_at DESC
                    LIMIT ? OFFSET ?
                ";
                $stmt = $db->prepare($query);
                $stmt->bind_param("iii", $genere_id, $limit, $offset);
                $stmt->execute();
                $result = $stmt->get_result();
                break;

            default:
                return $response->withStatus(404);
        }

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $books[] = $row;
            }
        }

        // Generate HTML for the books
        ob_start();
        include __DIR__ . '/../Views/frontend/home-books-grid.php';
        $html = ob_get_clean();

        // Calculate pagination for total count
        switch ($section) {
            case 'latest':
                $countStmt = $db->prepare("SELECT COUNT(*) as total FROM libri");
                $countStmt->execute();
                $countResult = $countStmt->get_result();
                break;
            case 'genre':
                $countStmt = $db->prepare("SELECT COUNT(*) as total FROM libri WHERE genere_id = ?");
                $countStmt->bind_param("i", $genere_id);
                $countStmt->execute();
                $countResult = $countStmt->get_result();
                break;
        }

        if ($countResult) {
            $totalRow = $countResult->fetch_assoc();
            $total = $totalRow['total'];
            $pagination = [
                'current_page' => $page,
                'total_pages' => ceil($total / $limit),
                'total_books' => $total,
                'start' => $offset + 1,
                'end' => min($offset + $limit, $total)
            ];
        }

        $responseData = [
            'html' => $html,
            'pagination' => $pagination
        ];

        $response->getBody()->write(json_encode($responseData));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function authorArchive(Request $request, Response $response, mysqli $db, string $authorName): Response
    {
        $params = $request->getQueryParams();
        $limit = 12;
        $page = max(1, (int)($params['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        // URL decode author name
        $authorName = urldecode($authorName);

        // Query per trovare l'autore
        $authorQuery = "SELECT id, nome, biografia FROM autori WHERE nome = ? LIMIT 1";
        $stmt = $db->prepare($authorQuery);
        $stmt->bind_param('s', $authorName);
        $stmt->execute();
        $authorResult = $stmt->get_result();

        if ($authorResult->num_rows === 0) {
            return $this->render404($response);
        }

        $author = $authorResult->fetch_assoc();

        // Count total books
        $countQuery = "
            SELECT COUNT(DISTINCT l.id) as total
            FROM libri l
            JOIN libri_autori la ON l.id = la.libro_id
            JOIN autori a ON la.autore_id = a.id
            WHERE a.nome = ?
        ";
        $stmt = $db->prepare($countQuery);
        $stmt->bind_param('s', $authorName);
        $stmt->execute();
        $totalBooks = $stmt->get_result()->fetch_assoc()['total'];
        $totalPages = ceil($totalBooks / $limit);

        // Query per i libri dell'autore
        $booksQuery = "
            SELECT DISTINCT l.*,
                   (SELECT a2.nome FROM libri_autori la2 JOIN autori a2 ON la2.autore_id = a2.id
                    WHERE la2.libro_id = l.id AND la2.ruolo = 'principale' LIMIT 1) AS autore,
                   e.nome AS editore,
                   g.nome AS genere
            FROM libri l
            JOIN libri_autori la ON l.id = la.libro_id
            JOIN autori a ON la.autore_id = a.id
            LEFT JOIN editori e ON l.editore_id = e.id
            LEFT JOIN generi g ON l.genere_id = g.id
            WHERE a.nome = ?
            ORDER BY l.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $db->prepare($booksQuery);
        $stmt->bind_param('sii', $authorName, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $books = [];
        while ($book = $result->fetch_assoc()) {
            $books[] = $book;
        }

        ob_start();
        $title = "Libri di " . htmlspecialchars($author['nome']);
        $archive_type = 'autore';
        $archive_info = $author;
        include __DIR__ . '/../Views/frontend/archive.php';
        $content = ob_get_clean();

        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function publisherArchive(Request $request, Response $response, mysqli $db, string $publisherName): Response
    {
        $params = $request->getQueryParams();
        $limit = 12;
        $page = max(1, (int)($params['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        // URL decode publisher name
        $publisherName = urldecode($publisherName);

        // Query per trovare l'editore
        $publisherQuery = "SELECT id, nome, indirizzo, sito_web FROM editori WHERE nome = ? LIMIT 1";
        $stmt = $db->prepare($publisherQuery);
        $stmt->bind_param('s', $publisherName);
        $stmt->execute();
        $publisherResult = $stmt->get_result();

        if ($publisherResult->num_rows === 0) {
            return $this->render404($response);
        }

        $publisher = $publisherResult->fetch_assoc();

        // Count total books
        $countQuery = "
            SELECT COUNT(l.id) as total
            FROM libri l
            JOIN editori e ON l.editore_id = e.id
            WHERE e.nome = ?
        ";
        $stmt = $db->prepare($countQuery);
        $stmt->bind_param('s', $publisherName);
        $stmt->execute();
        $totalBooks = $stmt->get_result()->fetch_assoc()['total'];
        $totalPages = ceil($totalBooks / $limit);

        // Query per i libri dell'editore
        $booksQuery = "
            SELECT l.*,
                   (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore,
                   e.nome AS editore,
                   g.nome AS genere
            FROM libri l
            JOIN editori e ON l.editore_id = e.id
            LEFT JOIN generi g ON l.genere_id = g.id
            WHERE e.nome = ?
            ORDER BY l.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $db->prepare($booksQuery);
        $stmt->bind_param('sii', $publisherName, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $books = [];
        while ($book = $result->fetch_assoc()) {
            $books[] = $book;
        }

        ob_start();
        $title = "Libri di " . htmlspecialchars($publisher['nome']);

        // SEO Variables
        $publisherName = htmlspecialchars($publisher['nome']);
        $seoTitle = "Libri di {$publisherName} - Catalogo Editore | Biblioteca";
        $seoDescription = "Scopri tutti i libri pubblicati da {$publisherName} disponibili nella nostra biblioteca. {$totalBooks} libr" . ($totalBooks === 1 ? 'o' : 'i') . " disponibili per il prestito.";
        $seoCanonical = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/editore/' . urlencode($publisher['nome']);
        $seoImage = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/uploads/copertine/placeholder.jpg';

        $archive_type = 'editore';
        $archive_info = $publisher;
        include __DIR__ . '/../Views/frontend/archive.php';
        $content = ob_get_clean();

        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function bookDetailSEO(Request $request, Response $response, mysqli $db, int $id, string $slug = ''): Response
    {
        // Richiama il metodo esistente modificando i parametri della query
        $modifiedRequest = $request->withQueryParams(['id' => $id]);
        return $this->bookDetail($modifiedRequest, $response, $db);
    }

    public function genreArchive(Request $request, Response $response, mysqli $db, string $genreName): Response
    {
        $params = $request->getQueryParams();
        $limit = 12;
        $page = max(1, (int)($params['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        // URL decode genre name
        $genreName = urldecode($genreName);

        // Query per trovare il genere
        $genreQuery = "SELECT id, nome FROM generi WHERE nome = ? LIMIT 1";
        $stmt = $db->prepare($genreQuery);
        $stmt->bind_param('s', $genreName);
        $stmt->execute();
        $genreResult = $stmt->get_result();

        if ($genreResult->num_rows === 0) {
            return $this->render404($response);
        }

        $genre = $genreResult->fetch_assoc();

        // Count total books
        $countQuery = "
            SELECT COUNT(l.id) as total
            FROM libri l
            JOIN generi g ON l.genere_id = g.id
            WHERE g.nome = ?
        ";
        $stmt = $db->prepare($countQuery);
        $stmt->bind_param('s', $genreName);
        $stmt->execute();
        $totalBooks = $stmt->get_result()->fetch_assoc()['total'];
        $totalPages = ceil($totalBooks / $limit);

        // Query per i libri del genere
        $booksQuery = "
            SELECT l.*,
                   (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore,
                   e.nome AS editore,
                   g.nome AS genere
            FROM libri l
            JOIN generi g ON l.genere_id = g.id
            LEFT JOIN editori e ON l.editore_id = e.id
            WHERE g.nome = ?
            ORDER BY l.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $db->prepare($booksQuery);
        $stmt->bind_param('sii', $genreName, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $books = [];
        while ($book = $result->fetch_assoc()) {
            $books[] = $book;
        }

        ob_start();
        $title = "Libri di genere " . htmlspecialchars($genre['nome']);

        // SEO Variables
        $genreName = htmlspecialchars($genre['nome']);
        $seoTitle = "Libri di {$genreName} - Catalogo per Genere | Biblioteca";
        $seoDescription = "Esplora tutti i libri del genere {$genreName} disponibili nella nostra biblioteca. {$totalBooks} libr" . ($totalBooks === 1 ? 'o' : 'i') . " disponibili per il prestito.";
        $seoCanonical = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/genere/' . urlencode($genre['nome']);
        $seoImage = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/uploads/copertine/placeholder.jpg';

        $archive_type = 'genere';
        $archive_info = $genre;
        include __DIR__ . '/../Views/frontend/archive.php';
        $content = ob_get_clean();

        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/html');
    }

    private function createSlug(string $text): string
    {
        // Decodifica entità HTML
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        // Converte in minuscolo
        $text = strtolower($text);
        // Sostituisce caratteri accentati con equivalenti non accentati
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        // Rimuove caratteri speciali eccetto lettere, numeri, spazi e trattini
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        $text = preg_replace('/[\s-]+/', '-', $text);
        $text = trim($text, '-');
        return $text;
    }

    public function getBookUrl(array $book): string
    {
        return book_url($book);
    }

    private function buildGenreHierarchy(array $generi_flat): array
    {
        $generi = [];
        $generi_by_id = [];

        // Prima passa: crea tutti i generi e indicizza per ID
        // Also cast parent_id to int for proper key matching
        foreach ($generi_flat as $genere) {
            $genere['id'] = (int)$genere['id'];
            $genere['parent_id'] = $genere['parent_id'] !== null && $genere['parent_id'] !== '' ? (int)$genere['parent_id'] : null;
            $generi_by_id[$genere['id']] = $genere;
            $generi_by_id[$genere['id']]['children'] = [];
        }

        // Seconda passa: costruisce la gerarchia
        // Store parent-child relationships by storing references
        foreach ($generi_by_id as $id => $genere) {
            // Check for null or empty parent_id (MySQL returns empty string for NULL)
            if ($genere['parent_id'] !== null && $genere['parent_id'] !== 0) {
                // È un sottogenere, aggiungilo al parent
                if (isset($generi_by_id[$genere['parent_id']])) {
                    // Store reference to the actual genre object in $generi_by_id
                    $generi_by_id[$genere['parent_id']]['children'][] = &$generi_by_id[$id];
                }
            }
        }

        // Third pass: collect only root genres from $generi_by_id
        // This ensures that changes to children are reflected
        foreach ($generi_by_id as $id => $genere) {
            if ($genere['parent_id'] === null || $genere['parent_id'] === 0) {
                $generi[] = $genere;
            }
        }

        return $generi;
    }

    private function collectGenreTreeIds(array $childrenByParent, int $rootId): array
    {
        $ids = [$rootId];
        $queue = [$rootId];
        $visited = [$rootId => true];

        while (!empty($queue)) {
            $current = array_shift($queue);
            $children = $childrenByParent[$current] ?? [];

            foreach ($children as $childId) {
                if (!isset($visited[$childId])) {
                    $ids[] = $childId;
                    $queue[] = $childId;
                    $visited[$childId] = true;
                }
            }
        }

        return $ids;
    }

    private function isHomeSectionEnabled(mysqli $db, string $sectionKey): bool
    {
        $stmt = $db->prepare("SELECT is_active FROM home_content WHERE section_key = ? LIMIT 1");
        $stmt->bind_param('s', $sectionKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return true;
        }

        return (int)$row['is_active'] === 1;
    }

    /**
     * Get the appropriate genres to display based on current filter selection
     * Implements hierarchical navigation:
     * - Level 0: Show all root genres (parent_id = null)
     * - Level 1: Show children of selected root genre
     * - Level 2: Show children of selected second-level genre
     *
     * @param array $allGenres Full genre hierarchy from buildGenreHierarchy
     * @param ?string $selectedGenre Currently selected genre name
     * @return array ['genres' => display genres, 'level' => current level, 'parent' => parent genre for back button]
     */
    private function getDisplayGenres(array $allGenres, ?string $selectedGenre): array
    {
        if (empty($selectedGenre)) {
            // Level 0: Show all root genres
            return [
                'genres' => $allGenres,
                'level' => 0,
                'parent' => null
            ];
        }

        // Find the selected genre in the hierarchy
        $selectedGenreData = null;
        $parentGenre = null;

        // Search in root genres
        foreach ($allGenres as $genre) {
            if ($genre['nome'] === $selectedGenre) {
                $selectedGenreData = $genre;
                break;
            }
            // Search in children
            if (!empty($genre['children'])) {
                foreach ($genre['children'] as $child) {
                    if ($child['nome'] === $selectedGenre) {
                        $selectedGenreData = $child;
                        $parentGenre = $genre;
                        break;
                    }
                    // Search in grandchildren
                    if (!empty($child['children'])) {
                        foreach ($child['children'] as $grandchild) {
                            if ($grandchild['nome'] === $selectedGenre) {
                                $selectedGenreData = $grandchild;
                                $parentGenre = $child;
                                break;
                            }
                        }
                    }
                }
            }
        }

        if (!$selectedGenreData) {
            return [
                'genres' => $allGenres,
                'level' => 0,
                'parent' => null
            ];
        }

        // Determine level: if selected genre is a root (no parent), it's level 1
        // If it has a parent, check if parent is root: level 2, otherwise level 3
        $selectedIsRoot = $selectedGenreData['parent_id'] === null || $selectedGenreData['parent_id'] === '' || $selectedGenreData['parent_id'] === 0;
        $level = 0;
        if ($selectedIsRoot) {
            $level = 1; // Selected is Level 1 (Radice), show Level 2 (Generi)
        } elseif ($parentGenre) {
            $parentIsRoot = $parentGenre['parent_id'] === null || $parentGenre['parent_id'] === '' || $parentGenre['parent_id'] === 0;
            $level = $parentIsRoot ? 2 : 3; // Level 2 or 3 selected
        }

        return [
            'genres' => !empty($selectedGenreData['children']) ? $selectedGenreData['children'] : [],
            'level' => $level,
            'parent' => $parentGenre,
            'selectedGenre' => $selectedGenreData
        ];
    }

    public function authorArchiveById(Request $request, Response $response, mysqli $db, int $authorId): Response
    {
        $params = $request->getQueryParams();
        $limit = 12;
        $page = max(1, (int)($params['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        // Query per trovare l'autore by ID
        $authorQuery = "SELECT id, nome, biografia FROM autori WHERE id = ? LIMIT 1";
        $stmt = $db->prepare($authorQuery);
        $stmt->bind_param('i', $authorId);
        $stmt->execute();
        $authorResult = $stmt->get_result();

        if ($authorResult->num_rows === 0) {
            return $this->render404($response);
        }

        $author = $authorResult->fetch_assoc();

        // Count total books
        $countQuery = "
            SELECT COUNT(DISTINCT l.id) as total
            FROM libri l
            JOIN libri_autori la ON l.id = la.libro_id
            WHERE la.autore_id = ?
        ";
        $stmt = $db->prepare($countQuery);
        $stmt->bind_param('i', $authorId);
        $stmt->execute();
        $totalBooks = $stmt->get_result()->fetch_assoc()['total'];
        $totalPages = ceil($totalBooks / $limit);

        // Query per i libri dell'autore
        $booksQuery = "
            SELECT DISTINCT l.*,
                   (SELECT a2.nome FROM libri_autori la2 JOIN autori a2 ON la2.autore_id = a2.id
                    WHERE la2.libro_id = l.id AND la2.ruolo = 'principale' LIMIT 1) AS autore,
                   e.nome AS editore,
                   g.nome AS genere,
                   (l.copie_totali - COALESCE(prestiti_attivi.count, 0)) AS copie_disponibili
            FROM libri l
            JOIN libri_autori la ON l.id = la.libro_id
            LEFT JOIN editori e ON l.editore_id = e.id
            LEFT JOIN generi g ON l.genere_id = g.id
            LEFT JOIN (
                SELECT libro_id, COUNT(*) as count
                FROM prestiti
                WHERE stato IN ('in_corso', 'prenotato')
                GROUP BY libro_id
            ) prestiti_attivi ON l.id = prestiti_attivi.libro_id
            WHERE la.autore_id = ?
            ORDER BY l.anno_pubblicazione DESC, l.titolo ASC
            LIMIT ? OFFSET ?
        ";

        $stmt = $db->prepare($booksQuery);
        $stmt->bind_param('iii', $authorId, $limit, $offset);
        $stmt->execute();
        $books = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Pagination info
        $pagination = [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_books' => $totalBooks,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages,
            'prev_page' => $page > 1 ? $page - 1 : null,
            'next_page' => $page < $totalPages ? $page + 1 : null
        ];

        // Render template
        ob_start();
        $title = "Libri di " . htmlspecialchars($author['nome']);
        $archive_type = 'autore';
        $archive_info = $author;
        include __DIR__ . '/../Views/frontend/archive.php';
        $content = ob_get_clean();

        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/html');
    }

    private function render(Response $response, string $template, array $data = []): Response
    {
        ob_start();
        
        $templatePath = __DIR__ . '/../Views/' . $template;
        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            $response->getBody()->write('Template not found: ' . $template);
            return $response->withStatus(500);
        }
        
        $content = ob_get_clean();
        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/html');
    }

    private function getRelatedBooks(mysqli $db, int $book_id, array $book, array $authors): array
    {
        $related_books = [];
        $limit = 3;

        // Priority 1: Same author(s) - highest priority as it's most relevant
        if (!empty($authors)) {
            $author_ids = array_column($authors, 'id');
            $placeholders = implode(',', array_fill(0, count($author_ids), '?'));

            $query = "
                SELECT DISTINCT l.*,
                       GROUP_CONCAT(DISTINCT a.nome SEPARATOR ', ') as autori
                FROM libri l
                LEFT JOIN libri_autori la ON l.id = la.libro_id
                LEFT JOIN autori a ON la.autore_id = a.id
                WHERE la.autore_id IN ($placeholders)
                AND l.id != ?
                GROUP BY l.id
                ORDER BY l.created_at DESC
                LIMIT ?
            ";

            $stmt = $db->prepare($query);
            $types = str_repeat('i', count($author_ids)) . 'ii';
            $params = array_merge($author_ids, [$book_id, $limit]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $related_books[] = $row;
            }
        }

        // Priority 2: Same genre - second most relevant
        if (count($related_books) < $limit && !empty($book['genere_id'])) {
            $remaining = $limit - count($related_books);
            $exclude_ids = array_merge([$book_id], array_column($related_books, 'id'));
            $placeholders = implode(',', array_fill(0, count($exclude_ids), '?'));

            $query = "
                SELECT DISTINCT l.*,
                       GROUP_CONCAT(DISTINCT a.nome SEPARATOR ', ') as autori
                FROM libri l
                LEFT JOIN libri_autori la ON l.id = la.libro_id
                LEFT JOIN autori a ON la.autore_id = a.id
                WHERE l.genere_id = ?
                AND l.id NOT IN ($placeholders)
                GROUP BY l.id
                ORDER BY l.created_at DESC
                LIMIT ?
            ";

            $stmt = $db->prepare($query);
            $types = 'i' . str_repeat('i', count($exclude_ids)) . 'i';
            $params = array_merge([$book['genere_id']], $exclude_ids, [$remaining]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $related_books[] = $row;
            }
        }

        // Priority 3: Recent additions (fallback)
        // Show newest books instead of random for better discovery
        if (count($related_books) < $limit) {
            $remaining = $limit - count($related_books);
            $exclude_ids = array_merge([$book_id], array_column($related_books, 'id'));
            $placeholders = implode(',', array_fill(0, count($exclude_ids), '?'));

            $query = "
                SELECT DISTINCT l.*,
                       GROUP_CONCAT(DISTINCT a.nome SEPARATOR ', ') as autori
                FROM libri l
                LEFT JOIN libri_autori la ON l.id = la.libro_id
                LEFT JOIN autori a ON la.autore_id = a.id
                WHERE l.id NOT IN ($placeholders)
                GROUP BY l.id
                ORDER BY l.created_at DESC
                LIMIT ?
            ";

            $stmt = $db->prepare($query);
            $types = str_repeat('i', count($exclude_ids)) . 'i';
            $params = array_merge($exclude_ids, [$remaining]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $related_books[] = $row;
            }
        }

        return array_slice($related_books, 0, $limit);
    }
}
?>
