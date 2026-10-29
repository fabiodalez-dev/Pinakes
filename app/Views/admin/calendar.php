<?php
/**
 * Admin page: closures calendar (/admin/calendar).
 *
 * @var list<int> $closedWeekdays
 * @var list<array<string, mixed>> $closures
 * @var array<string, mixed>|null $editing
 * @var array<int, string> $branches
 * @var ?int $previewSede
 * @var string $month
 * @var string $prevMonth
 * @var string $nextMonth
 * @var array<string, int> $monthClosed
 * @var int $currentYear
 * @var string $today
 * @var list<array<string, mixed>> $yearlyClosures
 * @var array{countries: array<string, string>, country: string, regions: array<string, string>, region: string, years: array{min: int, max: int}, year: int, list: list<array{id: string, name: string, date: ?string, dates: list<string>, yearly: bool, regional: bool, optional: bool, checked: bool}>, saved: bool, schema: bool} $holidays
 */

$csrf = \App\Support\Csrf::ensureToken();
// Plain escaping: the stored text is shown exactly as saved.
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$calLocale = \App\Support\I18n::getLocale();
$fmt = static function (string $pattern, string $ymd) use ($calLocale): string {
    if (class_exists('IntlDateFormatter')) {
        $f = new \IntlDateFormatter($calLocale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, $pattern);
        $out = $f->format(new \DateTimeImmutable($ymd . ' 12:00:00', new \DateTimeZone('UTC')));
        if (is_string($out) && $out !== '') {
            return $out;
        }
    }
    return $ymd;
};
$displayDate = static function (string $ymd): string {
    return \App\Support\LoanWindow::displayDate($ymd);
};
// 2026-11-01 is a Sunday: index 0 = Sunday … 6 = Saturday, as closed_weekdays.
$weekdayNames = [];
for ($w = 0; $w <= 6; $w++) {
    $weekdayNames[$w] = mb_convert_case($fmt('EEEE', \App\Support\LibraryCalendar::shift('2026-11-01', $w)), MB_CASE_TITLE, 'UTF-8');
}
$editingId = $editing !== null ? (int) $editing['id'] : 0;
$formAction = $editingId > 0 ? url('/admin/calendar/closures/' . $editingId . '/update') : url('/admin/calendar/closures');
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <?php
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-3xl font-bold text-gray-900 mb-2 flex items-center gap-3"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?>

            <?= __("Calendario chiusure") ?>
          <?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>

          <p class="text-gray-600"><?= __("Giorni in cui la biblioteca è chiusa: le scadenze che vi cadono passano al primo giorno di apertura e i giorni per il ritiro contano solo i giorni di apertura.") ?></p>
    <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?><nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
      <ol>
        <li>
          <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Home') ?></a>
        </li>
        <li>
          <a href="<?= htmlspecialchars(url('/admin/settings') . '?tab=loans', ENT_QUOTES, 'UTF-8') ?>"><?= __('Prestiti') ?></a>
        </li>
        <li aria-current="page">
            <?= __("Calendario chiusure") ?>
        </li>
      </ol>
    </nav><?php
