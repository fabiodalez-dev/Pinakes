<?php
/**
 * The desiderata panels on /admin/dashboard, printed by DesiderataPlugin::dashboard()
 * through the core `admin.dashboard.sections` action.
 *
 * Every class here is copied verbatim from the dashboard's own sections —
 * SECTION 5 (purple, reservations) for the wanted books and SECTION 2 (blue,
 * loan requests) for the proposals — because public/assets/main.css is a purged
 * Tailwind build made from the core views: a utility that appears nowhere else
 * in them simply has no rule, and the markup would render unstyled.
 *
 * Core fires the hook only for admin and staff. The second check below is not
 * redundant paranoia about that gate: this file is also reachable by calling
 * the handler directly, and the panels list donor names and open requests.
 *
 * @var list<array<string,mixed>> $books   open requests, each with `open_offers`
 * @var list<array<string,mixed>> $offers  proposals still to be judged
 * @var int $pendingTotal  proposals pending in total (the six above are a page)
 * @var int $wantedTotal   open requests in total
 */
$role = $_SESSION['user']['tipo_utente'] ?? '';
if (!in_array($role, ['admin', 'staff'], true)) { return; }
$e = [DesiderataPlugin::class, 'e'];
$token = \App\Support\Csrf::ensureToken();
?>
<div id="desiderata-dashboard">

    <!-- Desiderata: books the library is still looking for -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-purple-200 bg-purple-50 flex flex-col md:flex-row items-center justify-between gap-4 rounded-t-xl">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-purple-500 rounded-lg flex items-center justify-center">
            <i class="fas fa-hand-holding-heart text-white"></i>
          </div>
          <div>
            <h2 class="text-lg font-semibold text-gray-900"><?= __("Libri che cerchiamo") ?></h2>
            <p class="text-sm text-purple-600"><?= __("Richieste aperte in attesa di una donazione") ?></p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <span class="bg-purple-500 text-white text-sm font-bold px-3 py-1 rounded-full"><?= (int) $wantedTotal ?></span>
          <a href="<?= $e(url('/admin/desiderata') . '#requested-books') ?>" class="inline-flex items-center px-4 py-2 text-sm bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 whitespace-nowrap font-medium">
            <i class="fas fa-external-link-alt mr-1"></i>
            <?= __("Gestisci tutte") ?>
          </a>
        </div>
      </div>
      <div class="p-6">
        <?php if (!$books): ?>
          <p class="text-sm text-gray-600"><?= __("Nessuna richiesta aperta. Crea una scheda libro e seleziona Desiderata.") ?></p>
        <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
          <?php foreach ($books as $b): $bookId = (int) ($b['id'] ?? 0); ?>
            <div class="flex flex-col bg-white border border-gray-200 rounded-xl overflow-hidden hover:shadow-md transition-shadow">
              <div class="p-5">
                <div class="flex gap-4">
                  <div class="shrink-0">
                    <?php // wanted() already resolved this through url(), placeholder included. ?>
                    <img src="<?= $e($b['cover'] ?? url(DesiderataPlugin::PLACEHOLDER_COVER)) ?>"
                         alt=""
                         class="w-20 h-28 object-cover rounded-lg shadow-sm"
                         onerror="this.onerror=null;this.src=(window.BASE_PATH||'')+'/uploads/copertine/placeholder.jpg'">
                  </div>
                  <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-gray-900 mb-2 line-clamp-2"><?= $e($b['titolo'] ?? '') ?></h3>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium mb-2 bg-purple-100 text-purple-700">
                      <i class="fas fa-hand-holding-heart text-[10px]"></i>
                      <?= __("Cercato dalla biblioteca") ?>
                    </span>
                    <?php if (!empty($b['autore'])): ?>
                      <p class="text-sm text-gray-600 flex items-center">
                        <i class="fas fa-user w-4 mr-2 text-purple-500"></i>
                        <?= $e($b['autore']) ?>
                      </p>
                    <?php endif; ?>
                    <?php if (!empty($b['editore'])): ?>
                      <p class="text-sm text-gray-500 flex items-center mt-1">
                        <i class="fas fa-building w-4 mr-2 text-gray-400"></i>
                        <?= $e($b['editore']) ?>
                      </p>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <div class="px-5 pb-5 mt-auto">
                <?php if ((int) ($b['open_offers'] ?? 0) > 0): ?>
                  <?php // Somebody has already offered this one: receiveDirect()
                        // refuses it, so the card sends the operator to the
                        // proposal that must be closed instead. ?>
                  <a href="<?= $e(url('/admin/desiderata') . '#donation-offers') ?>" class="inline-flex items-center justify-center w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 px-4 rounded-lg transition-colors">
                    <i class="fas fa-inbox mr-2"></i>
                    <?= $e(__n('%d proposta aperta', '%d proposte aperte', (int) $b['open_offers'])) ?>
                  </a>
                <?php else: ?>
                  <form method="post" action="<?= $e(url('/admin/desiderata/books/' . $bookId . '/received')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= $e($token) ?>">
                    <input type="hidden" name="return_to" value="dashboard">
                    <button type="submit" class="inline-flex items-center justify-center w-full bg-gray-800 hover:bg-gray-700 text-white font-medium py-2.5 px-4 rounded-lg transition-colors" onclick="<?= $e(DesiderataPlugin::confirmJs(__('Registrare il libro come donato? Viene creata una copia fisica in inventario e la richiesta si chiude.'))) ?>">
                      <i class="fas fa-box-open mr-2"></i><?= __("Libro donato: registra la copia") ?>
                    </button>
                  </form>
                <?php endif; ?>
                <a href="<?= $e(url('/admin/books/edit/' . $bookId)) ?>" class="block text-center text-sm text-blue-700 underline mt-3"><?= __("Modifica richiesta") ?></a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Desiderata: donation proposals waiting for a verdict -->
    <?php if (!empty($offers)): ?>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-blue-200 bg-blue-50 flex flex-col md:flex-row items-center justify-between gap-4 rounded-t-xl">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center">
            <i class="fas fa-gift text-white"></i>
          </div>
          <div>
            <h2 class="text-lg font-semibold text-gray-900"><?= __("Proposte da valutare") ?></h2>
            <p class="text-sm text-blue-600"><?= __("Lettori che offrono un libro") ?></p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <span class="bg-blue-500 text-white text-sm font-bold px-3 py-1 rounded-full"><?= (int) $pendingTotal ?></span>
          <a href="<?= $e(url('/admin/desiderata') . '#donation-offers') ?>" class="inline-flex items-center px-4 py-2 text-sm bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 whitespace-nowrap font-medium">
            <i class="fas fa-external-link-alt mr-1"></i>
            <?= __("Gestisci tutte") ?>
          </a>
        </div>
      </div>
      <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
          <?php foreach ($offers as $o): ?>
            <div class="flex flex-col bg-white border border-gray-200 rounded-xl overflow-hidden hover:shadow-md transition-shadow">
              <div class="p-5">
                <h3 class="font-semibold text-gray-900 mb-2 line-clamp-2"><?= $e($o['title'] ?? '') ?></h3>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium mb-2 bg-blue-100 text-blue-700">
                  <i class="fas fa-clock text-[10px]"></i>
                  <?= __("Da valutare") ?>
                </span>
                <p class="text-sm text-gray-600 flex items-center">
                  <i class="fas fa-user w-4 mr-2 text-blue-500"></i>
                  <?= $e($o['donor_name'] ?? '') ?>
                </p>
                <?php if (!empty($o['donor_email'])): ?>
                  <p class="text-sm text-gray-500 flex items-center mt-1">
                    <i class="fas fa-envelope w-4 mr-2 text-gray-400"></i>
                    <?= $e($o['donor_email']) ?>
                  </p>
                <?php endif; ?>
              </div>
              <div class="px-5 pb-5 mt-auto">
                <a href="<?= $e(url('/admin/desiderata') . '#donation-offers') ?>" class="inline-flex items-center justify-center w-full bg-gray-800 hover:bg-gray-700 text-white font-medium py-2.5 px-4 rounded-lg transition-colors">
                  <i class="fas fa-clipboard-check mr-2"></i><?= __("Valuta") ?>
                </a>
              </div>
              <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 text-xs text-gray-400 flex items-center">
                <i class="fas fa-clock mr-2"></i>
                <?= __("Richiesto il") ?> <?= !empty($o['created_at']) ? $e(format_date((string) $o['created_at'], true)) : 'N/D' ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

</div>
