<?php
use App\Support\ConfigStore;
$isCatalogueMode = ConfigStore::isCatalogueMode();
?>
<!-- Minimal White Dashboard Interface -->
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Minimal Header -->
    <div class="mb-8 fade-in">
      <div>
        <h1 class="text-3xl font-bold text-gray-900 flex items-center">
          <i class="fas fa-tachometer-alt text-gray-600 mr-3"></i>
          <?= __("Dashboard") ?>
        </h1>
        <p class="text-sm text-gray-600 mt-2"><?= __("Panoramica generale di Pinakes") ?></p>
      </div>
    </div>

    <!-- Minimal Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-6 mb-8">
      <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600"><?= __("Libri") ?></p>
            <p class="text-3xl font-bold text-gray-900"><?php echo (int)$stats['libri']; ?></p>
            <p class="text-xs text-gray-500 mt-1"><?= __("Totale libri presenti") ?></p>
          </div>
          <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-book text-gray-600 text-xl"></i>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600"><?= __("Utenti") ?></p>
            <p class="text-3xl font-bold text-gray-900"><?php echo (int)$stats['utenti']; ?></p>
            <p class="text-xs text-gray-500 mt-1"><?= __("Utenti registrati") ?></p>
          </div>
          <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-users text-gray-600 text-xl"></i>
          </div>
        </div>
      </div>

      <?php if (!$isCatalogueMode): ?>
      <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600"><?= __("Prestiti Attivi") ?></p>
            <p class="text-3xl font-bold text-gray-900"><?php echo (int)$stats['prestiti_in_corso']; ?></p>
            <p class="text-xs text-gray-500 mt-1"><?= __("In corso di restituzione") ?></p>
          </div>
          <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-handshake text-gray-600 text-xl"></i>
          </div>
        </div>
      </div>

      <!-- Ready for Pickup Card -->
      <?php if ((int)($stats['pickup_pronti'] ?? 0) > 0): ?>
        <a href="/admin/loans/pending" class="bg-orange-50 rounded-xl border border-orange-200 p-6 hover:bg-orange-100 transition-colors duration-200">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-orange-600"><?= __("Pronti per il Ritiro") ?></p>
              <p class="text-3xl font-bold text-orange-800"><?php echo (int)($stats['pickup_pronti'] ?? 0); ?></p>
              <p class="text-xs text-orange-500 mt-1"><?= __("Da consegnare") ?></p>
            </div>
            <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center animate-pulse">
              <i class="fas fa-box text-orange-600 text-xl"></i>
            </div>
          </div>
        </a>
      <?php else: ?>
        <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md transition-shadow duration-200">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-gray-600"><?= __("Pronti per il Ritiro") ?></p>
              <p class="text-3xl font-bold text-gray-900">0</p>
              <p class="text-xs text-gray-500 mt-1"><?= __("Nessun ritiro") ?></p>
            </div>
            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
              <i class="fas fa-box text-gray-600 text-xl"></i>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Pending Requests Card -->
      <?php if ((int)($stats['prestiti_pendenti'] ?? 0) > 0): ?>
        <a href="/admin/loans/pending" class="bg-blue-50 rounded-xl border border-blue-200 p-6 hover:bg-blue-100 transition-colors duration-200">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-blue-600"><?= __("Richieste Pendenti") ?></p>
              <p class="text-3xl font-bold text-blue-800"><?php echo (int)($stats['prestiti_pendenti'] ?? 0); ?></p>
              <p class="text-xs text-blue-500 mt-1"><?= __("Da approvare") ?></p>
            </div>
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center animate-pulse">
              <i class="fas fa-clock text-blue-600 text-xl"></i>
            </div>
          </div>
        </a>
      <?php else: ?>
        <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md transition-shadow duration-200">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-gray-600"><?= __("Richieste Pendenti") ?></p>
              <p class="text-3xl font-bold text-gray-900">0</p>
              <p class="text-xs text-gray-500 mt-1"><?= __("Nessuna richiesta") ?></p>
            </div>
            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
              <i class="fas fa-check-circle text-gray-600 text-xl"></i>
            </div>
          </div>
        </div>
      <?php endif; ?>
      <?php endif; ?>

      <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600"><?= __("Autori") ?></p>
            <p class="text-3xl font-bold text-gray-900"><?php echo (int)$stats['autori']; ?></p>
            <p class="text-xs text-gray-500 mt-1"><?= __("Nella collezione") ?></p>
          </div>
          <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-user-edit text-gray-600 text-xl"></i>
          </div>
        </div>
      </div>
    </div>

    <?php if (!$isCatalogueMode): ?>
    <!-- Calendar Section with ICS Link -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-gray-200 flex flex-col md:flex-row items-center justify-between gap-4">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-calendar-alt text-gray-600 mr-2"></i>
          <?= __("Calendario Prestiti e Prenotazioni") ?>
        </h2>
        <div class="flex items-center gap-3">
          <a href="<?= htmlspecialchars($icsUrl ?? '/calendar/events.ics') ?>" class="px-3 py-1.5 text-sm bg-purple-600 text-white hover:bg-purple-500 rounded-lg transition-colors duration-200 whitespace-nowrap">
            <i class="fas fa-calendar-plus mr-1"></i>
            <?= __("Sincronizza (ICS)") ?>
          </a>
          <button type="button" id="copy-ics-url" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 whitespace-nowrap">
            <i class="fas fa-copy mr-1"></i>
            <?= __("Copia Link") ?>
          </button>
        </div>
      </div>
      <div class="p-6">
        <!-- Legend -->
        <div class="flex flex-wrap gap-4 mb-4 text-sm">
          <span class="flex items-center gap-2">
            <span class="w-3 h-3 rounded-full bg-green-500"></span>
            <?= __("Prestiti in corso") ?>
          </span>
          <span class="flex items-center gap-2">
            <span class="w-3 h-3 rounded-full bg-blue-500"></span>
            <?= __("Prestiti programmati") ?>
          </span>
          <span class="flex items-center gap-2">
            <span class="w-3 h-3 rounded-full bg-red-500"></span>
            <?= __("Prestiti scaduti") ?>
          </span>
          <span class="flex items-center gap-2">
            <span class="w-3 h-3 rounded-full" style="background-color: #F59E0B;"></span>
            <?= __("Richieste pendenti") ?>
          </span>
          <span class="flex items-center gap-2">
            <span class="w-3 h-3 rounded-full" style="background-color: #8B5CF6;"></span>
            <?= __("Prenotazioni") ?>
          </span>
        </div>
        <!-- Calendar Container -->
        <div id="dashboard-calendar" class="min-h-[400px]"></div>
      </div>
    </div>

    <!-- ============================================== -->
    <!-- SECTION 1: PICKUP READY (Most Urgent - Orange) -->
    <!-- ============================================== -->
    <?php if (!empty($pickupLoans)): ?>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-orange-200 bg-orange-50 flex flex-col md:flex-row items-center justify-between gap-4 rounded-t-xl">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-orange-500 rounded-lg flex items-center justify-center">
            <i class="fas fa-box text-white"></i>
          </div>
          <div>
            <h2 class="text-lg font-semibold text-gray-900"><?= __("Pronti per il Ritiro") ?></h2>
            <p class="text-sm text-orange-600"><?= __("Prestiti approvati in attesa di consegna") ?></p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <span class="bg-orange-500 text-white text-sm font-bold px-3 py-1 rounded-full"><?= count($pickupLoans) ?></span>
          <a href="/admin/loans/pending" class="px-4 py-2 text-sm bg-orange-600 text-white hover:bg-orange-700 rounded-lg transition-colors duration-200 whitespace-nowrap font-medium">
            <i class="fas fa-external-link-alt mr-1"></i>
            <?= __("Gestisci tutti") ?>
          </a>
        </div>
      </div>
      <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
          <?php foreach ($pickupLoans as $loan): ?>
            <?php
            $isExpired = !empty($loan['pickup_deadline']) && $loan['pickup_deadline'] < date('Y-m-d');
            $cardBg = $isExpired ? 'bg-red-50 border-red-200' : 'bg-white border-gray-200';
            ?>
            <div class="flex flex-col <?= $cardBg ?> border rounded-xl overflow-hidden hover:shadow-md transition-shadow" data-pickup-card>
              <div class="p-5">
                <div class="flex gap-4">
                  <div class="flex-shrink-0">
                    <?php $cover = !empty($loan['copertina_url']) ? $loan['copertina_url'] : '/uploads/copertine/placeholder.jpg'; ?>
                    <img src="<?= htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                         alt="<?= App\Support\HtmlHelper::e($loan['titolo'] ?? 'Copertina libro'); ?>"
                         class="w-20 h-28 object-cover rounded-lg shadow-sm"
                         onerror="this.src='/uploads/copertine/placeholder.jpg'">
                  </div>
                  <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-gray-900 mb-2 line-clamp-2"><?= App\Support\HtmlHelper::e($loan['titolo'] ?? ''); ?></h3>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium mb-2 <?= $isExpired ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700' ?>">
                      <i class="fas <?= $isExpired ? 'fa-exclamation-triangle' : 'fa-box' ?> text-[10px]"></i>
                      <?= $isExpired ? __("Ritiro scaduto") : __("Da ritirare") ?>
                    </span>
                    <p class="text-sm text-gray-600 flex items-center">
                      <i class="fas fa-user w-4 mr-2 text-orange-500"></i>
                      <?= App\Support\HtmlHelper::e($loan['utente_nome'] ?? ''); ?>
                    </p>
                    <?php if (!empty($loan['email'])): ?>
                      <p class="text-sm text-gray-500 flex items-center mt-1">
                        <i class="fas fa-envelope w-4 mr-2 text-gray-400"></i>
                        <?= App\Support\HtmlHelper::e($loan['email']); ?>
                      </p>
                    <?php endif; ?>
                    <div class="mt-2 space-y-1 text-xs text-gray-500">
                      <?php if (!empty($loan['data_prestito'])): ?>
                        <span class="flex items-center">
                          <i class="fas fa-calendar-alt w-4 mr-2 text-green-500"></i>
                          <?= __("Inizio:") ?> <?= format_date((string)$loan['data_prestito']); ?>
                        </span>
                      <?php endif; ?>
                      <?php if (!empty($loan['pickup_deadline'])): ?>
                        <span class="flex items-center <?= $isExpired ? 'text-red-600 font-medium' : '' ?>">
                          <i class="fas fa-hourglass-half w-4 mr-2 <?= $isExpired ? 'text-red-500' : 'text-orange-500' ?>"></i>
                          <?= __("Scadenza ritiro:") ?> <?= format_date((string)$loan['pickup_deadline']); ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
              <div class="px-5 pb-5">
                <?php if ($isExpired): ?>
                  <button type="button" class="w-full bg-red-600 hover:bg-red-700 text-white font-medium py-2.5 px-4 rounded-lg transition-colors cancel-pickup-btn" data-loan-id="<?= (int)$loan['id']; ?>">
                    <i class="fas fa-times mr-2"></i><?= __("Annulla Prestito Scaduto") ?>
                  </button>
                <?php else: ?>
                  <div class="flex gap-3">
                    <button type="button" class="flex-1 bg-green-600 hover:bg-green-700 text-white font-medium py-2.5 px-4 rounded-lg transition-colors confirm-pickup-btn" data-loan-id="<?= (int)$loan['id']; ?>">
                      <i class="fas fa-check mr-2"></i><?= __("Conferma Ritiro") ?>
                    </button>
                    <button type="button" class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-700 font-medium py-2.5 px-4 rounded-lg transition-colors cancel-pickup-btn" data-loan-id="<?= (int)$loan['id']; ?>">
                      <i class="fas fa-times mr-2"></i><?= __("Annulla") ?>
                    </button>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ============================================== -->
    <!-- SECTION 2: PENDING LOANS (Need Approval - Blue) -->
    <!-- ============================================== -->
    <?php if (!empty($pending)): ?>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-blue-200 bg-blue-50 flex flex-col md:flex-row items-center justify-between gap-4 rounded-t-xl">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center">
            <i class="fas fa-clock text-white"></i>
          </div>
          <div>
            <h2 class="text-lg font-semibold text-gray-900"><?= __("Richieste di Prestito") ?></h2>
            <p class="text-sm text-blue-600"><?= __("In attesa di approvazione") ?></p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <span class="bg-blue-500 text-white text-sm font-bold px-3 py-1 rounded-full"><?= count($pending) ?></span>
          <a href="/admin/loans/pending" class="px-4 py-2 text-sm bg-blue-600 text-white hover:bg-blue-700 rounded-lg transition-colors duration-200 whitespace-nowrap font-medium">
            <i class="fas fa-external-link-alt mr-1"></i>
            <?= __("Gestisci tutte") ?>
          </a>
        </div>
      </div>
      <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
          <?php foreach ($pending as $loan): ?>
            <div class="flex flex-col bg-white border border-gray-200 rounded-xl overflow-hidden hover:shadow-md transition-shadow" data-loan-card>
              <div class="p-5">
                <div class="flex gap-4">
                  <div class="flex-shrink-0">
                    <?php $cover = !empty($loan['copertina_url']) ? $loan['copertina_url'] : '/uploads/copertine/placeholder.jpg'; ?>
                    <img src="<?= htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                         alt="<?= App\Support\HtmlHelper::e($loan['titolo'] ?? 'Copertina libro'); ?>"
                         class="w-20 h-28 object-cover rounded-lg shadow-sm"
                         onerror="this.src='/uploads/copertine/placeholder.jpg'">
                  </div>
                  <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-gray-900 mb-2 line-clamp-2"><?= App\Support\HtmlHelper::e($loan['titolo'] ?? ''); ?></h3>
                    <?php
                    $origine = $loan['origine'] ?? 'richiesta';
                    $origineBadge = match($origine) {
                        'prenotazione' => ['bg-purple-100 text-purple-700', 'fa-calendar-check', __('Da prenotazione')],
                        'diretto' => ['bg-green-100 text-green-700', 'fa-hand-holding', __('Prestito diretto')],
                        default => ['bg-blue-100 text-blue-700', 'fa-paper-plane', __('Richiesta manuale')],
                    };
                    ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium mb-2 <?= $origineBadge[0] ?>">
                      <i class="fas <?= $origineBadge[1] ?> text-[10px]"></i>
                      <?= $origineBadge[2] ?>
                    </span>
                    <p class="text-sm text-gray-600 flex items-center">
                      <i class="fas fa-user w-4 mr-2 text-blue-500"></i>
                      <?= App\Support\HtmlHelper::e($loan['utente_nome'] ?? ''); ?>
                    </p>
                    <?php if (!empty($loan['email'])): ?>
                      <p class="text-sm text-gray-500 flex items-center mt-1">
                        <i class="fas fa-envelope w-4 mr-2 text-gray-400"></i>
                        <?= App\Support\HtmlHelper::e($loan['email']); ?>
                      </p>
                    <?php endif; ?>
                    <div class="mt-2 space-y-1 text-xs text-gray-500">
                      <?php if (!empty($loan['data_richiesta_inizio'])): ?>
                        <span class="flex items-center">
                          <i class="fas fa-calendar-alt w-4 mr-2 text-green-500"></i>
                          <?= __("Inizio:") ?> <?= format_date((string)$loan['data_richiesta_inizio']); ?>
                        </span>
                      <?php endif; ?>
                      <?php if (!empty($loan['data_richiesta_fine'])): ?>
                        <span class="flex items-center">
                          <i class="fas fa-calendar-times w-4 mr-2 text-red-500"></i>
                          <?= __("Fine:") ?> <?= format_date((string)$loan['data_richiesta_fine']); ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
              <div class="px-5 pb-5 mt-auto">
                <div class="flex gap-3">
                  <button type="button" class="flex-1 bg-gray-900 hover:bg-gray-800 text-white font-medium py-2.5 px-4 rounded-lg transition-colors approve-btn" data-loan-id="<?= (int)$loan['id']; ?>">
                    <i class="fas fa-check mr-2"></i><?= __("Approva") ?>
                  </button>
                  <button type="button" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-medium py-2.5 px-4 rounded-lg transition-colors reject-btn" data-loan-id="<?= (int)$loan['id']; ?>">
                    <i class="fas fa-times mr-2"></i><?= __("Rifiuta") ?>
                  </button>
                </div>
              </div>
              <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 text-xs text-gray-400 flex items-center">
                <i class="fas fa-clock mr-2"></i>
                <?= __("Richiesto il") ?> <?= !empty($loan['created_at']) ? format_date((string)$loan['created_at'], true) : 'N/D'; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ============================================== -->
    <!-- SECTION 3: OVERDUE LOANS (Need Attention - Red) -->
    <!-- ============================================== -->
    <?php if (!empty($overdue)): ?>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-red-200 bg-red-50 flex flex-col md:flex-row items-center justify-between gap-4 rounded-t-xl">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-red-500 rounded-lg flex items-center justify-center">
            <i class="fas fa-exclamation-triangle text-white"></i>
          </div>
          <div>
            <h2 class="text-lg font-semibold text-gray-900"><?= __("Prestiti Scaduti") ?></h2>
            <p class="text-sm text-red-600"><?= __("Richiedono attenzione immediata") ?></p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <span class="bg-red-500 text-white text-sm font-bold px-3 py-1 rounded-full"><?= count($overdue) ?></span>
          <a href="/admin/prestiti" class="px-4 py-2 text-sm bg-red-600 text-white hover:bg-red-700 rounded-lg transition-colors duration-200 whitespace-nowrap font-medium">
            <i class="fas fa-external-link-alt mr-1"></i>
            <?= __("Gestisci") ?>
          </a>
        </div>
      </div>
      <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
          <?php foreach ($overdue as $p): ?>
            <div class="flex flex-col bg-red-50 border border-red-200 rounded-xl overflow-hidden">
              <div class="p-5">
                <div class="flex items-start gap-4">
                  <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-book text-red-600"></i>
                  </div>
                  <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-gray-900 mb-1 line-clamp-2"><?= App\Support\HtmlHelper::e($p['titolo'] ?? ''); ?></h3>
                    <p class="text-sm text-gray-600 flex items-center">
                      <i class="fas fa-user w-4 mr-2 text-red-500"></i>
                      <?= App\Support\HtmlHelper::e($p['utente'] ?? ''); ?>
                    </p>
                    <div class="mt-2 space-y-1 text-xs">
                      <span class="flex items-center text-gray-500">
                        <i class="fas fa-calendar-alt w-4 mr-2 text-gray-400"></i>
                        <?= __("Prestito:") ?> <?= $p['data_prestito'] ? format_date($p['data_prestito']) : ''; ?>
                      </span>
                      <span class="flex items-center text-red-600 font-medium">
                        <i class="fas fa-calendar-times w-4 mr-2 text-red-500"></i>
                        <?= __("Scaduto il:") ?> <?= $p['data_scadenza'] ? format_date($p['data_scadenza']) : ''; ?>
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ============================================== -->
    <!-- SECTION 4: ACTIVE RESERVATIONS (Purple) -->
    <!-- ============================================== -->
    <?php if (!empty($reservations)): ?>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-purple-200 bg-purple-50 flex flex-col md:flex-row items-center justify-between gap-4 rounded-t-xl">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-purple-500 rounded-lg flex items-center justify-center">
            <i class="fas fa-calendar-check text-white"></i>
          </div>
          <div>
            <h2 class="text-lg font-semibold text-gray-900"><?= __("Prenotazioni Attive") ?></h2>
            <p class="text-sm text-purple-600"><?= __("Libri prenotati dagli utenti") ?></p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <span class="bg-purple-500 text-white text-sm font-bold px-3 py-1 rounded-full"><?= count($reservations) ?></span>
          <a href="/admin/prenotazioni" class="px-4 py-2 text-sm bg-purple-600 text-white hover:bg-purple-700 rounded-lg transition-colors duration-200 whitespace-nowrap font-medium">
            <i class="fas fa-external-link-alt mr-1"></i>
            <?= __("Gestisci tutte") ?>
          </a>
        </div>
      </div>
      <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
          <?php foreach ($reservations as $res): ?>
            <div class="flex flex-col bg-white border border-gray-200 rounded-xl overflow-hidden hover:shadow-md transition-shadow">
              <div class="p-5">
                <div class="flex gap-4">
                  <div class="flex-shrink-0">
                    <?php $cover = !empty($res['copertina_url']) ? $res['copertina_url'] : '/uploads/copertine/placeholder.jpg'; ?>
                    <img src="<?= htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                         alt="<?= App\Support\HtmlHelper::e($res['titolo'] ?? 'Copertina libro'); ?>"
                         class="w-20 h-28 object-cover rounded-lg shadow-sm"
                         onerror="this.src='/uploads/copertine/placeholder.jpg'">
                  </div>
                  <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-gray-900 mb-2 line-clamp-2"><?= App\Support\HtmlHelper::e($res['titolo'] ?? ''); ?></h3>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium mb-2 bg-purple-100 text-purple-700">
                      <i class="fas fa-calendar-check text-[10px]"></i>
                      <?= __("Prenotazione") ?>
                    </span>
                    <p class="text-sm text-gray-600 flex items-center">
                      <i class="fas fa-user w-4 mr-2 text-purple-500"></i>
                      <?= App\Support\HtmlHelper::e($res['utente_nome'] ?? ''); ?>
                    </p>
                    <?php if (!empty($res['email'])): ?>
                      <p class="text-sm text-gray-500 flex items-center mt-1">
                        <i class="fas fa-envelope w-4 mr-2 text-gray-400"></i>
                        <?= App\Support\HtmlHelper::e($res['email']); ?>
                      </p>
                    <?php endif; ?>
                    <div class="mt-2 space-y-1 text-xs text-gray-500">
                      <?php
                      $startDate = $res['data_inizio_richiesta'] ?? $res['data_scadenza_prenotazione'];
                      $endDate = $res['data_fine_richiesta'] ?? $res['data_scadenza_prenotazione'];
                      ?>
                      <?php if (!empty($startDate)): ?>
                        <span class="flex items-center">
                          <i class="fas fa-calendar-alt w-4 mr-2 text-green-500"></i>
                          <?= __("Inizio:") ?> <?= format_date((string)$startDate); ?>
                        </span>
                      <?php endif; ?>
                      <?php if (!empty($endDate)): ?>
                        <span class="flex items-center">
                          <i class="fas fa-calendar-times w-4 mr-2 text-red-500"></i>
                          <?= __("Fine:") ?> <?= format_date((string)$endDate); ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
              <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 text-xs text-gray-400 flex items-center mt-auto">
                <i class="fas fa-clock mr-2"></i>
                <?= __("Creata il") ?> <?= !empty($res['created_at']) ? format_date((string)$res['created_at'], true) : 'N/D'; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ============================================== -->
    <!-- SECTION 5: ACTIVE LOANS (Green) -->
    <!-- ============================================== -->
    <?php if (!empty($active)): ?>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-green-200 bg-green-50 flex flex-col md:flex-row items-center justify-between gap-4 rounded-t-xl">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center">
            <i class="fas fa-handshake text-white"></i>
          </div>
          <div>
            <h2 class="text-lg font-semibold text-gray-900"><?= __("Prestiti in Corso") ?></h2>
            <p class="text-sm text-green-600"><?= __("Libri attualmente in prestito") ?></p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <span class="bg-green-500 text-white text-sm font-bold px-3 py-1 rounded-full"><?= count($active) ?></span>
          <a href="/admin/prestiti" class="px-4 py-2 text-sm bg-green-600 text-white hover:bg-green-700 rounded-lg transition-colors duration-200 whitespace-nowrap font-medium">
            <i class="fas fa-eye mr-1"></i>
            <?= __("Vedi tutti") ?>
          </a>
        </div>
      </div>
      <div class="p-6">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Libro") ?></th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Utente") ?></th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Data Prestito") ?></th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Scadenza") ?></th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Stato") ?></th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php foreach ($active as $p): ?>
                <tr class="hover:bg-gray-50">
                  <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                    <a href="/admin/libri/<?php echo (int)($p['libro_id'] ?? 0); ?>" class="hover:text-blue-600 hover:underline transition-colors">
                      <?php echo App\Support\HtmlHelper::e($p['titolo'] ?? ''); ?>
                    </a>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                    <?php echo App\Support\HtmlHelper::e($p['utente'] ?? ''); ?>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    <?php echo $p['data_prestito'] ? format_date($p['data_prestito']) : ''; ?>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    <?php echo $p['data_scadenza'] ? format_date($p['data_scadenza']) : ''; ?>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                      <i class="fas fa-clock mr-1"></i>
                      <?= __("In corso") ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Empty state when no pending actions -->
    <?php if (empty($pickupLoans) && empty($pending) && empty($overdue)): ?>
    <div class="bg-green-50 border border-green-200 rounded-xl p-8 mb-8 text-center">
      <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
        <i class="fas fa-check-circle text-green-500 text-3xl"></i>
      </div>
      <h3 class="text-lg font-semibold text-green-800 mb-2"><?= __("Tutto sotto controllo!") ?></h3>
      <p class="text-green-600"><?= __("Non ci sono azioni urgenti da completare.") ?></p>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- ============================================== -->
    <!-- SECTION 6: RECENT BOOKS (Informational - Gray) -->
    <!-- ============================================== -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-gray-200 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-gray-500 rounded-lg flex items-center justify-center">
            <i class="fas fa-book-open text-white"></i>
          </div>
          <div>
            <h2 class="text-lg font-semibold text-gray-900"><?= __("Ultimi Libri Inseriti") ?></h2>
            <p class="text-sm text-gray-500"><?= __("Aggiunti di recente al catalogo") ?></p>
          </div>
        </div>
        <a href="/admin/libri" class="px-4 py-2 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 font-medium">
          <i class="fas fa-eye mr-1"></i>
          <?= __("Vedi tutti") ?>
        </a>
      </div>
      <div class="p-6">
        <?php if (empty($lastBooks)): ?>
          <div class="text-center py-8">
            <i class="fas fa-book-open text-4xl text-gray-300 mb-4"></i>
            <p class="text-gray-500"><?= __("Nessun libro ancora inserito") ?></p>
          </div>
        <?php else: ?>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php foreach ($lastBooks as $libro): ?>
              <a href="/admin/libri/<?php echo (int)$libro['id']; ?>" class="group h-full">
                <div class="bg-gray-50 rounded-lg border border-gray-200 overflow-hidden hover:shadow-md transition-all duration-200 h-full flex flex-col">
                  <?php $coverUrl = !empty($libro['copertina_url']) ? $libro['copertina_url'] : '/uploads/copertine/placeholder.jpg'; ?>
                  <img src="<?php echo htmlspecialchars($coverUrl, ENT_QUOTES, 'UTF-8'); ?>"
                       alt="<?php echo App\Support\HtmlHelper::e($libro['titolo'] ?? ''); ?>"
                       class="w-full h-48 object-cover"
                       onerror="this.src='/uploads/copertine/placeholder.jpg'">
                  <div class="p-4 flex-1">
                    <h3 class="font-semibold text-gray-900 group-hover:text-gray-700 transition-colors truncate">
                      <?php echo App\Support\HtmlHelper::e($libro['titolo'] ?? ''); ?>
                    </h3>
                    <p class="text-sm text-gray-600 truncate">
                      <?php echo App\Support\HtmlHelper::e($libro['autore'] ?? ''); ?>
                    </p>
                    <?php if (!empty($libro['anno_pubblicazione'])): ?>
                      <p class="text-xs text-gray-500 mt-1">
                        <?php echo App\Support\HtmlHelper::e($libro['anno_pubblicazione']); ?>
                      </p>
                    <?php endif; ?>
                  </div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
