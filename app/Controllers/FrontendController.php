<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\RecensioniRepository;
use App\Support\Branding;
use App\Support\ConfigStore;
use App\Support\HtmlHelper;
use App\Support\RouteTranslator;
use mysqli;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FrontendController
{
    private ?ContainerInterface $container = null;

    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function home(Request $request, Response $response, mysqli $db, ?ContainerInterface $container = null): Response
    {
        // Use provided container or fallback to instance container
        $container = $container ?? $this->container;
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
        $stmt_home = $db->prepare($query_home);
        $stmt_home->execute();
        $result_home = $stmt_home->get_result();

        if ($result_home) {
            while ($row = $result_home->fetch_assoc()) {
                $homeContent[$row['section_key']] = $row;
            }
        }
        $stmt_home->close();

        // Create ordered sections array for dynamic rendering
        // This array maintains the display_order and includes all section data
        $sectionsOrdered = [];
        $query_sections_ordered = "SELECT section_key, title, subtitle, content, button_text, button_link, background_image,
                                          is_active, display_order
                                   FROM home_content
                                   ORDER BY display_order ASC, section_key ASC";
        $stmt_ordered = $db->prepare($query_sections_ordered);
        $stmt_ordered->execute();
        $result_ordered = $stmt_ordered->get_result();
        if ($result_ordered) {
            while ($row = $result_ordered->fetch_assoc()) {
                $sectionsOrdered[$row['section_key']] = $row;
            }
        }
        $stmt_ordered->close();

        // Determine sort order for latest books section
        $latestBooksSort = $this->getLatestBooksSort($db);

        // Query per gli ultimi 10 libri inseriti
        $query_slider = "
            SELECT l.*,
                   (SELECT " . \App\Support\AuthorName::displaySql('a') . " FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore,
                   g.nome AS genere
            FROM libri l
            LEFT JOIN generi g ON l.genere_id = g.id
            WHERE l.deleted_at IS NULL
            ORDER BY l.{$latestBooksSort} DESC
            LIMIT 10
        ";
        $stmt_slider = $db->prepare($query_slider);
        $stmt_slider->execute();
        $result_slider = $stmt_slider->get_result();
        $latest_books = [];

        if ($result_slider) {
            while ($book = $result_slider->fetch_assoc()) {
                $latest_books[] = $book;
            }
        }
        $stmt_slider->close();

        // Costruisci i caroselli partendo dai generi radice (parent_id NULL)
        $genres_with_books = [];
        $allGenres = [];
        $childrenByParent = [];

        $stmt_genres = $db->prepare("SELECT id, nome, parent_id FROM generi");
        $stmt_genres->execute();
        $resultAllGenres = $stmt_genres->get_result();
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
        $stmt_genres->close();

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

                // Use proper prepared statements with dynamic placeholders
                $uniqueGenreIds = array_unique(array_map('intval', $genreIds));
                $inClause = '(' . implode(',', array_fill(0, count($uniqueGenreIds), '?')) . ')';
                $query_genre_books = "
                    SELECT l.*,
                           (SELECT " . \App\Support\AuthorName::displaySql('a') . " FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                            WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore
                    FROM libri l
                    WHERE l.genere_id IN " . $inClause . " AND l.deleted_at IS NULL
                    ORDER BY l.created_at DESC
                    LIMIT 12
                ";
                $stmt_genre_books = $db->prepare($query_genre_books);
                if ($stmt_genre_books === false) {
                    \App\Support\SecureLogger::error('Failed to prepare genre books query', ['db_error' => $db->error]);
                    continue;
                }
                $types = str_repeat('i', count($uniqueGenreIds));
                $stmt_genre_books->bind_param($types, ...$uniqueGenreIds);
                $stmt_genre_books->execute();
                $result_genre_books = $stmt_genre_books->get_result();

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
                $stmt_genre_books->close();
            }
        }

        $genreCarouselEnabled = $this->isHomeSectionEnabled($db, 'genre_carousel');

        // Home events preview (respect CMS visibility)
        $homeEvents = [];
        $homeEventsEnabled = false;
        $eventsFeatureEnabled = false;

        try {
            $settingsRepository = new \App\Models\SettingsRepository($db);
            $eventsFeatureEnabled = $settingsRepository->get('cms', 'events_page_enabled', '0') === '1';
        } catch (\Throwable $e) {
            $eventsFeatureEnabled = false;
        }

        if ($eventsFeatureEnabled) {
            $eventsQuery = "
                SELECT id, title, slug, event_date, event_time, featured_image
                FROM events
                WHERE is_active = 1 AND event_date >= CURDATE()
                ORDER BY event_date ASC, event_time ASC, created_at DESC
                LIMIT 3
            ";
            $stmt_events = $db->prepare($eventsQuery);
            $stmt_events->execute();
            $resultEvents = $stmt_events->get_result();
            if ($resultEvents) {
                while ($eventRow = $resultEvents->fetch_assoc()) {
                    $homeEvents[] = $eventRow;
                }
            }
            $stmt_events->close();

            // Fallback: if no upcoming events, show latest active events
            if (empty($homeEvents)) {
                $fallbackQuery = "
                    SELECT id, title, slug, event_date, event_time, featured_image
                    FROM events
                    WHERE is_active = 1
                    ORDER BY event_date DESC, created_at DESC
                    LIMIT 3
                ";
                $stmt_fallback = $db->prepare($fallbackQuery);
                $stmt_fallback->execute();
                $fallbackResult = $stmt_fallback->get_result();
                if ($fallbackResult) {
                    while ($eventRow = $fallbackResult->fetch_assoc()) {
                        $homeEvents[] = $eventRow;
                    }
                }
                $stmt_fallback->close();
            }
        }

        $homeEventsEnabled = $eventsFeatureEnabled && !empty($homeEvents);

        // Build dynamic SEO data from settings and CMS
        $hero = $homeContent['hero'] ?? [];

        // Fetch app settings for SEO fallbacks
        $appName = \App\Support\ConfigStore::get('app.name', 'Pinakes');
        $footerDescription = \App\Support\ConfigStore::get('app.footer_description', '');
        $appLogo = Branding::logo();

        // Build base URL (includes base path for subfolder installs)
        $baseUrl = rtrim(HtmlHelper::getBaseUrl(), '/');

        $seoCanonical = $baseUrl . '/';
        $brandLogoUrl = $appLogo !== '' ? HtmlHelper::absoluteUrl($appLogo) : '';
        $socialImage = Branding::socialImage();
        $defaultSocialImage = $socialImage !== '' ? HtmlHelper::absoluteUrl($socialImage) : '';

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
            $ogImage = HtmlHelper::absoluteUrl($hero['og_image']);
        } elseif (!empty($hero['background_image'])) {
            $ogImage = HtmlHelper::absoluteUrl($hero['background_image']);
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
            $twitterImage = HtmlHelper::absoluteUrl($hero['twitter_image']);
        } elseif (!empty($hero['og_image'])) {
            $twitterImage = HtmlHelper::absoluteUrl($hero['og_image']);
        } elseif (!empty($hero['background_image'])) {
            $twitterImage = HtmlHelper::absoluteUrl($hero['background_image']);
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
                'urlTemplate' => $baseUrl . RouteTranslator::route('catalog') . '?q={search_term_string}'
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
        $container = $container ?? $this->container;
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

        // Extra results from plugins (e.g. archive units) when a search is active.
        $searchTerm = trim((string) ($filters['search'] ?? ''));
        /** @var array<int, array<string, mixed>> $archiveResults */
        $archiveResults = $searchTerm !== ''
            ? \App\Support\Hooks::apply('frontend.catalog.archive_results', [], [$searchTerm])
            : [];

        // Query base senza JOIN con autori per evitare duplicati
        // Include genre parents/grandparents to support filtering at any level
        $base_query = "
            FROM libri l
            LEFT JOIN editori e ON l.editore_id = e.id
            LEFT JOIN generi g ON l.genere_id = g.id
            LEFT JOIN generi gp ON g.parent_id = gp.id
            LEFT JOIN generi gpp ON gp.parent_id = gpp.id
            LEFT JOIN generi sg ON l.sottogenere_id = sg.id
            WHERE l.deleted_at IS NULL
        ";

        if (!empty($where_conditions['conditions'])) {
            $base_query .= " AND " . implode(' AND ', $where_conditions['conditions']);
        }

        // Query per il conteggio totale
        $count_query = "SELECT COUNT(DISTINCT l.id) as total " . $base_query;
        $stmt_count = $db->prepare($count_query);
        if (!empty($query_params)) {
            $stmt_count->bind_param($param_types, ...$query_params);
        }
        $stmt_count->execute();
        $total_result = $stmt_count->get_result();
        $total_row = $total_result->fetch_assoc();
        $total_books = $total_row['total'] ?? 0;
        $total_pages = ceil($total_books / $limit);

        // Query per i libri. Expose the principal author's surname as an
        // explicit column so buildOrderBy can reference an alias instead of
        // re-running the correlated subquery twice per row (once for the
        // NULLs-last predicate, once for the sort value).
        $books_query = "
            SELECT DISTINCT l.*,
                   (SELECT " . \App\Support\AuthorName::displaySql('a') . " FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore,
                   (SELECT SUBSTRING_INDEX(TRIM(a.nome), ' ', -1) FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore_cognome,
                   e.nome AS editore,
                   g.nome AS genere
            " . $base_query . "
            " . $this->buildOrderBy($filters['sort']) . "
            LIMIT ? OFFSET ?
        ";

        $stmt_books = $db->prepare($books_query);
        $final_params = array_merge($query_params, [$limit, $offset]);
        $final_types = $param_types . 'ii';
        $stmt_books->bind_param($final_types, ...$final_params);
        $stmt_books->execute();
        $books_result = $stmt_books->get_result();

        $books = [];
        while ($book = $books_result->fetch_assoc()) {
            $books[] = $book;
        }

        // Ottieni le opzioni per i filtri
        $filter_options = $this->getFilterOptions($db, $filters);

        // Get hierarchical genre display based on current selection
        $genre_display = $this->getDisplayGenres($filter_options['generi'], (int)($filters['genere_id'] ?? 0));

        // Render template
        $container = $this->container;
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

        // FIX F001: removed archive results hook from catalogAPI() to avoid
        // returning archive matches in the search-as-you-type JSON payload.
        // catalog() still renders archives in its empty-state block.

        // Query base senza JOIN con autori per evitare duplicati
        // Include genre parents/grandparents/subgenre to support filtering at any level
        $base_query = "
            FROM libri l
            LEFT JOIN editori e ON l.editore_id = e.id
            LEFT JOIN generi g ON l.genere_id = g.id
            LEFT JOIN generi gp ON g.parent_id = gp.id
            LEFT JOIN generi gpp ON gp.parent_id = gpp.id
            LEFT JOIN generi sg ON l.sottogenere_id = sg.id
            WHERE l.deleted_at IS NULL
        ";

        if (!empty($where_conditions['conditions'])) {
            $base_query .= " AND " . implode(' AND ', $where_conditions['conditions']);
        }

        // Query per il conteggio totale
        $count_query = "SELECT COUNT(DISTINCT l.id) as total " . $base_query;
        $stmt_count = $db->prepare($count_query);
        if (!empty($query_params)) {
            $stmt_count->bind_param($param_types, ...$query_params);
        }
        $stmt_count->execute();
        $total_result = $stmt_count->get_result();
        $total_row = $total_result->fetch_assoc();
        $total_books = $total_row['total'] ?? 0;
        $total_pages = ceil($total_books / $limit);

        // Query per i libri. Expose the principal author's surname as an
        // explicit column so buildOrderBy can reference an alias instead of
        // re-running the correlated subquery twice per row (once for the
        // NULLs-last predicate, once for the sort value).
        $books_query = "
            SELECT DISTINCT l.*,
                   (SELECT " . \App\Support\AuthorName::displaySql('a') . " FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore,
                   (SELECT SUBSTRING_INDEX(TRIM(a.nome), ' ', -1) FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore_cognome,
                   e.nome AS editore,
                   g.nome AS genere
            " . $base_query . "
            " . $this->buildOrderBy($filters['sort']) . "
            LIMIT ? OFFSET ?
        ";

        $stmt_books = $db->prepare($books_query);
        $final_params = array_merge($query_params, [$limit, $offset]);
        $final_types = $param_types . 'ii';
        $stmt_books->bind_param($final_types, ...$final_params);
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
        $genre_display = $this->getDisplayGenres($filter_options['generi'], (int)($filters['genere_id'] ?? 0));

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

    /**
     * Render the public book-detail page, loading the book with its authors,
     * publishers (issue #143), series, reviews and related volumes.
     */
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
                   gp.id AS genere_parent_id_resolved,
                   gp.nome AS genere_parent,
                   gpp.id AS genere_grandparent_id,
                   gpp.nome AS genere_grandparent,
                   sg.nome AS sottogenere,
                   e.nome AS editore
            FROM libri l
            LEFT JOIN libri_autori la ON l.id = la.libro_id AND la.ruolo = 'principale'
            LEFT JOIN autori a ON la.autore_id = a.id
            LEFT JOIN generi g ON l.genere_id = g.id
            LEFT JOIN generi gp ON g.parent_id = gp.id
            LEFT JOIN generi gpp ON gp.parent_id = gpp.id
            LEFT JOIN generi sg ON l.sottogenere_id = sg.id
            LEFT JOIN editori e ON l.editore_id = e.id
            WHERE l.id = ? AND l.deleted_at IS NULL
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
                    WHEN 'co-autore' THEN 2
                    WHEN 'traduttore' THEN 3
                    WHEN 'illustratore' THEN 4
                    WHEN 'curatore' THEN 5
                    WHEN 'colorista' THEN 6
                    ELSE 7
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

        // Publishers (issue #143): full ordered list for multi-publisher books.
        // Falls back to the single primary publisher for pre-#143 data.
        $book['editori'] = [];
        $stmtPub = $db->prepare(
            'SELECT e.id, e.nome FROM libri_editori le JOIN editori e ON le.editore_id = e.id WHERE le.libro_id = ? ORDER BY le.ordine, e.nome'
        );
        if ($stmtPub) {
            $stmtPub->bind_param('i', $book_id);
            $stmtPub->execute();
            $resPub = $stmtPub->get_result();
            while ($pub = $resPub->fetch_assoc()) {
                $book['editori'][] = $pub;
            }
            $stmtPub->close();
        }
        if ($book['editori'] === [] && !empty($book['editore'])) {
            $book['editori'][] = ['id' => (int) ($book['editore_id'] ?? 0), 'nome' => (string) $book['editore']];
        }

        // Get approved reviews and statistics
        $recensioniRepo = new RecensioniRepository($db);
        $reviews = $recensioniRepo->getApprovedReviewsForBook($book_id);
        $reviewStats = $recensioniRepo->getReviewStats($book_id);

        // Other volumes in the same series (collana)
        $seriesBooks = [];
        $collana = trim((string) ($book['collana'] ?? ''));
        if ($collana !== '') {
            $stmtSeries = $db->prepare("
                SELECT l.id, l.titolo, l.numero_serie, l.copertina_url,
                       (SELECT " . \App\Support\AuthorName::displaySql('a') . " FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                        WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore_principale
                FROM libri l
                WHERE l.collana = ? AND l.id != ? AND l.deleted_at IS NULL
                ORDER BY
                    CASE WHEN TRIM(l.numero_serie) REGEXP '^[0-9]+$' THEN 0 ELSE 1 END,
                    CAST(l.numero_serie AS UNSIGNED),
                    l.titolo
            ");
            if ($stmtSeries) {
                $stmtSeries->bind_param('si', $collana, $book_id);
                $stmtSeries->execute();
                $resSeries = $stmtSeries->get_result();
                while ($row = $resSeries->fetch_assoc()) {
                    $seriesBooks[] = $row;
                }
                $stmtSeries->close();
            } else {
                \App\Support\SecureLogger::warning('FrontendController: series query prepare failed', ['db_error' => $db->error]);
            }
        }

        // Get related books (pass seriesBooks to avoid duplicate collana query)
        $related_books = $this->getRelatedBooks($db, $book_id, $book, $authors, $seriesBooks);

        // Social sharing
        $sharingProviders = array_values(array_filter(array_map('trim', explode(',', (string) ConfigStore::get('sharing.enabled_providers', '')))));
        $shareUrl = absoluteUrl($canonicalPath);
        $shareTitle = $book['titolo'] ?? '';

        // Check whether the BIBFRAME Linked Data plugin is active.
        // Done before template include so the view can use $bibframePluginActive.
        // Uses PluginManager::isActive() which caches per-process — the raw
        // `SELECT 1 FROM plugins ...` here used to run on every render of the
        // book-detail page (hottest catalog URL), including anonymous crawls.
        $bibframePluginActive = false;
        if ($this->container !== null && $this->container->has('pluginManager')) {
            try {
                /** @var \App\Support\PluginManager $pluginManager */
                $pluginManager = $this->container->get('pluginManager');
                $bibframePluginActive = $pluginManager->isActive('bibframe-linked-data');
            } catch (\Throwable $e) {
                \App\Support\SecureLogger::warning('FrontendController: pluginManager lookup failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Render template
        $container = $this->container;
        ob_start();
        include __DIR__ . '/../Views/frontend/book-detail.php';
        $content = ob_get_clean();

        // FAIR Signposting (RFC 9264) — machine-discoverable link relations
        $bookArr  = $book;
        $tipoRes  = \App\Support\MediaLabels::resolveTipoMedia(
            isset($bookArr['formato'])    && is_string($bookArr['formato'])    ? $bookArr['formato']    : null,
            isset($bookArr['tipo_media']) && is_string($bookArr['tipo_media']) ? $bookArr['tipo_media'] : null
        );

        $signLinks = [
            '<https://schema.org/' . \App\Support\MediaLabels::schemaOrgType($tipoRes) . '>; rel="type"',
        ];
        if ($bibframePluginActive) {
            $bibframeBookPath = str_replace('{id}', (string) $book_id, RouteTranslator::route('bibframe.book'));
            array_unshift($signLinks, '<' . absoluteUrl($bibframeBookPath) . '>; rel="describedby"; type="application/ld+json"');
        }
        $primaryAuthor = $authors[0] ?? null;
        if (is_array($primaryAuthor)) {
            $viafUri = '';
            if (!empty($primaryAuthor['viaf_uri']) && is_string($primaryAuthor['viaf_uri'])) {
                $viafUri = $primaryAuthor['viaf_uri'];
            } elseif (!empty($primaryAuthor['viaf_id']) && is_string($primaryAuthor['viaf_id'])) {
                $viafUri = 'https://viaf.org/viaf/' . rawurlencode($primaryAuthor['viaf_id']);
            }
            if ($viafUri !== '' && filter_var($viafUri, FILTER_VALIDATE_URL) !== false
                && preg_match('/^https?:\/\//', $viafUri)
                && strpbrk($viafUri, "<>,\r\n") === false) {
                $signLinks[] = '<' . $viafUri . '>; rel="author"';
            }
        }

        $response->getBody()->write($content);
        return $response
            ->withHeader('Content-Type', 'text/html')
            ->withHeader('Link', implode(', ', $signLinks));
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
        $rawTipoMedia = $params['tipo_media'] ?? '';
        if (is_array($rawTipoMedia)) {
            $rawTipoMedia = $rawTipoMedia[0] ?? '';
        }

        return [
            'search' => $searchTerm,
            'genere_id' => (int)($params['genere_id'] ?? 0),
            'disponibilita' => $params['disponibilita'] ?? '',
            'editore' => $params['editore'] ?? '',
            'anno_min' => $params['anno_min'] ?? '',
            'anno_max' => $params['anno_max'] ?? '',
            'tipo_media' => trim((string) $rawTipoMedia),
            'autore_id' => (int)($params['autore_id'] ?? 0),
            'sort' => $params['sort'] ?? 'newest'
        ];
    }

    private function buildWhereConditions(array $filters, mysqli $db): array
    {
        $conditions = [];
        $params = [];
        $types = '';

        // Use defensive isset() and trim() for robustness against future changes
        // Strict comparison instead of empty() - empty("0") returns true which breaks searches for "0"
        $searchQuery = isset($filters['search']) ? trim((string)$filters['search']) : '';

        if ($searchQuery !== '') {
            // Denormalized FULLTEXT search: `search_index` folds title, subtitle,
            // author names, publisher name(s), ISBN/EAN and keywords per book, so
            // a single MATCH(l.search_index) AGAINST(...) replaces the former
            // OR-of-LIKE chain plus per-row author EXISTS subquery. Each word is
            // required (BOOLEAN MODE +word*); tokens shorter than the FULLTEXT
            // min token size fall back to LIKE on the same column.
            $searchCondition = \App\Support\SearchIndexBuilder::buildSearchCondition($db, 'l.search_index', $searchQuery);
            if ($searchCondition !== null) {
                $conditions[] = $searchCondition['sql'];
                $params = array_merge($params, $searchCondition['params']);
                $types .= $searchCondition['types'];
            }
        }

        if (!empty($filters['genere_id'])) {
            $genreId = (int) $filters['genere_id'];
            // Match genre ID at any level of the hierarchy
            $conditions[] = "(l.genere_id = ? OR g.parent_id = ? OR gp.parent_id = ? OR l.sottogenere_id = ?)";
            $params[] = $genreId;
            $params[] = $genreId;
            $params[] = $genreId;
            $params[] = $genreId;
            $types .= 'iiii';
        }

        if (!empty($filters['editore'])) {
            // Match the publisher whether it is the book's primary (the joined
            // `e`) or a secondary one in the multi-publisher junction (issue
            // #143), so the catalog filter results agree with the publisher
            // facet count (which already counts secondaries). Gate the junction
            // subquery on table existence (pre-migration safety).
            if (\App\Support\SchemaInfo::hasLibriEditori($db)) {
                $conditions[] = "(e.nome = ? OR EXISTS (SELECT 1 FROM libri_editori le2 JOIN editori e2 ON le2.editore_id = e2.id WHERE le2.libro_id = l.id AND e2.nome = ?))";
                $params[] = $filters['editore'];
                $params[] = $filters['editore'];
                $types .= 'ss';
            } else {
                $conditions[] = "e.nome = ?";
                $params[] = $filters['editore'];
                $types .= 's';
            }
        }

        // Filtra sulla disponibilità reale (copie_disponibili), coerente con badge e
        // bottone prestito. Usare l.stato escludeva i libri 'prenotato' (copie a 0)
        // da entrambi i filtri.
        if ($filters['disponibilita'] === 'disponibile') {
            $conditions[] = "l.copie_disponibili > 0";
        } elseif ($filters['disponibilita'] === 'prestato') {
            $conditions[] = "l.copie_disponibili <= 0";
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

        if (!empty($filters['tipo_media']) && $this->hasLibriColumn($db, 'tipo_media')) {
            $conditions[] = "l.tipo_media = ?";
            $params[] = $filters['tipo_media'];
            $types .= 's';
        }

        if (!empty($filters['autore_id'])) {
            $conditions[] = "EXISTS (SELECT 1 FROM libri_autori la_f WHERE la_f.libro_id = l.id AND la_f.autore_id = ? AND la_f.ruolo IN ('principale', 'co-autore'))";
            $params[] = (int) $filters['autore_id'];
            $types .= 'i';
        }

        return [
            'conditions' => $conditions,
            'params' => $params,
            'types' => $types
        ];
    }

    private function hasLibriColumn(mysqli $db, string $column): bool
    {
        static $columnCache = [];

        if (!array_key_exists($column, $columnCache)) {
            $result = $db->query("SHOW COLUMNS FROM libri LIKE '" . $db->real_escape_string($column) . "'");
            $columnCache[$column] = $result !== false && $result->num_rows > 0;
        }

        return $columnCache[$column];
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
                // References the `autore_cognome` column alias exposed by the
                // catalog SELECT. `IS NULL` returns 0 for present surnames and
                // 1 for absent, so NULL books always sort last regardless of
                // direction (MySQL's default would bubble them to the top of
                // ASC). The alias keeps the correlated subquery evaluated
                // once per row instead of twice.
                return 'ORDER BY autore_cognome IS NULL, autore_cognome ASC, l.id ASC';
            case 'author_desc':
                return 'ORDER BY autore_cognome IS NULL, autore_cognome DESC, l.id DESC';
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
    $filtersForGeneri['genere_id'] = 0;
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
                   LEFT JOIN generi sg ON l.sottogenere_id = sg.id
                   WHERE (
                       l.genere_id = g.id
                       OR l.sottogenere_id = g.id
                       OR l.genere_id IN (SELECT id FROM generi WHERE parent_id = g.id)
                       OR l.sottogenere_id IN (SELECT id FROM generi WHERE parent_id = g.id)
                       OR l.genere_id IN (SELECT gc.id FROM generi gc JOIN generi gp ON gc.parent_id = gp.id WHERE gp.parent_id = g.id)
                       OR l.sottogenere_id IN (SELECT gc.id FROM generi gc JOIN generi gp ON gc.parent_id = gp.id WHERE gp.parent_id = g.id)
                   )
                   {$whereClauseGen}
               ) AS cnt
        FROM (
            -- Select all genres that have books via genere_id or sottogenere_id
            SELECT DISTINCT g.id FROM generi g
            JOIN libri l ON (g.id = l.genere_id OR g.id = l.sottogenere_id) AND l.deleted_at IS NULL
            UNION
            SELECT DISTINCT gp.id FROM generi g
            JOIN generi gp ON g.parent_id = gp.id
            JOIN libri l ON (g.id = l.genere_id OR g.id = l.sottogenere_id) AND l.deleted_at IS NULL
            UNION
            SELECT DISTINCT gpp.id FROM generi g
            JOIN generi gp ON g.parent_id = gp.id
            JOIN generi gpp ON gp.parent_id = gpp.id
            JOIN libri l ON (g.id = l.genere_id OR g.id = l.sottogenere_id) AND l.deleted_at IS NULL
        ) as genre_ids
        JOIN generi g ON genre_ids.id = g.id
        ORDER BY g.parent_id, g.nome
    ";

    $cacheKeyGeneri = 'genre_tree_' . md5($queryGeneri . serialize($paramsGen));
    $generi_flat = \App\Support\QueryCache::remember($cacheKeyGeneri, function() use ($db, $queryGeneri, $typesGen, $paramsGen) {
        $stmt = $db->prepare($queryGeneri);
        if ($stmt === false) {
            \App\Support\SecureLogger::error('FrontendController::getFilterOptions prepare failed', ['db_error' => $db->error]);
            return [];
        }
        if (!empty($paramsGen)) {
            $stmt->bind_param($typesGen, ...$paramsGen);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }, 300);
    $options['generi'] = $this->buildGenreHierarchy($generi_flat);

    // ---------- Editori ----------
    // Build filter conditions excluding the current 'editore' filter
    $filtersForEditori = $filters;
    $filtersForEditori['editore'] = '';
    $whereEd = $this->buildWhereConditions($filtersForEditori, $db);
    $conditionsEd = $whereEd['conditions'];
    $paramsEd = $whereEd['params'];
    $typesEd = $whereEd['types'];

    // issue #143: also count books where the publisher is a secondary one in
    // the junction; gate on table existence (pre-migration safety).
    $facetExists = \App\Support\SchemaInfo::hasLibriEditori($db)
        ? " OR EXISTS (SELECT 1 FROM libri_editori le WHERE le.libro_id = l.id AND le.editore_id = e.id)"
        : "";
    $queryEditori = "
        SELECT e.id, e.nome, COUNT(DISTINCT l.id) AS cnt
        FROM editori e
        JOIN libri l ON (e.id = l.editore_id{$facetExists})
                        AND l.deleted_at IS NULL
        LEFT JOIN generi g ON l.genere_id = g.id
        LEFT JOIN generi gp ON g.parent_id = gp.id
        LEFT JOIN generi gpp ON gp.parent_id = gpp.id
        LEFT JOIN generi sg ON l.sottogenere_id = sg.id
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
        LEFT JOIN generi sg ON l.sottogenere_id = sg.id
        WHERE l.deleted_at IS NULL
    ";
    if (!empty($conditionsAvail)) {
        // Keep all conditions except availability filter (which is excluded via filtersForAvailability)
        // Note: The availability filter is never in conditions because it's excluded, so we just use them as-is
        $availabilityBaseQuery .= " AND " . implode(' AND ', $conditionsAvail);
    }

    // Count available books (base query always has WHERE l.deleted_at IS NULL).
    // Conteggio coerente col filtro: basato su copie_disponibili, non su l.stato.
    $queryAvailable = "SELECT COUNT(DISTINCT l.id) as cnt " . $availabilityBaseQuery . " AND l.copie_disponibili > 0";
    $stmt = $db->prepare($queryAvailable);
    if (!empty($paramsAvail)) {
        $stmt->bind_param($typesAvail, ...$paramsAvail);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $availableCount = $row['cnt'] ?? 0;

    // Count borrowed/unavailable books (coerente col filtro: copie_disponibili <= 0)
    $queryBorrowed = "SELECT COUNT(DISTINCT l.id) as cnt " . $availabilityBaseQuery . " AND l.copie_disponibili <= 0";
    $stmt = $db->prepare($queryBorrowed);
    if (!empty($paramsAvail)) {
        $stmt->bind_param($typesAvail, ...$paramsAvail);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $borrowedCount = $row['cnt'] ?? 0;

    $options['availability_stats'] = [
        'available' => $availableCount,
        'borrowed' => $borrowedCount,
        'total' => $availableCount + $borrowedCount
    ];

    // Shared LEFT JOIN block so the remove-self WHERE conditions (which reference
    // e./g./gp./gpp./sg. aliases) resolve in every facet query below.
    $facetJoins = "
        LEFT JOIN editori e ON l.editore_id = e.id
        LEFT JOIN generi g ON l.genere_id = g.id
        LEFT JOIN generi gp ON g.parent_id = gp.id
        LEFT JOIN generi gpp ON gp.parent_id = gpp.id
        LEFT JOIN generi sg ON l.sottogenere_id = sg.id
    ";

    // ---------- Autori (remove-self: count excluding the autore_id filter) ----------
    $options['autori'] = [];
    $filtersForAutori = $filters;
    $filtersForAutori['autore_id'] = 0;
    $whereAu = $this->buildWhereConditions($filtersForAutori, $db);
    $queryAutori = "
        SELECT a.id, " . \App\Support\AuthorName::displaySql('a') . " AS nome, COUNT(DISTINCT l.id) AS cnt
        FROM autori a
        JOIN libri_autori la ON la.autore_id = a.id
        JOIN libri l ON l.id = la.libro_id AND l.deleted_at IS NULL
        {$facetJoins}
        WHERE la.ruolo IN ('principale', 'co-autore')
    ";
    if (!empty($whereAu['conditions'])) {
        $queryAutori .= " AND " . implode(' AND ', $whereAu['conditions']);
    }
    $queryAutori .= " GROUP BY a.id, a.nome, a.pseudonimo HAVING cnt > 0 ORDER BY a.nome LIMIT 100";
    $stmt = $db->prepare($queryAutori);
    if ($stmt !== false) {
        if (!empty($whereAu['params'])) {
            $stmt->bind_param($whereAu['types'], ...$whereAu['params']);
        }
        $stmt->execute();
        $options['autori'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // ---------- Tipo media (remove-self + per-type counts, only types present) ----------
    $options['media_types'] = [];
    if ($this->hasLibriColumn($db, 'tipo_media')) {
        $mediaLabels = \App\Support\MediaLabels::allTypes();
        $filtersForMedia = $filters;
        $filtersForMedia['tipo_media'] = '';
        $whereMt = $this->buildWhereConditions($filtersForMedia, $db);
        $queryMt = "
            SELECT l.tipo_media AS value, COUNT(DISTINCT l.id) AS cnt
            FROM libri l
            {$facetJoins}
            WHERE l.deleted_at IS NULL AND l.tipo_media IS NOT NULL AND l.tipo_media <> ''
        ";
        if (!empty($whereMt['conditions'])) {
            $queryMt .= " AND " . implode(' AND ', $whereMt['conditions']);
        }
        $queryMt .= " GROUP BY l.tipo_media HAVING cnt > 0 ORDER BY cnt DESC, l.tipo_media";
        $stmt = $db->prepare($queryMt);
        if ($stmt !== false) {
            if (!empty($whereMt['params'])) {
                $stmt->bind_param($whereMt['types'], ...$whereMt['params']);
            }
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                $val = (string) $r['value'];
                $options['media_types'][] = [
                    'value' => $val,
                    'label' => $mediaLabels[$val]['label'] ?? ucfirst($val),
                    'icon'  => $mediaLabels[$val]['icon'] ?? 'fa-tag',
                    'cnt'   => (int) $r['cnt'],
                ];
            }
        }
    }

    // ---------- Anno: dynamic bounds (remove-self on anno_min/anno_max) ----------
    $filtersForAnno = $filters;
    $filtersForAnno['anno_min'] = '';
    $filtersForAnno['anno_max'] = '';
    $whereAn = $this->buildWhereConditions($filtersForAnno, $db);
    $queryAnno = "
        SELECT MIN(l.anno_pubblicazione) AS ymin, MAX(l.anno_pubblicazione) AS ymax,
               COUNT(DISTINCT l.anno_pubblicazione) AS ydistinct
        FROM libri l
        {$facetJoins}
        WHERE l.deleted_at IS NULL AND l.anno_pubblicazione > 0
    ";
    if (!empty($whereAn['conditions'])) {
        $queryAnno .= " AND " . implode(' AND ', $whereAn['conditions']);
    }
    $annoBounds = ['min' => 0, 'max' => 0, 'distinct' => 0];
    $stmt = $db->prepare($queryAnno);
    if ($stmt !== false) {
        if (!empty($whereAn['params'])) {
            $stmt->bind_param($whereAn['types'], ...$whereAn['params']);
        }
        $stmt->execute();
        $rowAn = $stmt->get_result()->fetch_assoc() ?: [];
        $annoBounds = [
            'min' => (int) ($rowAn['ymin'] ?? 0),
            'max' => (int) ($rowAn['ymax'] ?? 0),
            'distinct' => (int) ($rowAn['ydistinct'] ?? 0),
        ];
    }
    $options['anno_bounds'] = $annoBounds;

    // ---------- Suppress flags: a facet with <=1 reachable value is noise ----------
    // Genre uses a stricter rule: only suppressed when ZERO genres are reachable
    // (an empty "Generi" header is pure noise) — a single genre is still useful
    // to show, and the hierarchical drill-down stays otherwise.
    $genreReachable = 0;
    $flattenGenres = function (array $nodes) use (&$flattenGenres, &$genreReachable): void {
        foreach ($nodes as $n) {
            if ((int) ($n['cnt'] ?? 0) > 0) {
                $genreReachable++;
            }
            if (!empty($n['children']) && is_array($n['children'])) {
                $flattenGenres($n['children']);
            }
        }
    };
    if (!empty($options['generi'])) {
        $flattenGenres($options['generi']);
    }
    $options['suppress'] = [
        'genere'     => ($genreReachable === 0),
        'editore'    => count($options['editori']) <= 1,
        'autore'     => count($options['autori']) <= 1,
        'tipo_media' => count($options['media_types']) <= 1,
        'anno'       => ($annoBounds['distinct'] <= 1),
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
        $genere_id = 0;

        switch ($section) {
            case 'latest':
                // Read sort preference from CMS settings
                $latestSort = $this->getLatestBooksSort($db);

                // Ultimi libri aggiunti
                $query = "
                    SELECT l.*,
                           (SELECT " . \App\Support\AuthorName::displaySql('a') . " FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                            WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore,
                           g.nome AS genere
                    FROM libri l
                    LEFT JOIN generi g ON l.genere_id = g.id
                    WHERE l.deleted_at IS NULL
                    ORDER BY l.{$latestSort} DESC
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
                           (SELECT " . \App\Support\AuthorName::displaySql('a') . " FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                            WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore
                    FROM libri l
                    WHERE l.genere_id = ? AND l.deleted_at IS NULL
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
                $countStmt = $db->prepare("SELECT COUNT(*) as total FROM libri WHERE deleted_at IS NULL");
                $countStmt->execute();
                $countResult = $countStmt->get_result();
                break;
            case 'genre':
                $countStmt = $db->prepare("SELECT COUNT(*) as total FROM libri WHERE genere_id = ? AND deleted_at IS NULL");
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
        $authorQuery = "SELECT id, nome, pseudonimo, biografia FROM autori WHERE nome = ? LIMIT 1";
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
            WHERE a.nome = ? AND l.deleted_at IS NULL
        ";
        $stmt = $db->prepare($countQuery);
        $stmt->bind_param('s', $authorName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $totalBooks = $row['total'] ?? 0;
        $totalPages = ceil($totalBooks / $limit);

        // Query per i libri dell'autore
        $booksQuery = "
            SELECT DISTINCT l.*,
                   (SELECT " . \App\Support\AuthorName::displaySql('a2') . " FROM libri_autori la2 JOIN autori a2 ON la2.autore_id = a2.id
                    WHERE la2.libro_id = l.id AND la2.ruolo = 'principale' LIMIT 1) AS autore,
                   e.nome AS editore,
                   g.nome AS genere
            FROM libri l
            JOIN libri_autori la ON l.id = la.libro_id
            JOIN autori a ON la.autore_id = a.id
            LEFT JOIN editori e ON l.editore_id = e.id
            LEFT JOIN generi g ON l.genere_id = g.id
            WHERE a.nome = ? AND l.deleted_at IS NULL
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

        $container = $this->container;
        ob_start();
        $title = "Libri di " . htmlspecialchars(\App\Support\AuthorName::display($author), ENT_QUOTES, 'UTF-8');
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

        // Find EVERY publisher with this name. Duplicates legitimately share a
        // name (the merge feature exists because of that), so resolving a single
        // id with LIMIT 1 would hide the books of the other same-named publishers.
        $publisherQuery = "SELECT id, nome, indirizzo, sito_web FROM editori WHERE nome = ? ORDER BY id";
        $stmt = $db->prepare($publisherQuery);
        $stmt->bind_param('s', $publisherName);
        $stmt->execute();
        $publisherResult = $stmt->get_result();

        $publisher = null;
        $publisherIds = [];
        while ($prow = $publisherResult->fetch_assoc()) {
            if ($publisher === null) {
                $publisher = $prow;
            }
            $publisherIds[] = (int) $prow['id'];
        }
        if ($publisherIds === []) {
            return $this->render404($response);
        }
        $publisherId = $publisherIds[0];

        // issue #143: match the publisher whether it is the primary
        // (libri.editore_id) or a secondary one in the multi-publisher junction;
        // gate the junction subquery on table existence so the public archive
        // degrades to primary-only on pre-migration installs instead of a 500.
        $hasJunction = \App\Support\SchemaInfo::hasLibriEditori($db);
        $ph = implode(',', array_fill(0, count($publisherIds), '?'));
        $idTypes = str_repeat('i', count($publisherIds));
        $exists = $hasJunction
            ? " OR EXISTS (SELECT 1 FROM libri_editori le WHERE le.libro_id = l.id AND le.editore_id IN ($ph))"
            : "";

        // Count total books
        $countQuery = "
            SELECT COUNT(l.id) as total
            FROM libri l
            WHERE (l.editore_id IN ($ph){$exists})
                  AND l.deleted_at IS NULL
        ";
        $stmt = $db->prepare($countQuery);
        $countTypes = $hasJunction ? $idTypes . $idTypes : $idTypes;
        $countArgs  = $hasJunction ? array_merge($publisherIds, $publisherIds) : $publisherIds;
        $stmt->bind_param($countTypes, ...$countArgs);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $totalBooks = $row['total'] ?? 0;
        $totalPages = ceil($totalBooks / $limit);

        // Query per i libri dell'editore
        $booksQuery = "
            SELECT l.*,
                   (SELECT " . \App\Support\AuthorName::displaySql('a') . " FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore,
                   e.nome AS editore,
                   g.nome AS genere
            FROM libri l
            LEFT JOIN editori e ON l.editore_id = e.id
            LEFT JOIN generi g ON l.genere_id = g.id
            WHERE (l.editore_id IN ($ph){$exists})
                  AND l.deleted_at IS NULL
            ORDER BY l.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $db->prepare($booksQuery);
        $booksTypes = ($hasJunction ? $idTypes . $idTypes : $idTypes) . 'ii';
        $booksArgs  = $hasJunction
            ? array_merge($publisherIds, $publisherIds, [$limit, $offset])
            : array_merge($publisherIds, [$limit, $offset]);
        $stmt->bind_param($booksTypes, ...$booksArgs);
        $stmt->execute();
        $result = $stmt->get_result();

        $books = [];
        while ($book = $result->fetch_assoc()) {
            $books[] = $book;
        }

        ob_start();
        $title = "Libri di " . $publisher['nome'];

        // SEO Variables — escaping is handled at template output time by HtmlHelper::e()
        $seoTitle = "Libri di {$publisher['nome']} - Catalogo Editore | Biblioteca";
        $seoDescription = "Scopri tutti i libri pubblicati da {$publisher['nome']} disponibili nella nostra biblioteca. {$totalBooks} libr" . ($totalBooks === 1 ? 'o' : 'i') . " disponibili per il prestito.";
        $seoCanonical = absoluteUrl(RouteTranslator::route('publisher') . '/' . urlencode($publisher['nome']));
        $seoImage = absoluteUrl('/uploads/copertine/placeholder.jpg');

        $archive_type = 'editore';
        $archive_info = $publisher;
        $container = $this->container;
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
        $genreId = (int) $genre['id'];

        // Collect this genre + all descendants (any depth via BFS)
        $visited = [$genreId => true];
        $queue = [$genreId];
        while (!empty($queue)) {
            $placeholders = implode(',', array_fill(0, count($queue), '?'));
            $descStmt = $db->prepare("SELECT id FROM generi WHERE parent_id IN ($placeholders)");
            if ($descStmt === false) {
                \App\Support\SecureLogger::error('Failed to prepare descendant genre query', ['db_error' => $db->error]);
                return $response->withStatus(500);
            }
            $types = str_repeat('i', count($queue));
            $descStmt->bind_param($types, ...$queue);
            $descStmt->execute();
            $descResult = $descStmt->get_result();
            $queue = [];
            while ($row = $descResult->fetch_assoc()) {
                $childId = (int) $row['id'];
                if (!isset($visited[$childId])) {
                    $visited[$childId] = true;
                    $queue[] = $childId;
                }
            }
            $descStmt->close();
        }

        $genreIds = array_keys($visited);
        $idPlaceholders = implode(',', array_fill(0, count($genreIds), '?'));
        $idTypes = str_repeat('i', count($genreIds));

        // Count total books
        $countQuery = "
            SELECT COUNT(l.id) as total
            FROM libri l
            WHERE l.genere_id IN ($idPlaceholders) AND l.deleted_at IS NULL
        ";
        $stmt = $db->prepare($countQuery);
        if ($stmt === false) {
            \App\Support\SecureLogger::error('Failed to prepare genre count query', ['db_error' => $db->error]);
            return $response->withStatus(500);
        }
        $stmt->bind_param($idTypes, ...$genreIds);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $totalBooks = $row['total'] ?? 0;
        $totalPages = ceil($totalBooks / $limit);

        // Query per i libri del genere e dei suoi discendenti
        $booksQuery = "
            SELECT l.*,
                   (SELECT " . \App\Support\AuthorName::displaySql('a') . " FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore,
                   e.nome AS editore,
                   g.nome AS genere
            FROM libri l
            JOIN generi g ON l.genere_id = g.id
            LEFT JOIN editori e ON l.editore_id = e.id
            WHERE l.genere_id IN ($idPlaceholders) AND l.deleted_at IS NULL
            ORDER BY l.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $db->prepare($booksQuery);
        if ($stmt === false) {
            \App\Support\SecureLogger::error('Failed to prepare genre books query', ['db_error' => $db->error]);
            return $response->withStatus(500);
        }
        $allParams = array_merge($genreIds, [$limit, $offset]);
        $stmt->bind_param($idTypes . 'ii', ...$allParams);
        $stmt->execute();
        $result = $stmt->get_result();

        $books = [];
        while ($book = $result->fetch_assoc()) {
            $books[] = $book;
        }

        ob_start();
        $title = "Libri di genere " . $genre['nome'];

        // SEO Variables — escaping is handled at template output time by HtmlHelper::e()
        $seoTitle = "Libri di {$genre['nome']} - Catalogo per Genere | Biblioteca";
        $seoDescription = "Esplora tutti i libri del genere {$genre['nome']} disponibili nella nostra biblioteca. {$totalBooks} libr" . ($totalBooks === 1 ? 'o' : 'i') . " disponibili per il prestito.";
        $seoCanonical = absoluteUrl(RouteTranslator::route('genre') . '/' . urlencode($genre['nome']));
        $seoImage = absoluteUrl('/uploads/copertine/placeholder.jpg');

        $archive_type = 'genere';
        $archive_info = $genre;
        $container = $this->container;
        include __DIR__ . '/../Views/frontend/archive.php';
        $content = ob_get_clean();

        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/html');
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
     * Get the sort column for the "latest books" section from CMS settings.
     */
    private function getLatestBooksSort(\mysqli $db): string
    {
        $stmt = $db->prepare("SELECT content FROM home_content WHERE section_key = 'latest_books_title' LIMIT 1");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $sort = $row['content'] ?? 'created_at';
        return in_array($sort, ['created_at', 'updated_at'], true) ? $sort : 'created_at';
    }

    /**
     * Get the appropriate genres to display based on current filter selection
     * Implements hierarchical navigation:
     * - Level 0: Show all root genres (parent_id = null)
     * - Level 1: Show children of selected root genre
     * - Level 2: Show children of selected second-level genre
     *
     * @param array $allGenres Full genre hierarchy from buildGenreHierarchy
     * @param int $selectedGenreId Currently selected genre ID (0 = none)
     * @return array ['genres' => display genres, 'level' => current level, 'parent' => parent genre for back button]
     */
    private function getDisplayGenres(array $allGenres, int $selectedGenreId): array
    {
        if ($selectedGenreId === 0) {
            // Level 0: Show all root genres
            return [
                'genres' => $allGenres,
                'level' => 0,
                'parent' => null
            ];
        }

        // Find the selected genre in the hierarchy by ID
        $selectedGenreData = null;
        $parentGenre = null;

        // Search in root genres
        foreach ($allGenres as $genre) {
            if ((int) $genre['id'] === $selectedGenreId) {
                $selectedGenreData = $genre;
                break;
            }
            // Search in children
            if (!empty($genre['children'])) {
                foreach ($genre['children'] as $child) {
                    if ((int) $child['id'] === $selectedGenreId) {
                        $selectedGenreData = $child;
                        $parentGenre = $genre;
                        break;
                    }
                    // Search in grandchildren
                    if (!empty($child['children'])) {
                        foreach ($child['children'] as $grandchild) {
                            if ((int) $grandchild['id'] === $selectedGenreId) {
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
        // #163: also load photo + relevant source/website links for the public page.
        $authorQuery = "SELECT id, nome, pseudonimo, biografia, sito_web, foto, collegamenti FROM autori WHERE id = ? LIMIT 1";
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
            WHERE la.autore_id = ? AND l.deleted_at IS NULL
        ";
        $stmt = $db->prepare($countQuery);
        $stmt->bind_param('i', $authorId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $totalBooks = $row['total'] ?? 0;
        $totalPages = ceil($totalBooks / $limit);

        // Query per i libri dell'autore. La disponibilità arriva da
        // l.copie_disponibili (via l.*): è il campo canonico mantenuto da
        // DataIntegrity e usato da catalogo/scheda. Ricalcolarla qui contando i
        // prestiti per stato ignorava overlap di date, attivo=1, pendenti con
        // copia e copie non prestabili, mostrando disponibilità incoerenti.
        $booksQuery = "
            SELECT DISTINCT l.*,
                   (SELECT " . \App\Support\AuthorName::displaySql('a2') . " FROM libri_autori la2 JOIN autori a2 ON la2.autore_id = a2.id
                    WHERE la2.libro_id = l.id AND la2.ruolo = 'principale' LIMIT 1) AS autore,
                   e.nome AS editore,
                   g.nome AS genere
            FROM libri l
            JOIN libri_autori la ON l.id = la.libro_id
            LEFT JOIN editori e ON l.editore_id = e.id
            LEFT JOIN generi g ON l.genere_id = g.id
            WHERE la.autore_id = ? AND l.deleted_at IS NULL
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
        $container = $this->container;
        ob_start();
        $title = "Libri di " . htmlspecialchars(\App\Support\AuthorName::display($author), ENT_QUOTES, 'UTF-8');
        $archive_type = 'autore';
        $archive_info = $author;
        include __DIR__ . '/../Views/frontend/archive.php';
        $content = ob_get_clean();

        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/html');
    }


    private function getRelatedBooks(mysqli $db, int $book_id, array $book, array $authors, array $seriesBooks = []): array
    {
        $related_books = [];
        $limit = 3;

        // Priority 0: Same series (collana) — reuse pre-fetched seriesBooks to avoid duplicate query
        if (!empty($seriesBooks)) {
            foreach (array_slice($seriesBooks, 0, $limit) as $sb) {
                if (!isset($sb['autori'])) {
                    $sb['autori'] = $sb['autore_principale'] ?? '';
                }
                $related_books[] = $sb;
            }
        }

        // Priority 1: Same author(s)
        if (count($related_books) < $limit && !empty($authors)) {
            $remaining = $limit - count($related_books);
            $creatorAuthors = array_values(array_filter(
                $authors,
                static fn (array $author): bool => in_array((string) ($author['ruolo'] ?? ''), ['principale', 'co-autore'], true)
            ));
            $author_ids = array_column($creatorAuthors, 'id');
            if ($author_ids === []) {
                $author_ids = [0];
            }
            $exclude_ids = array_merge([$book_id], array_column($related_books, 'id'));
            $authorPlaceholders = implode(',', array_fill(0, count($author_ids), '?'));
            $excludePlaceholders = implode(',', array_fill(0, count($exclude_ids), '?'));

            $query = "
                SELECT DISTINCT l.*,
                       GROUP_CONCAT(DISTINCT " . \App\Support\AuthorName::displaySql('a') . " SEPARATOR ', ') as autori
                FROM libri l
                LEFT JOIN libri_autori la ON l.id = la.libro_id AND la.ruolo IN ('principale', 'co-autore')
                LEFT JOIN autori a ON la.autore_id = a.id
                WHERE la.autore_id IN ($authorPlaceholders)
                AND l.id NOT IN ($excludePlaceholders)
                AND l.deleted_at IS NULL
                GROUP BY l.id
                ORDER BY l.created_at DESC
                LIMIT ?
            ";

            $stmt = $db->prepare($query);
            if ($stmt) {
                $types = str_repeat('i', count($author_ids)) . str_repeat('i', count($exclude_ids)) . 'i';
                $params = array_merge($author_ids, $exclude_ids, [$remaining]);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $related_books[] = $row;
                }
                $stmt->close();
            }
        }

        // Priority 2: Same genre - second most relevant
        if (count($related_books) < $limit && !empty($book['genere_id'])) {
            $remaining = $limit - count($related_books);
            $exclude_ids = array_merge([$book_id], array_column($related_books, 'id'));
            $placeholders = implode(',', array_fill(0, count($exclude_ids), '?'));

            $query = "
                SELECT DISTINCT l.*,
                       GROUP_CONCAT(DISTINCT " . \App\Support\AuthorName::displaySql('a') . " SEPARATOR ', ') as autori
                FROM libri l
                LEFT JOIN libri_autori la ON l.id = la.libro_id AND la.ruolo IN ('principale', 'co-autore')
                LEFT JOIN autori a ON la.autore_id = a.id
                WHERE l.genere_id = ?
                AND l.id NOT IN ($placeholders)
                AND l.deleted_at IS NULL
                GROUP BY l.id
                ORDER BY l.created_at DESC
                LIMIT ?
            ";

            $stmt = $db->prepare($query);
            if ($stmt) {
                $types = 'i' . str_repeat('i', count($exclude_ids)) . 'i';
                $params = array_merge([$book['genere_id']], $exclude_ids, [$remaining]);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $related_books[] = $row;
                }
                $stmt->close();
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
                       GROUP_CONCAT(DISTINCT " . \App\Support\AuthorName::displaySql('a') . " SEPARATOR ', ') as autori
                FROM libri l
                LEFT JOIN libri_autori la ON l.id = la.libro_id AND la.ruolo IN ('principale', 'co-autore')
                LEFT JOIN autori a ON la.autore_id = a.id
                WHERE l.id NOT IN ($placeholders)
                AND l.deleted_at IS NULL
                GROUP BY l.id
                ORDER BY l.created_at DESC
                LIMIT ?
            ";

            $stmt = $db->prepare($query);
            if ($stmt) {
                $types = str_repeat('i', count($exclude_ids)) . 'i';
                $params = array_merge($exclude_ids, [$remaining]);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $related_books[] = $row;
                }
                $stmt->close();
            }
        }

        return array_slice($related_books, 0, $limit);
    }

    /**
     * Display events list page
     */
    public function events(Request $request, Response $response, mysqli $db): Response
    {
        // CRITICAL: Set UTF-8 charset
        $db->set_charset('utf8mb4');

        // Check if events page is enabled
        $repository = new \App\Models\SettingsRepository($db);
        $eventsEnabled = $repository->get('cms', 'events_page_enabled', '0');

        if ($eventsEnabled !== '1') {
            // Events page disabled, return 404
            $response->getBody()->write('Pagina non trovata');
            return $response->withStatus(404);
        }

        // Pagination
        $queryParams = $request->getQueryParams();
        $page = max(1, (int)($queryParams['page'] ?? 1));
        $perPage = 12;
        $offset = ($page - 1) * $perPage;

        // Get total count of active events
        $stmt_count = $db->prepare("SELECT COUNT(*) as total FROM events WHERE is_active = 1");
        $stmt_count->execute();
        $countResult = $stmt_count->get_result();
        $countRow = $countResult->fetch_assoc();
        $totalEvents = $countRow['total'] ?? 0;
        $totalPages = (int)ceil($totalEvents / $perPage);
        $stmt_count->close();

        // Get events for current page
        $stmt = $db->prepare("
            SELECT id, title, slug, content, event_date, event_time, featured_image,
                   seo_title, seo_description
            FROM events
            WHERE is_active = 1
            ORDER BY event_date DESC, created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param('ii', $perPage, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $events = [];
        while ($row = $result->fetch_assoc()) {
            $events[] = $row;
        }
        $stmt->close();

        // SEO meta tags for events list page
        $seoTitle = __("Eventi") . ' - ' . \App\Support\ConfigStore::get('app.name');
        $seoDescription = __("Scopri tutti gli eventi organizzati dalla biblioteca");
        $seoCanonical = absoluteUrl(RouteTranslator::route('events'));

        $container = $this->container;
        ob_start();
        include __DIR__ . '/../Views/frontend/events.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Display single event page
     */
    public function event(Request $request, Response $response, mysqli $db, array $args): Response
    {
        // CRITICAL: Set UTF-8 charset
        $db->set_charset('utf8mb4');

        $slug = $args['slug'] ?? '';

        // Check if events page is enabled
        $repository = new \App\Models\SettingsRepository($db);
        $eventsEnabled = $repository->get('cms', 'events_page_enabled', '0');

        if ($eventsEnabled !== '1') {
            $response->getBody()->write('Pagina non trovata');
            return $response->withStatus(404);
        }

        // Featured-image layout (issue #137): admin-controlled rendering
        // strategy for the event hero image. Validated against the same
        // allow-list used by SettingsController to defend against legacy/
        // corrupted DB values.
        $eventImageLayoutAllowed = ['full', 'banner', 'contained', 'thumb'];
        $eventImageLayout = strtolower((string) $repository->get('cms', 'event_image_layout', 'contained'));
        if (!in_array($eventImageLayout, $eventImageLayoutAllowed, true)) {
            $eventImageLayout = 'contained';
        }

        // Get event by slug
        $stmt = $db->prepare("
            SELECT id, title, slug, content, event_date, event_time, featured_image,
                   seo_title, seo_description, seo_keywords, og_image,
                   og_title, og_description, og_type, og_url,
                   twitter_card, twitter_title, twitter_description, twitter_image
            FROM events
            WHERE slug = ? AND is_active = 1
        ");
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $result = $stmt->get_result();
        $event = $result->fetch_assoc();
        $stmt->close();

        if (!$event) {
            $response->getBody()->write('Evento non trovato');
            return $response->withStatus(404);
        }

        // Prepare SEO variables with fallbacks
        $appName = \App\Support\ConfigStore::get('app.name');

        // Extract excerpt from content (first 160 chars of plain text)
        $contentPlain = strip_tags($event['content'] ?? '');
        $excerpt = mb_substr($contentPlain, 0, 160);
        if (mb_strlen($contentPlain) > 160) {
            $excerpt .= '...';
        }

        // SEO meta tags with event-specific data
        $seoTitle = $event['seo_title'] ?: ($event['title'] . ' - ' . $appName);
        $seoDescription = $event['seo_description'] ?: $excerpt;
        $seoKeywords = $event['seo_keywords'] ?? '';
        $seoCanonical = absoluteUrl(RouteTranslator::route('events') . '/' . $event['slug']);

        // Open Graph tags
        $ogTitle = $event['og_title'] ?: $event['title'];
        $ogDescription = $event['og_description'] ?: $seoDescription;
        $ogType = $event['og_type'] ?: 'article';
        $ogUrl = $event['og_url'] ?: $seoCanonical;
        $ogImage = !empty($event['og_image'])
            ? absoluteUrl($event['og_image'])
            : (!empty($event['featured_image']) ? absoluteUrl($event['featured_image']) : absoluteUrl('/assets/social.jpg'));

        // Twitter Card tags
        $twitterCard = $event['twitter_card'] ?: 'summary_large_image';
        $twitterTitle = $event['twitter_title'] ?: $ogTitle;
        $twitterDescription = $event['twitter_description'] ?: $ogDescription;
        $twitterImage = !empty($event['twitter_image']) ? absoluteUrl($event['twitter_image']) : $ogImage;

        // Related events (upcoming, excluding current)
        $relatedEvents = [];
        $stmtRelated = $db->prepare("
            SELECT id, title, slug, event_date, event_time, featured_image
            FROM events
            WHERE is_active = 1 AND id != ? AND event_date >= CURDATE()
            ORDER BY event_date ASC, event_time ASC, id ASC
            LIMIT 3
        ");
        if ($stmtRelated) {
            $eventId = $event['id'];
            $stmtRelated->bind_param('i', $eventId);
            $stmtRelated->execute();
            $resultRelated = $stmtRelated->get_result();
            while ($row = $resultRelated->fetch_assoc()) {
                $relatedEvents[] = $row;
            }
            $stmtRelated->close();
        }

        $container = $this->container;
        ob_start();
        include __DIR__ . '/../Views/frontend/event-detail.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }
}
?>
