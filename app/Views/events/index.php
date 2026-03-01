<?php
/** @var bool $eventsEnabled */
/** @var int $totalPages */
/** @var int $page */
use App\Support\HtmlHelper;
use App\Support\Csrf;

$locale = $_SESSION['locale'] ?? 'it_IT';
if (class_exists('IntlDateFormatter')) {
    $dateFormatter = new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
    $timeFormatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT);
} else {
    $dateFormatter = null;
    $timeFormatter = null;
}

$createDateTime = static function (?string $value, array $formats = []) {
  if (!$value) {
    return null;
  }

  foreach ($formats as $format) {
    $dateTime = \DateTime::createFromFormat($format, $value);
    if ($dateTime instanceof \DateTimeInterface) {
      return $dateTime;
    }
  }

  try {
    return new \DateTime($value);
  } catch (\Exception $e) {
    return null;
  }
};

$fallbackDateFormat = match (strtolower(substr($locale, 0, 2))) {
    'de' => 'd.m.Y',
    'it' => 'd/m/Y',
    default => 'Y-m-d',
};

$formatEventDate = static function (?string $value) use ($dateFormatter, $createDateTime, $fallbackDateFormat) {
  $dateTime = $createDateTime($value, ['Y-m-d']);
  if (!$dateTime) {
    return (string) $value;
  }

  if ($dateFormatter) {
      $formatted = $dateFormatter->format($dateTime);
      if ($formatted !== false) {
          return $formatted;
      }
  }
  return $dateTime->format($fallbackDateFormat);
};

$formatEventTime = static function (?string $value) use ($timeFormatter, $createDateTime) {
  $dateTime = $createDateTime($value, ['H:i:s', 'H:i']);
  if (!$dateTime) {
    return (string) $value;
  }

  if ($timeFormatter) {
      $formatted = $timeFormatter->format($dateTime);
      if ($formatted !== false) {
          return $formatted;
      }
  }
  return $dateTime->format('H:i');
};
?>