</div>
</div>

<?php
require __DIR__ . '/../partials/loan-actions-swal.php';
unset($loanActionTranslations);
?>

<!-- Custom Styles for Enhanced UI -->
<style>
.fade-in {
  animation: fadeIn 0.5s ease-in-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

/* FullCalendar custom styles */
#dashboard-calendar .fc-event {
  cursor: pointer;
  padding: 2px 4px;
  border-radius: 4px;
  font-size: 0.75rem;
}
#dashboard-calendar .fc-event-title {
  font-weight: 500;
}
#dashboard-calendar .fc-daygrid-event {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Responsive styles for mobile */
@media (max-width: 767px) {
  #dashboard-calendar {
    min-height: 300px;
  }
  #dashboard-calendar .fc-toolbar {
    flex-direction: column;
    gap: 0.5rem;
  }
  #dashboard-calendar .fc-toolbar-chunk {
    display: flex;
    justify-content: center;
  }
  #dashboard-calendar .fc-toolbar-title {
    font-size: 1rem;
  }
  #dashboard-calendar .fc-button {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
  }
  #dashboard-calendar .fc-daygrid-day-number {
    font-size: 0.75rem;
    padding: 2px 4px;
  }
  #dashboard-calendar .fc-event {
    font-size: 0.65rem;
    padding: 1px 2px;
  }
  #dashboard-calendar .fc-list-event-title {
    font-size: 0.8rem;
  }
}
</style>

