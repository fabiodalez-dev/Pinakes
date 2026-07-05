<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Plugins\BookClub\Modules\ApiModule;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * API module (plan §11): read-only JSON endpoints under /api/book-club/v1/*.
 *
 * Authentication (core ApiKeyMiddleware) and rate limiting are wired as
 * route middleware by ApiModule; this controller only enforces the
 * book-club-level visibility rules:
 *
 *  - only ACTIVE clubs with privacy public/private are exposed — hidden and
 *    invite clubs 404 (and never appear in the list);
 *  - the api module must be enabled for the club (per-club setting),
 *    otherwise 404 — the clubs list applies the same filter;
 *  - no member names/emails ever leave the API (member_count only);
 *  - meetings expose location but NOT video_url;
 *  - books pending moderation (BookClubPlugin::STATE_PENDING) are not
 *    listed: the detail endpoint groups by workflow states only.
 *
 * Response envelope: {"success":true,"data":…} / {"success":false,"error":…}.
 */
class ApiController extends BaseController
{
    private ApiModule $module;
    private StatsRepo $stats;

    public function __construct(mysqli $db, Repo $repo, ApiModule $module)
    {
        parent::__construct($db, $repo);
        $this->module = $module;
        $this->stats = new StatsRepo($db);
    }

    // ------------------------------------------------------------------
    // Response envelope
    // ------------------------------------------------------------------

    private function success(ResponseInterface $response, mixed $data): ResponseInterface
    {
        $json = json_encode(
            ['success' => true, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $response->getBody()->write($json === false ? '{"success":false,"error":"encoding"}' : $json);
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus(200);
    }

    private function error(ResponseInterface $response, string $message, int $status): ResponseInterface
    {
        $json = json_encode(
            ['success' => false, 'error' => $message],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $response->getBody()->write($json === false ? '{"success":false,"error":"error"}' : $json);
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }

    // ------------------------------------------------------------------
    // Query helper (Repo::rows is private — same prepared-statement style)
    // ------------------------------------------------------------------

    /**
     * @param array<int, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[BookClub:api] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub:api] execute failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $out = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $out;
    }

    // ------------------------------------------------------------------
    // Club resolution / visibility
    // ------------------------------------------------------------------

    /**
     * API-visible club by slug, or null (→ 404): must exist, be active,
     * be public or private (hidden/invite are never exposed) and have the
     * api module enabled.
     *
     * @return array<string, mixed>|null
     */
    private function resolve(string $slug): ?array
    {
        $club = $this->repo->clubBySlug($slug);
        if (
            $club === null
            || (int) ($club['is_active'] ?? 0) !== 1
            || !in_array((string) $club['privacy'], ['public', 'private'], true)
            || !$this->module->enabledFor($club)
        ) {
            return null;
        }
        return $club;
    }

    /** @param array<string, mixed> $club
     *  @return array<string, mixed> */
    private function clubSummary(array $club): array
    {
        return [
            'id' => (int) $club['id'],
            'slug' => (string) $club['slug'],
            'name' => (string) $club['name'],
            'description' => (string) ($club['description'] ?? ''),
            'privacy' => (string) $club['privacy'],
            'member_count' => (int) ($club['member_count'] ?? $this->repo->countActiveMembers((int) $club['id'])),
        ];
    }

    // ------------------------------------------------------------------
    // GET /api/book-club/v1/clubs
    // ------------------------------------------------------------------

    public function listClubs(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $rows = $this->rows(
                "SELECT c.id, c.slug, c.name, c.description, c.privacy, c.settings,
                        (SELECT COUNT(*) FROM bookclub_members m
                          WHERE m.club_id = c.id AND m.status = 'active') AS member_count
                   FROM bookclub_clubs c
                  WHERE c.deleted_at IS NULL AND c.is_active = 1
                    AND c.privacy IN ('public','private')
                  ORDER BY c.name ASC"
            );
            $clubs = [];
            foreach ($rows as $club) {
                // Decode settings so the per-club module check can see the
                // enabled-modules list (same hydration as Repo::clubBySlug).
                $settings = json_decode((string) ($club['settings'] ?? ''), true);
                $club['settings'] = is_array($settings) ? $settings : [];
                if (!$this->module->enabledFor($club)) {
                    continue;
                }
                $clubs[] = $this->clubSummary($club);
            }
            return $this->success($response, ['clubs' => $clubs, 'count' => count($clubs)]);
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:api] listClubs failed: ' . $e->getMessage());
            return $this->error($response, __('Errore interno del server'), 500);
        }
    }

    // ------------------------------------------------------------------
    // GET /api/book-club/v1/clubs/{slug}
    // ------------------------------------------------------------------

    public function showClub(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        try {
            $club = $this->resolve($slug);
            if ($club === null) {
                return $this->error($response, __('Club non trovato'), 404);
            }
            $clubId = (int) $club['id'];
            $states = $this->repo->workflowStates($club);

            // Books grouped by workflow state. Grouping by the workflow's
            // own states silently drops moderation-pending rows
            // (BookClubPlugin::STATE_PENDING is not a workflow state).
            $byState = [];
            foreach ($this->repo->clubBooks($clubId) as $book) {
                $byState[(string) $book['state']][] = [
                    'title' => (string) $book['titolo'],
                    'authors' => (string) ($book['autori'] ?? ''),
                    'reading_starts' => $book['reading_starts'] !== null ? (string) $book['reading_starts'] : null,
                    'reading_ends' => $book['reading_ends'] !== null ? (string) $book['reading_ends'] : null,
                ];
            }
            $books = [];
            foreach ($states as $state) {
                $books[] = [
                    'state' => (string) $state['key'],
                    'state_label' => (string) $state['label'],
                    'books' => $byState[$state['key']] ?? [],
                ];
            }

            // Upcoming meetings: location yes, video_url NEVER.
            $meetings = [];
            $meetingRows = $this->rows(
                "SELECT mt.id, mt.title, mt.starts_at, mt.ends_at, mt.kind, mt.location,
                        l.titolo AS book_title
                   FROM bookclub_meetings mt
                   LEFT JOIN bookclub_books cb ON cb.id = mt.club_book_id
                   LEFT JOIN libri l ON l.id = cb.libro_id
                  WHERE mt.club_id = ? AND mt.status = 'scheduled' AND mt.starts_at >= NOW()
                  ORDER BY mt.starts_at ASC",
                'i',
                [$clubId]
            );
            foreach ($meetingRows as $meeting) {
                $meetings[] = [
                    'id' => (int) $meeting['id'],
                    'title' => (string) $meeting['title'],
                    'starts_at' => (string) $meeting['starts_at'],
                    'ends_at' => $meeting['ends_at'] !== null ? (string) $meeting['ends_at'] : null,
                    'kind' => (string) $meeting['kind'],
                    'location' => (string) ($meeting['location'] ?? ''),
                    'book' => $meeting['book_title'] !== null ? (string) $meeting['book_title'] : null,
                ];
            }

            // Open polls — metadata only, never per-voter data.
            $polls = [];
            $pollRows = $this->rows(
                "SELECT id, title, mode, votes_per_member, closes_at
                   FROM bookclub_polls
                  WHERE club_id = ? AND status = 'open'
                  ORDER BY created_at DESC",
                'i',
                [$clubId]
            );
            foreach ($pollRows as $poll) {
                $polls[] = [
                    'id' => (int) $poll['id'],
                    'title' => (string) $poll['title'],
                    'mode' => (string) $poll['mode'],
                    'votes_per_member' => (int) $poll['votes_per_member'],
                    'closes_at' => $poll['closes_at'] !== null ? (string) $poll['closes_at'] : null,
                ];
            }

            $data = $this->clubSummary($club);
            $data['created_at'] = (string) $club['created_at'];
            $data['books'] = $books;
            $data['upcoming_meetings'] = $meetings;
            $data['open_polls'] = $polls;
            return $this->success($response, $data);
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:api] showClub failed for ' . $slug . ': ' . $e->getMessage());
            return $this->error($response, __('Errore interno del server'), 500);
        }
    }

