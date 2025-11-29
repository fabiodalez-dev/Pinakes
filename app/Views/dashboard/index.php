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

      <!-- Pickup Confirmations Card (from reservations) -->
      <?php if ((int)($stats['ritiri_da_confermare'] ?? 0) > 0): ?>
        <a href="/admin/loans/pending" class="bg-purple-50 rounded-xl border border-purple-200 p-6 hover:bg-purple-100 transition-colors duration-200">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-purple-600"><?= __("Ritiri da Confermare") ?></p>
              <p class="text-3xl font-bold text-purple-800"><?php echo (int)($stats['ritiri_da_confermare'] ?? 0); ?></p>
              <p class="text-xs text-purple-500 mt-1"><?= __("Da prenotazioni") ?></p>
            </div>
            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center animate-pulse">
              <i class="fas fa-calendar-check text-purple-600 text-xl"></i>
            </div>
          </div>
        </a>
      <?php else: ?>
        <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md transition-shadow duration-200">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-gray-600"><?= __("Ritiri da Confermare") ?></p>
              <p class="text-3xl font-bold text-gray-900">0</p>
              <p class="text-xs text-gray-500 mt-1"><?= __("Nessun ritiro") ?></p>
            </div>
            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
              <i class="fas fa-check-circle text-gray-600 text-xl"></i>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Manual Requests Card -->
      <?php if ((int)($stats['richieste_manuali'] ?? 0) > 0): ?>
        <a href="/admin/loans/pending" class="bg-blue-50 rounded-xl border border-blue-200 p-6 hover:bg-blue-100 transition-colors duration-200">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-blue-600"><?= __("Richieste Manuali") ?></p>
              <p class="text-3xl font-bold text-blue-800"><?php echo (int)($stats['richieste_manuali'] ?? 0); ?></p>
              <p class="text-xs text-blue-500 mt-1"><?= __("Da approvare") ?></p>
            </div>
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center animate-pulse">
              <i class="fas fa-paper-plane text-blue-600 text-xl"></i>
            </div>
          </div>
        </a>
      <?php else: ?>
        <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md transition-shadow duration-200">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-gray-600"><?= __("Richieste Manuali") ?></p>
              <p class="text-3xl font-bold text-gray-900">0</p>
              <p class="text-xs text-gray-500 mt-1"><?= __("Nessuna richiesta") ?></p>
            </div>
            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
              <i class="fas fa-check-circle text-gray-600 text-xl"></i>
            </div>
          </div>
        </div>
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

    <!-- Calendar Section with ICS Link -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-gray-200 flex flex-col md:flex-row items-center justify-between gap-4">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-calendar-alt text-gray-600 mr-2"></i>
          <?= __("Calendario Prestiti e Prenotazioni") ?>
        </h2>
        <div class="flex items-center gap-3">
          <a href="<?= htmlspecialchars($icsUrl ?? '/storage/calendar/library-calendar.ics') ?>" class="px-3 py-1.5 text-sm bg-purple-600 text-white hover:bg-purple-500 rounded-lg transition-colors duration-200 whitespace-nowrap">
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

    <!-- Pending Loans Section -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-gray-200 flex flex-col md:flex-row items-center justify-between gap-4">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-clock text-gray-600 mr-2"></i>
          <?= __("Richieste di Prestito in Attesa") ?>
        </h2>
        <a href="/admin/loans/pending" class="px-3 py-1.5 text-sm bg-gray-900 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 whitespace-nowrap">
          <i class="fas fa-external-link-alt mr-1"></i>
          <?= __("Gestisci tutte") ?>
        </a>
      </div>
      <div class="p-6">
        <?php if (empty($pending)): ?>
          <div class="text-center py-8">
            <i class="fas fa-check-circle text-4xl text-green-500 mb-4"></i>
            <p class="text-gray-500"><?= __("Nessuna richiesta in attesa di approvazione.") ?></p>
          </div>
        <?php else: ?>
          <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
            <?php foreach ($pending as $loan): ?>
              <div class="flex flex-col bg-gray-50 border border-gray-200 rounded-xl p-5 shadow-sm" data-loan-card>
                <div class="flex flex-col gap-4 items-center md:items-start">
                  <div class="flex-shrink-0">
                    <?php $cover = !empty($loan['copertina_url']) ? $loan['copertina_url'] : '/uploads/copertine/placeholder.jpg'; ?>
                    <img
                      src="<?= htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                      alt="<?= App\Support\HtmlHelper::e($loan['titolo'] ?? 'Copertina libro'); ?>"
                      class="w-full md:w-20 h-auto md:h-28 object-cover rounded-lg shadow-sm"
                      onerror="this.src='/uploads/copertine/placeholder.jpg'"
                    >
                  </div>
                  <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-gray-900 mb-1 line-clamp-2">
                      <?= App\Support\HtmlHelper::e($loan['titolo'] ?? ''); ?>
                    </h3>
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
                      <i class="fas fa-user mr-2 text-blue-500"></i>
                      <?= App\Support\HtmlHelper::e($loan['utente_nome'] ?? ''); ?>
                    </p>
                    <?php if (!empty($loan['email'])): ?>
                      <p class="text-sm text-gray-600 flex items-center mt-1">
                        <i class="fas fa-envelope mr-2 text-green-500"></i>
                        <?= App\Support\HtmlHelper::e($loan['email']); ?>
                      </p>
                    <?php endif; ?>
                    <div class="mt-3 grid grid-cols-1 gap-1 text-xs text-gray-500">
                      <?php if (!empty($loan['data_richiesta_inizio'])): ?>
                        <span class="flex items-center">
                          <i class="fas fa-play mr-2 text-green-500"></i>
                          <?= __("Inizio:") ?> <?= date('d-m-Y', strtotime((string)$loan['data_richiesta_inizio'])); ?>
                        </span>
                      <?php endif; ?>
                      <?php if (!empty($loan['data_richiesta_fine'])): ?>
                        <span class="flex items-center">
                          <i class="fas fa-stop mr-2 text-red-500"></i>
                          <?= __("Fine:") ?> <?= date('d-m-Y', strtotime((string)$loan['data_richiesta_fine'])); ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="mt-4 flex flex-col md:flex-row gap-3">
                  <button type="button" class="flex-1 bg-gray-900 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition-colors approve-btn shadow-sm" data-loan-id="<?= (int)$loan['id']; ?>">
                    <i class="fas fa-check mr-2"></i><?= __("Approva") ?>
                  </button>
                  <button type="button" class="flex-1 bg-red-600 hover:bg-red-500 text-white font-medium py-2 px-4 rounded-lg transition-colors reject-btn shadow-sm" data-loan-id="<?= (int)$loan['id']; ?>">
                    <i class="fas fa-times mr-2"></i><?= __("Rifiuta") ?>
                  </button>
                </div>
                <div class="mt-3 text-xs text-gray-400 flex items-center">
                  <i class="fas fa-clock mr-2"></i>
                  <?= __("Richiesto il") ?> <?= !empty($loan['created_at']) ? date('d-m-Y H:i', strtotime((string)$loan['created_at'])) : 'N/D'; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Active Reservations Section -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-gray-200 flex flex-col md:flex-row items-center justify-between gap-4">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-calendar-check text-purple-600 mr-2"></i>
          <?= __("Prenotazioni Attive") ?>
        </h2>
        <a href="/admin/prenotazioni" class="px-3 py-1.5 text-sm bg-purple-600 text-white hover:bg-purple-500 rounded-lg transition-colors duration-200 whitespace-nowrap">
          <i class="fas fa-external-link-alt mr-1"></i>
          <?= __("Gestisci tutte") ?>
        </a>
      </div>
      <div class="p-6">
        <?php if (empty($reservations)): ?>
          <div class="text-center py-8">
            <i class="fas fa-calendar-check text-4xl text-gray-300 mb-4"></i>
            <p class="text-gray-500"><?= __("Nessuna prenotazione attiva") ?></p>
          </div>
        <?php else: ?>
          <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
            <?php foreach ($reservations as $res): ?>
              <div class="flex flex-col bg-purple-50 border border-purple-200 rounded-xl p-5 shadow-sm">
                <div class="flex flex-col gap-4 items-center md:items-start">
                  <div class="flex-shrink-0">
                    <?php $cover = !empty($res['copertina_url']) ? $res['copertina_url'] : '/uploads/copertine/placeholder.jpg'; ?>
                    <img
                      src="<?= htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                      alt="<?= App\Support\HtmlHelper::e($res['titolo'] ?? 'Copertina libro'); ?>"
                      class="w-full md:w-20 h-auto md:h-28 object-cover rounded-lg shadow-sm"
                      onerror="this.src='/uploads/copertine/placeholder.jpg'"
                    >
                  </div>
                  <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-gray-900 mb-1 line-clamp-2">
                      <?= App\Support\HtmlHelper::e($res['titolo'] ?? ''); ?>
                    </h3>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium mb-2 bg-purple-100 text-purple-700">
                      <i class="fas fa-calendar-check text-[10px]"></i>
                      <?= __("Prenotazione") ?>
                    </span>
                    <p class="text-sm text-gray-600 flex items-center">
                      <i class="fas fa-user mr-2 text-purple-500"></i>
                      <?= App\Support\HtmlHelper::e($res['utente_nome'] ?? ''); ?>
                    </p>
                    <?php if (!empty($res['email'])): ?>
                      <p class="text-sm text-gray-600 flex items-center mt-1">
                        <i class="fas fa-envelope mr-2 text-green-500"></i>
                        <?= App\Support\HtmlHelper::e($res['email']); ?>
                      </p>
                    <?php endif; ?>
                    <div class="mt-3 grid grid-cols-1 gap-1 text-xs text-gray-500">
                      <?php
                      $startDate = $res['data_inizio_richiesta'] ?? $res['data_scadenza_prenotazione'];
                      $endDate = $res['data_fine_richiesta'] ?? $res['data_scadenza_prenotazione'];
                      ?>
                      <?php if (!empty($startDate)): ?>
                        <span class="flex items-center">
                          <i class="fas fa-play mr-2 text-green-500"></i>
                          <?= __("Inizio:") ?> <?= date('d-m-Y', strtotime((string)$startDate)); ?>
                        </span>
                      <?php endif; ?>
                      <?php if (!empty($endDate)): ?>
                        <span class="flex items-center">
                          <i class="fas fa-stop mr-2 text-red-500"></i>
                          <?= __("Fine:") ?> <?= date('d-m-Y', strtotime((string)$endDate)); ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="mt-3 text-xs text-gray-400 flex items-center">
                  <i class="fas fa-clock mr-2"></i>
                  <?= __("Creata il") ?> <?= !empty($res['created_at']) ? date('d-m-Y H:i', strtotime((string)$res['created_at'])) : 'N/D'; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Recent Books Section -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-gray-200 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-clock text-gray-600 mr-2"></i>
          <?= __("Ultimi Libri Inseriti") ?>
        </h2>
        <a href="/admin/libri" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200">
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

    <!-- Active Loans Section -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-gray-200 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-handshake text-gray-600 mr-2"></i>
          <?= __("Prestiti in Corso") ?>
        </h2>
        <a href="/admin/prestiti" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200">
          <i class="fas fa-eye mr-1"></i>
          <?= __("Vedi tutti") ?>
        </a>
      </div>
      <div class="p-6">
        <?php if (empty($active)): ?>
          <div class="text-center py-8">
            <i class="fas fa-handshake text-4xl text-gray-300 mb-4"></i>
            <p class="text-gray-500"><?= __("Nessun prestito in corso") ?></p>
          </div>
        <?php else: ?>
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
                      <?php echo App\Support\HtmlHelper::e($p['titolo'] ?? ''); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                      <?php echo App\Support\HtmlHelper::e($p['utente'] ?? ''); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      <?php echo $p['data_prestito'] ? date('d-m-Y', strtotime($p['data_prestito'])) : ''; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      <?php echo $p['data_scadenza'] ? date('d-m-Y', strtotime($p['data_scadenza'])) : ''; ?>
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
        <?php endif; ?>
      </div>
    </div>

    <!-- Overdue Loans Section -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-gray-200 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-exclamation-triangle text-gray-600 mr-2"></i>
          <?= __("Prestiti Scaduti") ?>
        </h2>
        <a href="/admin/prestiti" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200">
          <i class="fas fa-eye mr-1"></i>
          <?= __("Gestisci") ?>
        </a>
      </div>
      <div class="p-6">
        <?php if (empty($overdue)): ?>
          <div class="text-center py-8">
            <i class="fas fa-check-circle text-4xl text-green-400 mb-4"></i>
            <p class="text-gray-500"><?= __("Nessun prestito scaduto") ?></p>
          </div>
        <?php else: ?>
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
                <?php foreach ($overdue as $p): ?>
                  <tr class="bg-red-50 hover:bg-red-100">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                      <?php echo App\Support\HtmlHelper::e($p['titolo'] ?? ''); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                      <?php echo App\Support\HtmlHelper::e($p['utente'] ?? ''); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      <?php echo $p['data_prestito'] ? date('d-m-Y', strtotime($p['data_prestito'])) : ''; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      <?php echo $p['data_scadenza'] ? date('d-m-Y', strtotime($p['data_scadenza'])) : ''; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <?= __("Scaduto") ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
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

