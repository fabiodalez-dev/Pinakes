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
    /** Optional hook manager: lets sibling plugins (e.g. book-club) extend the document. */
    private ?\App\Support\HookManager $hooks;

    public function __construct(?\App\Support\HookManager $hooks = null)
    {
        $this->hooks = $hooks;
    }

    public function document(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        try {
            $baseUrl = $this->baseUrl($request);
            $version = $this->appVersion();
            $doc     = $this->build($baseUrl, $version);

            // Cross-plugin extension point: active plugins that mount routes under
            // /api/v1 (book-club bridge) document them here, so the add-endpoint ⇒
            // add-manifest-row guard in tests/mobile-api-idempotency.spec.js can
            // see the whole surface.
            if ($this->hooks !== null) {
                $extended = $this->hooks->applyFilters('mobile_api.openapi', $doc);
                if (is_array($extended)) {
                    $doc = $extended;
                }
            }

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
                'contact'     => ['name' => 'Pinakes', 'url' => 'https://github.com/fabiodalez-dev/Pinakes'],
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
                    'RegistrationConfig'=> $this->registrationConfigSchema(),
                    'RegistrationFieldsPayload' => $this->registrationFieldsPayloadSchema(),
                    'CustomFieldDefinition' => $this->customFieldDefinitionSchema(),
                    'CustomProfileField'=> $this->customProfileFieldSchema(),
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

                    // Reviews
                    'Review'            => $this->reviewSchema(),
                    'ReviewRequest'     => $this->reviewRequestSchema(),
                    'BookReviews'       => $this->bookReviewsSchema(),
                    'MyReview'          => $this->myReviewSchema(),

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
                        '403' => ['description' => 'App access disabled on this instance (code app_access_disabled), email not verified or account not active.', 'content' => $json],
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

            '/auth/registration-fields' => [
                'get' => [
                    'tags'        => ['auth'],
                    'summary'     => 'Registration field discovery',
                    'description' => 'Public, no token. Advertises whether self-registration is enabled (registration_enabled) plus the fields the signup form should render: the config-driven built-ins (cognome / telefono / indirizzo, each with required + configurable flags) and the admin-defined custom_fields ({id, label, type, required}). The native app calls this before showing the registration form so it can render the right inputs and required markers. Gated on app access (403 app_access_disabled) like the other public auth endpoints.',
                    'operationId' => 'getAuthRegistrationFields',
                    'responses'   => [
                        '200' => [
                            'description' => 'Registration discovery payload.',
                            'content'     => ['application/json' => ['schema' => [
                                'allOf'      => [['$ref' => '#/components/schemas/Envelope']],
                                'properties' => ['data' => ['$ref' => '#/components/schemas/RegistrationFieldsPayload']],
                            ]]],
                        ],
                        '403' => ['$ref' => '#/components/responses/Forbidden'],
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
                        ['name' => 'sort',      'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['newest', 'oldest', 'title_asc', 'title_desc', 'author_asc', 'author_desc'], 'default' => 'newest'], 'description' => 'Sort order: newest, oldest, title_asc, title_desc, author_asc, or author_desc'],
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

            '/catalog/books/{id}/availability' => [
                'get' => [
                    'tags'        => ['catalog'],
                    'summary'     => 'Book loan availability (calendar)',
                    'description' => 'Per-day loan availability for the date-picker calendar: total_copies, earliest_available, unavailable_dates (fully-booked days), and days[] with per-day free/total counts. Same computation as the website; excludes the requesting user\'s own active reservations. Soft-deleted books return 404.',
                    'operationId' => 'getCatalogBookAvailability',
                    'security'    => [['bearerAuth' => []]],
                    'parameters'  => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'Book ID'],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Availability calendar data.',
                            'content'     => ['application/json' => ['schema' => [
                                'allOf'      => [['$ref' => '#/components/schemas/Envelope']],
                                'properties' => ['data' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'total_copies'       => ['type' => 'integer'],
                                        'earliest_available' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                                        'unavailable_dates'  => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date']],
                                        'days'               => ['type' => 'array', 'items' => [
                                            'type'       => 'object',
                                            'properties' => [
                                                'date'      => ['type' => 'string', 'format' => 'date'],
                                                'available' => ['type' => 'integer'],
                                                'loaned'    => ['type' => 'integer'],
                                                'reserved'  => ['type' => 'integer'],
                                                'state'     => ['type' => 'string', 'enum' => ['free', 'partial', 'full']],
                                            ],
                                        ]],
                                    ],
                                ]],
                            ]]],
                        ],
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

            '/catalog/languages' => [
                'get' => [
                    'tags'        => ['catalog'],
                    'summary'     => 'Catalogue languages',
                    'description' => 'Distinct language values present in the catalogue, each with a book count, for building the app language filter from real data rather than a hardcoded list (#282).',
                    'operationId' => 'getCatalogLanguages',
                    'security'    => [['bearerAuth' => []]],
                    'responses'   => [
                        '200' => ['description' => 'Languages.', 'content' => ['application/json' => ['schema' => [
                            'allOf' => [['$ref' => '#/components/schemas/Envelope']],
                            'properties' => ['data' => ['type' => 'array', 'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'language' => ['type' => 'string'],
                                    'count'    => ['type' => 'integer'],
                                ],
                            ]]],
                        ]]]],
                        '304' => ['description' => 'Not Modified (ETag / If-None-Match honored).'],
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

            '/catalog/books/{id}/reviews' => [
                'get' => [
                    'tags'        => ['reviews'],
                    'summary'     => 'Book reviews (aggregate + own + approved others)',
                    'description' => 'Reviews are MODERATED: `items` and the aggregate cover approved reviews only; `mine` is returned regardless of moderation state. `meta.next_cursor` pages the nested `data.items`. `can_review` = the caller has borrowed the title.',
                    'operationId' => 'getBookReviews',
                    'security'    => [['bearerAuth' => []]],
                    'parameters'  => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'Book ID'],
                        ['name' => 'cursor', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Opaque cursor for data.items'],
                        ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20]],
                    ],
                    'responses'   => [
                        '200' => ['description' => 'Reviews payload.', 'content' => ['application/json' => ['schema' => [
                            'allOf' => [['$ref' => '#/components/schemas/Envelope']],
                            'properties' => ['data' => ['$ref' => '#/components/schemas/BookReviews']],
                        ]]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '404' => ['$ref' => '#/components/responses/NotFound'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
                'put' => [
                    'tags'        => ['reviews'],
                    'summary'     => 'Create or update own review (idempotent upsert)',
                    'description' => 'One review per (user, book): PUT updates the existing review or creates it. Requires having borrowed the title (403 `not_eligible` otherwise). The stored review goes back to moderation (`pending`) on every write; `meta.pending=true` signals it.',
                    'operationId' => 'putBookReview',
                    'security'    => [['bearerAuth' => []]],
                    'parameters'  => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'Book ID'],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content'  => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ReviewRequest']]],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Stored review (pending moderation).', 'content' => ['application/json' => ['schema' => [
                            'allOf' => [['$ref' => '#/components/schemas/Envelope']],
                            'properties' => ['data' => ['$ref' => '#/components/schemas/Review']],
                        ]]]],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '403' => ['$ref' => '#/components/responses/Forbidden'],
                        '404' => ['$ref' => '#/components/responses/NotFound'],
                        '422' => ['$ref' => '#/components/responses/UnprocessableEntity'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
                'delete' => [
                    'tags'        => ['reviews'],
                    'summary'     => 'Delete own review (idempotent)',
                    'operationId' => 'deleteBookReview',
                    'security'    => [['bearerAuth' => []]],
                    'parameters'  => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'Book ID'],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Review deleted.', 'content' => $json],
                        '401' => ['$ref' => '#/components/responses/Unauthorized'],
                        '404' => ['$ref' => '#/components/responses/NotFound'],
                        '500' => ['$ref' => '#/components/responses/InternalError'],
                    ],
                ],
            ],

            '/me/reviews' => [
                'get' => [
                    'tags'        => ['reviews'],
                    'summary'     => 'Own reviews across all books (any moderation state)',
                    'operationId' => 'getMeReviews',
                    'security'    => [['bearerAuth' => []]],
                    'parameters'  => [
                        ['name' => 'cursor', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                        ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20]],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Own reviews.', 'content' => ['application/json' => ['schema' => [
                            'allOf' => [['$ref' => '#/components/schemas/Envelope']],
                            'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/MyReview']]],
                        ]]]],
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
                'code'    => ['type' => 'string', 'description' => 'Machine-readable error code, e.g. invalid_credentials, app_access_disabled, rate_limited.'],
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
            'required'   => ['nome', 'email', 'password', 'password_confirm', 'privacy_acceptance'],
            'properties' => [
                'nome'               => ['type' => 'string', 'maxLength' => 100],
                'cognome'            => ['type' => 'string', 'maxLength' => 100, 'description' => 'Required only when health.registration.require_cognome is true.'],
                'email'              => ['type' => 'string', 'format' => 'email', 'maxLength' => 255],
                'telefono'           => ['type' => 'string', 'description' => 'Required only when health.registration.require_telefono is true.'],
                'indirizzo'          => ['type' => 'string', 'description' => 'Required only when health.registration.require_indirizzo is true.'],
                'password'           => ['type' => 'string', 'minLength' => 8, 'maxLength' => 72],
                'password_confirm'   => ['type' => 'string', 'minLength' => 8, 'maxLength' => 72],
                'privacy_acceptance' => ['type' => 'boolean'],
                'custom_fields'      => [
                    'type'                 => 'object',
                    'description'          => 'Values keyed by the numeric field id advertised by health.registration.custom_fields.',
                    'additionalProperties' => ['type' => 'string', 'maxLength' => 1000],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function customFieldDefinitionSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['id', 'label', 'type', 'required'],
            'properties' => [
                'id'       => ['type' => 'integer'],
                'label'    => ['type' => 'string'],
                'type'     => ['type' => 'string', 'enum' => ['text', 'textarea', 'email', 'url', 'number', 'checkbox']],
                'required' => ['type' => 'boolean'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function customProfileFieldSchema(): array
    {
        $schema = $this->customFieldDefinitionSchema();
        $schema['required'][] = 'value';
        $schema['properties']['value'] = ['type' => 'string', 'maxLength' => 1000];
        return $schema;
    }

    /** @return array<string, mixed> */
    private function registrationConfigSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['require_cognome', 'require_telefono', 'require_indirizzo', 'custom_fields'],
            'properties' => [
                'require_cognome'   => ['type' => 'boolean'],
                'require_telefono'  => ['type' => 'boolean'],
                'require_indirizzo' => ['type' => 'boolean'],
                'custom_fields'     => [
                    'type'  => 'array',
                    'items' => ['$ref' => '#/components/schemas/CustomFieldDefinition'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function registrationFieldsPayloadSchema(): array
    {
        $builtinRule = [
            'type'       => 'object',
            'required'   => ['required', 'configurable'],
            'properties' => [
                'required'     => ['type' => 'boolean', 'description' => 'Whether this built-in field is required at signup (admin-configurable).'],
                'configurable' => ['type' => 'boolean', 'description' => 'Always true — these built-ins can be toggled required/optional by the admin.'],
            ],
        ];

        return [
            'type'        => 'object',
            'description' => 'Canonical signup form-render contract. builtin_fields carries the always-required core fields (nome / email / password, configurable=false) plus the three config-driven toggles (cognome / telefono / indirizzo, configurable=true). Profile-only fields (data_nascita / cod_fiscale / sesso) are always optional at signup and are not advertised here. GET /health carries a lightweight mirror of the same RegistrationFields source; the two cannot drift.',
            'required'    => ['registration_enabled', 'builtin_fields', 'custom_fields'],
            'properties'  => [
                'registration_enabled' => ['type' => 'boolean'],
                'builtin_fields'       => [
                    'type'       => 'object',
                    'properties' => [
                        'nome'      => $builtinRule,
                        'email'     => $builtinRule,
                        'password'  => $builtinRule,
                        'cognome'   => $builtinRule,
                        'telefono'  => $builtinRule,
                        'indirizzo' => $builtinRule,
                    ],
                ],
                'custom_fields'        => [
                    'type'  => 'array',
                    'items' => ['$ref' => '#/components/schemas/CustomFieldDefinition'],
                ],
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
                'id'               => ['type' => 'integer'],
                'title'            => ['type' => 'string'],
                'subtitle'         => ['type' => 'string', 'nullable' => true],
                'author'           => ['type' => 'string', 'nullable' => true],
                'publisher'        => ['type' => 'string', 'nullable' => true],
                'genre'            => ['type' => 'string', 'nullable' => true],
                'year'             => ['type' => 'integer', 'nullable' => true],
                'language'         => ['type' => 'string', 'nullable' => true],
                'media_type'       => ['type' => 'string', 'nullable' => true],
                'isbn13'           => ['type' => 'string', 'nullable' => true],
                'cover_url'        => ['type' => 'string', 'format' => 'uri', 'nullable' => true, 'description' => 'Absolute URL.'],
                'copies_total'     => ['type' => 'integer'],
                'copies_available' => ['type' => 'integer'],
                'loanable_now'     => ['type' => 'boolean', 'description' => 'True if at least one copy is currently loanable.'],
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
                'isbn10'           => ['type' => 'string', 'nullable' => true],
                'ean'              => ['type' => 'string', 'nullable' => true],
                'pages'            => ['type' => 'integer', 'nullable' => true],
                'description'      => ['type' => 'string', 'nullable' => true],
                'format'           => ['type' => 'string', 'nullable' => true],
                'series'           => ['type' => 'string', 'nullable' => true],
                'condition'        => ['type' => 'string', 'nullable' => true],
                'audio_url'        => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'has_audio'        => ['type' => 'boolean'],
                'ebook_url'        => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'ebook_format'     => ['type' => 'string', 'nullable' => true],
                'has_ebook'        => ['type' => 'boolean'],
                'genre'            => ['type' => 'object', 'nullable' => true, 'properties' => [
                    'id'          => ['type' => 'integer', 'nullable' => true],
                    'name'        => ['type' => 'string', 'nullable' => true],
                    'parent'      => ['type' => 'string', 'nullable' => true],
                    'grandparent' => ['type' => 'string', 'nullable' => true],
                    'subgenre'    => ['type' => 'string', 'nullable' => true],
                ]],
                'publishers'       => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id'   => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ]]],
                'authors'          => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id'             => ['type' => 'integer'],
                    'name'           => ['type' => 'string', 'description' => 'Preferred display label: pseudonym plus canonical name when present.'],
                    'canonical_name' => ['type' => 'string', 'description' => 'Canonical/real name used by authority providers and imports.'],
                    'pseudonym'      => ['type' => 'string', 'nullable' => true],
                    'role'           => ['type' => 'string', 'nullable' => true, 'enum' => ['principale', 'co-autore', 'traduttore', 'illustratore', 'curatore', 'colorista']],
                ]]],
                'availability'     => ['type' => 'object', 'properties' => [
                    'copies_total'     => ['type' => 'integer'],
                    'copies_available' => ['type' => 'integer'],
                    'loanable_now'     => ['type' => 'boolean'],
                    'state'            => ['type' => 'string', 'enum' => ['available', 'on_loan', 'reserved', 'unavailable']],
                ]],
                'location'         => ['type' => 'object', 'nullable' => true, 'properties' => [
                    'label'         => ['type' => 'string', 'nullable' => true],
                    'shelf_id'      => ['type' => 'integer', 'nullable' => true],
                    'shelf_unit_id' => ['type' => 'integer', 'nullable' => true],
                    'position'      => ['type' => 'integer', 'nullable' => true],
                ]],
                'personal_history' => ['$ref' => '#/components/schemas/PersonalHistory'],
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
                'has_pending_request' => ['type' => 'boolean', 'description' => 'The user has a pending loan request for this book.'],
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
                'name'     => ['type' => 'string'],
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
                'id'          => ['type' => 'integer'],
                'book_id'     => ['type' => 'integer'],
                'title'       => ['type' => 'string'],
                'cover_url'   => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'status'      => ['type' => 'string', 'description' => 'Raw prestiti.stato value.'],
                'loaned_at'   => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                'due_at'      => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                'due_attention' => [
                    'type' => 'boolean',
                    'description' => 'True when an active in-progress/overdue loan is due today or earlier in the application timezone.',
                ],
                'returned_at' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                'renewals'    => ['type' => 'integer', 'nullable' => true],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function reservationItemSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'             => ['type' => 'integer'],
                'book_id'        => ['type' => 'integer'],
                'title'          => ['type' => 'string'],
                'cover_url'      => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'status'         => ['type' => 'string'],
                'queue_position' => ['type' => 'integer', 'nullable' => true],
                'requested_from' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                'requested_to'   => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                'reserved_at'    => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'expires_at'     => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
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
                'book_id'      => ['type' => 'integer'],
                'desired_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true, 'description' => 'Requested start date. Today or omitted means immediate loan when a copy is free; future dates create reservations.'],
                'start_date'   => ['type' => 'string', 'format' => 'date', 'nullable' => true, 'deprecated' => true],
                'end_date'     => ['type' => 'string', 'format' => 'date', 'nullable' => true, 'deprecated' => true],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function wishlistItemSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'book_id'          => ['type' => 'integer'],
                'title'            => ['type' => 'string'],
                'author'           => ['type' => 'string', 'nullable' => true],
                'year'             => ['type' => 'integer', 'nullable' => true],
                'cover_url'        => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'copies_available' => ['type' => 'integer'],
                'loanable_now'     => ['type' => 'boolean'],
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
                'tipo_utente'       => ['type' => 'string', 'enum' => ['standard', 'premium', 'staff', 'admin']],
                'email_verificata'  => ['type' => 'boolean'],
                'stato'             => ['type' => 'string'],
                'avatar_url'        => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'telefono'          => ['type' => 'string', 'nullable' => true],
                'indirizzo'         => ['type' => 'string', 'nullable' => true],
                'data_nascita'      => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                'cod_fiscale'       => ['type' => 'string', 'nullable' => true],
                'sesso'             => ['type' => 'string', 'nullable' => true],
                'locale'            => ['type' => 'string', 'nullable' => true],
                'custom_fields'     => [
                    'type'  => 'array',
                    'items' => ['$ref' => '#/components/schemas/CustomProfileField'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function updateProfileRequestSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'nome'          => ['type' => 'string', 'maxLength' => 100],
                'cognome'       => ['type' => 'string', 'maxLength' => 100],
                'telefono'      => ['type' => 'string'],
                'indirizzo'     => ['type' => 'string'],
                'data_nascita'  => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                'cod_fiscale'   => ['type' => 'string', 'nullable' => true],
                'sesso'         => ['type' => 'string', 'nullable' => true],
                'locale'        => ['type' => 'string', 'nullable' => true],
                'custom_fields' => [
                    'type'                 => 'object',
                    'description'          => 'When omitted, all custom values are preserved. Within the map, omitted ids are preserved and an explicitly empty value clears that field.',
                    'additionalProperties' => ['type' => 'string', 'maxLength' => 1000],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function changePasswordRequestSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['current_password', 'password', 'password_confirm'],
            'properties' => [
                'current_password' => ['type' => 'string'],
                'password'         => ['type' => 'string', 'minLength' => 8, 'maxLength' => 72],
                'password_confirm' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 72],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function messageRequestSchema(): array
    {
        // Mirror the real ActionsController::sendMessage contract: the message
        // text is `messaggio` (aliases `message` / `body` are accepted); nome/
        // cognome/email default to the authenticated user when omitted; telefono/
        // indirizzo are optional. The previous schema wrongly required
        // subject/body — the controller never reads `subject`.
        return [
            'type'        => 'object',
            'required'    => ['messaggio'],
            'description' => 'nome/cognome/email default to the authenticated user when omitted. The message text is `messaggio` (aliases: `message`, `body`).',
            'properties'  => [
                'messaggio' => ['type' => 'string', 'maxLength' => 5000, 'description' => 'Message text.'],
                'message'   => ['type' => 'string', 'maxLength' => 5000, 'description' => 'Alias of `messaggio`.'],
                'body'      => ['type' => 'string', 'maxLength' => 5000, 'description' => 'Alias of `messaggio`.'],
                'nome'      => ['type' => 'string', 'maxLength' => 100],
                'cognome'   => ['type' => 'string', 'maxLength' => 100],
                'email'     => ['type' => 'string', 'format' => 'email'],
                'telefono'  => ['type' => 'string'],
                'indirizzo' => ['type' => 'string'],
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
                'message'    => ['type' => 'string'],
                'book_id'    => ['type' => 'integer', 'nullable' => true],
                'date'       => ['type' => 'string', 'nullable' => true, 'description' => 'ISO date or date-time associated with the notification.'],
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
                'catalogue_mode'       => [
                    'type'        => 'boolean',
                    'description' => 'True when the instance is in catalogue-only mode (loans, reservations and wishlist disabled).',
                ],
                'app_access_enabled'   => ['type' => 'boolean'],
                'registration_enabled' => ['type' => 'boolean'],
                'registration'         => ['$ref' => '#/components/schemas/RegistrationConfig'],
                'private_mode'         => ['type' => 'boolean'],
                'vapid_public_key'     => [
                    'type'        => 'string',
                    'nullable'    => true,
                    'description' => 'VAPID public key (base64url uncompressed P-256 point) to use as the Web Push / UnifiedPush applicationServerKey. Null when push is not set up.',
                ],
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

    /** @return array<string, mixed> */
    private function reviewSchema(): array
    {
        return [
            'type'        => 'object',
            'description' => 'A single book review. `status` (pendente|approvata|rifiutata) is server-extra: reviews are moderated and a just-submitted/edited review is pending until an admin approves it.',
            'properties'  => [
                'id'         => ['type' => 'integer'],
                'rating'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'text'       => ['type' => 'string', 'nullable' => true],
                'user_name'  => ['type' => 'string'],
                'is_mine'    => ['type' => 'boolean'],
                'status'     => ['type' => 'string', 'enum' => ['pendente', 'approvata', 'rifiutata']],
                'created_at' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-07-06T09:30:00Z'],
                'updated_at' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-07-06T09:30:00Z'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function reviewRequestSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['rating'],
            'properties' => [
                'rating' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'text'   => ['type' => 'string', 'maxLength' => 2000, 'nullable' => true],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bookReviewsSchema(): array
    {
        return [
            'type'        => 'object',
            'description' => 'Aggregate (approved reviews only) + the caller\'s own review (any state) + other users\' approved reviews. `meta.next_cursor` pages `items`.',
            'properties'  => [
                'average'      => ['type' => 'number', 'format' => 'float'],
                'count'        => ['type' => 'integer'],
                'distribution' => [
                    'type'       => 'object',
                    'properties' => [
                        '1' => ['type' => 'integer'],
                        '2' => ['type' => 'integer'],
                        '3' => ['type' => 'integer'],
                        '4' => ['type' => 'integer'],
                        '5' => ['type' => 'integer'],
                    ],
                ],
                'can_review'   => ['type' => 'boolean', 'description' => 'The caller has borrowed this title (eligibility only — independent of having already reviewed).'],
                'mine'         => ['allOf' => [['$ref' => '#/components/schemas/Review']], 'nullable' => true],
                'items'        => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Review']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function myReviewSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'          => ['type' => 'integer'],
                'book_id'     => ['type' => 'integer'],
                'book_title'  => ['type' => 'string'],
                'book_author' => ['type' => 'string', 'nullable' => true],
                'cover_url'   => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'rating'      => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'text'        => ['type' => 'string', 'nullable' => true],
                'status'      => ['type' => 'string', 'enum' => ['pendente', 'approvata', 'rifiutata']],
                'created_at'  => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-07-06T09:30:00Z'],
                'updated_at'  => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-07-06T09:30:00Z'],
            ],
        ];
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