<!-- FullCalendar (local) -->
<script src="/assets/fullcalendar.min.js"></script>
<?php
// Prepare calendar events JSON - show only START and END events, not duration
$calendarEventsJson = [];
foreach ($calendarEvents as $event) {
    // Skip events with missing dates
    if (empty($event['start'])) {
        continue;
    }

    $isReservation = $event['type'] === 'prenotazione';
    $typeLabel = $isReservation ? __('Prenotazione') : __('Prestito');

    // Color based on type/status
    $startColor = $isReservation ? '#8B5CF6' : match($event['status']) {
        'in_corso' => '#10B981',     // Green
        'da_ritirare' => '#F97316',  // Orange (ready for pickup)
        'prenotato' => '#3B82F6',    // Blue
        'in_ritardo' => '#EF4444',   // Red
        'pendente' => '#F59E0B',     // Amber
        default => '#6B7280'          // Gray
    };
    $endColor = '#EF4444'; // Red for end dates

    // Add START event
    $calendarEventsJson[] = [
        'id' => $event['id'] . '_start',
        'title' => '▶ ' . __('Inizio') . ': ' . $event['title'],
        'start' => $event['start'],
        'allDay' => true,
        'color' => $startColor,
        'extendedProps' => [
            'user' => $event['user'] ?? '',
            'type' => $event['type'] ?? '',
            'status' => $event['status'] ?? '',
            'eventType' => 'start',
            'originalStart' => $event['start'],
            'originalEnd' => $event['end'] ?? $event['start']
        ]
    ];

    // Add END event (only if different from start and end exists)
    $endDate = $event['end'] ?? $event['start'];
    if ($event['start'] !== $endDate) {
        $calendarEventsJson[] = [
            'id' => $event['id'] . '_end',
            'title' => '⏹ ' . __('Fine') . ': ' . $event['title'],
            'start' => $endDate,
            'allDay' => true,
            'color' => $endColor,
            'extendedProps' => [
                'user' => $event['user'] ?? '',
                'type' => $event['type'] ?? '',
                'status' => $event['status'] ?? '',
                'eventType' => 'end',
                'originalStart' => $event['start'],
                'originalEnd' => $endDate
            ]
        ];
    }
}
?>
<script>
// XSS protection helper
function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// formatDateLocale and appLocale are defined globally in layout.php