/* Responsive design for mobile */
@media (max-width: 768px) {
  .grid-cols-1.md\:grid-cols-2.lg\:grid-cols-4 {
    grid-template-columns: 1fr;
  }
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

document.addEventListener('DOMContentLoaded', function() {
    // Initialize FullCalendar
    const calendarEl = document.getElementById('dashboard-calendar');
    if (calendarEl) {
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            locale: '<?= strtolower(substr(\App\Support\I18n::getLocale(), 0, 2)) ?>',
            headerToolbar: {
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
                                <p><strong><?= __("Dal") ?>:</strong> ${start.toLocaleDateString()}</p>
                                <p><strong><?= __("Al") ?>:</strong> ${end.toLocaleDateString()}</p>
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
            const rawUrl = <?= json_encode($icsUrl ?? '/storage/calendar/library-calendar.ics') ?>;
            const icsUrl = rawUrl.startsWith('http://') || rawUrl.startsWith('https://')
                ? rawUrl
                : window.location.origin + rawUrl;
            navigator.clipboard.writeText(icsUrl).then(() => {
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
            }).catch(() => {
                // Fallback for older browsers
                const textarea = document.createElement('textarea');
                textarea.value = icsUrl;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                alert(<?= json_encode(__("Link copiato!")) ?>);
            });
        });
    }
});
</script>