<div class="min-h-screen bg-gray-50 py-8">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

    <!-- Header -->
    <div class="mb-6">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
            <i class="fas fa-calendar-alt text-purple-600"></i>
            <?= __("Gestione Eventi") ?>
          </h1>
          <p class="mt-2 text-sm text-gray-600">
            <?= __("Crea e gestisci gli eventi della biblioteca") ?>
          </p>
        </div>
        <div>
          <a href="<?= htmlspecialchars(url('/admin/cms/events/create'), ENT_QUOTES, 'UTF-8') ?>"
            class="inline-flex items-center gap-2 px-5 py-3 bg-gray-900 text-white rounded-xl hover:bg-gray-700 transition-colors font-semibold shadow-sm">
            <i class="fas fa-plus"></i>
            <?= __("Nuovo Evento") ?>
          </a>
        </div>
      </div>
    </div>

    <!-- Visibility Toggle Card -->
    <div class="mb-6 bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
      <form action="<?= htmlspecialchars(url('/admin/cms/events/toggle-visibility'), ENT_QUOTES, 'UTF-8') ?>" method="post" id="visibilityForm">
        <input type="hidden" name="csrf_token"
          value="<?= \App\Support\HtmlHelper::e(\App\Support\Csrf::ensureToken()) ?>">

        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
          <div class="flex-1">
            <div class="flex items-center gap-3 mb-2">
              <div
                class="w-12 h-12 rounded-xl <?= $eventsEnabled ? 'bg-green-100' : 'bg-gray-100' ?> flex items-center justify-center">
                <i
                  class="fas <?= $eventsEnabled ? 'fa-eye text-green-600' : 'fa-eye-slash text-gray-400' ?> text-xl"></i>
              </div>
              <div>
                <h3 class="text-lg font-semibold text-gray-900">
                  <?= __("Visibilità Sezione Eventi") ?>
                </h3>
                <p class="text-sm <?= $eventsEnabled ? 'text-green-600 font-medium' : 'text-gray-500' ?>">
                  <?= $eventsEnabled ? __("Abilitata - Visibile nel frontend") : __("Disabilitata - Nascosta nel frontend") ?>
                </p>
              </div>
            </div>
            <p class="text-sm text-gray-600 mt-2">
              <?= __("Quando la sezione è abilitata, il menu Eventi e tutte le pagine eventi saranno visibili agli utenti nel frontend. Quando è disabilitata, tutto sarà nascosto.") ?>
            </p>
          </div>

          <div class="flex items-center gap-4">
            <label class="flex items-center gap-3 cursor-pointer">
              <input type="checkbox" name="events_enabled" value="1" <?= $eventsEnabled ? 'checked' : '' ?>
                class="toggle-checkbox sr-only" onchange="document.getElementById('visibilityForm').submit()">
              <div class="toggle-switch">
                <div class="toggle-slider"></div>
              </div>
              <span class="text-sm font-medium text-gray-700">
                <?= $eventsEnabled ? __("Abilitata") : __("Disabilitata") ?>
              </span>
            </label>
          </div>
        </div>
      </form>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
      <div class="mb-6 bg-green-50 border border-green-200 rounded-xl p-4 flex items-start gap-3">
        <i class="fas fa-check-circle text-green-600 text-xl mt-0.5"></i>
        <div class="flex-1">
          <p class="text-sm text-green-800 font-medium"><?= HtmlHelper::e($_SESSION['success_message']) ?></p>
        </div>
        <button onclick="this.parentElement.remove()" class="text-green-600 hover:text-green-800">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
      <div class="mb-6 bg-red-50 border border-red-200 rounded-xl p-4 flex items-start gap-3">
        <i class="fas fa-exclamation-circle text-red-600 text-xl mt-0.5"></i>
        <div class="flex-1">
          <p class="text-sm text-red-800 font-medium"><?= HtmlHelper::e($_SESSION['error_message']) ?></p>
        </div>
        <button onclick="this.parentElement.remove()" class="text-red-600 hover:text-red-800">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <!-- Events List -->
    <?php if (empty($events)): ?>
      <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-12 text-center">
        <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <i class="fas fa-calendar-alt text-gray-400 text-3xl"></i>
        </div>
        <h3 class="text-lg font-semibold text-gray-900 mb-2"><?= __("Nessun evento") ?></h3>
        <p class="text-gray-600 mb-6">
          <?= __("Non hai ancora creato nessun evento. Inizia creando il tuo primo evento.") ?></p>
        <a href="<?= htmlspecialchars(url('/admin/cms/events/create'), ENT_QUOTES, 'UTF-8') ?>"
          class="inline-flex items-center gap-2 px-5 py-3 bg-gray-900 text-white rounded-xl hover:bg-gray-700 transition-colors font-semibold">
          <i class="fas fa-plus"></i>
          <?= __("Crea il tuo primo evento") ?>
        </a>
      </div>
    <?php else: ?>
      <div class="space-y-4">
        <?php foreach ($events as $event): ?>
          <div class="bg-white rounded-2xl shadow-sm border border-gray-200 hover:shadow-md transition-shadow">
            <div class="p-6">
              <div class="flex flex-col md:flex-row gap-6">

                <!-- Event Image -->
                <div class="flex-shrink-0">
                  <?php if ($event['featured_image']): ?>
                    <img src="<?= htmlspecialchars(url($event['featured_image']), ENT_QUOTES, 'UTF-8') ?>" alt="<?= HtmlHelper::e($event['title']) ?>"
                      class="w-full md:w-48 h-48 object-cover rounded-xl">
                  <?php else: ?>
                    <div class="w-full md:w-48 h-48 bg-gray-100 rounded-xl flex items-center justify-center">
                      <i class="fas fa-calendar-alt text-gray-400 text-4xl"></i>
                    </div>
                  <?php endif; ?>
                </div>

                <!-- Event Info -->
                <div class="flex-1 min-w-0">
                  <div class="flex items-start justify-between gap-4 mb-3">
                    <div class="flex-1">
                      <h3 class="text-xl font-bold text-gray-900 mb-2">
                        <?= HtmlHelper::e($event['title']) ?>
                      </h3>
                      <div class="flex flex-wrap items-center gap-4 text-sm text-gray-600">
                        <div class="flex items-center gap-2">
                          <i class="fas fa-calendar text-gray-400"></i>
                          <span><?= HtmlHelper::e($formatEventDate($event['event_date'] ?? '')) ?></span>
                        </div>
                        <?php if ($event['event_time']): ?>
                          <div class="flex items-center gap-2">
                            <i class="fas fa-clock text-gray-400"></i>
                            <span><?= HtmlHelper::e($formatEventTime($event['event_time'] ?? '')) ?></span>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="flex-shrink-0">
                      <?php if ($event['is_active']): ?>
                        <span
                          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-green-100 text-green-800 text-xs font-semibold">
                          <i class="fas fa-check-circle"></i>
                          <?= __("Attivo") ?>
                        </span>
                      <?php else: ?>
                        <span
                          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-100 text-gray-800 text-xs font-semibold">
                          <i class="fas fa-eye-slash"></i>
                          <?= __("Nascosto") ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>

                  <!-- Action Buttons -->
                  <div class="flex flex-wrap items-center gap-3 mt-4">
                    <a href="<?= htmlspecialchars(url('/admin/cms/events/edit/' . (int)$event['id']), ENT_QUOTES, 'UTF-8') ?>"
                      class="inline-flex items-center gap-2 px-4 py-2 bg-gray-900 text-white rounded-lg hover:bg-gray-700 transition-colors text-sm font-semibold">
                      <i class="fas fa-edit"></i>
                      <?= __("Modifica") ?>
                    </a>
                    <a href="<?= htmlspecialchars(url('/events/' . ($event['slug'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" target="_blank"
                      class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm font-semibold">
                      <i class="fas fa-external-link-alt"></i>
                      <?= __("Visualizza") ?>
                    </a>
                    <button
                      onclick="confirmDelete(<?= (int)$event['id'] ?>, <?= htmlspecialchars(json_encode($event['title']), ENT_QUOTES, 'UTF-8') ?>)"
                      class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-red-300 text-red-700 rounded-lg hover:bg-red-50 transition-colors text-sm font-semibold">
                      <i class="fas fa-trash"></i>
                      <?= __("Elimina") ?>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <div class="mt-8 flex items-center justify-center gap-2">
          <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>"
              class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-semibold">
              <i class="fas fa-chevron-left"></i>
              <?= __("Precedente") ?>
            </a>
          <?php endif; ?>

          <div class="flex items-center gap-1">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
              <?php if ($i == $page): ?>
                <span class="px-4 py-2 bg-gray-900 text-white rounded-lg font-semibold">
                  <?= $i ?>
                </span>
              <?php elseif ($i == 1 || $i == $totalPages || abs($i - $page) <= 2): ?>
                <a href="?page=<?= $i ?>"
                  class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-semibold">
                  <?= $i ?>
                </a>
              <?php elseif (abs($i - $page) == 3): ?>
                <span class="px-2 text-gray-400">...</span>
              <?php endif; ?>
            <?php endfor; ?>
          </div>

          <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>"
              class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-semibold">
              <?= __("Successivo") ?>
              <i class="fas fa-chevron-right"></i>
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Back to Settings -->
    <div class="mt-8">
      <a href="<?= htmlspecialchars(url('/admin/settings?tab=cms'), ENT_QUOTES, 'UTF-8') ?>"
        class="inline-flex items-center gap-2 text-gray-600 hover:text-gray-900 transition-colors text-sm font-semibold">
        <i class="fas fa-arrow-left"></i>
        <?= __("Torna alle Impostazioni CMS") ?>
      </a>
    </div>

  </div>
