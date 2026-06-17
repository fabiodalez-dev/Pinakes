<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Support\ConfigStore;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the OpenAPI 3.1 document at GET /api/v1/openapi.json.
 *
 * The document is built dynamically so that the `servers[].url` and the
 * `info.version` always reflect the running instance. Everything else is a
 * static schema that mirrors the Mobile API spec exactly.
 *
 * Public endpoint — no bearer token required (same as /health and /docs).
 */
final class OpenApiController
{
    public function document(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        try {
            $baseUrl = $this->baseUrl($request);
            $version = $this->appVersion();
            $doc     = $this->build($baseUrl, $version);

            $json = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($json === false) {
                throw new \RuntimeException('json_encode failed');
            }

            $response->getBody()->write($json);

            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Cache-Control', 'public, max-age=300');
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] openapi document failed: ' . $e->getMessage());

            $err = json_encode(['error' => 'document_error']) ?: '{"error":"document_error"}';
            $response->getBody()->write($err);

            return $response
                ->withStatus(500)
                ->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    // ─── Build ───────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function build(string $baseUrl, string $version): array
    {
        $envelope = $this->envelopeSchema();
        $errorObj = $this->errorObjectSchema();
        $metaObj  = $this->metaObjectSchema();

        return [
            'openapi' => '3.1.0',
            'info'    => [
                'title'       => (string) ConfigStore::get('app.name', 'Pinakes') . ' Mobile API',
                'description' => 'Versioned REST/JSON API for the Pinakes mobile companion app. '
                    . 'Every response uses the `{data, meta, error}` envelope. '
                    . 'Authenticated endpoints require `Authorization: Bearer <token>` obtained via POST /auth/login.',
                'version'     => $version,
                'contact'     => ['name' => 'Pinakes', 'url' => 'https://github.com/fabiodalez-dev/biblioteca'],
                'license'     => ['name' => 'AGPL-3.0', 'url' => 'https://www.gnu.org/licenses/agpl-3.0.html'],
            ],
            'servers' => [
                ['url' => rtrim($baseUrl, '/') . '/api/v1', 'description' => 'This instance'],
            ],
            'tags' => [
                ['name' => 'discovery',     'description' => 'Public discovery & health'],
                ['name' => 'auth',          'description' => 'Authentication & device management'],
                ['name' => 'catalog',       'description' => 'Book catalog (search, detail, genres)'],
                ['name' => 'loans',         'description' => 'Loans & reservations'],
                ['name' => 'wishlist',      'description' => 'Personal wishlist'],
                ['name' => 'profile',       'description' => 'User profile & password'],
                ['name' => 'messaging',     'description' => 'Contact messages & notifications'],
                ['name' => 'push',          'description' => 'Push notification subscriptions & preferences'],
                ['name' => 'docs',          'description' => 'API documentation'],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type'         => 'http',
                        'scheme'       => 'bearer',
                        'bearerFormat' => 'opaque',
                        'description'  => 'Device-scoped, revocable opaque bearer token. Obtain via POST /auth/login. Store only the sha256 hash is kept server-side; compare with hash_equals.',
                    ],
                ],
                'schemas' => [
                    'Envelope'    => $envelope,
                    'ErrorObject' => $errorObj,
                    'MetaObject'  => $metaObj,

                    // Auth
                    'LoginRequest'      => $this->loginRequestSchema(),
                    'LoginResponse'     => $this->loginResponseSchema(),
                    'RegisterRequest'   => $this->registerRequestSchema(),
                    'ForgotRequest'     => $this->forgotRequestSchema(),
                    'DeviceItem'        => $this->deviceItemSchema(),

                    // Catalog
                    'BookSummary'       => $this->bookSummarySchema(),
                    'BookDetail'        => $this->bookDetailSchema(),
                    'PersonalHistory'   => $this->personalHistorySchema(),
                    'GenreNode'         => $this->genreNodeSchema(),
                    'SearchMeta'        => $this->searchMetaSchema(),

                    // Actions
                    'LoanItem'          => $this->loanItemSchema(),
                    'ReservationItem'   => $this->reservationItemSchema(),
                    'ReservationRequest'=> $this->reservationRequestSchema(),
                    'WishlistItem'      => $this->wishlistItemSchema(),
                    'WishlistAddRequest'=> $this->wishlistAddRequestSchema(),
                    'UserProfile'       => $this->userProfileSchema(),
                    'UpdateProfileRequest' => $this->updateProfileRequestSchema(),
                    'ChangePasswordRequest'=> $this->changePasswordRequestSchema(),
                    'MessageRequest'    => $this->messageRequestSchema(),
                    'NotificationItem'  => $this->notificationItemSchema(),

                    // Push
                    'PushSubscribeRequest' => $this->pushSubscribeRequestSchema(),
                    'PushPrefs'         => $this->pushPrefsSchema(),

                    // Health
                    'HealthPayload'     => $this->healthPayloadSchema(),
                ],
                'responses' => [
                    'Unauthorized' => [
                        'description' => 'Missing or invalid bearer token.',
                        'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]],
                    ],
                    'Forbidden' => [
                        'description' => 'App access disabled or action not allowed.',
                        'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]],
                    ],
                    'NotFound' => [
                        'description' => 'Resource not found.',
                        'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]],
                    ],
                    'TooManyRequests' => [
                        'description' => 'Rate limit exceeded.',
                        'headers'     => ['Retry-After' => ['schema' => ['type' => 'integer']]],
                        'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]],
                    ],
                    'UnprocessableEntity' => [
                        'description' => 'Validation error.',
                        'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]],
                    ],
                    'InternalError' => [
                        'description' => 'Unexpected server error.',
                        'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]],
                    ],
                ],
            ],
            'security' => [],
            'paths'    => $this->paths(),
        ];
    }

    // ─── Paths ───────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function paths(): array
    {
        $json = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]];

        return [
            '/health' => [
                'get' => [
                    'tags'        => ['discovery'],
                    'summary'     => 'Instance discovery',
                    'description' => 'Public. Returns library identity, feature flags, app_access_enabled, registration_enabled, and whether the connection is HTTPS. The native app calls this first after the user types the instance URL.',
                    'operationId' => 'getHealth',
                    'responses'   => [
                        '200' => [
                            'description' => 'Discovery payload.',
                            'content'     => ['application/json' => ['schema' => [
                                'allOf' => [['$ref' => '#/components/schemas/Envelope']],
                                'properties' => ['data' => ['$ref' => '#/components/schemas/HealthPayload']],
                            ]]],
                        ],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/openapi.json' => [
                'get' => [
                    'tags'        => ['docs'],
                    'summary'     => 'OpenAPI 3.1 document',
                    'description' => 'Returns this OpenAPI document (JSON). Public, no token required.',
                    'operationId' => 'getOpenApiDocument',
                    'responses'   => [
                        '200' => ['description' => 'OpenAPI 3.1 document.', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/docs' => [
                'get' => [
                    'tags'        => ['docs'],
                    'summary'     => 'Swagger UI',
                    'description' => 'Returns the Swagger UI HTML page, self-hosted. Public, no token required.',
                    'operationId' => 'getSwaggerUi',
                    'responses'   => [
                        '200' => ['description' => 'HTML page.', 'content' => ['text/html' => ['schema' => ['type' => 'string']]]],
                    ],
                ],
            ],

            '/auth/login' => [
                'post' => [
                    'tags'        => ['auth'],
                    'summary'     => 'Login and issue device token',
                    'description' => 'Validates credentials against the utenti table (same hashing as the web flow), issues a 256-bit opaque token, stores only its sha256 hash. Returns the plaintext token ONCE — store it securely. Throttled: 10 attempts / 5 min per IP.',
                    'operationId' => 'postAuthLogin',
                    'requestBody' => [
                        'required' => true,
                        'content'  => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/LoginRequest']]],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Token issued.', 'content' => ['application/json' => ['schema' => [
                            'allOf' => [['$ref' => '#/components/schemas/Envelope']],
                            'properties' => ['data' => ['$ref' => '#/components/schemas/LoginResponse']],
                        ]]]],
                        '401' => ['description' => 'Invalid credentials.', 'content' => $json],
                        '403' => ['$ref' => '#/components/responses/Forbidden'],
                        '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/auth/register' => [
                'post' => [
                    'tags'        => ['auth'],
                    'summary'     => 'Register a new account',
                    'description' => 'Available only when the instance registration toggle is on. Reuses the exact web INSERT + email-verification flow. Throttled: 5 attempts / hour per IP.',
                    'operationId' => 'postAuthRegister',
                    'requestBody' => [
                        'required' => true,
                        'content'  => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/RegisterRequest']]],
                    ],
                    'responses' => [
                        '201' => ['description' => 'Account created; verification email sent.', 'content' => $json],
                        '400' => ['$ref' => '#/components/responses/UnprocessableEntity'],
                        '403' => ['$ref' => '#/components/responses/Forbidden'],
                        '409' => ['description' => 'Email already registered.', 'content' => $json],
                        '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/auth/forgot-password' => [
                'post' => [
                    'tags'        => ['auth'],
                    'summary'     => 'Request password reset',
                    'description' => 'Sends a password-reset email using the same web reset flow. Always returns 200 to prevent email enumeration. Throttled: 5 attempts / hour per IP.',
                    'operationId' => 'postAuthForgotPassword',
                    'requestBody' => [
                        'required' => true,
                        'content'  => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ForgotRequest']]],
                    ],
                    'responses' => [
                        '200' => ['description' => 'If the address exists, a reset email has been sent.', 'content' => $json],
                        '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/auth/logout' => [
                'post' => [
                    'tags'        => ['auth'],
                    'summary'     => 'Revoke current device token',
                    'description' => 'Sets `revoked_at` on the token presented in the Authorization header. Subsequent calls with the same token return 401.',
                    'operationId' => 'postAuthLogout',
                    'security'    => [['bearerAuth' => []]],
                    'responses'   => [
                        '200' => ['description' => 'Token revoked.', 'content' => $json],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/me' => [
                'get' => [
                    'tags'        => ['profile'],
                    'summary'     => 'Get own profile',
                    'operationId' => 'getMe',
                    'security'    => [['bearerAuth' => []]],
                    'responses'   => [
                        '200' => ['description' => 'User profile.', 'content' => ['application/json' => ['schema' => [
                            'allOf' => [['$ref' => '#/components/schemas/Envelope']],
                            'properties' => ['data' => ['$ref' => '#/components/schemas/UserProfile']],
                        ]]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
                'patch' => [
                    'tags'        => ['profile'],
                    'summary'     => 'Update own profile',
                    'operationId' => 'patchMe',
                    'security'    => [['bearerAuth' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content'  => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/UpdateProfileRequest']]],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Updated profile.', 'content' => $json],
                        '400' => ['$ref' => '#/components/responses/UnprocessableEntity'],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/me/password' => [
                'post' => [
                    'tags'        => ['profile'],
                    'summary'     => 'Change own password',
                    'operationId' => 'postMePassword',
                    'security'    => [['bearerAuth' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content'  => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ChangePasswordRequest']]],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Password changed.', 'content' => $json],
                        '400' => ['$ref' => '#/components/responses/UnprocessableEntity'],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/me/devices' => [
                'get' => [
                    'tags'        => ['auth'],
                    'summary'     => 'List active devices',
                    'description' => 'Returns all non-revoked, non-expired tokens for the authenticated user. Scoped to own devices only.',
                    'operationId' => 'getMeDevices',
                    'security'    => [['bearerAuth' => []]],
                    'responses'   => [
                        '200' => ['description' => 'Device list.', 'content' => ['application/json' => ['schema' => [
                            'allOf' => [['$ref' => '#/components/schemas/Envelope']],
                            'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DeviceItem']]],
                        ]]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/me/devices/{id}' => [
                'delete' => [
                    'tags'        => ['auth'],
                    'summary'     => 'Revoke a device',
                    'description' => 'Revokes a specific device token. Only the owning user can revoke their own devices.',
                    'operationId' => 'deleteMeDevice',
                    'security'    => [['bearerAuth' => []]],
                    'parameters'  => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'Device (token) ID'],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Device revoked.', 'content' => $json],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '403' => ['$ref' => '#/components/responses/Forbidden'],
                        '404' => ['$ref' => '#/components/responses/NotFound'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/catalog/search' => [
                'get' => [
                    'tags'        => ['catalog'],
                    'summary'     => 'Search the catalog',
                    'description' => 'Cursor-paginated, filtered catalog search. Soft-deleted books are never returned (`AND deleted_at IS NULL`). Personal history is NOT included here — use /catalog/books/{id} for that.',
                    'operationId' => 'getCatalogSearch',
                    'security'    => [['bearerAuth' => []]],
                    'parameters'  => [
                        ['name' => 'q',         'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Full-text search (title / author / keyword)'],
                        ['name' => 'author',    'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Filter by author name'],
                        ['name' => 'publisher', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Filter by publisher name'],
                        ['name' => 'genre',     'in' => 'query', 'schema' => ['type' => 'integer'], 'description' => 'Filter by genre ID (cascade: any level)'],
                        ['name' => 'language',  'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Filter by ISO 639-1 language code'],
                        ['name' => 'available', 'in' => 'query', 'schema' => ['type' => 'boolean'], 'description' => 'If true, return only books with at least one loanable copy'],
                        ['name' => 'cursor',    'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Opaque pagination cursor from meta.next_cursor'],
                        ['name' => 'limit',     'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20], 'description' => 'Page size (max 50)'],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Search results.', 'content' => ['application/json' => ['schema' => [
                            'allOf' => [['$ref' => '#/components/schemas/Envelope']],
                            'properties' => [
                                'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/BookSummary']],
                                'meta' => ['$ref' => '#/components/schemas/SearchMeta'],
                            ],
                        ]]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/catalog/books/{id}' => [
                'get' => [
                    'tags'        => ['catalog'],
                    'summary'     => 'Book detail',
                    'description' => 'Full book payload: availability, copies, shelf/location, absolute cover URL, full metadata, + personal history (has the user read/reserved/wishlisted it). Soft-deleted books return 404.',
                    'operationId' => 'getCatalogBook',
                    'security'    => [['bearerAuth' => []]],
                    'parameters'  => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'Book ID'],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Book detail.', 'content' => ['application/json' => ['schema' => [
                            'allOf' => [['$ref' => '#/components/schemas/Envelope']],
                            'properties' => ['data' => ['$ref' => '#/components/schemas/BookDetail']],
                        ]]]],
                        '304' => ['description' => 'Not Modified (ETag / If-None-Match honored).'],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '404' => ['$ref' => '#/components/responses/NotFound'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/catalog/genres' => [
                'get' => [
                    'tags'        => ['catalog'],
                    'summary'     => 'Genre cascade tree',
                    'description' => 'Full genre tree (up to 3 levels) for building filter UI dropdowns in the app.',
                    'operationId' => 'getCatalogGenres',
                    'security'    => [['bearerAuth' => []]],
                    'responses'   => [
                        '200' => ['description' => 'Genre tree.', 'content' => ['application/json' => ['schema' => [
                            'allOf' => [['$ref' => '#/components/schemas/Envelope']],
                            'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/GenreNode']]],
                        ]]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/me/loans' => [
                'get' => [
                    'tags'        => ['loans'],
                    'summary'     => 'Own loans',
                    'description' => 'Returns pending requests, active loans (scheduled / holding / overdue), and recent concluded history. Scoped to the authenticated user only.',
                    'operationId' => 'getMeLoans',
                    'security'    => [['bearerAuth' => []]],
                    'responses'   => [
                        '200' => ['description' => 'Loans.', 'content' => ['application/json' => ['schema' => [
                            'allOf' => [['$ref' => '#/components/schemas/Envelope']],
                            'properties' => ['data' => ['type' => 'object', 'properties' => [
                                'pending' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/LoanItem']],
                                'active'  => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/LoanItem']],
                                'history' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/LoanItem']],
                            ]]],
                        ]]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/me/reservations' => [
                'get' => [
                    'tags'        => ['loans'],
                    'summary'     => 'Own reservations',
                    'operationId' => 'getMeReservations',
                    'security'    => [['bearerAuth' => []]],
                    'responses'   => [
                        '200' => ['description' => 'Active reservations.', 'content' => ['application/json' => ['schema' => [
                            'allOf' => [['$ref' => '#/components/schemas/Envelope']],
                            'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ReservationItem']]],
                        ]]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/reservations' => [
                'post' => [
                    'tags'        => ['loans'],
                    'summary'     => 'Request a reservation / loan',
                    'description' => 'Honors existing overlap, availability, and max-active-loans rules (same as the web form). Returns error codes for overlap, unavailable, or queue position.',
                    'operationId' => 'postReservation',
                    'security'    => [['bearerAuth' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content'  => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ReservationRequest']]],
                    ],
                    'responses' => [
                        '201' => ['description' => 'Reservation created.', 'content' => $json],
                        '400' => ['$ref' => '#/components/responses/UnprocessableEntity'],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '409' => ['description' => 'Overlap or book unavailable.', 'content' => $json],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/reservations/{id}' => [
                'delete' => [
                    'tags'        => ['loans'],
                    'summary'     => 'Cancel own reservation',
                    'description' => 'Cancels a pending reservation or pending loan request. Only the owning user can cancel their own reservation.',
                    'operationId' => 'deleteReservation',
                    'security'    => [['bearerAuth' => []]],
                    'parameters'  => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'Reservation ID'],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Reservation cancelled.', 'content' => $json],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '403' => ['$ref' => '#/components/responses/Forbidden'],
                        '404' => ['$ref' => '#/components/responses/NotFound'],
                        '409' => ['description' => 'Cannot cancel a reservation already picked up / concluded.', 'content' => $json],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/me/wishlist' => [
                'get' => [
                    'tags'        => ['wishlist'],
                    'summary'     => 'Own wishlist',
                    'operationId' => 'getMeWishlist',
                    'security'    => [['bearerAuth' => []]],
                    'responses'   => [
                        '200' => ['description' => 'Wishlist.', 'content' => ['application/json' => ['schema' => [
                            'allOf' => [['$ref' => '#/components/schemas/Envelope']],
                            'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/WishlistItem']]],
                        ]]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
                'post' => [
                    'tags'        => ['wishlist'],
                    'summary'     => 'Add book to wishlist',
                    'operationId' => 'postMeWishlist',
                    'security'    => [['bearerAuth' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content'  => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/WishlistAddRequest']]],
                    ],
                    'responses' => [
                        '201' => ['description' => 'Added (or already present — idempotent).', 'content' => $json],
                        '400' => ['$ref' => '#/components/responses/UnprocessableEntity'],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '404' => ['$ref' => '#/components/responses/NotFound'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/me/wishlist/{book_id}' => [
                'delete' => [
                    'tags'        => ['wishlist'],
                    'summary'     => 'Remove book from wishlist',
                    'operationId' => 'deleteMeWishlistBook',
                    'security'    => [['bearerAuth' => []]],
                    'parameters'  => [
                        ['name' => 'book_id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'Book ID'],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Removed (or was not present — idempotent).', 'content' => $json],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/messages' => [
                'post' => [
                    'tags'        => ['messaging'],
                    'summary'     => 'Send a contact message',
                    'description' => 'Same as the web contact form: the message is stored (Flamingo) and an email is sent to the library manager.',
                    'operationId' => 'postMessages',
                    'security'    => [['bearerAuth' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content'  => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/MessageRequest']]],
                    ],
                    'responses' => [
                        '201' => ['description' => 'Message sent.', 'content' => $json],
                        '400' => ['$ref' => '#/components/responses/UnprocessableEntity'],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/me/notifications' => [
                'get' => [
                    'tags'        => ['messaging'],
                    'summary'     => 'In-app notification feed',
                    'description' => 'Derived feed of loan/reservation/message events. This is the fallback when push notifications are off or not configured.',
                    'operationId' => 'getMeNotifications',
                    'security'    => [['bearerAuth' => []]],
                    'responses'   => [
                        '200' => ['description' => 'Notification list.', 'content' => ['application/json' => ['schema' => [
                            'allOf' => [['$ref' => '#/components/schemas/Envelope']],
                            'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/NotificationItem']]],
                        ]]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/me/push/subscribe' => [
                'post' => [
                    'tags'        => ['push'],
                    'summary'     => 'Register push endpoint',
                    'description' => 'Registers a UnifiedPush or FCM endpoint for the current device. Always accepted even if the instance has no push credentials (NullProvider graceful fallback).',
                    'operationId' => 'postMePushSubscribe',
                    'security'    => [['bearerAuth' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content'  => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/PushSubscribeRequest']]],
                    ],
                    'responses' => [
                        '201' => ['description' => 'Subscription registered.', 'content' => $json],
                        '400' => ['$ref' => '#/components/responses/UnprocessableEntity'],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
                'delete' => [
                    'tags'        => ['push'],
                    'summary'     => 'Remove push subscription',
                    'description' => 'Removes the push endpoint(s) associated with the current device token.',
                    'operationId' => 'deleteMePushSubscribe',
                    'security'    => [['bearerAuth' => []]],
                    'responses'   => [
                        '200' => ['description' => 'Subscription removed.', 'content' => $json],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/me/push/prefs' => [
                'get' => [
                    'tags'        => ['push'],
                    'summary'     => 'Get push preferences',
                    'operationId' => 'getMePushPrefs',
                    'security'    => [['bearerAuth' => []]],
                    'responses'   => [
                        '200' => ['description' => 'Push preferences.', 'content' => ['application/json' => ['schema' => [
                            'allOf' => [['$ref' => '#/components/schemas/Envelope']],
                            'properties' => ['data' => ['$ref' => '#/components/schemas/PushPrefs']],
                        ]]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
                'put' => [
                    'tags'        => ['push'],
                    'summary'     => 'Set push preferences',
                    'description' => 'Per-type toggles + quiet hours. Partial update: only send the keys you want to change.',
                    'operationId' => 'putMePushPrefs',
                    'security'    => [['bearerAuth' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content'  => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/PushPrefs']]],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Preferences updated.', 'content' => $json],
                        '400' => ['$ref' => '#/components/responses/UnprocessableEntity'],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],
        ];
    }

    // ─── Shared envelope schemas ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function envelopeSchema(): array
    {
        return [
            'type'        => 'object',
            'description' => 'Standard API envelope. Every response is one of these two shapes.',
            'required'    => ['data', 'meta', 'error'],
            'properties'  => [
                'data'  => ['nullable' => true, 'description' => 'Payload on success, null on error.'],
                'meta'  => ['$ref' => '#/components/schemas/MetaObject'],
                'error' => ['oneOf' => [['$ref' => '#/components/schemas/ErrorObject'], ['type' => 'null']]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorObjectSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['code', 'message'],
            'properties' => [
                'code'    => ['type' => 'string', 'description' => 'Machine-readable error code, e.g. invalid_credentials, app_disabled, rate_limited.'],
                'message' => ['type' => 'string', 'description' => 'Human-readable message in the instance locale. Safe to display; never leaks internals.'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metaObjectSchema(): array
    {
        return [
            'type'        => 'object',
            'description' => 'Optional metadata. For search results includes next_cursor and total_count.',
            'properties'  => [
                'next_cursor'  => ['type' => 'string', 'nullable' => true, 'description' => 'Opaque cursor for the next page. Null when no further pages.'],
                'total_count'  => ['type' => 'integer', 'nullable' => true, 'description' => 'Total matching records (search only, may be null on large sets).'],
                'https'        => ['type' => 'boolean', 'nullable' => true, 'description' => 'Health endpoint: whether the connection is HTTPS.'],
                'warning'      => ['type' => 'string', 'nullable' => true, 'description' => 'Health endpoint: insecure_transport when not HTTPS.'],
            ],
            'additionalProperties' => true,
        ];
    }

    // ─── Request / response schemas ───────────────────────────────────────────

    /** @return array<string, mixed> */
    private function loginRequestSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['email', 'password', 'device_name', 'device_id', 'platform'],
            'properties' => [
                'email'       => ['type' => 'string', 'format' => 'email'],
                'password'    => ['type' => 'string', 'minLength' => 1],
                'device_name' => ['type' => 'string', 'maxLength' => 190, 'example' => 'Fabio\'s Pixel 9'],
                'device_id'   => ['type' => 'string', 'maxLength' => 190, 'description' => 'Stable per-device identifier from the OS.'],
                'platform'    => ['type' => 'string', 'enum' => ['android', 'ios', 'other'], 'example' => 'android'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function loginResponseSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'token' => ['type' => 'string', 'description' => 'Plaintext bearer token — returned once, store securely.'],
                'user'  => ['$ref' => '#/components/schemas/UserProfile'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function registerRequestSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['nome', 'cognome', 'email', 'password'],
            'properties' => [
                'nome'     => ['type' => 'string', 'maxLength' => 100],
                'cognome'  => ['type' => 'string', 'maxLength' => 100],
                'email'    => ['type' => 'string', 'format' => 'email'],
                'password' => ['type' => 'string', 'minLength' => 8],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function forgotRequestSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['email'],
            'properties' => ['email' => ['type' => 'string', 'format' => 'email']],
        ];
    }

    /** @return array<string, mixed> */
    private function deviceItemSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'           => ['type' => 'integer'],
                'device_name'  => ['type' => 'string', 'nullable' => true],
                'device_id'    => ['type' => 'string', 'nullable' => true],
                'platform'     => ['type' => 'string', 'nullable' => true],
                'created_at'   => ['type' => 'string', 'format' => 'date-time'],
                'last_used_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'expires_at'   => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'is_current'   => ['type' => 'boolean', 'description' => 'True if this is the device making the request.'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bookSummarySchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'            => ['type' => 'integer'],
                'titolo'        => ['type' => 'string'],
                'sottotitolo'   => ['type' => 'string', 'nullable' => true],
                'autori'        => ['type' => 'array', 'items' => ['type' => 'string']],
                'editore'       => ['type' => 'string', 'nullable' => true],
                'anno'          => ['type' => 'integer', 'nullable' => true],
                'copertina_url' => ['type' => 'string', 'format' => 'uri', 'nullable' => true, 'description' => 'Absolute URL.'],
                'disponibile'   => ['type' => 'boolean', 'description' => 'True if at least one copy is currently loanable.'],
                'genere'        => ['type' => 'string', 'nullable' => true],
                'lingua'        => ['type' => 'string', 'nullable' => true],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bookDetailSchema(): array
    {
        return [
            'allOf' => [['$ref' => '#/components/schemas/BookSummary']],
            'type'  => 'object',
            'properties' => [
                'isbn10'          => ['type' => 'string', 'nullable' => true],
                'isbn13'          => ['type' => 'string', 'nullable' => true],
                'ean'             => ['type' => 'string', 'nullable' => true],
                'descrizione'     => ['type' => 'string', 'nullable' => true],
                'numero_pagine'   => ['type' => 'integer', 'nullable' => true],
                'condizione'      => ['type' => 'string', 'nullable' => true],
                'collocazione'    => ['type' => 'string', 'nullable' => true, 'description' => 'Shelf / location label.'],
                'copie_totali'    => ['type' => 'integer', 'description' => 'Total physical copies.'],
                'copie_disponibili' => ['type' => 'integer', 'description' => 'Copies currently loanable.'],
                'personal_history'  => ['$ref' => '#/components/schemas/PersonalHistory'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function personalHistorySchema(): array
    {
        return [
            'type'       => 'object',
            'description' => 'Authenticated user\'s relationship with this book.',
            'properties' => [
                'has_read'        => ['type' => 'boolean', 'description' => 'The user has a returned (past) loan of this book.'],
                'has_reserved'    => ['type' => 'boolean', 'description' => 'The user has a pending/active reservation for this book.'],
                'has_wishlisted'  => ['type' => 'boolean', 'description' => 'This book is in the user\'s wishlist.'],
                'has_active_loan' => ['type' => 'boolean', 'description' => 'The user currently has this book on loan.'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function genreNodeSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'       => ['type' => 'integer'],
                'nome'     => ['type' => 'string'],
                'livello'  => ['type' => 'integer', 'description' => 'Depth: 1 = root, 2 = mid, 3 = leaf.'],
                'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/GenreNode']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function searchMetaSchema(): array
    {
        return [
            'allOf' => [['$ref' => '#/components/schemas/MetaObject']],
            'type'  => 'object',
            'properties' => [
                'next_cursor' => ['type' => 'string', 'nullable' => true],
                'total_count' => ['type' => 'integer', 'nullable' => true],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function loanItemSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'             => ['type' => 'integer'],
                'libro_id'       => ['type' => 'integer'],
                'titolo'         => ['type' => 'string'],
                'copertina_url'  => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'stato'          => ['type' => 'string', 'description' => 'e.g. in_corso, concluso, in_scadenza, scaduto, prenotato, in_attesa.'],
                'data_prestito'  => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                'data_scadenza'  => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                'data_restituzione' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                'created_at'     => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function reservationItemSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'            => ['type' => 'integer'],
                'libro_id'      => ['type' => 'integer'],
                'titolo'        => ['type' => 'string'],
                'copertina_url' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'stato'         => ['type' => 'string'],
                'data_inizio'   => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                'data_fine'     => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                'queue_position'=> ['type' => 'integer', 'nullable' => true],
                'created_at'    => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function reservationRequestSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['book_id'],
            'properties' => [
                'book_id'    => ['type' => 'integer'],
                'start_date' => ['type' => 'string', 'format' => 'date', 'description' => 'Requested start date (ISO-8601). Defaults to today if omitted.'],
                'end_date'   => ['type' => 'string', 'format' => 'date', 'description' => 'Requested end date (ISO-8601).'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function wishlistItemSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'book_id'       => ['type' => 'integer'],
                'titolo'        => ['type' => 'string'],
                'copertina_url' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'disponibile'   => ['type' => 'boolean'],
                'added_at'      => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function wishlistAddRequestSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['book_id'],
            'properties' => ['book_id' => ['type' => 'integer']],
        ];
    }

    /** @return array<string, mixed> */
    private function userProfileSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'                => ['type' => 'integer'],
                'nome'              => ['type' => 'string'],
                'cognome'           => ['type' => 'string'],
                'email'             => ['type' => 'string', 'format' => 'email'],
                'tipo_utente'       => ['type' => 'string', 'enum' => ['utente', 'staff', 'admin']],
                'email_verificata'  => ['type' => 'boolean'],
                'stato'             => ['type' => 'string'],
                'avatar_url'        => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function updateProfileRequestSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'nome'    => ['type' => 'string', 'maxLength' => 100],
                'cognome' => ['type' => 'string', 'maxLength' => 100],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function changePasswordRequestSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['current_password', 'new_password'],
            'properties' => [
                'current_password' => ['type' => 'string'],
                'new_password'     => ['type' => 'string', 'minLength' => 8],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function messageRequestSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['subject', 'body'],
            'properties' => [
                'subject' => ['type' => 'string', 'maxLength' => 255],
                'body'    => ['type' => 'string', 'maxLength' => 5000],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function notificationItemSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'         => ['type' => 'string', 'description' => 'Opaque notification identifier.'],
                'type'       => ['type' => 'string', 'enum' => ['loan_due', 'loan_overdue', 'reservation_ready', 'new_message', 'book_available']],
                'title'      => ['type' => 'string'],
                'body'       => ['type' => 'string'],
                'read'       => ['type' => 'boolean'],
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                'payload'    => ['type' => 'object', 'additionalProperties' => true, 'nullable' => true],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function pushSubscribeRequestSchema(): array
    {
        return [
            'type'        => 'object',
            'required'    => ['provider'],
            'description' => 'Register a push endpoint. For UnifiedPush provide `endpoint` + optional `public_key`/`auth`. For FCM provide `registration_id`.',
            'properties'  => [
                'provider'        => ['type' => 'string', 'enum' => ['unifiedpush', 'fcm']],
                'endpoint'        => ['type' => 'string', 'format' => 'uri', 'description' => 'UnifiedPush: the distributor-provided HTTPS endpoint URL.'],
                'public_key'      => ['type' => 'string', 'description' => 'UnifiedPush WebPush: base64url-encoded ECDH public key.'],
                'auth'            => ['type' => 'string', 'description' => 'UnifiedPush WebPush: base64url-encoded auth secret.'],
                'registration_id' => ['type' => 'string', 'description' => 'FCM: the registration token from the FCM SDK.'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function pushPrefsSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'loan_due'          => ['type' => 'boolean', 'description' => 'Notify when a loan is due soon.'],
                'loan_overdue'      => ['type' => 'boolean', 'description' => 'Notify when a loan is overdue.'],
                'reservation_ready' => ['type' => 'boolean', 'description' => 'Notify when a reservation is ready for pick-up.'],
                'new_message'       => ['type' => 'boolean', 'description' => 'Notify on admin reply / new message.'],
                'book_available'    => ['type' => 'boolean', 'description' => 'Notify when a wishlisted/reserved book is available again.'],
                'quiet_start'       => ['type' => 'string', 'pattern' => '^\\d{2}:\\d{2}$', 'nullable' => true, 'example' => '22:00', 'description' => 'Quiet hours start (HH:MM, instance time zone).'],
                'quiet_end'         => ['type' => 'string', 'pattern' => '^\\d{2}:\\d{2}$', 'nullable' => true, 'example' => '08:00', 'description' => 'Quiet hours end (HH:MM).'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function healthPayloadSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'name'                 => ['type' => 'string', 'description' => 'Library name.'],
                'logo'                 => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'version'              => ['type' => 'string', 'description' => 'Pinakes application version.'],
                'api_version'          => ['type' => 'string', 'example' => 'v1'],
                'features'             => [
                    'type'       => 'object',
                    'properties' => [
                        'catalog'       => ['type' => 'boolean'],
                        'loans'         => ['type' => 'boolean'],
                        'reservations'  => ['type' => 'boolean'],
                        'wishlist'      => ['type' => 'boolean'],
                        'messages'      => ['type' => 'boolean'],
                        'notifications' => ['type' => 'boolean'],
                        'push'          => ['type' => 'boolean'],
                    ],
                ],
                'app_access_enabled'   => ['type' => 'boolean'],
                'registration_enabled' => ['type' => 'boolean'],
                'private_mode'         => ['type' => 'boolean'],
            ],
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function baseUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $base = $uri->getScheme() . '://' . $uri->getHost();
        $port = $uri->getPort();
        if ($port !== null && $port !== 80 && $port !== 443) {
            $base .= ':' . $port;
        }

        $basePath = defined('BASE_PATH') ? (string) BASE_PATH : '';

        return rtrim($base . $basePath, '/');
    }

    private function appVersion(): string
    {
        $file = dirname(__DIR__, 5) . '/version.json';
        if (is_file($file)) {
            $raw = file_get_contents($file);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['version']) && is_string($decoded['version'])) {
                    return $decoded['version'];
                }
            }
        }

        return '0.0.0';
    }
}