$workspaceHeader['breadcrumb'] = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Weekly closed days -->
      <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6 slide-in-up" id="calendar-weekdays">
        <div class="p-6 border-b border-gray-200 flex items-center justify-between pk-stack-mobile">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-calendar-week text-gray-600"></i>
            <?= __("Chiusura settimanale") ?>
          </h2>
        </div>
        <div class="p-6">
          <form method="POST" action="<?= htmlspecialchars(url('/admin/calendar/weekdays'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <p class="text-sm text-gray-600"><?= __("Seleziona i giorni della settimana in cui la biblioteca è sempre chiusa.") ?></p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $w): ?>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                  <input type="checkbox" name="closed_weekdays[]" value="<?= $w ?>" id="closed_weekday_<?= $w ?>"
                         <?= in_array($w, $closedWeekdays, true) ? 'checked' : '' ?>>
                  <span><?= $e($weekdayNames[$w]) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="flex justify-end pt-4 border-t border-gray-200">
              <button type="submit" id="save-weekdays" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center">
                <i class="fas fa-save mr-2"></i>
                <?= __("Salva") ?>
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Add / edit a closure -->
      <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6 slide-in-up" id="calendar-closure-form">
        <div class="p-6 border-b border-gray-200 flex items-center justify-between pk-stack-mobile">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-<?= $editingId > 0 ? 'edit' : 'plus' ?> text-gray-600"></i>
            <?= $editingId > 0 ? __("Modifica chiusura") : __("Aggiungi chiusura") ?>
          </h2>
          <?php if ($editingId > 0): ?>
            <a href="<?= htmlspecialchars(url('/admin/calendar'), ENT_QUOTES, 'UTF-8') ?>" class="text-sm text-gray-600 hover:text-gray-800"><?= __("Annulla") ?></a>
          <?php endif; ?>
        </div>
        <div class="p-6">
          <form method="POST" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label for="closure_start" class="form-label"><i class="fas fa-calendar mr-1"></i><?= __("Data inizio") ?> *</label>
                <input type="date" id="closure_start" name="data_inizio" class="form-input" required data-no-flatpickr
                       value="<?= $e((string) ($editing['data_inizio'] ?? '')) ?>">
              </div>
              <div>
                <label for="closure_end" class="form-label"><i class="fas fa-calendar mr-1"></i><?= __("Data fine") ?></label>
                <input type="date" id="closure_end" name="data_fine" class="form-input" data-no-flatpickr
                       value="<?= $e((string) ($editing['data_fine'] ?? '')) ?>">
              </div>
            </div>
            <div>
              <label for="closure_description" class="form-label"><i class="fas fa-tag mr-1"></i><?= __("Descrizione") ?></label>
              <input type="text" id="closure_description" name="descrizione" maxlength="255" class="form-input"
                     placeholder="<?= htmlspecialchars(__('es. Chiusura estiva'), ENT_QUOTES, 'UTF-8') ?>"
                     value="<?= $e((string) ($editing['descrizione'] ?? '')) ?>">
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label for="closure_branch" class="form-label"><i class="fas fa-building mr-1"></i><?= __("Sede") ?></label>
                <select id="closure_branch" name="sede_id" class="form-input">
                  <option value=""><?= __("Tutte le sedi") ?></option>
                  <?php foreach ($branches as $branchId => $branchName): ?>
                    <option value="<?= (int) $branchId ?>" <?= (int) ($editing['sede_id'] ?? 0) === (int) $branchId ? 'selected' : '' ?>><?= $e($branchName) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="flex items-end">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                  <input type="checkbox" id="closure_yearly" name="ricorrente_annuale" value="1" <?= !empty($editing['ricorrente_annuale']) ? 'checked' : '' ?>>
                  <span><?= __("Si ripete ogni anno") ?></span>
                </label>
              </div>
            </div>
            <div class="flex justify-end pt-4 border-t border-gray-200">
              <button type="submit" id="save-closure" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center">
                <i class="fas fa-save mr-2"></i>
                <?= $editingId > 0 ? __("Salva modifiche") : __("Aggiungi chiusura") ?>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- National holidays: country, region, the holidays to apply -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6 slide-in-up" id="calendar-preset">
      <div class="p-6 border-b border-gray-200 flex items-center justify-between pk-stack-mobile">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-flag text-gray-600"></i>
          <?= __("Festività nazionali") ?>
        </h2>
      </div>
      <div class="p-6 space-y-4">
        <p class="text-sm text-gray-600"><?= __("Scegli il paese (e la regione, dove serve) e spunta le festività in cui la biblioteca è chiusa. Le festività fisse diventano chiusure annuali, quelle mobili sono ricalcolate ogni anno. Togliere la spunta rimuove solo quella festività; le chiusure aggiunte a mano o da file ICS non vengono toccate.") ?></p>
        <p class="text-sm text-gray-600"><?= __("Le date sono calcolate dalle regole ufficiali note; la biblioteca sceglie quali applicare e può sempre aggiungere chiusure a mano.") ?></p>
        <form method="GET" action="<?= htmlspecialchars(url('/admin/calendar'), ENT_QUOTES, 'UTF-8') ?>" id="holiday-filter" class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label for="holiday_country" class="form-label"><i class="fas fa-globe mr-1"></i><?= __("Paese") ?></label>
            <select id="holiday_country" name="holiday_country" class="form-input" onchange="if (this.form.holiday_region) { this.form.holiday_region.value = ''; } this.form.submit()">
              <?php foreach ($holidays['countries'] as $code => $countryName): ?>
                <option value="<?= $e($code) ?>" <?= $code === $holidays['country'] ? 'selected' : '' ?>><?= $e($countryName) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($holidays['regions'] !== []): ?>
            <div>
              <label for="holiday_region" class="form-label"><i class="fas fa-map-marker-alt mr-1"></i><?= __("Regione") ?></label>
              <select id="holiday_region" name="holiday_region" class="form-input" onchange="this.form.submit()">
                <?php if (empty(\App\Support\Holidays\HolidayCalculator::country($holidays['country'])['region_required'])): ?>
                  <option value=""><?= __("Solo festività nazionali") ?></option>
                <?php endif; ?>
                <?php foreach ($holidays['regions'] as $code => $regionName): ?>
                  <option value="<?= $e($code) ?>" <?= $code === $holidays['region'] ? 'selected' : '' ?>><?= $e($regionName) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
          <div>
            <label for="holiday_year" class="form-label"><i class="fas fa-calendar-alt mr-1"></i><?= __("Anno di anteprima") ?></label>
            <input type="number" id="holiday_year" name="holiday_year" class="form-input" min="<?= (int) $holidays['years']['min'] ?>" max="<?= (int) $holidays['years']['max'] ?>" step="1" value="<?= (int) $holidays['year'] ?>" onchange="if (this.checkValidity()) this.form.submit()">
          </div>
          <noscript>
            <button type="submit" class="inline-flex items-center px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200"><?= __("Aggiorna anteprima") ?></button>
          </noscript>
        </form>

        <form method="POST" action="<?= htmlspecialchars(url('/admin/calendar/preset'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-4" id="holiday-apply">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="country" value="<?= $e($holidays['country']) ?>">
          <input type="hidden" name="region" value="<?= $e($holidays['region']) ?>">
          <div class="flex items-center gap-4 text-sm">
            <button type="button" class="text-sm text-gray-600 hover:text-gray-800" data-holiday-select="all"><?= __("Seleziona tutte") ?></button>
            <button type="button" class="text-sm text-gray-600 hover:text-gray-800" data-holiday-select="none"><?= __("Nessuna") ?></button>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="holiday-list">
            <?php foreach ($holidays['list'] as $holiday):
                $boxId = 'holiday_rule_' . preg_replace('/[^A-Za-z0-9_]/', '_', $holiday['id']); ?>
              <label class="flex items-center gap-2 text-sm text-gray-700" data-holiday-row="<?= $e($holiday['id']) ?>">
                <input type="checkbox" name="rules[]" value="<?= $e($holiday['id']) ?>" id="<?= $e($boxId) ?>" data-holiday-rule
                       <?= $holiday['checked'] ? 'checked' : '' ?>>
                <span><?= $e($holiday['name']) ?></span>
                <span class="text-gray-500" data-holiday-date="<?= $e((string) $holiday['date']) ?>"><?= $holiday['date'] !== null ? $e($fmt('EEE d MMMM y', $holiday['date'])) : $e(__('non prevista nel %d', $holidays['year'])) ?></span>
                <?php foreach (array_diff($holiday['dates'], [(string) $holiday['date']]) as $holidayActual): ?>
                  <span class="text-gray-500" data-holiday-actual="<?= $e($holidayActual) ?>"><?= $e(sprintf(__('chiusa anche %s'), $fmt('EEE d MMMM y', $holidayActual))) ?></span>
                <?php endforeach; ?>
                <?php if ($holiday['yearly']): ?>
                  <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded-full"><?= __("ogni anno") ?></span>
                <?php else: ?>
                  <span class="px-2 py-1 bg-gray-100 text-gray-800 text-xs rounded-full"><?= __("mobile") ?></span>
                <?php endif; ?>
                <?php if ($holiday['regional']): ?>
                  <span class="px-2 py-1 bg-purple-100 text-purple-800 text-xs rounded-full"><?= __("regionale") ?></span>
                <?php endif; ?>
                <?php if ($holiday['optional']): ?>
                  <span class="px-2 py-1 bg-yellow-100 text-yellow-800 text-xs rounded-full"><?= __("facoltativa") ?></span>
                <?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="flex justify-end pt-4 border-t border-gray-200">
            <button type="submit" id="import-preset" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center">
              <i class="fas fa-check mr-2"></i>
              <?= __("Applica festività selezionate") ?>
            </button>
          </div>
        </form>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Yearly closures added by hand: on / off without deleting them -->
      <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6 slide-in-up" id="calendar-yearly">
        <div class="p-6 border-b border-gray-200 flex items-center justify-between pk-stack-mobile">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-redo text-gray-600"></i>
            <?= __("Chiusure annuali") ?>
          </h2>
        </div>
        <div class="p-6">
          <form method="POST" action="<?= htmlspecialchars(url('/admin/calendar/yearly'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <p class="text-sm text-gray-600"><?= __("Le chiusure che si ripetono ogni anno, aggiunte a mano o da file ICS. Togli la spunta per sospenderne una senza eliminarla.") ?></p>
            <?php if ($yearlyClosures === []): ?>
              <p class="text-sm text-gray-500" id="calendar-yearly-empty"><?= __("Nessuna chiusura annuale aggiunta a mano.") ?></p>
            <?php else: ?>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach ($yearlyClosures as $yearly):
                    $yid = (int) $yearly['id']; ?>
                  <label class="flex items-center gap-2 text-sm text-gray-700" data-yearly-row="<?= $yid ?>">
                    <input type="hidden" name="listed[]" value="<?= $yid ?>">
                    <input type="checkbox" name="active[]" value="<?= $yid ?>" id="yearly_closure_<?= $yid ?>"
                           <?= (int) $yearly['attiva'] === 1 ? 'checked' : '' ?>>
                    <span><?= $e((string) $yearly['descrizione'] !== '' ? (string) $yearly['descrizione'] : __('Chiusura')) ?></span>
                    <span class="text-gray-500"><?= $e($fmt('d MMMM', (string) $yearly['data_inizio'])) ?><?php if ($yearly['data_fine'] !== $yearly['data_inizio']): ?> – <?= $e($fmt('d MMMM', (string) $yearly['data_fine'])) ?><?php endif; ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <div class="flex justify-end pt-4 border-t border-gray-200">
              <button type="submit" id="save-yearly" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center" <?= $yearlyClosures === [] ? 'disabled' : '' ?>>
                <i class="fas fa-save mr-2"></i>
                <?= __("Salva") ?>
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- ICS import -->
      <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6 slide-in-up" id="calendar-ics">
        <div class="p-6 border-b border-gray-200 flex items-center justify-between pk-stack-mobile">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-file-upload text-gray-600"></i>
            <?= __("Importa da file ICS") ?>
          </h2>
        </div>
        <div class="p-6">
          <form method="POST" action="<?= htmlspecialchars(url('/admin/calendar/import-ics'), ENT_QUOTES, 'UTF-8') ?>" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <p class="text-sm text-gray-600"><?= __("Ogni evento del calendario diventa un giorno o un periodo di chiusura; gli eventi annuali (RRULE FREQ=YEARLY) si ripetono ogni anno.") ?></p>
            <div>
              <label for="ics_file" class="form-label"><i class="fas fa-file mr-1"></i><?= __("File ICS") ?></label>
              <input type="file" id="ics_file" name="ics_file" accept=".ics,text/calendar" class="form-input" required>
            </div>
            <div>
              <label for="ics_branch" class="form-label"><i class="fas fa-building mr-1"></i><?= __("Sede") ?></label>
              <select id="ics_branch" name="sede_id" class="form-input">
                <option value=""><?= __("Tutte le sedi") ?></option>
                <?php foreach ($branches as $branchId => $branchName): ?>
                  <option value="<?= (int) $branchId ?>"><?= $e($branchName) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="flex justify-end pt-4 border-t border-gray-200">
              <button type="submit" id="import-ics" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center">
                <i class="fas fa-upload mr-2"></i>
                <?= __("Importa ICS") ?>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Monthly preview -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6 slide-in-up" id="calendar-preview">
      <div class="p-6 border-b border-gray-200 flex items-center justify-between pk-stack-mobile">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-calendar-alt text-gray-600"></i>
          <span id="calendar-preview-title"><?= $e(mb_convert_case($fmt('LLLL y', $month . '-01'), MB_CASE_TITLE, 'UTF-8')) ?></span>
        </h2>
        <div class="flex items-center gap-2 pk-stack-mobile">
          <?php $sedeQuery = $previewSede !== null ? '&sede=' . $previewSede : ''; ?>
          <a href="<?= htmlspecialchars(url('/admin/calendar') . '?month=' . $prevMonth . $sedeQuery, ENT_QUOTES, 'UTF-8') ?>" id="calendar-prev" class="inline-flex items-center px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200" aria-label="<?= htmlspecialchars(__('Mese precedente'), ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-chevron-left"></i></a>
          <a href="<?= htmlspecialchars(url('/admin/calendar') . '?month=' . $nextMonth . $sedeQuery, ENT_QUOTES, 'UTF-8') ?>" id="calendar-next" class="inline-flex items-center px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200" aria-label="<?= htmlspecialchars(__('Mese successivo'), ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-chevron-right"></i></a>
          <?php if ($branches !== []): ?>
            <form method="GET" action="<?= htmlspecialchars(url('/admin/calendar'), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="month" value="<?= $e($month) ?>">
              <select name="sede" class="form-input" onchange="this.form.submit()" aria-label="<?= htmlspecialchars(__('Sede'), ENT_QUOTES, 'UTF-8') ?>">
                <option value=""><?= __("Tutte le sedi") ?></option>
                <?php foreach ($branches as $branchId => $branchName): ?>
                  <option value="<?= (int) $branchId ?>" <?= $previewSede === (int) $branchId ? 'selected' : '' ?>><?= $e($branchName) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <div class="p-6">
        <div class="grid grid-cols-7 gap-1 text-center text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">
          <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $w): ?>
            <div><?= $e(mb_substr($weekdayNames[$w], 0, 3)) ?></div>
          <?php endforeach; ?>
        </div>
        <div class="grid grid-cols-7 gap-1 text-center text-sm">
          <?php
          $firstDay = $month . '-01';
          $lead = ((int) (new \DateTimeImmutable($firstDay, new \DateTimeZone('UTC')))->format('N')) - 1; // Monday first
          for ($i = 0; $i < $lead; $i++): ?>
            <div></div>
          <?php endfor;
          $day = $firstDay;
          while (substr($day, 0, 7) === $month):
              $isClosed = isset($monthClosed[$day]); ?>
            <div class="p-2 rounded-lg border <?= $isClosed ? 'bg-red-50 border-red-200 text-red-700 font-semibold' : 'bg-green-50 border-green-200 text-green-700' ?><?= $day === $today ? ' ring-2 ring-gray-500' : '' ?>"
                 data-calendar-day="<?= $e($day) ?>" data-closed="<?= $isClosed ? '1' : '0' ?>"
                 title="<?= htmlspecialchars($isClosed ? __('Chiuso') : __('Aperto'), ENT_QUOTES, 'UTF-8') ?>">
              <?= (int) substr($day, 8, 2) ?>
            </div>
          <?php $day = \App\Support\LibraryCalendar::shift($day, 1);
          endwhile; ?>
        </div>
        <div class="flex items-center gap-4 mt-4 text-xs text-gray-600">
          <span class="flex items-center gap-2"><span class="w-3 h-3 rounded bg-green-50 border border-green-200"></span><?= __("Aperto") ?></span>
          <span class="flex items-center gap-2"><span class="w-3 h-3 rounded bg-red-50 border border-red-200"></span><?= __("Chiuso") ?></span>
        </div>
      </div>
    </div>

    <!-- Closures list -->
    <div class="card" id="calendar-closures">
      <div class="card-header flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900">
          <?= __("Chiusure") ?>
          <span class="ml-2 text-sm font-normal text-gray-500">(<?= count($closures) ?>)</span>
        </h2>
      </div>
      <div class="card-body p-0">
        <?php if ($closures === []): ?>
          <div class="p-8 text-center text-gray-500">
            <i class="fas fa-calendar-check text-6xl mb-4 text-gray-300"></i>
            <p class="text-lg mb-2"><?= __("Nessuna chiusura configurata") ?></p>
            <p class="text-sm"><?= __("Aggiungi una chiusura o importa le festività nazionali.") ?></p>
          </div>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Periodo") ?></th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Descrizione") ?></th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Ricorrenza") ?></th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Sede") ?></th>
                  <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Azioni") ?></th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($closures as $closure):
                    $cid = (int) $closure['id'];
                    $yearlyRow = (int) $closure['ricorrente_annuale'] === 1;
                    $presetRow = ($closure['regola_festivita'] ?? null) !== null;
                    $activeRow = (int) ($closure['attiva'] ?? 1) === 1;
                    $startLabel = $yearlyRow ? $fmt('d MMMM', (string) $closure['data_inizio']) : $displayDate((string) $closure['data_inizio']);
                    $endLabel = $yearlyRow ? $fmt('d MMMM', (string) $closure['data_fine']) : $displayDate((string) $closure['data_fine']); ?>
                  <tr class="hover:bg-gray-50" data-closure-id="<?= $cid ?>">
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                      <?= $e($startLabel) ?><?php if ($closure['data_fine'] !== $closure['data_inizio']): ?> – <?= $e($endLabel) ?><?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-700">
                      <?= $e((string) $closure['descrizione']) ?>
                      <?php if ($presetRow): ?>
                        <span class="ml-2 px-2 py-1 bg-purple-100 text-purple-800 text-xs rounded-full" data-badge="holiday"><i class="fas fa-flag mr-1"></i><?= __("Festività") ?></span>
                      <?php endif; ?>
                      <?php if (!$activeRow): ?>
                        <span class="ml-2 px-2 py-1 bg-gray-100 text-gray-800 text-xs rounded-full" data-badge="inactive"><?= __("Sospesa") ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                      <?php if ($yearlyRow): ?>
                        <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded-full"><i class="fas fa-redo mr-1"></i><?= __("Ogni anno") ?></span>
                      <?php else: ?>
                        <span class="text-gray-500"><?= __("Una volta") ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?= $e($closure['sede_nome'] !== null ? (string) $closure['sede_nome'] : __('Tutte le sedi')) ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                      <?php if ($presetRow): ?>
                        <span class="text-gray-500 text-xs"><?= __("Dalla selezione delle festività") ?></span>
                      <?php else: ?>
                      <div class="inline-flex items-center gap-2">
                        <a href="<?= htmlspecialchars(url('/admin/calendar') . '?edit=' . $cid . '#calendar-closure-form', ENT_QUOTES, 'UTF-8') ?>"
                           class="btn-icon btn-secondary" data-action="edit-closure"
                           title="<?= htmlspecialchars(__('Modifica'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(__('Modifica'), ENT_QUOTES, 'UTF-8') ?>">
                          <i class="fas fa-edit"></i>
                        </a>
                        <form method="POST" action="<?= htmlspecialchars(url('/admin/calendar/closures/' . $cid . '/delete'), ENT_QUOTES, 'UTF-8') ?>" class="inline"
                              data-swal-confirm="<?= htmlspecialchars(__('Eliminare questa chiusura?'), ENT_QUOTES, 'UTF-8') ?>"
                              data-swal-confirm-title="<?= htmlspecialchars(__('Conferma eliminazione'), ENT_QUOTES, 'UTF-8') ?>"
                              data-swal-confirm-button="<?= htmlspecialchars(__('Elimina'), ENT_QUOTES, 'UTF-8') ?>">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                          <button type="submit" class="btn-icon btn-danger" data-action="delete-closure"
                                  title="<?= htmlspecialchars(__('Elimina'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(__('Elimina'), ENT_QUOTES, 'UTF-8') ?>">
                            <i class="fas fa-trash"></i>
                          </button>
                        </form>
                      </div>
                      <?php endif; ?>
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
<script>
document.querySelectorAll('[data-holiday-select]').forEach(function (button) {
  button.addEventListener('click', function () {
    var on = button.getAttribute('data-holiday-select') === 'all';
    document.querySelectorAll('input[data-holiday-rule]').forEach(function (box) { box.checked = on; });
  });
});
</script>