    // ------------------------------------------------------------------
    // GET /api/book-club/v1/clubs/{slug}/stats
    // ------------------------------------------------------------------

    public function clubStats(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        try {
            $club = $this->resolve($slug);
            if ($club === null) {
                return $this->error($response, __('Club non trovato'), 404);
            }
            $clubId = (int) $club['id'];
            $states = $this->repo->workflowStates($club);
            return $this->success($response, [
                'club' => (string) $club['slug'],
                'books_total' => $this->stats->totalBookCount($clubId),
                'finished' => $this->stats->finishedBookCount($clubId, StatsRepo::finishedStateKeys($states)),
                'members_active' => $this->repo->countActiveMembers($clubId),
                'meetings_done' => $this->stats->meetingsHeld($clubId),
            ]);
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:api] clubStats failed for ' . $slug . ': ' . $e->getMessage());
            return $this->error($response, __('Errore interno del server'), 500);
        }
    }

    // ------------------------------------------------------------------
    // GET /api/book-club/v1/openapi.json
    // ------------------------------------------------------------------

    /**
     * Minimal OpenAPI 3.1 document for the 4 endpoints. No club context:
     * always served while the module's routes are registered.
     */
    public function openapi(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uri = $request->getUri();
        $base = $uri->getScheme() !== '' && $uri->getAuthority() !== ''
            ? $uri->getScheme() . '://' . $uri->getAuthority()
            : '/';

        $envelope = static fn(array $dataSchema): array => [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'data' => $dataSchema,
            ],
        ];
        $jsonResponse = static fn(string $description, array $dataSchema) => [
            'description' => $description,
            'content' => ['application/json' => ['schema' => $envelope($dataSchema)]],
        ];
        $errorResponse = [
            'description' => __('Errore'),
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'const' => false],
                    'error' => ['type' => 'string'],
                ],
            ]]],
        ];
        $slugParam = [
            'name' => 'slug',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string', 'pattern' => '^[a-z0-9\\-]+$'],
        ];

        $doc = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Pinakes Book Club API',
                'version' => '1.0.0',
                'description' => __('API JSON in sola lettura del plugin Book Club di Pinakes'),
            ],
            'servers' => [['url' => $base]],
            'security' => [['ApiKeyAuth' => []]],
            'paths' => [
                '/api/book-club/v1/clubs' => ['get' => [
                    'operationId' => 'listClubs',
                    'summary' => __('Elenco dei club attivi'),
                    'responses' => [
                        '200' => $jsonResponse(__('Elenco dei club attivi'), [
                            'type' => 'object',
                            'properties' => [
                                'clubs' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ClubSummary']],
                                'count' => ['type' => 'integer'],
                            ],
                        ]),
                        '401' => $errorResponse,
                        '429' => $errorResponse,
                    ],
                ]],
                '/api/book-club/v1/clubs/{slug}' => ['get' => [
                    'operationId' => 'getClub',
                    'summary' => __('Dettaglio del club con libri, incontri e votazioni aperte'),
                    'parameters' => [$slugParam],
                    'responses' => [
                        '200' => $jsonResponse(__('Dettaglio del club con libri, incontri e votazioni aperte'), ['$ref' => '#/components/schemas/ClubDetail']),
                        '404' => $errorResponse,
                        '401' => $errorResponse,
                        '429' => $errorResponse,
                    ],
                ]],
                '/api/book-club/v1/clubs/{slug}/stats' => ['get' => [
                    'operationId' => 'getClubStats',
                    'summary' => __('Statistiche principali del club'),
                    'parameters' => [$slugParam],
                    'responses' => [
                        '200' => $jsonResponse(__('Statistiche principali del club'), ['$ref' => '#/components/schemas/ClubStats']),
                        '404' => $errorResponse,
                        '401' => $errorResponse,
                        '429' => $errorResponse,
                    ],
                ]],
                '/api/book-club/v1/openapi.json' => ['get' => [
                    'operationId' => 'getOpenApi',
                    'summary' => __('Documento OpenAPI di questa API'),
                    'responses' => [
                        '200' => ['description' => __('Documento OpenAPI di questa API')],
                        '401' => $errorResponse,
                        '429' => $errorResponse,
                    ],
                ]],
            ],
            'components' => [
                'securitySchemes' => [
                    'ApiKeyAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                ],
                'schemas' => [
                    'ClubSummary' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'slug' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'privacy' => ['type' => 'string', 'enum' => ['public', 'private']],
                            'member_count' => ['type' => 'integer'],
                        ],
                    ],
                    'ClubDetail' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/ClubSummary'],
                            [
                                'type' => 'object',
                                'properties' => [
                                    'created_at' => ['type' => 'string'],
                                    'books' => ['type' => 'array', 'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'state' => ['type' => 'string'],
                                            'state_label' => ['type' => 'string'],
                                            'books' => ['type' => 'array', 'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'title' => ['type' => 'string'],
                                                    'authors' => ['type' => 'string'],
                                                    'reading_starts' => ['type' => ['string', 'null']],
                                                    'reading_ends' => ['type' => ['string', 'null']],
                                                ],
                                            ]],
                                        ],
                                    ]],
                                    'upcoming_meetings' => ['type' => 'array', 'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'id' => ['type' => 'integer'],
                                            'title' => ['type' => 'string'],
                                            'starts_at' => ['type' => 'string'],
                                            'ends_at' => ['type' => ['string', 'null']],
                                            'kind' => ['type' => 'string'],
                                            'location' => ['type' => 'string'],
                                            'book' => ['type' => ['string', 'null']],
                                        ],
                                    ]],
                                    'open_polls' => ['type' => 'array', 'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'id' => ['type' => 'integer'],
                                            'title' => ['type' => 'string'],
                                            'mode' => ['type' => 'string'],
                                            'votes_per_member' => ['type' => 'integer'],
                                            'closes_at' => ['type' => ['string', 'null']],
                                        ],
                                    ]],
                                ],
                            ],
                        ],
                    ],
                    'ClubStats' => [
                        'type' => 'object',
                        'properties' => [
                            'club' => ['type' => 'string'],
                            'books_total' => ['type' => 'integer'],
                            'finished' => ['type' => 'integer'],
                            'members_active' => ['type' => 'integer'],
                            'meetings_done' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];

        // Served bare (no success envelope): OpenAPI tooling expects the
        // document itself at the top level.
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $response->getBody()->write($json === false ? '{}' : $json);
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus(200);
    }
}
