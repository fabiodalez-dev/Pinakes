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

return function (App $app): void {
    $app->get('/', function ($request, $response) use ($app) {
        // Redirect to frontend home page
        $controller = new \App\Controllers\FrontendController();
        $db = $app->getContainer()->get('db');
        return $controller->home($request, $response, $db);
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
                return $response->withHeader('Location', '/user/dashboard')->withStatus(302);
            }
        }
        return $response->withHeader('Location', '/login')->withStatus(302);
    });

    // User Dashboard (separate from admin)
    $app->get('/user/dashboard', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\UserDashboardController();
        return $controller->index($request, $response, $db);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));

    // Auth routes
    $app->get('/login', function ($request, $response) use ($app) {
        // If already logged in, redirect appropriately
        if (!empty($_SESSION['user'])) {
            $userRole = $_SESSION['user']['tipo_utente'] ?? '';
            if ($userRole === 'admin' || $userRole === 'staff') {
                return $response->withHeader('Location', '/admin/dashboard')->withStatus(302);
            } else {
                return $response->withHeader('Location', '/user/dashboard')->withStatus(302);
            }
        }
        $controller = new AuthController();
        return $controller->loginForm($request, $response);
    });

    $app->post('/login', function ($request, $response) use ($app) {
        $controller = new AuthController();
        return $controller->login($request, $response, $app->getContainer()->get('db'));
    })->add(new \App\Middleware\RateLimitMiddleware(5, 300)); // 5 attempts per 5 minutes

    $app->get('/logout', function ($request, $response) use ($app) {
        $controller = new AuthController();
        return $controller->logout($request, $response);
    });

    // User profile
    $app->get('/profilo', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new ProfileController();
        return $controller->show($request, $response, $db);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));

    $app->post('/profilo/update', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new ProfileController();
        return $controller->update($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin','staff','standard','premium']));

    // Redirect GET to /profilo/update to /profilo
    $app->get('/profilo/update', function ($request, $response) {
        return $response->withHeader('Location', '/profilo')->withStatus(302);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));

    $app->post('/profilo/password', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new ProfileController();
        return $controller->changePassword($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin','staff','standard','premium']));

    // Redirect GET to /profilo/password to /profilo
    $app->get('/profilo/password', function ($request, $response) {
        return $response->withHeader('Location', '/profilo')->withStatus(302);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));

    // User wishlist
    $app->get('/wishlist', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new UserWishlistController();
        return $controller->page($request, $response, $db);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));

    // User reservations (with loan history and reviews)
    $app->get('/prenotazioni', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new UserDashboardController();
        return $controller->prenotazioni($request, $response, $db);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));

    // Public registration routes
    $app->get('/register', function ($request, $response) use ($app) {
        $controller = new RegistrationController();
        return $controller->form($request, $response);
    });
    $app->post('/register', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new RegistrationController();
        return $controller->register($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(3, 3600)); // 3 attempts per hour
    $app->get('/register/success', function ($request, $response) use ($app) {
        $controller = new RegistrationController();
        return $controller->success($request, $response);
    });
    $app->get('/verify-email', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new RegistrationController();
        return $controller->verifyEmail($request, $response, $db);
    });

    // Password reset public
    $app->get('/forgot-password', function ($request, $response) use ($app) {
        $controller = new PasswordController();
        return $controller->forgotForm($request, $response);
    });
    $app->post('/forgot-password', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new PasswordController();
        return $controller->forgot($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(3, 900)); // 3 attempts per 15 minutes
    $app->get('/reset-password', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new PasswordController();
        return $controller->resetForm($request, $response, $db);
    });
    $app->post('/reset-password', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new PasswordController();
        return $controller->reset($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(5, 300)); // 5 attempts per 5 minutes

    // Profile (authenticated)
    $app->get('/profile', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new ProfileController();
        return $controller->show($request, $response, $db);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));
    $app->post('/profile/password', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new ProfileController();
        return $controller->changePassword($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin','staff','standard','premium']));

    // Frontend user actions: loan/reserve
    $app->post('/user/loan', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new UserActionsController();
        return $controller->loan($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin','staff','standard','premium']));
    $app->post('/user/reserve', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new UserActionsController();
        return $controller->reserve($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin','staff','standard','premium']));

    // Rotte frontend per gestire le prenotazioni dell'utente (slug '/prenotazioni')

    // Redirect old URL for compatibility
    $app->get('/my-reservations', function ($request, $response) {
        return $response->withHeader('Location', '/prenotazioni')->withStatus(301);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));
    $app->post('/reservation/cancel', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new UserActionsController();
        return $controller->cancelReservation($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin','staff','standard','premium']));
    $app->post('/reservation/change-date', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new UserActionsController();
        return $controller->changeReservationDate($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin','staff','standard','premium']));

    // User reservations count (for badge) - protected
    $app->get('/api/user/reservations/count', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new UserActionsController();
        return $controller->reservationsCount($request, $response, $db);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));

    // Wishlist API (AJAX)
    $app->get('/api/user/wishlist/status', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new UserWishlistController();
        return $controller->status($request, $response, $db);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));
    $app->post('/api/user/wishlist/toggle', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new UserWishlistController();
        return $controller->toggle($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin','staff','standard','premium']));

    // Rotte dedicate alla gestione della wishlist utente

    // ========== RECENSIONI (Reviews System) ==========
    // User review routes
    $app->get('/api/user/can-review/{libro_id:\d+}', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\RecensioniController();
        return $controller->canReview($request, $response, $db, $args);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));

    $app->post('/api/user/recensioni', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\RecensioniController();
        return $controller->create($request, $response, $db);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));

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
        $template = (string)($args['template'] ?? '');
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
        return $controller->toggleApiKey($request, $response, $db, (int)$args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/settings/api/keys/{id:\d+}/delete', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new SettingsController();
        return $controller->deleteApiKey($request, $response, $db, (int)$args['id']);
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
    })->add(new AdminAuthMiddleware());

    $app->post('/admin/messages/{id:\d+}/archive', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\MessagesController($db);
        return $controller->archive($request, $response, $args);
    })->add(new AdminAuthMiddleware());

    $app->post('/admin/messages/mark-all-read', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\MessagesController($db);
        return $controller->markAllRead($request, $response);
    })->add(new AdminAuthMiddleware());

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
    })->add(new AdminAuthMiddleware());

    $app->post('/admin/notifications/mark-all-read', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\NotificationsController($db);
        return $controller->markAllAsRead($request, $response);
    })->add(new AdminAuthMiddleware());

    $app->delete('/admin/notifications/{id:\d+}', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\Admin\NotificationsController($db);
        return $controller->delete($request, $response, $args);
    })->add(new AdminAuthMiddleware());

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

    // Admin CMS routes - Other pages
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
        $count = (int)($res->fetch_assoc()['c'] ?? 0);
        $payload = json_encode(['count' => $count]);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->get('/api/admin/pending-registrations', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $res = $db->query("SELECT id, nome, cognome, email, created_at FROM utenti WHERE stato='sospeso' AND email_verificata=1 ORDER BY created_at DESC LIMIT 10");
        $rows = [];
        while ($row = $res->fetch_assoc()) { $rows[] = $row; }
        $response->getBody()->write(json_encode($rows));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    // Dashboard (protected admin)
    $app->get('/admin/dashboard', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new DashboardController();
        return $controller->index($request, $response, $db);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));

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
            if ($res) { $count = (int)($res->fetch_assoc()['c'] ?? 0); }
        }
        $response->getBody()->write(json_encode(['count' => $count], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));

    $app->get('/api/stats/active-loans-count', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $count = 0;
        if ($db) {
            $res = $db->query("SELECT COUNT(*) AS c FROM prestiti WHERE attivo = 1 AND stato IN ('in_corso','in_ritardo')");
            if ($res) { $count = (int)($res->fetch_assoc()['c'] ?? 0); }
        }
        $response->getBody()->write(json_encode(['count' => $count], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));

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
        return $controller->editForm($request, $response, $db, (int)$args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/utenti/update/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\UsersController();
        $db = $app->getContainer()->get('db');
        return $controller->update($request, $response, $db, (int)$args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->get('/admin/utenti/dettagli/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\UsersController();
        $db = $app->getContainer()->get('db');
        return $controller->details($request, $response, $db, (int)$args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/utenti/delete/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\UsersController();
        $db = $app->getContainer()->get('db');
        return $controller->delete($request, $response, $db, (int)$args['id']);
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
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));
    $app->get('/admin/autori/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new AutoriController();
        $db = $app->getContainer()->get('db');
        return $controller->show($request, $response, $db, (int)$args['id']);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));
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
        return $controller->editForm($request, $response, $db, (int)$args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/autori/update/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new AutoriController();
        $db = $app->getContainer()->get('db');
        return $controller->update($request, $response, $db, (int)$args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    // Fallback GET to avoid 405 if user navigates directly
    $app->get('/admin/autori/update/{id:\d+}', function ($request, $response, $args) {
        return $response->withHeader('Location', '/admin/autori/modifica/' . (int)$args['id'])->withStatus(302);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/autori/delete/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new AutoriController();
        $db = $app->getContainer()->get('db');
        return $controller->delete($request, $response, $db, (int)$args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Prestiti (protected admin)
    $app->get('/admin/prestiti', function ($request, $response) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->index($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    $app->get('/admin/prestiti/crea', function ($request, $response) {
        $controller = new PrestitiController();
        return $controller->createForm($request, $response);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware());

    $app->post('/admin/prestiti/crea', function ($request, $response) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->store($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->get('/admin/prestiti/modifica/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->editForm($request, $response, $db, (int)$args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware());

    $app->post('/admin/prestiti/update/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->update($request, $response, $db, (int)$args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Libri (protected admin)
    $app->get('/admin/libri', function ($request, $response) use ($app) {
        $controller = new LibriController();
        $db = $app->getContainer()->get('db');
        return $controller->index($request, $response, $db);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));

    $app->get('/admin/libri/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new LibriController();
        $db = $app->getContainer()->get('db');
        return $controller->show($request, $response, $db, (int)$args['id']);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));

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
        return $controller->editForm($request, $response, $db, (int)$args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    $app->post('/admin/libri/update/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new LibriController();
        $db = $app->getContainer()->get('db');
        return $controller->update($request, $response, $db, (int)$args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->get('/api/libri/{id:\d+}/etichetta-pdf', function ($request, $response, $args) use ($app) {
        $controller = new LibriController();
        $db = $app->getContainer()->get('db');
        return $controller->generateLabelPDF($request, $response, $db, (int)$args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

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

    // Fallback GET to avoid 405 if user navigates directly
    $app->get('/admin/libri/update/{id:\d+}', function ($request, $response, $args) {
        return $response->withHeader('Location', '/admin/libri/modifica/' . (int)$args['id'])->withStatus(302);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    $app->post('/admin/libri/delete/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new LibriController();
        $db = $app->getContainer()->get('db');
        return $controller->delete($request, $response, $db, (int)$args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Copy management routes
    $app->post('/admin/libri/copie/{id:\d+}/update', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\CopyController();
        $db = $app->getContainer()->get('db');
        return $controller->updateCopy($request, $response, $db, (int)$args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/libri/copie/{id:\d+}/delete', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\CopyController();
        $db = $app->getContainer()->get('db');
        return $controller->deleteCopy($request, $response, $db, (int)$args['id']);
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
        return $controller->show($request, $response, $db, (int)$args['id']);
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
        return $controller->deleteScaffale($request, $response, $db, (int)$args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->post('/admin/collocazione/mensole/{id}/delete', function ($request, $response, $args) use ($app) {
        $controller = new CollocazioneController();
        $db = $app->getContainer()->get('db');
        return $controller->deleteMensola($request, $response, $db, (int)$args['id']);
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

    // Prestiti (protected)
    $app->get('/prestiti', function ($request, $response) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->index($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->get('/prestiti/crea', function ($request, $response) use ($app) {
        $controller = new PrestitiController();
        return $controller->createForm($request, $response);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/prestiti/crea', function ($request, $response) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->store($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->get('/prestiti/modifica/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->editForm($request, $response, $db, (int)$args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/prestiti/update/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->update($request, $response, $db, (int)$args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->post('/prestiti/close/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->close($request, $response, $db, (int)$args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->get('/prestiti/restituito/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->returnForm($request, $response, $db, (int)$args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/prestiti/restituito/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->processReturn($request, $response, $db, (int)$args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->get('/prestiti/dettagli/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->details($request, $response, $db, (int)$args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    // Loan approval routes
    $app->get('/admin/loans/pending', function ($request, $response) use ($app) {
        $controller = new LoanApprovalController();
        $db = $app->getContainer()->get('db');
        return $controller->pendingLoans($request, $response, $db);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute

    $app->post('/admin/loans/approve', function ($request, $response) use ($app) {
        $controller = new LoanApprovalController();
        $db = $app->getContainer()->get('db');
        return $controller->approveLoan($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    $app->post('/admin/loans/reject', function ($request, $response) use ($app) {
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
    $app->get('/admin/prestiti/restituito/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->returnForm($request, $response, $db, (int)$args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/prestiti/restituito/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->processReturn($request, $response, $db, (int)$args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->post('/admin/prestiti/rinnova/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->renew($request, $response, $db, (int)$args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    $app->get('/admin/prestiti/dettagli/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new PrestitiController();
        $db = $app->getContainer()->get('db');
        return $controller->details($request, $response, $db, (int)$args['id']);
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
        return $controller->editForm($request, $response, $db, (int)$args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/prenotazioni/update/{id:\\d+}', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new ReservationsAdminController();
        return $controller->update($request, $response, $db, (int)$args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());

    // Book availability endpoint
    $app->get('/api/books/{id:\\d+}/availability', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $bookId = (int)$args['id'];
        $data = ['available'=>false,'copies_available'=>0,'copies_total'=>0,'next_due_date'=>null,'queue'=>0];
        // Copies info
        $stmt = $db->prepare("SELECT copie_disponibili, copie_totali FROM libri WHERE id = ?");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $data['copies_available'] = (int)($row['copie_disponibili'] ?? 0);
            $data['copies_total'] = (int)($row['copie_totali'] ?? 0);
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
        $data['queue'] = (int)($res->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));

    // Availability calendar (per-day availability for next N days)
    $app->get('/api/books/{id:\\d+}/availability-calendar', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $bookId = (int)$args['id'];
        $days = (int)(($request->getQueryParams()['days'] ?? 60));
        if ($days < 1) $days = 30; if ($days > 180) $days = 180;
        $copies = ['tot'=>0];
        $stmt = $db->prepare("SELECT copie_totali FROM libri WHERE id = ?");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $copies['tot'] = (int)($row['copie_totali'] ?? 0);
        }
        $stmt->close();
        // Preload active loans for the date range
        $today = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime("+{$days} days"));
        $loans = [];
        $stmt = $db->prepare("SELECT data_prestito, COALESCE(data_restituzione, data_scadenza) AS fine FROM prestiti WHERE libro_id = ?");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $r = $stmt->get_result();
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $start = substr((string)$row['data_prestito'], 0, 10);
                $fine  = substr((string)$row['fine'], 0, 10);
                if ($start === '') continue;
                if ($fine === '' || $fine < $start) $fine = $start; // fallback
                $loans[] = [$start, $fine];
            }
        }
        $stmt->close();
        // Preload reservations (we only subtract those that start that day)
        $reservationsByDay = [];
        $stmt = $db->prepare("SELECT DATE(data_prenotazione) AS giorno, COUNT(*) AS c FROM prenotazioni WHERE libro_id = ? AND stato = 'attiva' AND data_prenotazione BETWEEN ? AND ? GROUP BY DATE(data_prenotazione)");
        $startDate = $today.' 00:00:00';
        $endDateFull = $endDate.' 23:59:59';
        $stmt->bind_param('iss', $bookId, $startDate, $endDateFull);
        $stmt->execute();
        $r = $stmt->get_result();
        if ($r) {
            while ($row = $r->fetch_assoc()) { $reservationsByDay[$row['giorno']] = (int)$row['c']; }
        }
        $stmt->close();

        $result = [];
        for ($i=0; $i<$days; $i++) {
            $d = date('Y-m-d', strtotime("+{$i} days"));
            // Count loans overlapping this date
            $overlap = 0;
            foreach ($loans as [$s,$e]) {
                if ($s <= $d && $d <= $e) { $overlap++; }
            }
            $resCount = (int)($reservationsByDay[$d] ?? 0);
            $avail = max(0, $copies['tot'] - $overlap - $resCount);
            $result[] = ['date'=>$d, 'available'=>$avail];
        }
        $response->getBody()->write(json_encode(['total_copies'=>$copies['tot'], 'days'=>$result]));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));

    // Endpoint di debug disabilitato per evitare esposizione accidentale di dati sensibili

    // Editori (protected)
    $app->get('/admin/editori', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\EditorsController();
        $db = $app->getContainer()->get('db');
        return $controller->index($request, $response, $db);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));
    $app->get('/admin/editori/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\EditorsController();
        $db = $app->getContainer()->get('db');
        return $controller->show($request, $response, $db, (int)$args['id']);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));
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
        return $controller->editForm($request, $response, $db, (int)$args['id']);
    })->add(new \App\Middleware\RateLimitMiddleware(10, 60))->add(new AdminAuthMiddleware()); // 10 requests per minute
    $app->post('/admin/editori/update/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\EditorsController();
        $db = $app->getContainer()->get('db');
        return $controller->update($request, $response, $db, (int)$args['id']);
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

    // API Editori (server-side DataTables)
    $app->get('/api/editori', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\EditoriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->list($request, $response, $db);
    });

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
        return $controller->delete($request, $response, $db, (int)$args['id']);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AdminAuthMiddleware());
    
    // API Libri by Author/Publisher
    $app->get('/api/libri/author/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\LibriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->getByAuthor($request, $response, $db, (int)$args['id']);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));
    
    $app->get('/api/libri/publisher/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\LibriApiController();
        $db = $app->getContainer()->get('db');
        return $controller->getByPublisher($request, $response, $db, (int)$args['id']);
    })->add(new AuthMiddleware(['admin','staff','standard','premium']));

    // Frontend routes (public)
    $app->get('/home.php', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\FrontendController();
        $db = $app->getContainer()->get('db');
        return $controller->home($request, $response, $db);
    });

$app->get('/catalogo.php', function ($request, $response) use ($app) {
    // Redirect to /catalogo for SEO-friendly URL
    return $response->withHeader('Location', '/catalogo')->withStatus(301);
});

$app->get('/catalogo', function ($request, $response) use ($app) {
    $controller = new \App\Controllers\FrontendController();
    $db = $app->getContainer()->get('db');
    return $controller->catalog($request, $response, $db);
});

    $app->get('/scheda.php', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\FrontendController();
        $db = $app->getContainer()->get('db');
        return $controller->bookDetail($request, $response, $db);
    });

    // SEO-friendly book detail URL
    $app->get('/libro/{id:\d+}[/{slug}]', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\FrontendController();
        $db = $app->getContainer()->get('db');
        return $controller->bookDetailSEO($request, $response, $db, (int)$args['id'], $args['slug'] ?? '');
    });

    // API endpoints for book reservations
    $app->get('/api/libro/{id:\d+}/availability', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\ReservationsController($db);
        return $controller->getBookAvailability($request, $response, $args);
    });

    $app->post('/api/libro/{id:\d+}/reservation', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\ReservationsController($db);
        return $controller->createReservation($request, $response, $args);
    })->add(new CsrfMiddleware($app->getContainer()))->add(new AuthMiddleware(['admin','staff','standard','premium']));

    // API endpoint for AJAX catalog filtering
    $app->get('/api/catalogo', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\FrontendController();
        $db = $app->getContainer()->get('db');
        return $controller->catalogAPI($request, $response, $db);
    });

    // API endpoint for home page sections
    $app->get('/api/home/{section}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\FrontendController();
        $db = $app->getContainer()->get('db');
        return $controller->homeAPI($request, $response, $db, $args['section']);
    });

    // Author archive page by ID (public) - must come before name route
    $app->get('/autore/{id:\d+}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\FrontendController();
        $db = $app->getContainer()->get('db');
        return $controller->authorArchiveById($request, $response, $db, (int)$args['id']);
    });

    // Author archive page by name (public)
    $app->get('/autore/{name}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\FrontendController();
        $db = $app->getContainer()->get('db');
        return $controller->authorArchive($request, $response, $db, $args['name']);
    });

    // Publisher archive page (public)
    $app->get('/editore/{name}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\FrontendController();
        $db = $app->getContainer()->get('db');
        return $controller->publisherArchive($request, $response, $db, $args['name']);
    });

    // Genre archive page (public)
    $app->get('/genere/{name}', function ($request, $response, $args) use ($app) {
        $controller = new \App\Controllers\FrontendController();
        $db = $app->getContainer()->get('db');
        return $controller->genreArchive($request, $response, $db, $args['name']);
    });

    // CMS pages (Chi Siamo, etc.)
    $app->get('/chi-siamo', function ($request, $response, $args) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\CmsController();
        return $controller->showPage($request, $response, $db, ['slug' => 'chi-siamo']);
    });

    // Contact page
    $app->get('/contatti', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\ContactController();
        return $controller->showPage($request, $response);
    });

    $app->post('/contatti/invia', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $controller = new \App\Controllers\ContactController();
        return $controller->submitForm($request, $response, $db);
    })->add(new CsrfMiddleware($app->getContainer()));

    // Privacy Policy page
    $app->get('/privacy-policy', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\PrivacyController();
        return $controller->showPage($request, $response);
    });

    // Cookie Policy page
    $app->get('/cookies', function ($request, $response) use ($app) {
        $controller = new \App\Controllers\CookiesController();
        return $controller->showPage($request, $response);
    });

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
            'www.lafeltrinelli.it'
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
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'BibliotecaBot/1.0',
            CURLOPT_MAXREDIRS => 0
        ]);

        $img = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
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

    // Serve uploaded files from storage directory
    $app->get('/uploads/storage/{filepath:.*}', function ($request, $response, $args) {
        $rawPath = (string)($args['filepath'] ?? '');
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
    })->add(new AdminAuthMiddleware());

    // Plugin activate
    $app->post('/admin/plugins/{id}/activate', function ($request, $response, $args) use ($app) {
        $pluginManager = $app->getContainer()->get('pluginManager');
        $controller = new \App\Controllers\PluginController($pluginManager);
        return $controller->activate($request, $response, $args);
    })->add(new AdminAuthMiddleware());

    // Plugin deactivate
    $app->post('/admin/plugins/{id}/deactivate', function ($request, $response, $args) use ($app) {
        $pluginManager = $app->getContainer()->get('pluginManager');
        $controller = new \App\Controllers\PluginController($pluginManager);
        return $controller->deactivate($request, $response, $args);
    })->add(new AdminAuthMiddleware());

    // Plugin uninstall
    $app->post('/admin/plugins/{id}/uninstall', function ($request, $response, $args) use ($app) {
        $pluginManager = $app->getContainer()->get('pluginManager');
        $controller = new \App\Controllers\PluginController($pluginManager);
        return $controller->uninstall($request, $response, $args);
    })->add(new AdminAuthMiddleware());

    // Plugin details
    $app->get('/admin/plugins/{id}/details', function ($request, $response, $args) use ($app) {
        $pluginManager = $app->getContainer()->get('pluginManager');
        $controller = new \App\Controllers\PluginController($pluginManager);
        return $controller->details($request, $response, $args);
    })->add(new AdminAuthMiddleware());

    // Language switcher (no auth required - available to all users)
    $app->get('/language/{locale}', function ($request, $response, $args) use ($app) {
        $controller = new LanguageController();
        return $controller->switchLanguage($request, $response, $app->getContainer()->get('db'), $args);
    });
};