document.addEventListener('DOMContentLoaded', function() {
    // Initialize FullCalendar
    const calendarEl = document.getElementById('dashboard-calendar');
    if (calendarEl) {
        // Detect mobile for responsive toolbar
        const isMobile = window.innerWidth < 768;

        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: isMobile ? 'listWeek' : 'dayGridMonth',
            locale: '<?= strtolower(substr(\App\Support\I18n::getLocale(), 0, 2)) ?>',
            // Responsive toolbar: simpler on mobile
            headerToolbar: isMobile ? {
                left: 'prev,next',
                center: 'title',
                right: 'listWeek,dayGridMonth'
            } : {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,dayGridWeek,listWeek'
            },
            buttonText: {
                today: '<?= __("Oggi") ?>',
                month: '<?= __("Mese") ?>',
                week: '<?= __("Settimana") ?>',
                list: '<?= __("Lista") ?>'
            },
            // Responsive settings
            handleWindowResize: true,
            contentHeight: 'auto',
            expandRows: true,
            dayMaxEvents: isMobile ? 2 : true,
            moreLinkClick: 'popover',
            events: <?= json_encode(
                $calendarEventsJson,
                JSON_UNESCAPED_UNICODE
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
            ) ?>,
            eventClick: function(info) {
                const props = info.event.extendedProps;
                const typeLabel = props.type === 'prenotazione' ? '<?= __("Prenotazione") ?>' : '<?= __("Prestito") ?>';
                const statusLabels = {
                    'in_corso': '<?= __("In corso") ?>',
                    'prenotato': '<?= __("Programmato") ?>',
                    'da_ritirare': '<?= __("Da Ritirare") ?>',
                    'in_ritardo': '<?= __("Scaduto") ?>',
                    'pendente': '<?= __("In attesa") ?>',
                    'attiva': '<?= __("Attiva") ?>'
                };
                const statusLabel = statusLabels[props.status] || props.status;

                // Use originalStart/originalEnd with fallback to event dates
                const start = props.originalStart ? new Date(props.originalStart) : info.event.start;
                const endRaw = props.originalEnd || props.originalStart || info.event.start;
                const end = new Date(endRaw);

                if (window.Swal) {
                    Swal.fire({
                        title: escapeHtml(info.event.title),
                        html: `
                            <div class="text-left">
                                <p><strong><?= __("Tipo") ?>:</strong> ${escapeHtml(typeLabel)}</p>
                                <p><strong><?= __("Utente") ?>:</strong> ${escapeHtml(props.user)}</p>
                                <p><strong><?= __("Stato") ?>:</strong> ${escapeHtml(statusLabel)}</p>
                                <p><strong><?= __("Dal") ?>:</strong> ${formatDateLocale(start)}</p>
                                <p><strong><?= __("Al") ?>:</strong> ${formatDateLocale(end)}</p>
                            </div>
                        `,
                        icon: 'info',
                        confirmButtonText: '<?= __("Chiudi") ?>'
                    });
                } else {
                    alert(`${escapeHtml(info.event.title)}\n${escapeHtml(typeLabel)} - ${escapeHtml(statusLabel)}\n${escapeHtml(props.user)}`);
                }
            },
            eventDidMount: function(info) {
                // Add tooltip with XSS protection
                info.el.title = escapeHtml(info.event.title) + '\n' + escapeHtml(info.event.extendedProps.user);
            }
        });
        calendar.render();
    }

    // Copy ICS URL button
    const copyBtn = document.getElementById('copy-ics-url');
    if (copyBtn) {
        copyBtn.addEventListener('click', function() {
            const rawUrl = <?= json_encode($icsUrl ?? '/calendar/events.ics') ?>;
            const icsUrl = rawUrl.startsWith('http://') || rawUrl.startsWith('https://')
                ? rawUrl
                : window.location.origin + rawUrl;

            const showSuccess = function() {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'success',
                        title: <?= json_encode(__("Link copiato!")) ?>,
                        text: <?= json_encode(__("L'URL del calendario è stato copiato negli appunti.")) ?>,
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    alert(<?= json_encode(__("Link copiato!")) ?>);
                }
            };

            const fallbackCopy = function() {
                const textarea = document.createElement('textarea');
                textarea.value = icsUrl;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                showSuccess();
            };

            // Feature detection: check if Clipboard API is available
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                navigator.clipboard.writeText(icsUrl).then(showSuccess).catch(fallbackCopy);
            } else {
                fallbackCopy();
            }
        });
    }
});
</script>
