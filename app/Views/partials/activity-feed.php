<?php
/**
 * Shared activity timeline for the admin dashboard and admin book detail.
 *
 * @var array{items:list<array<string,mixed>>,page:int,pages:int,total:int} $activityFeed
 * @var 'dashboard'|'book' $activityContext
 * @var string $activityBaseUrl
 * @var string $activityPageParam
 * @var array{activity_type:string,activity_operator:int,activity_q:string} $activityFilters
 * @var list<array{id:int,name:string}> $activityOperators
 */
use App\Support\ActivityLog;
use App\Support\HtmlHelper;

$isDashboardActivity = $activityContext === 'dashboard';

$renderActivityValue = static function (mixed $value, string $field): string {
    if ($value === null || $value === '') {
        return __('Non impostato');
    }
    if ($field === 'attivo' && in_array($value, [true, false, 1, 0, '1', '0'], true)) {
        return in_array($value, [true, 1, '1'], true) ? __('Sì') : __('No');
    }
    if (is_string($value)) {
        $label = ActivityLog::valueLabel($field, $value);
        if ($label !== null) {
            return __($label);
        }
    }
    if (is_array($value)) {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return (string) $value;
};

$activityPageUrl = static function (int $page) use ($activityBaseUrl, $activityPageParam, $activityFilters): string {
    $query = [];
    foreach ($activityFilters as $key => $value) {
        if ($value !== '' && $value !== 0) {
            $query[$key] = $value;
        }
    }
    $query[$activityPageParam] = $page;
    return url($activityBaseUrl) . '?' . http_build_query($query);
};
?>
<style>
  /* Explicit, id-scoped CSS on purpose: a brand-new Tailwind utility here would
     not exist in the compiled main.css without a frontend rebuild (JIT). */
  #activity-feed .activity-scroll { max-height: 32rem; overflow-y: auto; }
  /* 4-column filter row ≥640px: sm:grid-cols-4 is not in the compiled CSS. */
  @media (min-width: 640px) {
    #activity-feed .activity-filter-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  }
</style>
<div class="<?= $isDashboardActivity ? 'mb-8' : 'mt-6' ?>" id="activity-feed">
  <div class="card bg-white rounded-xl border border-gray-200 shadow-sm">
    <div class="card-header p-6 border-b border-gray-200 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
      <div>
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-history text-gray-600" aria-hidden="true"></i>
          <?= $isDashboardActivity ? __('Attività recenti') : __('Cronologia modifiche') ?>
          <span class="text-sm font-normal text-gray-500">(<?= (int) $activityFeed['total'] ?>)</span>
        </h2>
        <p class="mt-1 text-sm text-gray-600">
          <?= $isDashboardActivity
              ? __('Modifiche recenti ai libri, importazioni, arricchimenti e prestiti.')
              : __('Modifiche registrate per questo libro, dalla più recente.') ?>
        </p>
      </div>

      <?php if ($isDashboardActivity): ?>
      <form method="get" action="<?= htmlspecialchars(url($activityBaseUrl), ENT_QUOTES, 'UTF-8') ?>"
            class="activity-filter-grid grid grid-cols-1 gap-3 w-full lg:w-auto"
            aria-label="<?= htmlspecialchars(__('Filtra attività'), ENT_QUOTES, 'UTF-8') ?>">
        <div>
          <label for="activity-type" class="sr-only"><?= __('Tipo attività') ?></label>
          <select id="activity-type" name="activity_type" class="form-input min-w-44">
            <option value=""><?= __('Tutti i tipi') ?></option>
            <?php foreach (ActivityLog::TYPES as $type): ?>
              <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" <?= $activityFilters['activity_type'] === $type ? 'selected' : '' ?>>
                <?= HtmlHelper::e(__(ActivityLog::typeLabel($type))) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="activity-operator" class="sr-only"><?= __('Operatore') ?></label>
          <select id="activity-operator" name="activity_operator" class="form-input min-w-44">
            <option value=""><?= __('Tutti gli operatori') ?></option>
            <?php foreach ($activityOperators as $operator): ?>
              <option value="<?= (int) $operator['id'] ?>" <?= $activityFilters['activity_operator'] === (int) $operator['id'] ? 'selected' : '' ?>>
                <?= HtmlHelper::e($operator['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="activity-q" class="sr-only"><?= __('Cerca nella cronologia') ?></label>
          <input type="search" id="activity-q" name="activity_q"
                 value="<?= htmlspecialchars($activityFilters['activity_q'], ENT_QUOTES, 'UTF-8') ?>"
                 placeholder="<?= htmlspecialchars(__('Cerca nella cronologia'), ENT_QUOTES, 'UTF-8') ?>"
                 class="form-input min-w-44">
        </div>
        <button type="submit" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 transition-colors">
          <i class="fas fa-filter" aria-hidden="true"></i><?= __('Filtra') ?>
        </button>
      </form>
      <?php else: ?>
      <form method="get" action="<?= htmlspecialchars(url($activityBaseUrl), ENT_QUOTES, 'UTF-8') ?>"
            class="flex flex-col gap-3 w-full sm:flex-row lg:w-auto"
            aria-label="<?= htmlspecialchars(__('Cerca nella cronologia'), ENT_QUOTES, 'UTF-8') ?>">
        <label for="activity-type" class="sr-only"><?= __('Tipo attività') ?></label>
        <select id="activity-type" name="activity_type" class="form-input min-w-44">
          <option value=""><?= __('Tutti i tipi') ?></option>
          <?php foreach (ActivityLog::TYPES as $type): ?>
            <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" <?= $activityFilters['activity_type'] === $type ? 'selected' : '' ?>>
              <?= HtmlHelper::e(__(ActivityLog::typeLabel($type))) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <label for="activity-q" class="sr-only"><?= __('Cerca nella cronologia') ?></label>
        <input type="search" id="activity-q" name="activity_q"
               value="<?= htmlspecialchars($activityFilters['activity_q'], ENT_QUOTES, 'UTF-8') ?>"
               placeholder="<?= htmlspecialchars(__('Cerca nella cronologia'), ENT_QUOTES, 'UTF-8') ?>"
               class="form-input min-w-44 flex-1">
        <button type="submit" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 transition-colors">
          <i class="fas fa-search" aria-hidden="true"></i><?= __('Cerca') ?>
        </button>
      </form>
      <?php endif; ?>
    </div>

    <div class="card-body p-0">
      <?php if (empty($activityFeed['items'])): ?>
        <div class="px-6 py-10 text-center">
          <i class="fas fa-history text-3xl text-gray-300 mb-3" aria-hidden="true"></i>
          <p class="font-medium text-gray-700"><?= __('Nessuna attività registrata.') ?></p>
          <p class="mt-1 text-sm text-gray-500"><?= __('Le prossime modifiche compariranno qui.') ?></p>
        </div>
      <?php else: ?>
        <div class="activity-scroll">
        <ol class="divide-y divide-gray-200">
          <?php foreach ($activityFeed['items'] as $activity): ?>
            <?php
            $type = (string) ($activity['type'] ?? 'edit');
            $typeStyle = ActivityLog::typeClasses($type);
            $eventLabel = ActivityLog::eventLabel((string) ($activity['event'] ?? ''), (string) ($activity['azione'] ?? ''));
            $operatorName = trim((string) ($activity['operator_name'] ?? ''));
            ?>
            <li class="px-4 py-5 sm:px-6" data-activity-type="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
              <div class="flex items-start gap-4">
                <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg <?= $typeStyle['badge'] ?>">
                  <i class="fas <?= $typeStyle['icon'] ?>" aria-hidden="true"></i>
                </span>
                <div class="min-w-0 flex-1">
                  <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                    <div>
                      <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-semibold text-gray-900"><?= HtmlHelper::e(__($eventLabel)) ?></h3>
                        <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium <?= $typeStyle['badge'] ?>">
                          <?= HtmlHelper::e(__(ActivityLog::typeLabel($type))) ?>
                        </span>
                      </div>
                      <?php if ($isDashboardActivity && !empty($activity['record_id'])): ?>
                        <a href="<?= htmlspecialchars(url('/admin/books/' . (int) $activity['record_id'] . '#activity-feed'), ENT_QUOTES, 'UTF-8') ?>"
                           class="mt-1 inline-flex items-center gap-1 text-sm font-medium text-gray-700 hover:text-gray-900">
                          <i class="fas fa-book text-xs text-gray-400" aria-hidden="true"></i>
                          <?= HtmlHelper::e($activity['book_title'] !== '' ? $activity['book_title'] : sprintf(__('Libro #%d'), (int) $activity['record_id'])) ?>
                        </a>
                      <?php endif; ?>
                    </div>
                    <div class="shrink-0 text-sm text-gray-600 sm:text-right">
                      <time datetime="<?= htmlspecialchars((string) ($activity['data_modifica'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <?= HtmlHelper::e(format_date((string) ($activity['data_modifica'] ?? ''), true, '/')) ?>
                      </time>
                      <div class="mt-1 text-xs text-gray-500">
                        <i class="fas fa-user mr-1" aria-hidden="true"></i>
                        <?= HtmlHelper::e($operatorName !== '' ? $operatorName : __('Sistema')) ?>
                      </div>
                    </div>
                  </div>

                  <?php if (!empty($activity['changes'])): ?>
                    <dl class="mt-4 grid grid-cols-1 gap-2">
                      <?php foreach ($activity['changes'] as $change): ?>
                        <div class="grid grid-cols-1 gap-1 rounded-lg bg-gray-50 px-3 py-2 text-sm md:grid-cols-[minmax(9rem,0.35fr)_1fr] md:gap-4">
                          <dt class="font-medium text-gray-700"><?= HtmlHelper::e(__(ActivityLog::fieldLabel((string) $change['field']))) ?></dt>
                          <dd class="min-w-0 text-gray-700">
                            <?php if (($activity['azione'] ?? '') === 'inserimento'): ?>
                              <span class="break-words"><?= HtmlHelper::e($renderActivityValue($change['after'], (string) $change['field'])) ?></span>
                            <?php elseif (($activity['azione'] ?? '') === 'cancellazione'): ?>
                              <span class="break-words line-through text-gray-500"><?= HtmlHelper::e($renderActivityValue($change['before'], (string) $change['field'])) ?></span>
                            <?php else: ?>
                              <span class="break-words text-gray-500 line-through"><?= HtmlHelper::e($renderActivityValue($change['before'], (string) $change['field'])) ?></span>
                              <i class="fas fa-arrow-right mx-2 text-xs text-gray-400" aria-hidden="true"></i>
                              <span class="break-words font-medium text-gray-900"><?= HtmlHelper::e($renderActivityValue($change['after'], (string) $change['field'])) ?></span>
                            <?php endif; ?>
                          </dd>
                        </div>
                      <?php endforeach; ?>
                    </dl>
                  <?php endif; ?>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
        </div>
      <?php endif; ?>
    </div>

    <?php if ((int) $activityFeed['pages'] > 1): ?>
      <nav class="flex items-center justify-between gap-4 border-t border-gray-200 px-4 py-4 sm:px-6" aria-label="<?= htmlspecialchars(__('Paginazione attività'), ENT_QUOTES, 'UTF-8') ?>">
        <?php if ((int) $activityFeed['page'] > 1): ?>
          <a href="<?= htmlspecialchars($activityPageUrl((int) $activityFeed['page'] - 1), ENT_QUOTES, 'UTF-8') ?>#activity-feed" class="btn-secondary inline-flex items-center gap-2">
            <i class="fas fa-chevron-left" aria-hidden="true"></i><?= __('Precedente') ?>
          </a>
        <?php else: ?><span></span><?php endif; ?>
        <span class="text-sm text-gray-600"><?= sprintf(__('Pagina %d di %d'), (int) $activityFeed['page'], (int) $activityFeed['pages']) ?></span>
        <?php if ((int) $activityFeed['page'] < (int) $activityFeed['pages']): ?>
          <a href="<?= htmlspecialchars($activityPageUrl((int) $activityFeed['page'] + 1), ENT_QUOTES, 'UTF-8') ?>#activity-feed" class="btn-secondary inline-flex items-center gap-2">
            <?= __('Successiva') ?><i class="fas fa-chevron-right" aria-hidden="true"></i>
          </a>
        <?php else: ?><span></span><?php endif; ?>
      </nav>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  'use strict';
  // Progressive enhancement: filters, search and pagination swap the feed
  // in place via fetch. The plain GET forms/links above stay as the no-JS
  // fallback, and any fetch failure falls back to a full navigation.
  // Listeners are delegated to document so they survive innerHTML swaps.
  if (window.__activityFeedAjax) { return; }
  window.__activityFeedAjax = true;

  var controller = null;
  var debounceTimer = null;

  function swapFeed(url) {
    if (controller) { controller.abort(); }
    controller = new AbortController();
    var feed = document.getElementById('activity-feed');
    if (feed) { feed.setAttribute('aria-busy', 'true'); feed.classList.add('opacity-60'); }

    var active = document.activeElement;
    var restoreSearch = active && active.name === 'activity_q'
      ? { value: active.value, start: active.selectionStart, end: active.selectionEnd }
      : null;

    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal })
      .then(function (res) {
        if (!res.ok) { throw new Error('HTTP ' + res.status); }
        return res.text();
      })
      .then(function (html) {
        var next = new DOMParser().parseFromString(html, 'text/html').getElementById('activity-feed');
        var current = document.getElementById('activity-feed');
        if (!next || !current) { window.location.assign(url); return; }
        current.innerHTML = next.innerHTML;
        current.removeAttribute('aria-busy');
        current.classList.remove('opacity-60');
        window.history.replaceState(null, '', url);
        if (restoreSearch) {
          var input = current.querySelector('input[name="activity_q"]');
          if (input) {
            input.value = restoreSearch.value;
            input.focus();
            try { input.setSelectionRange(restoreSearch.start, restoreSearch.end); } catch (e) { /* type=search quirks */ }
          }
        }
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') { return; }
        window.location.assign(url);
      });
  }

  function formUrl(form) {
    var params = new URLSearchParams(new FormData(form));
    var clean = new URLSearchParams();
    params.forEach(function (value, key) {
      if (value !== '') { clean.append(key, value); }
    });
    var query = clean.toString();
    return form.action + (query ? '?' + query : '');
  }

  document.addEventListener('submit', function (e) {
    var form = e.target && e.target.closest ? e.target.closest('#activity-feed form') : null;
    if (!form) { return; }
    e.preventDefault();
    swapFeed(formUrl(form));
  });

  document.addEventListener('change', function (e) {
    var select = e.target && e.target.closest ? e.target.closest('#activity-feed form select') : null;
    if (!select || !select.form) { return; }
    swapFeed(formUrl(select.form));
  });

  document.addEventListener('input', function (e) {
    var input = e.target && e.target.closest ? e.target.closest('#activity-feed form input[name="activity_q"]') : null;
    if (!input || !input.form) { return; }
    clearTimeout(debounceTimer);
    var form = input.form;
    debounceTimer = setTimeout(function () { swapFeed(formUrl(form)); }, 350);
  });

  document.addEventListener('click', function (e) {
    var link = e.target && e.target.closest ? e.target.closest('#activity-feed nav a[href]') : null;
    if (!link) { return; }
    e.preventDefault();
    swapFeed(link.href);
  });
})();
</script>
