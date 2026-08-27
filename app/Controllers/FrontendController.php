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

        // The whole home dataset (CMS sections, latest books, genre carousels,
        // events, hero counters) is identical for every visitor. Build it once
        // and cache it across requests; content mutations (books, CMS home,
        // events) clear the 'home_' prefix via ContentCache — which also
        // covers the home_api_count_* keys below — while the TTL covers
        // loan-driven availability drift.
        $homeData = \App\Support\QueryCache::remember('home_page_data_v1', function () use ($db) {
            return $this->buildHomePageData($db);
        }, 300);

        $homeContent = $homeData['homeContent'];
        $sectionsOrdered = $homeData['sectionsOrdered'];
        $latest_books = $homeData['latest_books'];
        $latestBooksTotal = $homeData['latestBooksTotal'];
        $genres_with_books = $homeData['genres_with_books'];
        $genreCarouselEnabled = $homeData['genreCarouselEnabled'];
        $homeEvents = $homeData['homeEvents'];
        $heroTotalBooks = $homeData['totalBooks'];
        $heroAvailableBooks = $homeData['availableBooks'];

        $homeEventsEnabled = $homeData['eventsFeatureEnabled'] && !empty($homeEvents);

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
        // Sanitize social URLs before they can enter the Organization schema's
        // sameAs array — an unsanitized javascript:/data: value would otherwise
        // leak into the emitted JSON-LD. sanitizePublicHttpUrl() returns '' for
        // anything that isn't a clean http(s) URL, so the guards below skip it.
        $socialFacebook = \App\Support\HtmlHelper::sanitizePublicHttpUrl((string) \App\Support\ConfigStore::get('app.social_facebook', ''));
        $socialTwitter = \App\Support\HtmlHelper::sanitizePublicHttpUrl((string) \App\Support\ConfigStore::get('app.social_twitter', ''));
        $socialInstagram = \App\Support\HtmlHelper::sanitizePublicHttpUrl((string) \App\Support\ConfigStore::get('app.social_instagram', ''));
        $socialLinkedin = \App\Support\HtmlHelper::sanitizePublicHttpUrl((string) \App\Support\ConfigStore::get('app.social_linkedin', ''));
        $socialBluesky = \App\Support\HtmlHelper::sanitizePublicHttpUrl((string) \App\Support\ConfigStore::get('app.social_bluesky', ''));
        $socialTelegram = \App\Support\HtmlHelper::sanitizePublicHttpUrl((string) \App\Support\ConfigStore::get('app.social_telegram', ''));

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
            if ($socialBluesky) $sameAs[] = $socialBluesky;
            if ($socialTelegram) $sameAs[] = $socialTelegram;

            if (!empty($sameAs)) {
                $orgSchema['sameAs'] = $sameAs;
            }

            // Combine schemas. JSON_HEX_TAG neutralises any </script> that could
            // reach the value (defence in depth on top of the strip_tags at save).
            $seoSchema = json_encode([$schemaOrg, $orgSchema], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG);
        } else {
            $seoSchema = json_encode($schemaOrg, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG);
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

        // Cached total: the COUNT(DISTINCT) scan is identical for every page of
        // the same filter set. Short TTL; book mutations clear the 'catalog_'
        // prefix so admin edits show up immediately.
        $catalogCacheSuffix = md5(serialize($this->normalizeFiltersForCache($filters)));
        $count_query = "SELECT COUNT(DISTINCT l.id) as total " . $base_query;
        $countLoader = function () use ($db, $count_query, $param_types, $query_params) {
            $stmt_count = $db->prepare($count_query);
            if (!empty($query_params)) {
                $stmt_count->bind_param($param_types, ...$query_params);
            }
            $stmt_count->execute();
            $total_row = $stmt_count->get_result()->fetch_assoc();
            $stmt_count->close();
            return (int) ($total_row['total'] ?? 0);
        };
        $total_books = $this->rememberCatalogValue(
            'catalog_count_' . $catalogCacheSuffix,
            $filters,
            $countLoader
        );
        $total_pages = ceil($total_books / $limit);

        // Query per i libri. Expose the principal author's surname as an
        // explicit column so buildOrderBy can reference an alias instead of
        // re-running the correlated subquery twice per row (once for the
        // NULLs-last predicate, once for the sort value).
        $books_query = "
            SELECT DISTINCT l.*,
                   (SELECT " . \App\Support\AuthorName::displaySql('a') . " FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo IN ('principale','co-autore') ORDER BY la.ruolo = 'principale' DESC LIMIT 1) AS autore,
                   (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo IN ('principale','co-autore') ORDER BY la.ruolo = 'principale' DESC LIMIT 1) AS autore_principale_nome,
                   (SELECT SUBSTRING_INDEX(" . \App\Support\AuthorName::preferredSql('a') . ", ' ', -1) FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo IN ('principale','co-autore') ORDER BY la.ruolo = 'principale' DESC LIMIT 1) AS autore_cognome,
                   e.nome AS editore,
                   g.nome AS genere
            " . $base_query . "
            " . $this->buildOrderBy($filters['sort']) . "
            LIMIT ? OFFSET ?
        ";

        // Listing rows: cached only for the bounded filter states (availability
        // fields stripped from the cached copy and merged back live per request).
        $books = $this->loadCatalogPageRows($db, $books_query, $param_types, $query_params, $filters, $limit, $offset, $page);

        // Ottieni le opzioni per i filtri
        $filter_options = $this->getFilterOptions($db, $filters);

        // Get hierarchical genre display based on current selection
        $genre_display = $this->getDisplayGenres($filter_options['generi'], (int)($filters['genere_id'] ?? 0));

        // Render template
        $container = $this->container;
        // The catalog view reads $current_page for the server-rendered pagination
        // nav and the initial JS pagination config; the controller tracks it as
        // $page. Without this the no-JS nav always marks page 1 active and never
        // links past page 5, so pages 6+ are not crawlable.
        $current_page = $page;
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

        // Cached total: the COUNT(DISTINCT) scan is identical for every page of
        // the same filter set. Short TTL; book mutations clear the 'catalog_'
        // prefix so admin edits show up immediately.
        $catalogCacheSuffix = md5(serialize($this->normalizeFiltersForCache($filters)));
        $count_query = "SELECT COUNT(DISTINCT l.id) as total " . $base_query;
        $countLoader = function () use ($db, $count_query, $param_types, $query_params) {
            $stmt_count = $db->prepare($count_query);
            if (!empty($query_params)) {
                $stmt_count->bind_param($param_types, ...$query_params);
            }
            $stmt_count->execute();
            $total_row = $stmt_count->get_result()->fetch_assoc();
            $stmt_count->close();
            return (int) ($total_row['total'] ?? 0);
        };
        $total_books = $this->rememberCatalogValue(
            'catalog_count_' . $catalogCacheSuffix,
            $filters,
            $countLoader
        );
        $total_pages = ceil($total_books / $limit);

        // Query per i libri. Expose the principal author's surname as an
        // explicit column so buildOrderBy can reference an alias instead of
        // re-running the correlated subquery twice per row (once for the
        // NULLs-last predicate, once for the sort value).
        $books_query = "
            SELECT DISTINCT l.*,
                   (SELECT " . \App\Support\AuthorName::displaySql('a') . " FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo IN ('principale','co-autore') ORDER BY la.ruolo = 'principale' DESC LIMIT 1) AS autore,
                   (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo IN ('principale','co-autore') ORDER BY la.ruolo = 'principale' DESC LIMIT 1) AS autore_principale_nome,
                   (SELECT SUBSTRING_INDEX(" . \App\Support\AuthorName::preferredSql('a') . ", ' ', -1) FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo IN ('principale','co-autore') ORDER BY la.ruolo = 'principale' DESC LIMIT 1) AS autore_cognome,
                   e.nome AS editore,
                   g.nome AS genere
            " . $base_query . "
            " . $this->buildOrderBy($filters['sort']) . "
            LIMIT ? OFFSET ?
        ";

        // Listing rows: cached only for the bounded filter states (availability
        // fields stripped from the cached copy and merged back live per request).
        $books = $this->loadCatalogPageRows($db, $books_query, $param_types, $query_params, $filters, $limit, $offset, $page);

        // Render only the books grid
        ob_start();
        include __DIR__ . '/../Views/frontend/catalog-grid.php';
        $html = ob_get_clean();

        // Get updated filter options based on current filters
        $filter_options = $this->getFilterOptions($db, $filters);

        // Get hierarchical genre display for correct sidebar rendering
        $genre_display = $this->getDisplayGenres($filter_options['generi'], (int)($filters['genere_id'] ?? 0));

        // Available-books count (same filter set, restricted to loanable copies)
        // so the home hero "Disponibili" stat can show a real number instead of
        // a placeholder emoji. Reuses the same bound params as the total count.
        //
        // FIX F001: this adds a sixth aggregate scan (5 LEFT JOINs) on top of the
        // total-count and books queries, yet ONLY the home hero consumes it (a
        // single loadStats() fetch). The live catalog search/filter path — which
        // hits this endpoint on every keystroke — used to pay for it and throw
        // the value away. Gate it behind an explicit `with_stats=1` flag so the
        // aggregate runs only when the caller actually needs the number.
        //
        // When the flag is absent the field stays null; home.php's JS falls back
        // to total_books, but only the search path (which never requests stats)
        // ever sees that fallback. When the flag IS passed, database failures are
        // logged and cached as 0 so the same failing aggregate is not retried on
        // every request until the short cache entry expires.
        $available_books = null;
        if (($params['with_stats'] ?? '') === '1') {
            $availabilityLoader = function () use ($db, $base_query, $param_types, $query_params) {
                $available_stmt = $db->prepare("SELECT COUNT(DISTINCT l.id) as total " . $base_query . " AND l.copie_disponibili > 0");
                if ($available_stmt === false) {
                    \App\Support\SecureLogger::error('Available-books count prepare failed', ['db_error' => $db->error]);
                    return 0;
                }
                $available_books = 0;
                if (!empty($query_params)) {
                    $available_stmt->bind_param($param_types, ...$query_params);
                }
                if (!$available_stmt->execute()) {
                    \App\Support\SecureLogger::error('Available-books count execute failed', ['db_error' => $available_stmt->error]);
                } else {
                    $available_result = $available_stmt->get_result();
                    if ($available_result === false) {
                        \App\Support\SecureLogger::error('Available-books count get_result failed', ['db_error' => $available_stmt->error]);
                    } else {
                        $available_row = $available_result->fetch_assoc();
                        $available_books = (int) ($available_row['total'] ?? 0);
                    }
                }
                $available_stmt->close();
                return $available_books;
            };
            $available_books = $this->rememberCatalogValue(
                'catalog_avail_' . $catalogCacheSuffix,
                $filters,
                $availabilityLoader
            );
        }

        $data = [
            'html' => $html,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $total_pages,
                'total_books' => $total_books,
                'available_books' => $available_books,
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

        // Static book-detail DTO (issue #387 step 4): the book row, its
        // authors, publishers, series and related volumes are identical for
        // every visitor and change only through admin write paths that all
        // funnel into ContentCache::booksChanged() → 'book_detail_' generation
        // bump. Live availability (copie_disponibili/copie_totali/stato) is
        // deliberately EXCLUDED from the cached payload and re-read from the
        // database on every request further below: a stale availability number
        // is a user-facing correctness bug (double-loan), a stale title is not.
        $locale = \App\Support\I18n::getLocale();

        // Live availability is read FIRST — it is also the soft-delete-aware proof
        // that the book exists. Reading it before remember() means an unknown id
        // never creates a cache entry (bounded, mirrors hasBoundedCatalogCacheKey);
        // a raw scan of nonexistent ids cannot grow storage/cache. Reused below.
        $liveBook = $this->fetchLiveAvailability($db, [$book_id]);
        if (!isset($liveBook[$book_id])) {
            return $this->render404($response);
        }

        $detail = \App\Support\QueryCache::remember(
            'book_detail_' . $locale . '_' . $book_id,
            function () use ($db, $book_id): ?array {
                return $this->buildBookDetailStatic($db, $book_id);
            },
            300
        );

        // Defence in depth: a soft-delete racing between the live read above and
        // the DTO build yields null (remember() treats null as a miss — never
        // negatively cached).
        if (!is_array($detail) || !isset($detail['book'])) {
            return $this->render404($response);
        }

        $book = $detail['book'];
        $authors = $detail['authors'];
        $seriesBooks = $detail['seriesBooks'];
        $related_books = $detail['related_books'];

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

        // LIVE availability merge — NEVER served from cache. The book's own
        // availability was already read above ($liveBook); here we only refresh
        // the related volumes (one indexed primary-key lookup), reusing $liveBook
        // for the book itself rather than querying it twice.
        $relatedIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $related_books);
        $liveRelated = $relatedIds !== [] ? $this->fetchLiveAvailability($db, $relatedIds) : [];
        $book = array_merge($book, $liveBook[$book_id]);
        $freshRelated = [];
        foreach ($related_books as $row) {
            $rowId = (int) ($row['id'] ?? 0);
            if (!isset($liveRelated[$rowId])) {
                continue; // soft-deleted since the DTO was cached
            }
            $freshRelated[] = array_merge($row, $liveRelated[$rowId]);
        }
        $related_books = $freshRelated;

        // Approved reviews + statistics: cached per book ('book_reviews_'
        // namespace), invalidated on moderation (approve/reject/delete →
        // ContentCache::reviewsChanged()). New reviews are 'pendente' and
        // never publicly visible, so submission does not need to invalidate.
        $reviewsBlock = \App\Support\QueryCache::remember(
            'book_reviews_' . $locale . '_' . $book_id,
            static function () use ($db, $book_id): array {
                $recensioniRepo = new RecensioniRepository($db);
                return [
                    'reviews' => $recensioniRepo->getApprovedReviewsForBook($book_id),
                    'stats' => $recensioniRepo->getReviewStats($book_id),
                ];
            },
            300
        );
        $reviews = is_array($reviewsBlock['reviews'] ?? null) ? $reviewsBlock['reviews'] : [];
        $reviewStats = is_array($reviewsBlock['stats'] ?? null) ? $reviewsBlock['stats'] : [
            'total_reviews' => 0,
            'average_rating' => 0,
            'one_star' => 0,
            'two_star' => 0,
            'three_star' => 0,
            'four_star' => 0,
            'five_star' => 0,
        ];

        // Social sharing
        $sharingProviders = array_values(array_filter(array_map('trim', explode(',', (string) ConfigStore::get('sharing.enabled_providers', '')))));
        $shareUrl = absoluteUrl($canonicalPath);
        $shareTitle = $book['titolo'] ?? '';

        // Keep the public request calendar aligned with the server-side default
        // used when end_date is omitted. The reservation endpoint caps that
        // default to max_loan_duration_days, so expose the same effective value
        // to the view instead of hardcoding one calendar month in JavaScript.
        $loanSettings = new \App\Models\SettingsRepository($db);
        $maxRequestDays = max(1, (int) ($loanSettings->get('loans', 'max_loan_duration_days', '90') ?? 90));
        $defaultRequestLoanDays = min($loanSettings->loanDurationDays(), $maxRequestDays);

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

    /**
     * Fields whose value depends on live circulation state. They are stripped
     * from every cached payload and re-read from the database at request time
     * (fetchLiveAvailability), so a warm cache can never serve a stale
     * availability number.
     */
    private const LIVE_AVAILABILITY_FIELDS = ['copie_disponibili', 'copie_totali', 'stato'];

    /**
     * Highest catalog page number eligible for the page-rows cache. Together
     * with hasBoundedCatalogCacheKey() this keeps the cached key space finite
     * (locale × 4 availability states × 6 sorts × N pages); deeper pages are
     * request-controlled long-tail traffic and always hit the database.
     */
    private const CATALOG_PAGE_CACHE_MAX_PAGE = 10;

    /**
     * Build the visitor-independent portion of the book-detail page: the book
     * row (WITHOUT the live availability fields), its authors, publishers
     * (issue #143), same-series volumes and related volumes (also stripped of
     * availability). Returns null when the book does not exist or is
     * soft-deleted — callers must treat that as a 404.
     *
     * Cached by bookDetail() under the 'book_detail_' namespace; every book
     * metadata write path (create/edit/soft-delete, author/publisher/genre/
     * series mutations and imports) funnels into ContentCache::booksChanged(),
     * which bumps that generation. Availability-only recomputes use
     * availabilityChanged() and intentionally leave this static DTO warm.
     *
     * @return array{book: array<string, mixed>, authors: array<int, array<string, mixed>>,
     *               seriesBooks: array<int, array<string, mixed>>,
     *               related_books: array<int, array<string, mixed>>}|null
     */
    private function buildBookDetailStatic(mysqli $db, int $book_id): ?array
    {
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
            $stmt->close();
            return null;
        }

        $book = $result->fetch_assoc();
        $stmt->close();

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
        $stmt_authors->close();

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

        // Other volumes in the same series (collana)
        $seriesBooks = [];
        $collana = trim((string) ($book['collana'] ?? ''));
        if ($collana !== '') {
            $stmtSeries = $db->prepare("
                SELECT l.id, l.titolo, l.numero_serie, l.copertina_url,
                       (SELECT " . \App\Support\AuthorName::displaySql('a') . " FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                        WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore_principale,
                       (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                        WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore_principale_nome
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

        return [
            'book' => $this->stripLiveAvailability($book),
            'authors' => $authors,
            'seriesBooks' => $seriesBooks,
            'related_books' => array_map(
                fn (array $row): array => $this->stripLiveAvailability($row),
                $related_books
            ),
        ];
    }

    /**
     * Remove the live availability fields from a row destined for the cache.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function stripLiveAvailability(array $row): array
    {
        foreach (self::LIVE_AVAILABILITY_FIELDS as $field) {
            unset($row[$field]);
        }

        return $row;
    }

    /**
     * Read the CURRENT availability of the given books straight from the
     * database (soft-delete aware). This is the only source of the
     * copie_disponibili/copie_totali/stato values the frontend renders — by
     * design it runs on every request and is never cached.
     *
     * @param array<int, int> $ids
     * @return array<int, array{copie_disponibili: int, copie_totali: int, stato: mixed}>
     */
    private function fetchLiveAvailability(mysqli $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT id, copie_disponibili, copie_totali, stato FROM libri WHERE id IN ({$placeholders}) AND deleted_at IS NULL"
        );
        if ($stmt === false) {
            // Degrade gracefully instead of throwing: this runs on the most
            // trafficked public pages. An empty map lets the caller decide —
            // the book-detail path 404s (existence unknown), the catalog path
            // renders rows without availability — rather than a hard 500.
            \App\Support\SecureLogger::error('FrontendController: live availability prepare failed', ['db_error' => $db->error]);
            return [];
        }

        $types = str_repeat('i', count($ids));
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();

        $live = [];
        if ($result !== false) {
            while ($row = $result->fetch_assoc()) {
                $live[(int) $row['id']] = [
                    'copie_disponibili' => (int) $row['copie_disponibili'],
                    'copie_totali' => (int) $row['copie_totali'],
                    'stato' => $row['stato'],
                ];
            }
        }
        $stmt->close();

        return $live;
    }

    /**
     * Merge fresh availability into cached listing rows, dropping rows whose
     * book was soft-deleted after the cache entry was written (defence in
     * depth on top of the booksChanged() generation bump).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function mergeLiveAvailability(mysqli $db, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $live = $this->fetchLiveAvailability($db, array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows));

        $fresh = [];
        foreach ($rows as $row) {
            $rowId = (int) ($row['id'] ?? 0);
            if (!isset($live[$rowId])) {
                continue;
            }
            $fresh[] = array_merge($row, $live[$rowId]);
        }

        return $fresh;
    }

    /**
     * Load one page of catalog listing rows. For the finite, high-traffic
     * bounded filter states (same bounding as the facets cache, plus a page
     * cap and the canonicalized sort) the rows are cached WITHOUT their live
     * availability fields, which are merged back fresh on every request.
     * Unbounded states (free text, ids, years, deep pages) always query.
     *
     * @param array<int, mixed> $query_params
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function loadCatalogPageRows(
        mysqli $db,
        string $books_query,
        string $param_types,
        array $query_params,
        array $filters,
        int $limit,
        int $offset,
        int $page
    ): array {
        $fetch = static function () use ($db, $books_query, $param_types, $query_params, $limit, $offset): array {
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
            $stmt_books->close();

            return $books;
        };

        if (!$this->hasBoundedCatalogCacheKey($filters) || $page < 1 || $page > self::CATALOG_PAGE_CACHE_MAX_PAGE) {
            return $fetch();
        }

        // Key: locale + bounded filter signature + canonical sort + page. The
        // sort goes through buildOrderBy() so arbitrary request strings
        // collapse onto the six real orderings instead of fragmenting keys.
        $cacheKey = 'catalog_page_' . \App\Support\I18n::getLocale() . '_' . md5(serialize([
            $this->normalizeFiltersForCache($filters),
            $this->buildOrderBy((string) ($filters['sort'] ?? 'newest')),
            $page,
            $limit,
        ]));

        $rows = \App\Support\QueryCache::remember($cacheKey, function () use ($fetch): array {
            // Live availability never enters the cache (hard rule): strip it
            // here, mergeLiveAvailability() re-reads it per request.
            return array_map(
                fn (array $row): array => $this->stripLiveAvailability($row),
                $fetch()
            );
        }, 120);

        if (!is_array($rows)) {
            return $fetch();
        }

        return $this->mergeLiveAvailability($db, $rows);
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

        // Availability facets mirror the recomputed l.stato so the filter agrees
        // with the card badge and the book page by construction:
        //   - "available"  → copie_disponibili > 0 (canonical, drives the loan button)
        //   - "prenotato"  → reserved: physically present but held by a scheduled
        //                    loan / pending request / slot reservation (l.stato)
        //   - "prestato"   → on loan: a copy is actually checked out (l.stato)
        // Books with copies all out of circulation (l.stato = 'non_disponibile')
        // and empty records belong to none of the three — they show only under "All".
        if ($filters['disponibilita'] === 'disponibile') {
            $conditions[] = "l.copie_disponibili > 0";
        } elseif ($filters['disponibilita'] === 'prenotato') {
            $conditions[] = "l.stato = 'prenotato'";
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
    // The sidebar facets require ~6 aggregate scans; for a given filter set
    // (sort/page irrelevant) the result is deterministic, so cache it whole.
    // Locale is part of the key (media-type labels are translated).
    $cacheKey = 'catalog_facets_' . \App\Support\I18n::getLocale()
        . '_' . md5(serialize($this->normalizeFiltersForCache($filters)));

    $loader = function () use ($db, $filters) {
        return $this->computeFilterOptions($db, $filters);
    };

    return $this->rememberCatalogValue($cacheKey, $filters, $loader);
}

/**
 * Drop cache-irrelevant keys (sort order does not change counts or facets)
 * and normalize scalars so equivalent filter sets share one cache entry.
 */
private function normalizeFiltersForCache(array $filters): array
{
    unset($filters['sort']);
    ksort($filters);
    return array_map(static fn($v) => is_scalar($v) || $v === null ? (string) $v : serialize($v), $filters);
}

/**
 * Cache only the finite, high-traffic catalogue states. Free text, publisher
 * names, ids and year bounds are request-controlled and have effectively
 * unbounded cardinality; persisting one file per combination would let normal
 * search traffic (or a hostile client) grow storage/cache without limit.
 *
 * @param callable(): mixed $loader
 */
private function rememberCatalogValue(string $key, array $filters, callable $loader): mixed
{
    if (!$this->hasBoundedCatalogCacheKey($filters)) {
        return $loader();
    }

    return \App\Support\QueryCache::remember($key, $loader, 120);
}

private function hasBoundedCatalogCacheKey(array $filters): bool
{
    $availability = (string) ($filters['disponibilita'] ?? '');

    return trim((string) ($filters['search'] ?? '')) === ''
        && (int) ($filters['genere_id'] ?? 0) === 0
        && trim((string) ($filters['editore'] ?? '')) === ''
        && trim((string) ($filters['anno_min'] ?? '')) === ''
        && trim((string) ($filters['anno_max'] ?? '')) === ''
        && trim((string) ($filters['tipo_media'] ?? '')) === ''
        && (int) ($filters['autore_id'] ?? 0) === 0
        && in_array($availability, ['', 'disponibile', 'prenotato', 'prestato'], true);
}

private function computeFilterOptions(mysqli $db, array $filters = []): array
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
                   WHERE l.deleted_at IS NULL
                   AND (
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

    // The complete facets payload is already cached by getFilterOptions() for
    // bounded filter states. A second per-query cache here would recreate the
    // unbounded file-key problem for free-text searches.
    $loadGenres = function() use ($db, $queryGeneri, $typesGen, $paramsGen) {
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
    };
    $generi_flat = $loadGenres();
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
        SELECT e.nome, COUNT(DISTINCT l.id) AS cnt
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
    // Group by NAME, not id: the catalog filter matches publishers by e.nome
    // (editori.nome has no UNIQUE constraint — same-named rows are legitimate),
    // so grouping per id would render duplicate labels each with a partial count
    // that no single filtered result could match. Grouping by name merges them
    // and makes each badge equal the count the name filter returns.
    $queryEditori .= " GROUP BY e.nome HAVING cnt > 0 ORDER BY e.nome";

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

    // Compute every mutually-exclusive availability facet and the real catalogue
    // total in one scan. The direct total includes records with no copies, while
    // the conditional counts mirror the corresponding filter predicates.
    $queryStats = "SELECT
        COUNT(DISTINCT l.id) AS total_cnt,
        COUNT(DISTINCT CASE WHEN l.copie_disponibili > 0 THEN l.id END) AS available_cnt,
        COUNT(DISTINCT CASE WHEN l.stato = 'prenotato' THEN l.id END) AS reserved_cnt,
        COUNT(DISTINCT CASE WHEN l.stato = 'prestato' THEN l.id END) AS borrowed_cnt
        " . $availabilityBaseQuery;
    $stmt = $db->prepare($queryStats);
    if (!empty($paramsAvail)) {
        $stmt->bind_param($typesAvail, ...$paramsAvail);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $totalCount = (int) ($row['total_cnt'] ?? 0);
    $availableCount = (int) ($row['available_cnt'] ?? 0);
    $reservedCount = (int) ($row['reserved_cnt'] ?? 0);
    $borrowedCount = (int) ($row['borrowed_cnt'] ?? 0);

    $options['availability_stats'] = [
        'available' => $availableCount,
        'reserved' => $reservedCount,
        'borrowed' => $borrowedCount,
        'total' => $totalCount
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
    $queryAutori .= " GROUP BY a.id, a.nome, a.pseudonimo HAVING cnt > 0 ORDER BY "
        . \App\Support\AuthorName::preferredSql('a') . " LIMIT 100";
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
                            WHERE la.libro_id = l.id AND la.ruolo IN ('principale','co-autore') ORDER BY la.ruolo = 'principale' DESC LIMIT 1) AS autore,
                           (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                            WHERE la.libro_id = l.id AND la.ruolo IN ('principale','co-autore') ORDER BY la.ruolo = 'principale' DESC LIMIT 1) AS autore_principale_nome,
                           e.nome AS editore,
                           g.nome AS genere
                    FROM libri l
                    LEFT JOIN editori e ON l.editore_id = e.id
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
                            WHERE la.libro_id = l.id AND la.ruolo IN ('principale','co-autore') ORDER BY la.ruolo = 'principale' DESC LIMIT 1) AS autore,
                           (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                            WHERE la.libro_id = l.id AND la.ruolo IN ('principale','co-autore') ORDER BY la.ruolo = 'principale' DESC LIMIT 1) AS autore_principale_nome,
                           e.nome AS editore
                    FROM libri l
                    LEFT JOIN editori e ON l.editore_id = e.id
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

        // Calculate pagination for total count (cached: identical for every
        // page request of the same section; 'home_' prefix cleared on book saves)
        $total = null;
        switch ($section) {
            case 'latest':
                $total = \App\Support\QueryCache::remember('home_api_count_latest', function () use ($db) {
                    $row = $db->query("SELECT COUNT(*) as total FROM libri WHERE deleted_at IS NULL")->fetch_assoc();
                    return (int) ($row['total'] ?? 0);
                }, 120);
                break;
            case 'genre':
                $total = \App\Support\QueryCache::remember('home_api_count_genre_' . $genere_id, function () use ($db, $genere_id) {
                    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM libri WHERE genere_id = ? AND deleted_at IS NULL");
                    $countStmt->bind_param("i", $genere_id);
                    $countStmt->execute();
                    $row = $countStmt->get_result()->fetch_assoc();
                    $countStmt->close();
                    return (int) ($row['total'] ?? 0);
                }, 120);
                break;
        }

        if ($total !== null) {
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
        // Keep the name-based route feature-equivalent to the ID route: both
        // expose the public photo, website and authority/source links.
        $authorQuery = "SELECT id, nome, pseudonimo, biografia, sito_web, foto, collegamenti FROM autori WHERE nome = ? LIMIT 1";
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
                   (SELECT a2.nome FROM libri_autori la2 JOIN autori a2 ON la2.autore_id = a2.id
                    WHERE la2.libro_id = l.id AND la2.ruolo = 'principale' LIMIT 1) AS autore_principale_nome,
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
        // Title, meta, canonical and JSON-LD are centralized in archive.php.
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
                   (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore_principale_nome,
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
        // Title, meta, canonical and JSON-LD are centralized in archive.php,
        // shared with the author and genre archives (localized, name-based
        // canonical, CollectionPage + BreadcrumbList schema).
        $archive_type = 'editore';
        $archive_info = $publisher;
        $container = $this->container;
        include __DIR__ . '/../Views/frontend/archive.php';
        $content = ob_get_clean();

        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/html');
    }

    /**
     * Public publisher page by ID — mirrors authorArchiveById(). The header search
     * links a publisher result to /editore/{id}, but only the by-name route existed,
     * so /editore/5 matched the {name} route with name="5", found no publisher named
     * "5" and 404'd. Resolve the id to the publisher name and delegate to
     * publisherArchive(), which aggregates same-named publishers and renders the
     * shared frontend/archive.php — the same layout as the author page.
     */
    public function publisherArchiveById(Request $request, Response $response, mysqli $db, int $publisherId): Response
    {
        $stmt = $db->prepare("SELECT nome FROM editori WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $publisherId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return $this->render404($response);
        }

        // publisherArchive() urldecode()s its argument, so hand it an encoded name.
        return $this->publisherArchive($request, $response, $db, rawurlencode((string) $row['nome']));
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
                   (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore_principale_nome,
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
        // Title, meta, canonical and JSON-LD are centralized in archive.php.
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

    /**
     * Build the cacheable, visitor-independent home page dataset.
     *
     * One home_content read serves both the active-sections map (with full SEO
     * fields) and the ordered-sections list, plus the latest-books sort and the
     * genre-carousel visibility flag — previously four separate queries.
     *
     * @return array{homeContent: array, sectionsOrdered: array, latest_books: array,
     *               latestBooksTotal: int, genres_with_books: array, genreCarouselEnabled: bool,
     *               eventsFeatureEnabled: bool, homeEvents: array, totalBooks: int, availableBooks: int}
     */
    private function buildHomePageData(mysqli $db): array
    {
        // Carica i contenuti CMS della home (inclusi campi SEO completi)
        $homeContent = [];
        $sectionsOrdered = [];
        $query_home = "SELECT section_key, title, subtitle, content, button_text, button_link, background_image,
                              seo_title, seo_description, seo_keywords, og_image,
                              og_title, og_description, og_type, og_url,
                              twitter_card, twitter_title, twitter_description, twitter_image,
                              is_active, display_order
                       FROM home_content
                       ORDER BY display_order ASC, section_key ASC";
        $result_home = $db->query($query_home);
        if ($result_home) {
            while ($row = $result_home->fetch_assoc()) {
                $sectionsOrdered[$row['section_key']] = $row;
                if ((int) $row['is_active'] === 1) {
                    $homeContent[$row['section_key']] = $row;
                }
            }
            $result_home->free();
        }

        // Sort preference for the latest-books section (was a dedicated query)
        $latestSortRaw = $sectionsOrdered['latest_books_title']['content'] ?? 'created_at';
        $latestBooksSort = in_array($latestSortRaw, ['created_at', 'updated_at'], true) ? $latestSortRaw : 'created_at';

        // Ultimi 12 libri inseriti (12 = page size of /api/home/latest, so the
        // server-rendered first page lines up with the load-more pagination)
        $query_slider = "
            SELECT l.*,
                   (SELECT " . \App\Support\AuthorName::displaySql('a') . " FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo IN ('principale','co-autore') ORDER BY la.ruolo = 'principale' DESC LIMIT 1) AS autore,
                   (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo IN ('principale','co-autore') ORDER BY la.ruolo = 'principale' DESC LIMIT 1) AS autore_principale_nome,
                   g.nome AS genere
            FROM libri l
            LEFT JOIN generi g ON l.genere_id = g.id
            WHERE l.deleted_at IS NULL
            ORDER BY l.{$latestBooksSort} DESC
            LIMIT 12
        ";
        $latest_books = [];
        $result_slider = $db->query($query_slider);
        if ($result_slider) {
            $latest_books = $result_slider->fetch_all(MYSQLI_ASSOC);
            $result_slider->free();
        }

        // Hero counters + latest pagination in one aggregate scan
        $totalBooks = 0;
        $availableBooks = 0;
        $statsResult = $db->query("
            SELECT COUNT(*) AS total_cnt,
                   COUNT(CASE WHEN copie_disponibili > 0 THEN 1 END) AS available_cnt
            FROM libri
            WHERE deleted_at IS NULL
        ");
        if ($statsResult) {
            $statsRow = $statsResult->fetch_assoc();
            $totalBooks = (int) ($statsRow['total_cnt'] ?? 0);
            $availableBooks = (int) ($statsRow['available_cnt'] ?? 0);
            $statsResult->free();
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
            $resultAllGenres->free();
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

                // Use proper prepared statements with dynamic placeholders
                $uniqueGenreIds = array_unique(array_map('intval', $genreIds));
                $inClause = '(' . implode(',', array_fill(0, count($uniqueGenreIds), '?')) . ')';
                $query_genre_books = "
                    SELECT l.*,
                           (SELECT " . \App\Support\AuthorName::displaySql('a') . " FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                            WHERE la.libro_id = l.id AND la.ruolo IN ('principale','co-autore') ORDER BY la.ruolo = 'principale' DESC LIMIT 1) AS autore,
                           (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                            WHERE la.libro_id = l.id AND la.ruolo IN ('principale','co-autore') ORDER BY la.ruolo = 'principale' DESC LIMIT 1) AS autore_principale_nome
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
                    $genres_with_books[] = [
                        'genre' => $rootGenre,
                        'books' => $result_genre_books->fetch_all(MYSQLI_ASSOC)
                    ];
                }
                $stmt_genre_books->close();
            }
        }

        // Genre carousel visibility from the already-loaded sections map.
        // A missing row keeps the historical default: enabled.
        $genreCarouselRow = $sectionsOrdered['genre_carousel'] ?? null;
        $genreCarouselEnabled = $genreCarouselRow === null || (int) $genreCarouselRow['is_active'] === 1;

        // Home events preview (respect CMS visibility)
        $homeEvents = [];
        $eventsFeatureEnabled = false;
        try {
            $settingsRepository = new \App\Models\SettingsRepository($db);
            $eventsFeatureEnabled = $settingsRepository->get('cms', 'events_page_enabled', '0') === '1';
        } catch (\Throwable $e) {
            $eventsFeatureEnabled = false;
        }

        if ($eventsFeatureEnabled) {
            $eventsResult = $db->query("
                SELECT id, title, slug, event_date, event_time, featured_image
                FROM events
                WHERE is_active = 1 AND event_date >= CURDATE()
                ORDER BY event_date ASC, event_time ASC, created_at DESC
                LIMIT 3
            ");
            if ($eventsResult) {
                $homeEvents = $eventsResult->fetch_all(MYSQLI_ASSOC);
                $eventsResult->free();
            }

            // Fallback: if no upcoming events, show latest active events
            if (empty($homeEvents)) {
                $fallbackResult = $db->query("
                    SELECT id, title, slug, event_date, event_time, featured_image
                    FROM events
                    WHERE is_active = 1
                    ORDER BY event_date DESC, created_at DESC
                    LIMIT 3
                ");
                if ($fallbackResult) {
                    $homeEvents = $fallbackResult->fetch_all(MYSQLI_ASSOC);
                    $fallbackResult->free();
                }
            }
        }

        return [
            'homeContent' => $homeContent,
            'sectionsOrdered' => $sectionsOrdered,
            'latest_books' => $latest_books,
            'latestBooksTotal' => $totalBooks,
            'genres_with_books' => $genres_with_books,
            'genreCarouselEnabled' => $genreCarouselEnabled,
            'eventsFeatureEnabled' => $eventsFeatureEnabled,
            'homeEvents' => $homeEvents,
            'totalBooks' => $totalBooks,
            'availableBooks' => $availableBooks,
        ];
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
                   (SELECT a2.nome FROM libri_autori la2 JOIN autori a2 ON la2.autore_id = a2.id
                    WHERE la2.libro_id = l.id AND la2.ruolo = 'principale' LIMIT 1) AS autore_principale_nome,
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
        // Title, meta, canonical and JSON-LD are centralized in archive.php.
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
        // Fetch a superset so the book page can show as many related books as
        // fit the viewport width (the grid shows one row and hides the overflow
        // on narrower screens) instead of a fixed 3. #279-adjacent UX request.
        $limit = 6;
        $allCreatorsSelect = "
            (SELECT GROUP_CONCAT(DISTINCT " . \App\Support\AuthorName::displaySql('a_all') . "
                     ORDER BY (la_all.ruolo = 'principale') DESC,
                              COALESCE(la_all.ordine_credito, 0), la_all.autore_id
                     SEPARATOR ', ')
               FROM libri_autori la_all
               JOIN autori a_all ON a_all.id = la_all.autore_id
              WHERE la_all.libro_id = l.id
                AND la_all.ruolo IN ('principale', 'co-autore'))";
        $primaryCreatorNameSelect = "
            (SELECT a_primary.nome
               FROM libri_autori la_primary
               JOIN autori a_primary ON a_primary.id = la_primary.autore_id
              WHERE la_primary.libro_id = l.id
                AND la_primary.ruolo IN ('principale', 'co-autore')
              ORDER BY (la_primary.ruolo = 'principale') DESC,
                       COALESCE(la_primary.ordine_credito, 0), la_primary.autore_id
              LIMIT 1)";

        // Priority 0: Same series (collana) — reuse pre-fetched seriesBooks to avoid duplicate query
        if (!empty($seriesBooks)) {
            foreach (array_slice($seriesBooks, 0, $limit) as $sb) {
                if (!isset($sb['autori'])) {
                    $sb['autori'] = $sb['autore_principale'] ?? '';
                }
                $related_books[] = $sb;
            }
        }

        // Priorities 1-3 in one ranked query: same author, then same genre,
        // then recent additions. This preserves the previous ordering while
        // avoiding up to three sequential round-trips on every book page.
        if (count($related_books) < $limit) {
            $remaining = $limit - count($related_books);
            $exclude_ids = array_merge([$book_id], array_column($related_books, 'id'));
            $excludePlaceholders = implode(',', array_fill(0, count($exclude_ids), '?'));

            $creatorAuthors = array_values(array_filter(
                $authors,
                static fn (array $author): bool => in_array((string) ($author['ruolo'] ?? ''), ['principale', 'co-autore'], true)
            ));
            $authorIds = array_values(array_filter(array_map('intval', array_column($creatorAuthors, 'id'))));

            $priorityCases = [];
            $priorityTypes = '';
            $priorityParams = [];
            if ($authorIds !== []) {
                $authorPlaceholders = implode(',', array_fill(0, count($authorIds), '?'));
                $priorityCases[] = "WHEN EXISTS (
                    SELECT 1
                      FROM libri_autori la_match
                     WHERE la_match.libro_id = l.id
                       AND la_match.autore_id IN ($authorPlaceholders)
                       AND la_match.ruolo IN ('principale', 'co-autore')
                ) THEN 0";
                $priorityTypes .= str_repeat('i', count($authorIds));
                array_push($priorityParams, ...$authorIds);
            }
            if (!empty($book['genere_id'])) {
                $priorityCases[] = 'WHEN l.genere_id = ? THEN 1';
                $priorityTypes .= 'i';
                $priorityParams[] = (int) $book['genere_id'];
            }
            $priorityOrder = $priorityCases === []
                ? '2'
                : 'CASE ' . implode(' ', $priorityCases) . ' ELSE 2 END';

            $query = "
                SELECT l.*, {$allCreatorsSelect} AS autori,
                       {$primaryCreatorNameSelect} AS autore_principale_nome
                FROM libri l
                WHERE l.id NOT IN ($excludePlaceholders)
                AND l.deleted_at IS NULL
                ORDER BY {$priorityOrder}, l.created_at DESC, l.id DESC
                LIMIT ?
            ";

            $stmt = $db->prepare($query);
            if ($stmt) {
                // Placeholder order follows the SQL text: WHERE exclusions,
                // ORDER BY ranking values, then LIMIT.
                $types = str_repeat('i', count($exclude_ids)) . $priorityTypes . 'i';
                $params = array_merge($exclude_ids, $priorityParams, [$remaining]);
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

        // Schema.org Event + breadcrumb (rich results: date, organizer).
        // No location column exists, so none is asserted rather than invented.
        $eventStart = (string) $event['event_date'];
        if (!empty($event['event_time'])) {
            $eventStart .= 'T' . $event['event_time'];
        }
        $eventSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => (string) $event['title'],
            'startDate' => $eventStart,
            'url' => $seoCanonical,
            'organizer' => [
                '@type' => 'Organization',
                'name' => $appName,
                'url' => rtrim(\App\Support\HtmlHelper::getBaseUrl(), '/') . '/',
            ],
        ];
        if ($seoDescription !== '') {
            $eventSchema['description'] = $seoDescription;
        }
        if (!empty($event['featured_image'])) {
            $eventSchema['image'] = absoluteUrl($event['featured_image']);
        }
        $eventBreadcrumbSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => __('Home'), 'item' => rtrim(\App\Support\HtmlHelper::getBaseUrl(), '/') . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => __('Eventi'), 'item' => absoluteUrl(RouteTranslator::route('events'))],
                ['@type' => 'ListItem', 'position' => 3, 'name' => (string) $event['title']],
            ],
        ];
        $seoSchema = json_encode(
            [$eventSchema, $eventBreadcrumbSchema],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
        );

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
