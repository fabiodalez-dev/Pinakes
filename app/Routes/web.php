<?php
declare(strict_types=1);

use Slim\App;
use Slim\Psr7\Response;
use App\Controllers\AutoriController;
use App\Controllers\LibriController;
use App\Controllers\PrestitiController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\RegistrationController;
use App\Controllers\PasswordController;
use App\Controllers\ProfileController;
use App\Controllers\UserActionsController;
use App\Controllers\UserWishlistController;
use App\Middleware\AuthMiddleware;
use App\Controllers\SeoController;
use App\Controllers\DeweyController;
use App\Controllers\DeweyApiController;
use App\Controllers\GeneriApiController;
use App\Controllers\GeneriController;
use App\Controllers\ReservationsController;
use App\Controllers\LoanApprovalController;
use App\Controllers\CollocazioneController;
use App\Controllers\UserDashboardController;
use App\Controllers\MaintenanceController;
use App\Controllers\SettingsController;
use App\Controllers\LanguageController;
use App\Middleware\CsrfMiddleware;
use App\Middleware\AdminAuthMiddleware;
use App\Support\RouteTranslator;
use App\Support\I18n;

return function (App $app): void {
    $installationLocale = I18n::getInstallationLocale();
    // Supported locales for multi-language route variants
    // Content routes (book, author, publisher, genre) are registered
    // in all languages to support language switching without 404s
    $supportedLocales = ['it_IT', 'en_US'];

    // Track registered routes to avoid duplicates (some routes are identical across languages)
    $registeredRoutes = [];

    // Helper function to register route only if not already registered
    $registerRouteIfUnique = function (string $method, string $pattern, callable $handler, ?array $middleware = null) use ($app, &$registeredRoutes) {
        $routeKey = $method . ':' . $pattern;

        if (isset($registeredRoutes[$routeKey])) {
            return; // Already registered, skip
        }

        $registeredRoutes[$routeKey] = true;

        // Use specific HTTP method instead of map() for better compatibility
        $route = match (strtoupper($method)) {
            'GET' => $app->get($pattern, $handler),
            'POST' => $app->post($pattern, $handler),
            'PUT' => $app->put($pattern, $handler),
            'DELETE' => $app->delete($pattern, $handler),
            'PATCH' => $app->patch($pattern, $handler),
            default => $app->map([$method], $pattern, $handler),
        };

        if ($middleware) {
            foreach ($middleware as $mw) {
                $route->add($mw);
            }
        }
    };


    // ==========================================
    // Plugin Routes Hook (register early)
    // ==========================================
    try {
        $hookManager = $app->getContainer()->get('hookManager');
        $hookManager->doAction('app.routes.register', [$app]);
    } catch (\Throwable $e) {
        error_log('[Routes] Error loading plugin routes: ' . $e->getMessage());
    }

    $app->get('/', function ($request, $response) use ($app) {
        // Redirect to frontend home page
        $container = $app->getContainer();
        $controller = new \App\Controllers\FrontendController($container);
        $db = $container->get('db');
        return $controller->home($request, $response, $db, $container);
    });

    // Frontend Events routes
    $app->get('/events', function ($request, $response) use ($app) {
        $container = $app->getContainer();
        $controller = new \App\Controllers\FrontendController($container);
        $db = $container->get('db');
        return $controller->events($request, $response, $db);
    });

    $app->get('/events/{slug}', function ($request, $response, $args) use ($app) {
        $container = $app->getContainer();
        $controller = new \App\Controllers\FrontendController($container);
        $db = $container->get('db');
        return $controller->event($request, $response, $db, $args);
    });

    $app->get('/health', function ($request, $response) {
        $data = ['status' => 'ok'];
        $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    });

    $app->get('/robots.txt', function ($request, $response) {
        $controller = new SeoController();
        return $controller->robots($request, $response);
    });

    $app->get('/sitemap.xml', function ($request, $response) use ($app) {
        $controller = new SeoController();
        $db = $app->getContainer()->get('db');
        return $controller->sitemap($request, $response, $db);
    });

    // Public language switch endpoint
    $app->get('/language/{locale}', function ($request, $response, $args) use ($app) {
        $controller = new LanguageController();
        $db = $app->getContainer()->get('db');
        return $controller->switchLanguage($request, $response, $db, $args);
    });

    // Admin area redirect
    $app->get('/admin', function ($request, $response) {
        // Redirect to dashboard if logged in; else to login
        if (!empty($_SESSION['user'])) {
            // Check user role to determine which dashboard to show
            $userRole = $_SESSION['user']['tipo_utente'] ?? '';
            if ($userRole === 'admin' || $userRole === 'staff') {
                return $response->withHeader('Location', '/admin/dashboard')->withStatus(302);
            } else {
                // For standard users, show user dashboard
                return $response->withHeader('Location', RouteTranslator::route('user_dashboard'))->withStatus(302);
            }
        }
        return $response->withHeader('Location', RouteTranslator::route('login'))->withStatus(302);
    });

    // User Dashboard (separate from admin)
    // User dashboard (multi-language variants)
    foreach ($supportedLocales as $locale) {
        $dashboardRoute = RouteTranslator::getRouteForLocale('user_dashboard', $locale);

        $registerRouteIfUnique('GET', $dashboardRoute, function ($request, $response) use ($app) {
            $db = $app->getContainer()->get('db');
            $controller = new \App\Controllers\UserDashboardController();
            return $controller->index($request, $response, $db);
        }, [new AuthMiddleware(['admin', 'staff', 'standard', 'premium'])]);
    }

    // Auth routes (translated based on installation locale)
    // Ensure legacy/common paths also work by redirecting them to the canonical translated routes

    // Legacy Italian login URL -> redirect to current login route
    $app->get('/login.php', function ($request, $response) {
        return $response->withHeader('Location', \App\Support\RouteTranslator::route('login'))->withStatus(301);
    });

    // Legacy Italian register URL -> redirect to current register route (e.g. /registrati or /register)
    $app->get('/registra', function ($request, $response) {
        return $response->withHeader('Location', \App\Support\RouteTranslator::route('register'))->withStatus(301);
    });

    // Canonical login route
    // Login - support both English and localized routes
    $loginGetHandler = function ($request, $response) use ($app) {
        // If already logged in, redirect appropriately
        if (!empty($_SESSION['user'])) {
            $userRole = $_SESSION['user']['tipo_utente'] ?? '';
            if ($userRole === 'admin' || $userRole === 'staff') {
                return $response->withHeader('Location', '/admin/dashboard')->withStatus(302);
            } else {
                return $response->withHeader('Location', RouteTranslator::route('user_dashboard'))->withStatus(302);
            }
        }
        $controller = new AuthController();
        return $controller->loginForm($request, $response);
    };
    $registerRouteIfUnique('GET', '/login', $loginGetHandler); // English fallback (always works)
    $registerRouteIfUnique('GET', RouteTranslator::route('login'), $loginGetHandler); // Localized route (skipped if same as English)

    $loginPostHandler = function ($request, $response) use ($app) {
        $controller = new AuthController();
        return $controller->login($request, $response, $app->getContainer()->get('db'));
    };
    $registerRouteIfUnique('POST', '/login', $loginPostHandler, [new \App\Middleware\RateLimitMiddleware(5, 300), new CsrfMiddleware()]); // English fallback
    $registerRouteIfUnique('POST', RouteTranslator::route('login'), $loginPostHandler, [new \App\Middleware\RateLimitMiddleware(5, 300), new CsrfMiddleware()]); // Localized route (skipped if same as English)

    // Logout - support both English and localized routes
    $logoutHandler = function ($request, $response) use ($app) {
        $controller = new AuthController();
        return $controller->logout($request, $response);
    };
    $registerRouteIfUnique('GET', '/logout', $logoutHandler); // English fallback
    $registerRouteIfUnique('GET', RouteTranslator::route('logout'), $logoutHandler); // Localized route (e.g. /esci for Italian)

    // User profile (multi-language variants)
    foreach ($supportedLocales as $locale) {
        $profileRoute = RouteTranslator::getRouteForLocale('profile', $locale);

        $registerRouteIfUnique('GET', $profileRoute, function ($request, $response) use ($app) {
            $container = $app->getContainer();
            $db = $container->get('db');
            $controller = new ProfileController();
            return $controller->show($request, $response, $db, $container);
        }, [new AuthMiddleware(['admin', 'staff', 'standard', 'premium'])]);

        $profileUpdateRoute = RouteTranslator::getRouteForLocale('profile_update', $locale);
        $registerRouteIfUnique('POST', $profileUpdateRoute, function ($request, $response) use ($app) {
            $db = $app->getContainer()->get('db');
            $controller = new ProfileController();
            return $controller->update($request, $response, $db);
        }, [new CsrfMiddleware($app->getContainer()), new AuthMiddleware(['admin', 'staff', 'standard', 'premium'])]);

        // Redirect GET to profile update to profile
        $registerRouteIfUnique('GET', $profileUpdateRoute, function ($request, $response) use ($app) {
            return $response->withHeader('Location', RouteTranslator::route('profile'))->withStatus(302);
        }, [new AuthMiddleware(['admin', 'staff', 'standard', 'premium'])]);

        $profilePasswordRoute = RouteTranslator::getRouteForLocale('profile_password', $locale);
        $registerRouteIfUnique('POST', $profilePasswordRoute, function ($request, $response) use ($app) {
            $db = $app->getContainer()->get('db');
            $controller = new ProfileController();
            return $controller->changePassword($request, $response, $db);
        }, [new CsrfMiddleware($app->getContainer()), new AuthMiddleware(['admin', 'staff', 'standard', 'premium'])]);

        // Redirect GET to profile password to profile
        $registerRouteIfUnique('GET', $profilePasswordRoute, function ($request, $response) use ($app) {
            return $response->withHeader('Location', RouteTranslator::route('profile'))->withStatus(302);
        }, [new AuthMiddleware(['admin', 'staff', 'standard', 'premium'])]);
    }

    // User wishlist (multi-language variants)
    foreach ($supportedLocales as $locale) {
        $wishlistRoute = RouteTranslator::getRouteForLocale('wishlist', $locale);

        $registerRouteIfUnique('GET', $wishlistRoute, function ($request, $response) use ($app) {
            // Redirect to user dashboard if catalogue mode is enabled
            if (\App\Support\ConfigStore::isCatalogueMode()) {
                return $response->withHeader('Location', RouteTranslator::route('user_dashboard'))->withStatus(302);
            }
            $container = $app->getContainer();
            $db = $container->get('db');
            $controller = new UserWishlistController();
            return $controller->page($request, $response, $db, $container);
        }, [new AuthMiddleware(['admin', 'staff', 'standard', 'premium'])]);
    }

    // User reservations (multi-language variants)
    foreach ($supportedLocales as $locale) {
        $reservationsRoute = RouteTranslator::getRouteForLocale('reservations', $locale);

        $registerRouteIfUnique('GET', $reservationsRoute, function ($request, $response) use ($app) {
            // Redirect to user dashboard if catalogue mode is enabled
            if (\App\Support\ConfigStore::isCatalogueMode()) {
                return $response->withHeader('Location', RouteTranslator::route('user_dashboard'))->withStatus(302);
            }
            $container = $app->getContainer();
            $db = $container->get('db');
            $controller = new UserDashboardController();
            return $controller->prenotazioni($request, $response, $db, $container);
        }, [new AuthMiddleware(['admin', 'staff', 'standard', 'premium'])]);
    }

    // Public registration routes (translated)
    $app->get(RouteTranslator::route('register'), function ($request, $response) use ($app) {
        // Redirect logged-in users to dashboard
        if (!empty($_SESSION['user']['id'])) {
            return $response->withHeader('Location', RouteTranslator::route('user_dashboard'))->withStatus(302);
        }
        $controller = new RegistrationController();
        return $controller->form($request, $response);
    });
    $app->post(RouteTranslator::route('register'), function ($request, $response) use ($app) {
        // Redirect logged-in users to dashboard
        if (!empty($_SESSION['user']['id'])) {
            return $response->withHeader('Location', RouteTranslator::route('user_dashboard'))->withStatus(302);
        }
        $db = $app->getContainer()->get('db');
        $controller = new RegistrationController();
        return $controller->register($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(3, 3600))->add(new CsrfMiddleware($app->getContainer())); // 3 attempts per hour
    $app->get(RouteTranslator::route('register_success'), function ($request, $response) use ($app) {
        $controller = new RegistrationController();
        return $controller->success($request, $response);
    });
    // Email verification - support both English and localized routes
    $verifyEmailHandler = function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new RegistrationController();
        return $controller->verifyEmail($request, $response, $db);
    };
    $registerRouteIfUnique('GET', '/verify-email', $verifyEmailHandler); // English fallback
    $registerRouteIfUnique('GET', RouteTranslator::route('verify_email'), $verifyEmailHandler); // Localized (skipped if same)

    // Password reset - support both English and localized routes
    $forgotPasswordGetHandler = function ($request, $response) use ($app) {
        $controller = new PasswordController();
        return $controller->forgotForm($request, $response);
    };
    $registerRouteIfUnique('GET', '/forgot-password', $forgotPasswordGetHandler); // English fallback
    $registerRouteIfUnique('GET', RouteTranslator::route('forgot_password'), $forgotPasswordGetHandler); // Localized (skipped if same)

    $forgotPasswordPostHandler = function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new PasswordController();
        return $controller->forgot($request, $response, $db);
    };
    $registerRouteIfUnique('POST', '/forgot-password', $forgotPasswordPostHandler, [new \App\Middleware\RateLimitMiddleware(3, 900), new CsrfMiddleware()]); // English fallback
    $registerRouteIfUnique('POST', RouteTranslator::route('forgot_password'), $forgotPasswordPostHandler, [new \App\Middleware\RateLimitMiddleware(3, 900), new CsrfMiddleware()]); // Localized (skipped if same)

    $resetPasswordGetHandler = function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new PasswordController();
        return $controller->resetForm($request, $response, $db);
    };
    $registerRouteIfUnique('GET', '/reset-password', $resetPasswordGetHandler); // English fallback
    $registerRouteIfUnique('GET', RouteTranslator::route('reset_password'), $resetPasswordGetHandler); // Localized (skipped if same)

    $resetPasswordPostHandler = function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new PasswordController();
        return $controller->reset($request, $response, $db);
    };
    $registerRouteIfUnique('POST', '/reset-password', $resetPasswordPostHandler, [new \App\Middleware\RateLimitMiddleware(5, 300), new CsrfMiddleware()]); // English fallback
    $registerRouteIfUnique('POST', RouteTranslator::route('reset_password'), $resetPasswordPostHandler, [new \App\Middleware\RateLimitMiddleware(5, 300), new CsrfMiddleware()]); // Localized (skipped if same)

    // Frontend user actions: loan/reserve
    $app->post('/user/loan', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new UserActionsController();
        return $controller->loan($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));
    $app->post('/user/reserve', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new UserActionsController();
        return $controller->reserve($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));

    // Rotte frontend per gestire le prenotazioni dell'utente (slug '/prenotazioni')

    // Redirect old URL for compatibility
    $app->get('/my-reservations', function ($request, $response) {
        return $response->withHeader('Location', \App\Support\RouteTranslator::route('reservations'))->withStatus(301);
    })->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));
    $app->post('/reservation/cancel', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new UserActionsController();
        return $controller->cancelReservation($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));
    $app->post('/reservation/change-date', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new UserActionsController();
        return $controller->changeReservationDate($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));

    // User reservations count (for badge)
    // If authenticated (via session user), returns JSON {count: N}
    // If not authenticated, returns JSON {count: 0} with 200 (used on public pages safely).
    $app->get('/api/user/reservations/count', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new UserActionsController();
        return $controller->reservationsCount($request, $response, $db);
    });

    // Wishlist API (AJAX)
    $app->get('/api/user/wishlist/status', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new UserWishlistController();
        return $controller->status($request, $response, $db);
    })->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));
    $app->post('/api/user/wishlist/toggle', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new UserWishlistController();
        return $controller->toggle($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));

    // Rotte dedicate alla gestione della wishlist utente

    // ========== RECENSIONI (Reviews System) ==========
    // User review routes
    $app->get('/api/user/can-review/{libro_id:\d+}', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\RecensioniController();
        return $controller->canReview($request, $response, $db, $args);
    })->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));

    $app->post('/api/user/recensioni', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\RecensioniController();
        return $controller->create($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));

    // Admin review management routes
    $app->get('/admin/recensioni', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\RecensioniAdminController();
        return $controller->index($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware());

    $app->post('/admin/recensioni/{id:\d+}/approve', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\RecensioniAdminController();
        return $controller->approve($request, $response, $db, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/recensioni/{id:\d+}/reject', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\RecensioniAdminController();
        return $controller->reject($request, $response, $db, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->delete('/admin/recensioni/{id:\d+}', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\RecensioniAdminController();
        return $controller->delete($request, $response, $db, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    // ========== END RECENSIONI ==========

    // Admin settings (general + email + templates)
    // Security Logs (Admin Only)
    $app->get('/admin/security-logs', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\SecurityLogsController();
        return $controller->index($request, $response);
    })->add(new AdminAuthMiddleware());

    $app->get('/admin/settings', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new SettingsController();
        return $controller->index($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    $app->post('/admin/settings/general', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new SettingsController();
        return $controller->updateGeneral($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/settings/email', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new SettingsController();
        return $controller->updateEmailSettings($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/settings/contacts', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new SettingsController();
        return $controller->updateContactSettings($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/settings/privacy', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new SettingsController();
        return $controller->updatePrivacySettings($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/settings/cookie-banner', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new SettingsController();
        return $controller->updateCookieBannerTexts($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/settings/templates/{template}', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new SettingsController();
        $template = (string) ($args['template'] ?? '');
        return $controller->updateEmailTemplate($request, $response, $db, $template);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/settings/labels', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new SettingsController();
        return $controller->updateLabels($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/settings/advanced', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new SettingsController();
        return $controller->updateAdvancedSettings($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/settings/advanced/regenerate-sitemap', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new SettingsController();
        return $controller->regenerateSitemap($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // API Settings Routes
    $app->post('/admin/settings/api/toggle', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new SettingsController();
        return $controller->toggleApi($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/settings/api/keys/create', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new SettingsController();
        return $controller->createApiKey($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/settings/api/keys/{id:\d+}/toggle', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new SettingsController();
        return $controller->toggleApiKey($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/settings/api/keys/{id:\d+}/delete', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new SettingsController();
        return $controller->deleteApiKey($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Language Management Routes (Admin Only)
    $app->get('/admin/languages', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\LanguagesController();
        return $controller->index($request, $response, $db, []);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware());

    $app->get('/admin/languages/create', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\LanguagesController();
        return $controller->create($request, $response, $db, []);
    })->add(new AdminAuthMiddleware());

    $app->post('/admin/languages', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\LanguagesController();
        return $controller->store($request, $response, $db, []);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // IMPORTANT: Static routes MUST come BEFORE dynamic routes with {code}
    // to avoid FastRoute shadowing errors
    $app->post('/admin/languages/refresh-stats', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\LanguagesController();
        return $controller->refreshStats($request, $response, $db, []);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->get('/admin/languages/{code}/download', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\LanguagesController();
        return $controller->download($request, $response, $db, $args);
    })->add(new AdminAuthMiddleware());

    // Route translation editor (must come before /admin/languages/{code}/edit)
    $app->get('/admin/languages/{code}/edit-routes', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\LanguagesController();
        return $controller->editRoutes($request, $response, $db, $args);
    })->add(new AdminAuthMiddleware());

    if ($installationLocale === 'en_US') {
        $legacyRedirects = [
            '/accedi' => 'login',
            '/registrati' => 'register',
            '/profilo' => 'profile',
            '/profilo/password' => 'profile_password',
            '/profilo/aggiorna' => 'profile_update',
            '/prenotazioni' => 'reservations',
            '/lista-desideri' => 'wishlist',
            '/catalogo' => 'catalog',
            '/catalogo.php' => 'catalog_legacy',
        ];

        foreach ($legacyRedirects as $legacyPath => $routeKey) {
            $targetPath = RouteTranslator::route($routeKey);
            if ($targetPath === $legacyPath) {
                continue;
            }

            $registerRouteIfUnique('GET', $legacyPath, function ($request, $response) use ($targetPath) {
                return $response->withHeader('Location', $targetPath)->withStatus(302);
            });
        }
    }

    $app->post('/admin/languages/{code}/update-routes', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\LanguagesController();
        return $controller->updateRoutes($request, $response, $db, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->get('/admin/languages/{code}/edit', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\LanguagesController();
        return $controller->edit($request, $response, $db, $args);
    })->add(new AdminAuthMiddleware());

    $app->post('/admin/languages/{code}', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\LanguagesController();
        return $controller->update($request, $response, $db, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/languages/{code}/delete', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\LanguagesController();
        return $controller->delete($request, $response, $db, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/languages/{code}/toggle-active', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\LanguagesController();
        return $controller->toggleActive($request, $response, $db, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/languages/{code}/set-default', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\LanguagesController();
        return $controller->setDefault($request, $response, $db, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Admin Messages API routes
    $app->get('/admin/messages', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\MessagesController($db);
        return $controller->getAll($request, $response);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware());

    $app->get('/admin/messages/{id:\d+}', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\MessagesController($db);
        return $controller->getOne($request, $response, $args);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware());

    $app->delete('/admin/messages/{id:\d+}', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\MessagesController($db);
        return $controller->delete($request, $response, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/messages/{id:\d+}/archive', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\MessagesController($db);
        return $controller->archive($request, $response, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/messages/mark-all-read', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\MessagesController($db);
        return $controller->markAllRead($request, $response);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Admin Notifications routes
    $app->get('/admin/notifications', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\NotificationsController($db);
        return $controller->index($request, $response);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware());

    $app->get('/admin/notifications/recent', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\NotificationsController($db);
        return $controller->getRecent($request, $response);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware());

    $app->get('/admin/notifications/unread-count', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\NotificationsController($db);
        return $controller->getUnreadCount($request, $response);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware());

    $app->post('/admin/notifications/{id:\d+}/read', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\NotificationsController($db);
        return $controller->markAsRead($request, $response, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/notifications/mark-all-read', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\NotificationsController($db);
        return $controller->markAllAsRead($request, $response);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->delete('/admin/notifications/{id:\d+}', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\NotificationsController($db);
        return $controller->delete($request, $response, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Admin CMS routes - Homepage
    $app->get('/admin/cms/home', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\CmsController();
        return $controller->editHome($request, $response, $db, $args);
    })->add(new AdminAuthMiddleware());

    $app->post('/admin/cms/home', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\CmsController();
        return $controller->updateHome($request, $response, $db, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Reorder home sections (AJAX)
    $app->post('/admin/cms/home/reorder', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\CmsController();
        return $controller->reorderHomeSections($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Toggle home section visibility (AJAX)
    $app->post('/admin/cms/home/toggle-visibility', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\CmsController();
        return $controller->toggleSectionVisibility($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Admin Events routes (MUST be before the catch-all /admin/cms/{slug} route)
    $app->get('/admin/cms/events', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\EventsController();
        return $controller->index($request, $response, $db);
    })->add(new AdminAuthMiddleware());

    $app->post('/admin/cms/events/toggle-visibility', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\EventsController();
        return $controller->toggleVisibility($request, $response, $db);
    })->add(new AdminAuthMiddleware());

    $app->get('/admin/cms/events/create', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\EventsController();
        return $controller->create($request, $response, $db);
    })->add(new AdminAuthMiddleware());

    $app->post('/admin/cms/events', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\EventsController();
        return $controller->store($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->get('/admin/cms/events/edit/{id}', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\EventsController();
        return $controller->edit($request, $response, $db, $args);
    })->add(new AdminAuthMiddleware());

    $app->post('/admin/cms/events/update/{id}', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\EventsController();
        return $controller->update($request, $response, $db, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->get('/admin/cms/events/delete/{id}', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\EventsController();
        return $controller->delete($request, $response, $db, $args);
    })->add(new AdminAuthMiddleware());

    // Admin CMS routes - Other pages (catch-all, MUST be after specific routes)
    $app->get('/admin/cms/{slug}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\Admin\CmsAdminController();
        return $controller->editPage($request, $response, $args);
    })->add(new AdminAuthMiddleware());

    $app->post('/admin/cms/{slug}/update', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\Admin\CmsAdminController();
        return $controller->updatePage($request, $response, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/cms/upload', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\Admin\CmsAdminController();
        return $controller->uploadImage($request, $response);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Admin API: pending registrations count/list
    $app->get('/api/admin/pending-registrations-count', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $res = $db->query("SELECT COUNT(*) AS c FROM utenti WHERE stato='sospeso' AND email_verificata=1");
        $count = (int) ($res->fetch_assoc()['c'] ?? 0);
        $payload = json_encode(['count' => $count]);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->get('/api/admin/pending-registrations', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $res = $db->query("SELECT id, nome, cognome, email, created_at FROM utenti WHERE stato='sospeso' AND email_verificata=1 ORDER BY created_at DESC LIMIT 10");
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $response->getBody()->write(json_encode($rows));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    // Dashboard (protected admin)
    $app->get('/admin/dashboard', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new DashboardController();
        return $controller->index($request, $response, $db);
    })->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));

    // Statistics page
    $app->get('/admin/statistiche', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\StatsController();
        return $controller->index($request, $response, $db);
    })->add(new AdminAuthMiddleware());

    // Simple stats APIs used by header quick stats (require authentication)
    $app->get('/api/stats/books-count', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $count = 0;
        if ($db) {
            $res = $db->query("SELECT COUNT(*) AS c FROM libri");
            if ($res) {
                $count = (int) ($res->fetch_assoc()['c'] ?? 0);
            }
        }
        $response->getBody()->write(json_encode(['count' => $count], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));

    $app->get('/api/stats/active-loans-count', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $count = 0;
        if ($db) {
            $res = $db->query("SELECT COUNT(*) AS c FROM prestiti WHERE attivo = 1 AND stato IN ('in_corso','in_ritardo')");
            if ($res) {
                $count = (int) ($res->fetch_assoc()['c'] ?? 0);
            }
        }
        $response->getBody()->write(json_encode(['count' => $count], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));

    // Utenti (protected admin)
    $app->get('/admin/utenti', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\UsersController();
        $db = $app->getContainer()->get('db');
        return $controller->index($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->get('/admin/utenti/crea', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\UsersController();
        return $controller->createForm($request, $response);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/utenti/store', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\UsersController();
        $db = $app->getContainer()->get('db');
        return $controller->store($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->get('/admin/utenti/modifica/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\UsersController();
        $db = $app->getContainer()->get('db');
        return $controller->editForm($request, $response, $db, (int) $args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/utenti/update/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\UsersController();
        $db = $app->getContainer()->get('db');
        return $controller->update($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->get('/admin/utenti/dettagli/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\UsersController();
        $db = $app->getContainer()->get('db');
        return $controller->details($request, $response, $db, (int) $args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/utenti/delete/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\UsersController();
        $db = $app->getContainer()->get('db');
        return $controller->delete($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->post('/admin/utenti/{id:\d+}/approve-and-send-activation', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\UsersController();
        $db = $app->getContainer()->get('db');
        return $controller->approveAndSendActivation($request, $response, $db, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->post('/admin/utenti/{id:\d+}/activate-directly', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\UsersController();
        $db = $app->getContainer()->get('db');
        return $controller->activateDirectly($request, $response, $db, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // CSV Export route for users
    $app->get('/admin/utenti/export/csv', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\UsersController();
        $db = $app->getContainer()->get('db');
        return $controller->exportCsv($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(5, 60))->add(new AdminAuthMiddleware()); // 5 requests per minute

    // Autori (protected admin)
    $app->get('/admin/autori', function ($request, $response) use ($app) {
        $controller = new AutoriController();
        $db = $app->getContainer()->get('db');
        return $controller->index($request, $response, $db);
    })->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));
    $app->get('/admin/autori/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new AutoriController();
        $db = $app->getContainer()->get('db');
        return $controller->show($request, $response, $db, (int) $args['id']);
    })->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));
    $app->get('/admin/autori/crea', function ($request, $response) use ($app) {
        $controller = new AutoriController();
        return $controller->createForm($request, $response);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/autori/crea', function ($request, $response) use ($app) {
        $controller = new AutoriController();
        $db = $app->getContainer()->get('db');
        return $controller->store($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->get('/admin/autori/modifica/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new AutoriController();
        $db = $app->getContainer()->get('db');
        return $controller->editForm($request, $response, $db, (int) $args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/autori/update/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new AutoriController();
        $db = $app->getContainer()->get('db');
        return $controller->update($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    // Fallback GET to avoid 405 if user navigates directly
    $app->get('/admin/autori/update/{id:\d+}', function ($request, $response, $args) {
        return $response->withHeader('Location', '/admin/autori/modifica/' . (int) $args['id'])->withStatus(302);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/autori/delete/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new AutoriController();
        $db = $app->getContainer()->get('db');
        return $controller->delete($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Prestiti (protected admin) - Disabled in catalogue mode
    $app->get('/admin/prestiti', function ($request, $response) use ($app) {
        if (\App\Support\ConfigStore::isCatalogueMode()) {
            return $response->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->index($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    $app->get('/admin/prestiti/export-csv', function ($request, $response) use ($app) {
        if (\App\Support\ConfigStore::isCatalogueMode()) {
            return $response->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->exportCsv($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(5, 60))->add(new AdminAuthMiddleware()); // 5 exports per minute

    $app->get('/admin/prestiti/crea', function ($request, $response) {
        if (\App\Support\ConfigStore::isCatalogueMode()) {
            return $response->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }
        $controller = new PrestitiController();
        return $controller->createForm($request, $response);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware());

    $app->post('/admin/prestiti/crea', function ($request, $response) use ($app) {
        if (\App\Support\ConfigStore::isCatalogueMode()) {
            return $response->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->store($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->get('/admin/prestiti/modifica/{id:\d+}', function ($request, $response, $args) use ($app) {
        if (\App\Support\ConfigStore::isCatalogueMode()) {
            return $response->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->editForm($request, $response, $db, (int) $args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware());

    $app->post('/admin/prestiti/update/{id:\d+}', function ($request, $response, $args) use ($app) {
        if (\App\Support\ConfigStore::isCatalogueMode()) {
            return $response->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->update($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Libri (protected admin)
    $app->get('/admin/libri', function ($request, $response) use ($app) {
        $controller = new LibriController();
        $db = $app->getContainer()->get('db');
        return $controller->index($request, $response, $db);
    })->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));

    $app->get('/admin/libri/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new LibriController();
        $db = $app->getContainer()->get('db');
        return $controller->show($request, $response, $db, (int) $args['id']);
    })->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));

    $app->get('/admin/libri/crea', function ($request, $response) use ($app) {
        $controller = new LibriController();
        $db = $app->getContainer()->get('db');
        return $controller->createForm($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    $app->post('/admin/libri/crea', function ($request, $response) use ($app) {
        $controller = new LibriController();
        $db = $app->getContainer()->get('db');
        return $controller->store($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->get('/admin/libri/modifica/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new LibriController();
        $db = $app->getContainer()->get('db');
        return $controller->editForm($request, $response, $db, (int) $args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    $app->post('/admin/libri/update/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new LibriController();
        $db = $app->getContainer()->get('db');
        return $controller->update($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->get('/api/libri/{id:\d+}/etichetta-pdf', function ($request, $response, $args) use ($app) {
        $controller = new LibriController();
        $db = $app->getContainer()->get('db');
        return $controller->generateLabelPDF($request, $response, $db, (int) $args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    // Fetch cover for a book via scraping
    $app->post('/api/libri/{id:\d+}/fetch-cover', function ($request, $response, $args) use ($app) {
        $controller = new LibriController();
        $db = $app->getContainer()->get('db');
        return $controller->fetchCover($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new \App\Middleware\RateLimitMiddleware(30, 60))->add(new AdminAuthMiddleware()); // 30 requests per minute for bulk operations

    // CSV Import routes
    $app->get('/admin/libri/import', function ($request, $response) {
        $controller = new \App\Controllers\CsvImportController();
        return $controller->showImportPage($request, $response);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    $app->get('/admin/libri/import/example', function ($request, $response) {
        $controller = new \App\Controllers\CsvImportController();
        return $controller->downloadExample($request, $response);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    $app->post('/admin/libri/import/upload', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\CsvImportController();
        $db = $app->getContainer()->get('db');
        return $controller->processImport($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->get('/admin/libri/import/progress', function ($request, $response) {
        $controller = new \App\Controllers\CsvImportController();
        return $controller->getProgress($request, $response);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    // CSV Export route
    $app->get('/admin/libri/export/csv', function ($request, $response) use ($app) {
        $controller = new LibriController();
        $db = $app->getContainer()->get('db');
        return $controller->exportCsv($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(5, 60))->add(new AdminAuthMiddleware()); // 5 requests per minute

    // Sync covers route
    $app->post('/admin/libri/sync-covers', function ($request, $response) use ($app) {
        $controller = new LibriController();
        $db = $app->getContainer()->get('db');
        return $controller->syncCovers($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(1, 120))->add(new AdminAuthMiddleware()); // 1 request per 2 minutes (long-running operation)

    // Fallback GET to avoid 405 if user navigates directly
    $app->get('/admin/libri/update/{id:\d+}', function ($request, $response, $args) {
        return $response->withHeader('Location', '/admin/libri/modifica/' . (int) $args['id'])->withStatus(302);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    $app->post('/admin/libri/delete/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new LibriController();
        $db = $app->getContainer()->get('db');
        return $controller->delete($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Copy management routes
    $app->post('/admin/libri/copie/{id:\d+}/update', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\CopyController();
        $db = $app->getContainer()->get('db');
        return $controller->updateCopy($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/libri/copie/{id:\d+}/delete', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\CopyController();
        $db = $app->getContainer()->get('db');
        return $controller->deleteCopy($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Generi (protected admin/staff only)
    $app->get('/admin/generi', function ($request, $response) use ($app) {
        $controller = new GeneriController();
        $db = $app->getContainer()->get('db');
        return $controller->index($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    $app->get('/admin/generi/crea', function ($request, $response) use ($app) {
        $controller = new GeneriController();
        $db = $app->getContainer()->get('db');
        return $controller->createForm($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    $app->post('/admin/generi/crea', function ($request, $response) use ($app) {
        $controller = new GeneriController();
        $db = $app->getContainer()->get('db');
        return $controller->store($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->get('/admin/generi/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new GeneriController();
        $db = $app->getContainer()->get('db');
        return $controller->show($request, $response, $db, (int) $args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    // Collocazione (protected admin)
    $app->get('/admin/collocazione', function ($request, $response) use ($app) {
        $controller = new CollocazioneController();
        $db = $app->getContainer()->get('db');
        return $controller->index($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/collocazione/scaffali', function ($request, $response) use ($app) {
        $controller = new CollocazioneController();
        $db = $app->getContainer()->get('db');
        return $controller->createScaffale($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->post('/admin/collocazione/mensole', function ($request, $response) use ($app) {
        $controller = new CollocazioneController();
        $db = $app->getContainer()->get('db');
        return $controller->createMensola($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->post('/admin/collocazione/scaffali/{id}/delete', function ($request, $response, $args) use ($app) {
        $controller = new CollocazioneController();
        $db = $app->getContainer()->get('db');
        return $controller->deleteScaffale($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->post('/admin/collocazione/mensole/{id}/delete', function ($request, $response, $args) use ($app) {
        $controller = new CollocazioneController();
        $db = $app->getContainer()->get('db');
        return $controller->deleteMensola($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->get('/api/collocazione/suggerisci', function ($request, $response) use ($app) {
        $controller = new CollocazioneController();
        $db = $app->getContainer()->get('db');
        return $controller->suggest($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->get('/api/collocazione/next', function ($request, $response) use ($app) {
        $controller = new CollocazioneController();
        $db = $app->getContainer()->get('db');
        return $controller->nextPosition($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/api/collocazione/sort', function ($request, $response) use ($app) {
        $controller = new CollocazioneController();
        $db = $app->getContainer()->get('db');
        return $controller->sort($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->get('/api/collocazione/libri', function ($request, $response) use ($app) {
        $controller = new CollocazioneController();
        $db = $app->getContainer()->get('db');
        return $controller->getLibri($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->get('/api/collocazione/export-csv', function ($request, $response) use ($app) {
        $controller = new CollocazioneController();
        $db = $app->getContainer()->get('db');
        return $controller->exportCSV($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    // Prestiti (protected) - Disabled in catalogue mode
    $app->get('/prestiti', function ($request, $response) use ($app) {
        if (\App\Support\ConfigStore::isCatalogueMode()) {
            return $response->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->index($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->get('/prestiti/crea', function ($request, $response) use ($app) {
        if (\App\Support\ConfigStore::isCatalogueMode()) {
            return $response->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }
        $controller = new PrestitiController();
        return $controller->createForm($request, $response);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/prestiti/crea', function ($request, $response) use ($app) {
        if (\App\Support\ConfigStore::isCatalogueMode()) {
            return $response->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->store($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->get('/prestiti/modifica/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->editForm($request, $response, $db, (int) $args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/prestiti/update/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->update($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->post('/prestiti/close/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->close($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->get('/prestiti/restituito/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->returnForm($request, $response, $db, (int) $args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/prestiti/restituito/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->processReturn($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->get('/prestiti/dettagli/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->details($request, $response, $db, (int) $args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    // Loan approval routes - Disabled in catalogue mode
    $app->get('/admin/loans/pending', function ($request, $response) use ($app) {
        if (\App\Support\ConfigStore::isCatalogueMode()) {
            return $response->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }
        $controller = new LoanApprovalController();
        $db = $app->getContainer()->get('db');
        return $controller->pendingLoans($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    $app->post('/admin/loans/approve', function ($request, $response) use ($app) {
        if (\App\Support\ConfigStore::isCatalogueMode()) {
            return $response->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }
        $controller = new LoanApprovalController();
        $db = $app->getContainer()->get('db');
        return $controller->approveLoan($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/loans/reject', function ($request, $response) use ($app) {
        if (\App\Support\ConfigStore::isCatalogueMode()) {
            return $response->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }
        $controller = new LoanApprovalController();
        $db = $app->getContainer()->get('db');
        return $controller->rejectLoan($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Maintenance routes
    $app->get('/admin/maintenance/integrity-report', function ($request, $response) use ($app) {
        $controller = new MaintenanceController();
        $db = $app->getContainer()->get('db');
        return $controller->integrityReport($request, $response, $db);
    })->add(new AuthMiddleware(['admin']));

    $app->post('/admin/maintenance/fix-issues', function ($request, $response) use ($app) {
        $controller = new MaintenanceController();
        $db = $app->getContainer()->get('db');
        return $controller->fixIntegrityIssues($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin']));

    $app->post('/admin/maintenance/recalculate-availability', function ($request, $response) use ($app) {
        $controller = new MaintenanceController();
        $db = $app->getContainer()->get('db');
        return $controller->recalculateAvailability($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin']));

    $app->post('/admin/maintenance/perform', function ($request, $response) use ($app) {
        $controller = new MaintenanceController();
        $db = $app->getContainer()->get('db');
        return $controller->performMaintenance($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin']));

    $app->post('/admin/maintenance/apply-config-fix', function ($request, $response) {
        $controller = new MaintenanceController();
        return $controller->applyConfigFix($request, $response);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin']));

    // Index optimization routes
    $app->post('/admin/maintenance/create-indexes', function ($request, $response) use ($app) {
        $controller = new MaintenanceController();
        $db = $app->getContainer()->get('db');
        return $controller->createMissingIndexes($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin']));

    $app->get('/admin/maintenance/indexes-sql', function ($request, $response) use ($app) {
        $controller = new MaintenanceController();
        $db = $app->getContainer()->get('db');
        return $controller->generateIndexesSQL($request, $response, $db);
    })->add(new AuthMiddleware(['admin']));

    $app->get('/admin/prestiti/restituito/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->returnForm($request, $response, $db, (int) $args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/prestiti/restituito/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->processReturn($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->post('/admin/prestiti/rinnova/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->renew($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->get('/admin/prestiti/dettagli/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->details($request, $response, $db, (int) $args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    // API prestiti per DataTables
    $app->get('/api/prestiti', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\PrestitiApiController();
        $db = $app->getContainer()->get('db');
        return $controller->list($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    // API utenti per DataTables
    $app->get('/api/utenti', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\UtentiApiController();
        $db = $app->getContainer()->get('db');
        return $controller->list($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    // Prenotazioni (admin)
    $app->get('/admin/prenotazioni', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new ReservationsAdminController();
        return $controller->index($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->get('/admin/prenotazioni/crea', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new ReservationsAdminController();
        return $controller->createForm($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/prenotazioni/crea', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new ReservationsAdminController();
        return $controller->store($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->get('/admin/prenotazioni/modifica/{id:\\d+}', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new ReservationsAdminController();
        return $controller->editForm($request, $response, $db, (int) $args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/prenotazioni/update/{id:\\d+}', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new ReservationsAdminController();
        return $controller->update($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Book availability endpoint
    $app->get('/api/books/{id:\\d+}/availability', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $bookId = (int) $args['id'];
        $data = ['available' => false, 'copies_available' => 0, 'copies_total' => 0, 'next_due_date' => null, 'queue' => 0];
        // Copies info
        $stmt = $db->prepare("SELECT copie_disponibili, copie_totali FROM libri WHERE id = ?");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $data['copies_available'] = (int) ($row['copie_disponibili'] ?? 0);
            $data['copies_total'] = (int) ($row['copie_totali'] ?? 0);
        }
        $stmt->close();
        $data['available'] = ($data['copies_available'] > 0);
        // Next due date among active loans
        if (!$data['available']) {
            $stmt = $db->prepare("SELECT MIN(data_scadenza) AS next_due FROM prestiti WHERE libro_id = ? AND attivo = 1");
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $data['next_due_date'] = $row && !empty($row['next_due']) ? $row['next_due'] : null;
            $stmt->close();
        }
        // Queue size
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE libro_id = ? AND stato = 'attiva'");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $res = $stmt->get_result();
        $data['queue'] = (int) ($res->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));

    // Availability calendar (per-day availability for next N days)
    $app->get('/api/books/{id:\\d+}/availability-calendar', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $bookId = (int) $args['id'];
        $days = (int) (($request->getQueryParams()['days'] ?? 60));
        if ($days < 1)
            $days = 30;
        if ($days > 180)
            $days = 180;
        $controller = new \App\Controllers\ReservationsController($db);
        $availability = $controller->getBookAvailabilityData($bookId, date('Y-m-d'), $days);

        $response->getBody()->write(json_encode([
            'total_copies' => $availability['total_copies'] ?? 0,
            'days' => array_slice($availability['days'] ?? [], 0, $days)
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));

    // Debug endpoint intentionally removed to avoid accidental exposure of sensitive data

    // Editori (protected)
    $app->get('/admin/editori', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\EditorsController();
        $db = $app->getContainer()->get('db');
        return $controller->index($request, $response, $db);
    })->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));
    $app->get('/admin/editori/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\EditorsController();
        $db = $app->getContainer()->get('db');
        return $controller->show($request, $response, $db, (int) $args['id']);
    })->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));
    $app->get('/admin/editori/crea', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\EditorsController();
        return $controller->createForm($request, $response);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/editori/crea', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\EditorsController();
        $db = $app->getContainer()->get('db');
        return $controller->store($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->get('/admin/editori/modifica/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\EditorsController();
        $db = $app->getContainer()->get('db');
        return $controller->editForm($request, $response, $db, (int) $args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/editori/update/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\EditorsController();
        $db = $app->getContainer()->get('db');
        return $controller->update($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    // API Dewey (basata su JSON) - PROTETTO: Solo admin e staff
    $app->get('/api/dewey/categories', [DeweyApiController::class, 'getCategories'])->add(new AdminAuthMiddleware());
    $app->get('/api/dewey/divisions', [DeweyApiController::class, 'getDivisions'])->add(new AdminAuthMiddleware());
    $app->get('/api/dewey/specifics', [DeweyApiController::class, 'getSpecifics'])->add(new AdminAuthMiddleware());
    // Reseed endpoint (per compatibilità - ora non fa nulla) - PROTETTO: Solo admin
    $app->post('/api/dewey/reseed', [DeweyApiController::class, 'reseed'])->add(new AuthMiddleware(['admin']));
    $app->get('/api/dewey/counts', function ($request, $response) use ($app) {
        $controller = new DeweyController();
        $db = $app->getContainer()->get('db');
        return $controller->counts($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    // Admin Dewey page
    $app->get('/admin/dewey', function ($request, $response) use ($app) {
        $controller = new DeweyController();
        $db = $app->getContainer()->get('db');
        return $controller->adminPage($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/api/cover/download', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\CoverController();
        return $controller->download($request, $response);
    })->add(new CsrfMiddleware($app->getContainer()));
    $app->get('/api/libri', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\LibriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->list($request, $response, $db);
    });
    // API Autori (server-side DataTables)
    $app->get('/api/autori', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\AutoriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->list($request, $response, $db);
    });

    // API Autori - Bulk Delete
    $app->post('/api/autori/bulk-delete', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\AutoriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->bulkDelete($request, $response, $db);
    })->add(new CsrfMiddleware())->add(new AdminAuthMiddleware());

    // API Autori - Bulk Export
    $app->post('/api/autori/bulk-export', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\AutoriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->bulkExport($request, $response, $db);
    })->add(new CsrfMiddleware())->add(new AdminAuthMiddleware());

    // API Editori (server-side DataTables)
    $app->get('/api/editori', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\EditoriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->list($request, $response, $db);
    });

    // API Editori - Bulk Delete
    $app->post('/api/editori/bulk-delete', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\EditoriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->bulkDelete($request, $response, $db);
    })->add(new CsrfMiddleware())->add(new AdminAuthMiddleware());

    // API Editori - Bulk Export
    $app->post('/api/editori/bulk-export', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\EditoriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->bulkExport($request, $response, $db);
    })->add(new CsrfMiddleware())->add(new AdminAuthMiddleware());

    $app->get('/api/search/autori', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\SearchController();
        $db = $app->getContainer()->get('db');
        return $controller->authors($request, $response, $db);
    });
    $app->get('/api/search/editori', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\SearchController();
        $db = $app->getContainer()->get('db');
        return $controller->publishers($request, $response, $db);
    });
    $app->get('/api/search/utenti', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\SearchController();
        $db = $app->getContainer()->get('db');
        return $controller->users($request, $response, $db);
    });
    $app->get('/api/search/libri', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\SearchController();
        $db = $app->getContainer()->get('db');
        return $controller->books($request, $response, $db);
    });

    // API to get book availability dates for calendar display
    $app->get('/api/libri/{id}/disponibilita', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $libroId = (int)$args['id'];

        // Get all active loans/reservations for this book
        $stmt = $db->prepare("
            SELECT data_prestito, data_scadenza, stato
            FROM prestiti
            WHERE libro_id = ? AND attivo = 1 AND stato IN ('in_corso', 'prenotato', 'in_ritardo')
            ORDER BY data_prestito
        ");
        $stmt->bind_param('i', $libroId);
        $stmt->execute();
        $result = $stmt->get_result();

        $occupiedRanges = [];
        $today = date('Y-m-d');
        $firstAvailable = $today;

        while ($row = $result->fetch_assoc()) {
            $occupiedRanges[] = [
                'from' => $row['data_prestito'],
                'to' => $row['data_scadenza'],
                'stato' => $row['stato']
            ];

            // Calculate first available date (day after latest loan ends)
            if ($row['data_scadenza'] >= $today) {
                $nextDay = date('Y-m-d', strtotime($row['data_scadenza'] . ' +1 day'));
                if ($nextDay > $firstAvailable) {
                    $firstAvailable = $nextDay;
                }
            }
        }
        $stmt->close();

        // Get book info
        $bookStmt = $db->prepare("SELECT copie_disponibili, copie_totali FROM libri WHERE id = ?");
        $bookStmt->bind_param('i', $libroId);
        $bookStmt->execute();
        $book = $bookStmt->get_result()->fetch_assoc();
        $bookStmt->close();

        $data = [
            'libro_id' => $libroId,
            'copie_disponibili' => (int)($book['copie_disponibili'] ?? 0),
            'copie_totali' => (int)($book['copie_totali'] ?? 0),
            'occupied_ranges' => $occupiedRanges,
            'first_available' => $firstAvailable,
            'is_available_now' => (int)($book['copie_disponibili'] ?? 0) > 0
        ];

        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // API for frontend calendar availability (used by book-detail.php)
    $app->get('/api/libro/{id}/availability', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $libroId = (int)$args['id'];

        // Get actual number of copies from copie table (not from libri.copie_totali column)
        $countStmt = $db->prepare("SELECT COUNT(*) as total FROM copie WHERE libro_id = ?");
        $countStmt->bind_param('i', $libroId);
        $countStmt->execute();
        $countResult = $countStmt->get_result()->fetch_assoc();
        $copieTotali = max(1, (int)($countResult['total'] ?? 1));
        $countStmt->close();

        // Get all active loans for this book (including pendente without copia_id)
        // Include all states that occupy a copy or a slot
        $stmt = $db->prepare("
            SELECT p.copia_id, p.data_prestito, p.data_scadenza, p.stato
            FROM prestiti p
            WHERE p.libro_id = ? AND p.attivo = 1 AND p.stato IN ('in_corso', 'prenotato', 'in_ritardo', 'pendente')
            ORDER BY p.data_prestito
        ");
        $stmt->bind_param('i', $libroId);
        $stmt->execute();
        $result = $stmt->get_result();

        $loans = [];
        while ($row = $result->fetch_assoc()) {
            $loans[] = $row;
        }
        $stmt->close();

        // Also get active prenotazioni (from prenotazioni table) - include open-ended reservations
        $resStmt = $db->prepare("
            SELECT data_inizio_richiesta,
                   data_fine_richiesta,
                   data_scadenza_prenotazione,
                   'prenotazione' as tipo
            FROM prenotazioni
            WHERE libro_id = ? AND stato = 'attiva'
            AND data_inizio_richiesta IS NOT NULL
        ");
        $resStmt->bind_param('i', $libroId);
        $resStmt->execute();
        $resResult = $resStmt->get_result();

        $reservations = [];
        while ($row = $resResult->fetch_assoc()) {
            // Normalize end date: prefer data_fine_richiesta, then data_scadenza_prenotazione (date part), fallback to start
            $start = $row['data_inizio_richiesta'];
            $end = $row['data_fine_richiesta'] ?? null;
            if (!$end && !empty($row['data_scadenza_prenotazione'])) {
                $end = substr((string)$row['data_scadenza_prenotazione'], 0, 10);
            }
            if (!$end) {
                $end = $start;
            }
            $reservations[] = [
                'start' => $start,
                'end' => $end
            ];
        }
        $resStmt->close();

        // Generate day-by-day availability for next 180 days
        $today = date('Y-m-d');
        $days = [];
        $unavailableDates = [];
        $earliestAvailable = $today;
        $foundEarliest = false;

        for ($i = 0; $i < 180; $i++) {
            $date = date('Y-m-d', strtotime("+{$i} days"));

            // Count how many copies are occupied on this date
            $occupiedCount = 0;
            $hasOverdue = false;
            $hasReserved = false;

            // Count from loans (prestiti)
            foreach ($loans as $loan) {
                // Check if loan overlaps with this date
                if ($loan['data_prestito'] <= $date && $loan['data_scadenza'] >= $date) {
                    $occupiedCount++;
                    if ($loan['stato'] === 'in_ritardo') {
                        $hasOverdue = true;
                    } elseif ($loan['stato'] === 'prenotato' || $loan['stato'] === 'pendente') {
                        $hasReserved = true;
                    }
                }
            }

            // Count from active prenotazioni
            foreach ($reservations as $res) {
                if ($res['start'] <= $date && $res['end'] >= $date) {
                    $occupiedCount++;
                    $hasReserved = true;
                }
            }

            // Determine state for this date
            $availableCopies = $copieTotali - $occupiedCount;

            if ($availableCopies <= 0) {
                // All copies occupied
                if ($hasOverdue) {
                    $state = 'borrowed'; // Red - overdue loans
                } elseif ($hasReserved) {
                    $state = 'reserved'; // Yellow - reserved/pending
                } else {
                    $state = 'borrowed'; // Red - in corso
                }
                $unavailableDates[] = $date;
            } else {
                $state = 'free'; // Green - available
                if (!$foundEarliest) {
                    $earliestAvailable = $date;
                    $foundEarliest = true;
                }
            }

            $days[] = [
                'date' => $date,
                'state' => $state,
                'available_copies' => $availableCopies
            ];
        }

        $data = [
            'success' => true,
            'availability' => [
                'unavailable_dates' => $unavailableDates,
                'earliest_available' => $earliestAvailable,
                'days' => $days
            ]
        ];

        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $app->get('/api/search/collocazione', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\SearchController();
        $db = $app->getContainer()->get('db');
        return $controller->locations($request, $response, $db);
    });

    // API for Generi (Genres)
    $app->get('/api/search/generi', function ($request, $response) use ($app) {
        $controller = new GeneriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->search($request, $response, $db);
    });

    $app->get('/api/search/unified', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\SearchController();
        $db = $app->getContainer()->get('db');
        return $controller->unifiedSearch($request, $response, $db);
    });
    $app->get('/api/search/preview', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\SearchController();
        $db = $app->getContainer()->get('db');
        return $controller->searchPreview($request, $response, $db);
    });
    $app->post('/api/generi', function ($request, $response) use ($app) {
        $controller = new GeneriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->create($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->get('/api/generi', function ($request, $response) use ($app) {
        $controller = new GeneriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->listGeneri($request, $response, $db);
    });
    $app->get('/api/generi/sottogeneri', function ($request, $response) use ($app) {
        $controller = new GeneriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->getSottogeneri($request, $response, $db);
    });
    // Editor delete route

    // API per scraping dati libro tramite ISBN - PROTETTO: Solo admin e staff
    $app->get("/api/scrape/isbn", function ($request, $response) use ($app) {
        $controller = new \App\Controllers\ScrapeController();
        return $controller->byIsbn($request, $response);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute



    // Editori - Elimina
    $app->post('/admin/editori/delete/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\EditorsController();
        $db = $app->getContainer()->get('db');
        return $controller->delete($request, $response, $db, (int) $args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // API Libri by Author/Publisher
    $app->get('/api/libri/author/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\LibriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->getByAuthor($request, $response, $db, (int) $args['id']);
    })->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));

    $app->get('/api/libri/publisher/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\LibriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->getByPublisher($request, $response, $db, (int) $args['id']);
    })->add(new AuthMiddleware(['admin', 'staff', 'standard', 'premium']));

    // API Libri by Genre (public for homepage carousels)
    $app->get('/api/libri/genre/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\LibriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->byGenre($request->withQueryParams(array_merge(
            $request->getQueryParams(),
            ['genre_id' => (int) $args['id']]
        )), $response, $db);
    });

    // API Bulk operations (admin only)
    $app->post('/api/libri/bulk-status', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\LibriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->bulkStatus($request, $response, $db);
    })->add(new CsrfMiddleware())->add(new AdminAuthMiddleware());

    $app->post('/api/libri/bulk-delete', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\LibriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->bulkDelete($request, $response, $db);
    })->add(new CsrfMiddleware())->add(new AdminAuthMiddleware());

    // API Increase copies of a book (admin only)
    $app->post('/api/libri/{id:\d+}/increase-copies', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $bookId = (int) $args['id'];

        // Get request body
        $body = $request->getParsedBody();
        if (!$body) {
            $body = json_decode((string) $request->getBody(), true);
        }

        $copiesToAdd = (int) ($body['copies'] ?? 0);

        if ($copiesToAdd < 1) {
            $response->getBody()->write(json_encode([
                'error' => true,
                'message' => __('Numero di copie non valido.')
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Get current book data
        $stmt = $db->prepare('SELECT copie_totali, numero_inventario FROM libri WHERE id = ?');
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $book = $result->fetch_assoc();
        $stmt->close();

        if (!$book) {
            $response->getBody()->write(json_encode([
                'error' => true,
                'message' => __('Libro non trovato.')
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Calculate new total
        $currentCopieTotali = (int) $book['copie_totali'];
        $newCopieTotali = $currentCopieTotali + $copiesToAdd;

        // Update copie_totali counter in libri table
        $stmt = $db->prepare('UPDATE libri SET copie_totali = ? WHERE id = ?');
        $stmt->bind_param('ii', $newCopieTotali, $bookId);
        $stmt->execute();
        $stmt->close();

        // Create physical copies in copie table
        $copyRepo = new \App\Models\CopyRepository($db);
        $baseInventario = !empty($book['numero_inventario'])
            ? $book['numero_inventario']
            : "LIB-{$bookId}";

        // Start from current total + 1 for new copies
        for ($i = 1; $i <= $copiesToAdd; $i++) {
            $copyNumber = $currentCopieTotali + $i;
            $numeroInventario = $newCopieTotali > 1
                ? "{$baseInventario}-C{$copyNumber}"
                : $baseInventario;

            $note = "Copia {$copyNumber} di {$newCopieTotali}";
            $copyRepo->create($bookId, $numeroInventario, 'disponibile', $note);
        }

        // Recalculate availability using DataIntegrity
        $integrity = new \App\Support\DataIntegrity($db);
        $integrity->recalculateBookAvailability($bookId);

        // Get updated availability
        $stmt = $db->prepare('SELECT copie_disponibili FROM libri WHERE id = ?');
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $updatedBook = $result->fetch_assoc();
        $stmt->close();

        $response->getBody()->write(json_encode([
            'success' => true,
            'copie_totali' => $newCopieTotali,
            'copie_disponibili' => (int) $updatedBook['copie_disponibili'],
            'added' => $copiesToAdd
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin']));

    // Frontend routes (public)
    $app->get('/home.php', function ($request, $response) use ($app) {
        $container = $app->getContainer();
        $controller = new \App\Controllers\FrontendController($container);
        $db = $container->get('db');
        return $controller->home($request, $response, $db, $container);
    });

    // Legacy redirect for backward compatibility (all language variants)
    foreach ($supportedLocales as $locale) {
        $registerRouteIfUnique('GET', RouteTranslator::getRouteForLocale('catalog_legacy', $locale), function ($request, $response) use ($app) {
            return $response->withHeader('Location', RouteTranslator::route('catalog'))->withStatus(301);
        });
    }

    // Catalog page (all language variants)
    foreach ($supportedLocales as $locale) {
        $registerRouteIfUnique('GET', RouteTranslator::getRouteForLocale('catalog', $locale), function ($request, $response) use ($app) {
            $container = $app->getContainer();
            $controller = new \App\Controllers\FrontendController($container);
            $db = $container->get('db');
            return $controller->catalog($request, $response, $db);
        });
    }

    // Legacy book detail redirect (all language variants)
    foreach ($supportedLocales as $locale) {
        $registerRouteIfUnique('GET', RouteTranslator::getRouteForLocale('book_legacy', $locale), function ($request, $response) use ($app) {
            $container = $app->getContainer();
            $controller = new \App\Controllers\FrontendController($container);
            $db = $container->get('db');
            return $controller->bookDetail($request, $response, $db);
        });
    }

    // Old /libro/{id}/{slug} variants remain available and redirect inside the controller
    foreach ($supportedLocales as $locale) {
        $bookRoute = RouteTranslator::getRouteForLocale('book', $locale);

        // Route without slug
        $registerRouteIfUnique('GET', $bookRoute . '/{id:\d+}', function ($request, $response, $args) use ($app) {
            $container = $app->getContainer();
            $controller = new \App\Controllers\FrontendController($container);
            $db = $container->get('db');
            return $controller->bookDetailSEO($request, $response, $db, (int) $args['id'], '');
        });

        // Route with slug
        $registerRouteIfUnique('GET', $bookRoute . '/{id:\d+}/{slug}', function ($request, $response, $args) use ($app) {
            $container = $app->getContainer();
            $controller = new \App\Controllers\FrontendController($container);
            $db = $container->get('db');
            return $controller->bookDetailSEO($request, $response, $db, (int) $args['id'], $args['slug']);
        });
    }

    // Canonical SEO route: /{author-slug}/{book-slug}/{id}
    $registerRouteIfUnique('GET', '/{authorSlug}/{bookSlug}/{id:\d+}', function ($request, $response, $args) use ($app) {
        $container = $app->getContainer();
        $controller = new \App\Controllers\FrontendController($container);
        $db = $container->get('db');
        return $controller->bookDetailSEO($request, $response, $db, (int) $args['id'], $args['bookSlug']);
    });

    // API endpoints for book reservations (all language variants)
    foreach ($supportedLocales as $locale) {
        $registerRouteIfUnique('GET', RouteTranslator::getRouteForLocale('api_book', $locale) . '/{id:\d+}/availability', function ($request, $response, $args) use ($app) {
            $db = $app->getContainer()->get('db');
            $controller = new \App\Controllers\ReservationsController($db);
            return $controller->getBookAvailability($request, $response, $args);
        });

        $registerRouteIfUnique('POST', RouteTranslator::getRouteForLocale('api_book', $locale) . '/{id:\d+}/reservation', function ($request, $response, $args) use ($app) {
            $db = $app->getContainer()->get('db');
            $controller = new \App\Controllers\ReservationsController($db);
            return $controller->createReservation($request, $response, $args);
        }, [new AuthMiddleware(['admin', 'staff', 'standard', 'premium']), new CsrfMiddleware($app->getContainer())]);
    }

    // API endpoint for AJAX catalog filtering (all language variants)
    foreach ($supportedLocales as $locale) {
        $registerRouteIfUnique('GET', RouteTranslator::getRouteForLocale('api_catalog', $locale), function ($request, $response) use ($app) {
            $container = $app->getContainer();
            $controller = new \App\Controllers\FrontendController($container);
            $db = $container->get('db');
            return $controller->catalogAPI($request, $response, $db);
        });
    }

    // API endpoint for home page sections (all language variants)
    foreach ($supportedLocales as $locale) {
        $registerRouteIfUnique('GET', RouteTranslator::getRouteForLocale('api_home', $locale) . '/{section}', function ($request, $response, $args) use ($app) {
            $container = $app->getContainer();
            $controller = new \App\Controllers\FrontendController($container);
            $db = $container->get('db');
            return $controller->homeAPI($request, $response, $db, $args['section']);
        });
    }

    // Author archive page by ID (all language variants)
    foreach ($supportedLocales as $locale) {
        $registerRouteIfUnique('GET', RouteTranslator::getRouteForLocale('author', $locale) . '/{id:\d+}', function ($request, $response, $args) use ($app) {
            $container = $app->getContainer();
            $controller = new \App\Controllers\FrontendController($container);
            $db = $container->get('db');
            return $controller->authorArchiveById($request, $response, $db, (int) $args['id']);
        });
    }

    // Author archive page by name (all language variants)
    foreach ($supportedLocales as $locale) {
        $registerRouteIfUnique('GET', RouteTranslator::getRouteForLocale('author', $locale) . '/{name}', function ($request, $response, $args) use ($app) {
            $container = $app->getContainer();
            $controller = new \App\Controllers\FrontendController($container);
            $db = $container->get('db');
            return $controller->authorArchive($request, $response, $db, $args['name']);
        });
    }

    // Publisher archive page (all language variants)
    foreach ($supportedLocales as $locale) {
        $registerRouteIfUnique('GET', RouteTranslator::getRouteForLocale('publisher', $locale) . '/{name}', function ($request, $response, $args) use ($app) {
            $container = $app->getContainer();
            $controller = new \App\Controllers\FrontendController($container);
            $db = $container->get('db');
            return $controller->publisherArchive($request, $response, $db, $args['name']);
        });
    }

    // Genre archive page (all language variants)
    foreach ($supportedLocales as $locale) {
        $registerRouteIfUnique('GET', RouteTranslator::getRouteForLocale('genre', $locale) . '/{name}', function ($request, $response, $args) use ($app) {
            $container = $app->getContainer();
            $controller = new \App\Controllers\FrontendController($container);
            $db = $container->get('db');
            return $controller->genreArchive($request, $response, $db, $args['name']);
        });
    }

    // CMS pages (Chi Siamo, etc.) (multi-language variants)
    foreach ($supportedLocales as $locale) {
        $aboutRoute = RouteTranslator::getRouteForLocale('about', $locale);

        $registerRouteIfUnique('GET', $aboutRoute, function ($request, $response, $args) use ($app) {
            $db = $app->getContainer()->get('db');
            $controller = new \App\Controllers\CmsController();
            return $controller->showPage($request, $response, $db, ['slug' => 'about-us']);
        });
    }

    // Contact page (multi-language variants)
    foreach ($supportedLocales as $locale) {
        $contactRoute = RouteTranslator::getRouteForLocale('contact', $locale);

        $registerRouteIfUnique('GET', $contactRoute, function ($request, $response) use ($app) {
            $controller = new \App\Controllers\ContactController();
            return $controller->showPage($request, $response);
        });

        $contactSubmitRoute = RouteTranslator::getRouteForLocale('contact_submit', $locale);
        $registerRouteIfUnique('POST', $contactSubmitRoute, function ($request, $response) use ($app) {
            $db = $app->getContainer()->get('db');
            $controller = new \App\Controllers\ContactController();
            return $controller->submitForm($request, $response, $db);
        }, [new CsrfMiddleware($app->getContainer())]);
    }

    // Privacy Policy page (multi-language variants)
    foreach ($supportedLocales as $locale) {
        $privacyRoute = RouteTranslator::getRouteForLocale('privacy', $locale);

        $registerRouteIfUnique('GET', $privacyRoute, function ($request, $response) use ($app) {
            $container = $app->getContainer();
            $controller = new \App\Controllers\PrivacyController();
            return $controller->showPage($request, $response, $container);
        });
    }

    // Cookie Policy page (multi-language variants)
    foreach ($supportedLocales as $locale) {
        $cookiesRoute = RouteTranslator::getRouteForLocale('cookies', $locale);

        $registerRouteIfUnique('GET', $cookiesRoute, function ($request, $response) use ($app) {
            $container = $app->getContainer();
            $controller = new \App\Controllers\CookiesController();
            return $controller->showPage($request, $response, $container);
        });
    }

    // Cover proxy endpoint for previews (bypasses CORS)
    $app->get('/proxy/cover', function ($request, $response) use ($app) {
        $url = $request->getQueryParams()['url'] ?? '';
        if (!$url) {
            return $response->withStatus(400);
        }

        // Validate URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $response->withStatus(400);
        }

        // Parse URL components
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            return $response->withStatus(400);
        }

        // Enforce HTTPS only
        if (strtolower($parts['scheme']) !== 'https') {
            return $response->withStatus(403);
        }

        // Domain whitelist validation
        $allowedDomains = [
            'img.libreriauniversitaria.it',
            'img2.libreriauniversitaria.it',
            'img3.libreriauniversitaria.it',
            'covers.openlibrary.org',
            'images.amazon.com',
            'images-na.ssl-images-amazon.com',
            'www.lafeltrinelli.it',
            'books.google.com',
            'books.googleusercontent.com'
        ];

        $host = strtolower($parts['host']);
        if (!in_array($host, $allowedDomains, true)) {
            return $response->withStatus(403);
        }

        // DNS resolution check - ensure domain resolves to public IPs only
        $ips = gethostbynamel($host) ?: [];
        $aaaaRecords = @dns_get_record($host, DNS_AAAA) ?: [];

        if (!$ips && !$aaaaRecords) {
            return $response->withStatus(403);
        }

        // Validate all resolved IPs are public
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return $response->withStatus(403);
            }
        }

        foreach ($aaaaRecords as $record) {
            $ipv6 = $record['ipv6'] ?? null;
            if ($ipv6 && filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return $response->withStatus(403);
            }
        }

        // Use cURL with secure settings (no automatic redirect following)
        // Use standard browser User-Agent for Google to avoid blocking
        $userAgent = (strpos($host, 'google') !== false)
            ? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            : 'BibliotecaBot/1.0';

        // Prepare headers for Google Books (they may require browser-like headers)
        $headers = [];
        if (strpos($host, 'google') !== false) {
            $headers = [
                'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'Accept-Language: it-IT,it;q=0.9,en-US;q=0.8,en;q=0.7',
                'Referer: https://books.google.com/',
                'Sec-Fetch-Dest: image',
                'Sec-Fetch-Mode: no-cors',
                'Sec-Fetch-Site: same-origin'
            ];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_MAXREDIRS => 0
        ]);

        $img = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($img === false || $httpCode !== 200) {
            return $response->withStatus(404);
        }

        // Validate it's actually an image
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($img);

        // Only allow image MIME types
        if (!str_starts_with($mimeType, 'image/')) {
            return $response->withStatus(403);
        }

        $response->getBody()->write($img);
        return $response->withHeader('Content-Type', $mimeType)
            ->withHeader('Cache-Control', 'public, max-age=3600');
    });

    // Plugin image proxy endpoint - More permissive for plugin extensibility
    // Allows any HTTPS domain but protects against SSRF attacks
    $app->get('/api/plugins/proxy-image', function ($request, $response) use ($app) {
        $url = $request->getQueryParams()['url'] ?? '';
        if (!$url) {
            return $response->withStatus(400);
        }

        // Validate URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $response->withStatus(400);
        }

        // Parse URL components
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            return $response->withStatus(400);
        }

        // Enforce HTTPS only (no HTTP, file://, ftp://, etc.)
        if (strtolower($parts['scheme']) !== 'https') {
            return $response->withStatus(403);
        }

        $host = strtolower($parts['host']);

        // Block localhost and loopback addresses
        $blockedHosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
        if (in_array($host, $blockedHosts, true)) {
            return $response->withStatus(403);
        }

        // Block common private network patterns
        $privatePatterns = [
            '/^192\.168\./',
            '/^10\./',
            '/^172\.(1[6-9]|2[0-9]|3[0-1])\./',
            '/^169\.254\./',  // Link-local
            '/^fe80:/i',      // IPv6 link-local
            '/^fc00:/i',      // IPv6 private
            '/^fd00:/i',      // IPv6 private
        ];

        foreach ($privatePatterns as $pattern) {
            if (preg_match($pattern, $host)) {
                return $response->withStatus(403);
            }
        }

        // DNS resolution check - ensure domain resolves to public IPs only
        $ips = @gethostbynamel($host) ?: [];
        $aaaaRecords = @dns_get_record($host, DNS_AAAA) ?: [];

        if (!$ips && !$aaaaRecords) {
            return $response->withStatus(403);
        }

        // Validate all resolved IPv4 addresses are public
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return $response->withStatus(403);
            }
        }

        // Validate all resolved IPv6 addresses are public
        foreach ($aaaaRecords as $record) {
            $ipv6 = $record['ipv6'] ?? null;
            if ($ipv6 && filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return $response->withStatus(403);
            }
        }

        // Fetch image with secure settings
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BibliotecaBot/1.0) Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8'
            ],
        ]);

        $img = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($img === false || $httpCode !== 200) {
            return $response->withStatus(404);
        }

        // Re-validate final URL after redirects to prevent SSRF via redirect
        if ($finalUrl && $finalUrl !== $url) {
            $finalParts = parse_url($finalUrl);
            $finalHost = strtolower($finalParts['host'] ?? '');

            // Check if redirect went to private network
            if (in_array($finalHost, $blockedHosts, true)) {
                return $response->withStatus(403);
            }

            foreach ($privatePatterns as $pattern) {
                if (preg_match($pattern, $finalHost)) {
                    return $response->withStatus(403);
                }
            }
        }

        // Validate it's actually an image
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($img);

        // Only allow image MIME types
        if (!str_starts_with($mimeType, 'image/')) {
            return $response->withStatus(403);
        }

        $response->getBody()->write($img);
        return $response->withHeader('Content-Type', $mimeType)
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withHeader('X-Proxy-Type', 'plugin');
    });

    // Serve uploaded files from storage directory
    $app->get('/uploads/storage/{filepath:.*}', function ($request, $response, $args) {
        $rawPath = (string) ($args['filepath'] ?? '');
        if ($rawPath === '') {
            return $response->withStatus(404);
        }

        // Normalize and validate the requested path
        $sanitizedPath = str_replace(["\0", "\r", "\n"], '', $rawPath);
        $sanitizedPath = str_replace('\\', '/', $sanitizedPath);
        $sanitizedPath = ltrim($sanitizedPath, '/');

        if ($sanitizedPath === '' || preg_match('#(^|/)\.\.(?:/|$)#', $sanitizedPath)) {
            return $response->withStatus(403);
        }

        $basePath = realpath(__DIR__ . '/../../storage/uploads');
        if ($basePath === false) {
            return $response->withStatus(500);
        }

        $fullPath = $basePath . '/' . $sanitizedPath;

        // Ensure file exists and resolve real path for final security check
        if (!file_exists($fullPath) || !is_file($fullPath)) {
            return $response->withStatus(404);
        }

        $realFullPath = realpath($fullPath);
        if ($realFullPath === false || !str_starts_with($realFullPath, $basePath)) {
            return $response->withStatus(403);
        }

        // Security: Check file extension
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        $extension = strtolower(pathinfo($realFullPath, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            return $response->withStatus(403);
        }

        // Serve file
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($realFullPath);
        $fileContent = file_get_contents($realFullPath);

        if ($fileContent === false) {
            return $response->withStatus(500);
        }

        $response->getBody()->write($fileContent);
        return $response->withHeader('Content-Type', $mimeType)
            ->withHeader('Cache-Control', 'public, max-age=3600');
    });

    // Serve ICS calendar file for external calendar sync
    $app->get('/storage/calendar/library-calendar.ics', function ($request, $response) use ($app) {
        $icsPath = __DIR__ . '/../../storage/calendar/library-calendar.ics';

        // Generate ICS if it doesn't exist
        if (!file_exists($icsPath)) {
            try {
                $db = $app->getContainer()->get('db');
                $maintenanceService = new \App\Support\MaintenanceService($db);
                $maintenanceService->generateIcsCalendar();
            } catch (\Throwable $e) {
                error_log('ICS generation error: ' . $e->getMessage());
            }
        }

        // Check if file exists now
        if (!file_exists($icsPath) || !is_file($icsPath)) {
            $response->getBody()->write(__('Calendario non disponibile'));
            return $response->withStatus(404);
        }

        $content = file_get_contents($icsPath);
        if ($content === false) {
            $response->getBody()->write(__('Errore lettura calendario'));
            return $response->withStatus(500);
        }

        $response->getBody()->write($content);
        return $response
            ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="library-calendar.ics"')
            ->withHeader('Cache-Control', 'no-cache, must-revalidate');
    });

    // Public API endpoint for book search (protected by API key)
    $app->get('/api/public/books/search', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\PublicApiController();
        return $controller->searchBooks($request, $response, $db);
    })->add(new \App\Middleware\ApiKeyMiddleware($app->getContainer()->get('db')));

    // ==========================================
    // Plugin Management Routes (Admin Only)
    // ==========================================

    // Plugin list page
    $app->get('/admin/plugins', function ($request, $response) use ($app) {
        $pluginManager = $app->getContainer()->get('pluginManager');
        $controller = new \App\Controllers\PluginController($pluginManager);
        return $controller->index($request, $response);
    })->add(new AdminAuthMiddleware());

    // Plugin upload/install
    $app->post('/admin/plugins/upload', function ($request, $response) use ($app) {
        $pluginManager = $app->getContainer()->get('pluginManager');
        $controller = new \App\Controllers\PluginController($pluginManager);
        return $controller->upload($request, $response);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Plugin activate
    $app->post('/admin/plugins/{id}/activate', function ($request, $response, $args) use ($app) {
        $pluginManager = $app->getContainer()->get('pluginManager');
        $controller = new \App\Controllers\PluginController($pluginManager);
        return $controller->activate($request, $response, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Plugin deactivate
    $app->post('/admin/plugins/{id}/deactivate', function ($request, $response, $args) use ($app) {
        $pluginManager = $app->getContainer()->get('pluginManager');
        $controller = new \App\Controllers\PluginController($pluginManager);
        return $controller->deactivate($request, $response, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Plugin uninstall
    $app->post('/admin/plugins/{id}/uninstall', function ($request, $response, $args) use ($app) {
        $pluginManager = $app->getContainer()->get('pluginManager');
        $controller = new \App\Controllers\PluginController($pluginManager);
        return $controller->uninstall($request, $response, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Plugin settings update
    $app->post('/admin/plugins/{id}/settings', function ($request, $response, $args) use ($app) {
        $pluginManager = $app->getContainer()->get('pluginManager');
        $controller = new \App\Controllers\PluginController($pluginManager);
        return $controller->updateSettings($request, $response, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // ==========================================
    // Theme Management Routes (Admin Only)
    // ==========================================

    $app->get('/admin/themes', function ($request, $response) use ($app) {
        $themeManager = $app->getContainer()->get('themeManager');
        $themeColorizer = $app->getContainer()->get('themeColorizer');
        $controller = new \App\Controllers\ThemeController($themeManager, $themeColorizer);
        return $controller->index($request, $response);
    })->add(new AdminAuthMiddleware());

    $app->get('/admin/themes/{id}/customize', function ($request, $response, $args) use ($app) {
        $themeManager = $app->getContainer()->get('themeManager');
        $themeColorizer = $app->getContainer()->get('themeColorizer');
        $controller = new \App\Controllers\ThemeController($themeManager, $themeColorizer);
        return $controller->customize($request, $response, $args);
    })->add(new AdminAuthMiddleware());

    $app->post('/admin/themes/{id}/save', function ($request, $response, $args) use ($app) {
        $themeManager = $app->getContainer()->get('themeManager');
        $themeColorizer = $app->getContainer()->get('themeColorizer');
        $controller = new \App\Controllers\ThemeController($themeManager, $themeColorizer);
        return $controller->save($request, $response, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/themes/{id}/activate', function ($request, $response, $args) use ($app) {
        $themeManager = $app->getContainer()->get('themeManager');
        $themeColorizer = $app->getContainer()->get('themeColorizer');
        $controller = new \App\Controllers\ThemeController($themeManager, $themeColorizer);
        return $controller->activate($request, $response, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/themes/{id}/reset', function ($request, $response, $args) use ($app) {
        $themeManager = $app->getContainer()->get('themeManager');
        $themeColorizer = $app->getContainer()->get('themeColorizer');
        $controller = new \App\Controllers\ThemeController($themeManager, $themeColorizer);
        return $controller->reset($request, $response, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/themes/check-contrast', function ($request, $response) use ($app) {
        $themeManager = $app->getContainer()->get('themeManager');
        $themeColorizer = $app->getContainer()->get('themeColorizer');
        $controller = new \App\Controllers\ThemeController($themeManager, $themeColorizer);
        return $controller->checkContrast($request, $response);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

};