</div>

<style>
  /* Toggle Switch Styles */
  .toggle-switch {
    position: relative;
    width: 60px;
    height: 32px;
    background-color: #d1d5db;
    border-radius: 9999px;
    transition: background-color 0.3s ease;
  }

  .toggle-checkbox:checked+.toggle-switch {
    background-color: #10b981;
  }

  .toggle-slider {
    position: absolute;
    top: 3px;
    left: 3px;
    width: 26px;
    height: 26px;
    background-color: white;
    border-radius: 9999px;
    transition: transform 0.3s ease;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
  }

  .toggle-checkbox:checked+.toggle-switch .toggle-slider {
    transform: translateX(28px);
  }

  .toggle-checkbox:focus+.toggle-switch {
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
  }
</style>

<script>
  function confirmDelete(eventId, eventTitle) {
    if (confirm(<?= json_encode(__("Sei sicuro di voler eliminare l'evento"), JSON_HEX_TAG) ?> + ' "' + eventTitle + '"?\n\n' + <?= json_encode(__("Questa azione non può essere annullata."), JSON_HEX_TAG) ?>)) {
      // Usa form POST per operazioni di eliminazione (sicurezza OWASP)
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = window.BASE_PATH + '/admin/cms/events/delete/' + parseInt(eventId, 10);

      const csrfInput = document.createElement('input');
      csrfInput.type = 'hidden';
      csrfInput.name = 'csrf_token';
      csrfInput.value = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      form.appendChild(csrfInput);

      document.body.appendChild(form);
      form.submit();
    }
  }
</script>