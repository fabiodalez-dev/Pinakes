<?php
/**
 * Visitor log kiosk + statistics.
 *
 * @var array $data {
 *   days:int, periods:list<int>, daily:list<array>, hourly:array<int,int>,
 *   totals:array, today:array, recent:list<array>, branches:array<int,string>,
 *   retentionDays:int, isAdmin:bool
 * }
 */
use App\Support\Csrf;
use App\Support\HtmlHelper;

$csrf = Csrf::ensureToken();
$days = (int) $data['days'];
$branches = $data['branches'];
$selectedBranch = (int) ($_SESSION['attendance_sede_id'] ?? 0);
$hourly = $data['hourly'];
$maxHour = max(1, ...array_values($hourly));
$branchSelect = static function (string $id) use ($branches, $selectedBranch): void {
    if ($branches === []) {
        return;
    }
    ?>
    <div>
      <label for="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" class="form-label"><?= __("Sede") ?></label>
      <select id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" name="sede_id" class="form-input">
        <option value=""><?= __("Nessuna sede") ?></option>
        <?php foreach ($branches as $bid => $bname): ?>
          <option value="<?= (int) $bid ?>" <?= $selectedBranch === (int) $bid ? 'selected' : '' ?>><?= HtmlHelper::e($bname) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php
};
?>
<section class="min-h-screen py-6 px-4">
  <?php
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-3xl font-bold text-gray-900 flex items-center gap-3"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?><?= __("Registro presenze") ?><?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>
    <p class="mt-1 text-sm text-gray-600"><?= __("Registra chi entra in biblioteca: lettura della tessera per i soci, modulo rapido per gli ospiti") ?></p>
  <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?>
    <div class="flex items-center gap-2">
      <a href="<?= htmlspecialchars(url('/admin/attendance/export.csv?days=' . $days), ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary" id="attendance-export">
        <i class="fas fa-file-csv mr-2"></i><?= __("Esporta CSV") ?>
      </a>
    </div>
  <?php
$workspaceHeader['actions'] = (string) ob_get_clean();
ob_start();
?><nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
      <ol>
        <li>
          <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Home') ?></a>
        </li>
        <li aria-current="page">
          <span><?= __("Registro presenze") ?></span>
        </li>
      </ol>
    </nav><?php
$workspaceHeader['breadcrumb'] = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

  <!-- Kiosk -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="card">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-id-card text-gray-900"></i>
          <?= __("Socio: leggi la tessera") ?>
        </h2>
      </div>
      <div class="card-body">
        <form method="post" action="<?= htmlspecialchars(url('/admin/attendance/scan'), ENT_QUOTES, 'UTF-8') ?>" id="attendance-scan-form" class="space-y-4" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <div>
            <label for="codice_tessera" class="form-label"><?= __("Codice tessera") ?></label>
            <input id="codice_tessera" name="codice_tessera" type="text" maxlength="20" required autofocus class="form-input" placeholder="<?= htmlspecialchars(__('Passa la tessera sul lettore o digita il codice'), ENT_QUOTES, 'UTF-8') ?>" />
          </div>
          <?php $branchSelect('sede_scan'); ?>
          <button type="submit" class="btn-primary">
            <i class="fas fa-sign-in-alt mr-2"></i><?= __("Registra ingresso") ?>
          </button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-user-clock text-gray-900"></i>
          <?= __("Ospite") ?>
        </h2>
      </div>
      <div class="card-body">
        <form method="post" action="<?= htmlspecialchars(url('/admin/attendance/guest'), ENT_QUOTES, 'UTF-8') ?>" id="attendance-guest-form" class="space-y-4" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <div class="form-grid-2">
            <div>
              <label for="nome_ospite" class="form-label"><?= __("Nome (facoltativo)") ?></label>
              <input id="nome_ospite" name="nome_ospite" type="text" maxlength="150" class="form-input" />
            </div>
            <div>
              <label for="motivo" class="form-label"><?= __("Motivo della visita") ?></label>
              <input id="motivo" name="motivo" type="text" maxlength="255" class="form-input" placeholder="<?= htmlspecialchars(__('es. consultazione, studio, evento'), ENT_QUOTES, 'UTF-8') ?>" />
            </div>
          </div>
          <?php $branchSelect('sede_guest'); ?>
          <button type="submit" class="btn-secondary">
            <i class="fas fa-user-plus mr-2"></i><?= __("Registra ospite") ?>
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Summary Cards -->
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
      <p class="text-xs uppercase text-gray-500 font-medium"><?= __("Presenze oggi") ?></p>
      <p class="text-2xl font-bold text-gray-900 mt-1" id="attendance-today"><?= number_format((int) $data['today']['totale']) ?></p>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
      <p class="text-xs uppercase text-gray-500 font-medium"><?= sprintf(__("Presenze ultimi %d giorni"), $days) ?></p>
      <p class="text-2xl font-bold text-gray-900 mt-1" id="attendance-total"><?= number_format((int) $data['totals']['totale']) ?></p>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
      <p class="text-xs uppercase text-gray-500 font-medium"><?= __("Soci") ?></p>
      <p class="text-2xl font-bold text-blue-600 mt-1" id="attendance-members"><?= number_format((int) $data['totals']['soci']) ?></p>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
      <p class="text-xs uppercase text-gray-500 font-medium"><?= __("Ospiti") ?></p>
      <p class="text-2xl font-bold text-purple-600 mt-1" id="attendance-guests"><?= number_format((int) $data['totals']['ospiti']) ?></p>
    </div>
  </div>

  <!-- Period selector -->
  <div class="flex flex-wrap items-center gap-2 mb-4" role="group" aria-label="<?= htmlspecialchars(__('Periodo'), ENT_QUOTES, 'UTF-8') ?>">
    <span class="text-sm text-gray-600"><?= __("Periodo") ?>:</span>
    <?php foreach ($data['periods'] as $p): ?>
      <a href="<?= htmlspecialchars(url('/admin/attendance?days=' . (int) $p), ENT_QUOTES, 'UTF-8') ?>" class="<?= (int) $p === $days ? 'btn-primary' : 'btn-secondary' ?>" <?= (int) $p === $days ? 'aria-current="true"' : '' ?>>
        <?= sprintf(__("%d giorni"), (int) $p) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- By hour slot -->
    <div class="card">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-clock text-gray-900"></i>
          <?= __("Presenze per fascia oraria") ?>
        </h2>
      </div>
      <div class="card-body p-0">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200" id="attendance-hourly">
            <thead class="bg-gray-50">
              <tr>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Fascia") ?></th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Presenze") ?></th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php foreach ($hourly as $hour => $count): if ($count === 0) { continue; } ?>
                <tr data-hour="<?= (int) $hour ?>">
                  <td class="px-6 py-2 whitespace-nowrap text-sm text-gray-700"><?= sprintf('%02d:00–%02d:00', (int) $hour, ((int) $hour + 1) % 24) ?></td>
                  <td class="px-6 py-2 text-sm text-gray-900">
                    <div class="flex items-center gap-3">
                      <div class="h-2 rounded bg-gray-800" style="width: <?= (int) round(($count / $maxHour) * 160) ?>px"></div>
                      <span data-hour-count><?= (int) $count ?></span>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (array_sum($hourly) === 0): ?>
                <tr><td colspan="2" class="px-6 py-8 text-center text-gray-500"><?= __("Nessuna presenza nel periodo") ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- By day -->
    <div class="card">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-calendar-day text-gray-900"></i>
          <?= __("Presenze per giorno") ?>
        </h2>
      </div>
      <div class="card-body p-0">
        <div class="overflow-x-auto max-h-96 overflow-y-auto">
          <table class="min-w-full divide-y divide-gray-200" id="attendance-daily">
            <thead class="bg-gray-50">
              <tr>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Data") ?></th>
                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Soci") ?></th>
                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Ospiti") ?></th>
                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Totale") ?></th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php foreach (array_reverse($data['daily']) as $row): if ($row['totale'] === 0) { continue; } ?>
                <tr data-day="<?= htmlspecialchars($row['giorno'], ENT_QUOTES, 'UTF-8') ?>">
                  <td class="px-6 py-2 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars(format_date($row['giorno'], false, '/'), ENT_QUOTES, 'UTF-8') ?></td>
                  <td class="px-6 py-2 text-center text-sm" data-col="soci"><?= (int) $row['soci'] ?></td>
                  <td class="px-6 py-2 text-center text-sm" data-col="ospiti"><?= (int) $row['ospiti'] ?></td>
                  <td class="px-6 py-2 text-center text-sm font-semibold" data-col="totale"><?= (int) $row['totale'] ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if ((int) $data['totals']['totale'] === 0): ?>
                <tr><td colspan="4" class="px-6 py-8 text-center text-gray-500"><?= __("Nessuna presenza nel periodo") ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Recent -->
    <div class="card">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-list text-gray-900"></i>
          <?= __("Ultime presenze") ?>
        </h2>
      </div>
      <div class="card-body p-0">
        <ul class="divide-y divide-gray-200" id="attendance-recent">
          <?php if ($data['recent'] === []): ?>
            <li class="px-6 py-6 text-center text-gray-500"><?= __("Nessuna presenza registrata") ?></li>
          <?php endif; ?>
          <?php foreach ($data['recent'] as $visit): ?>
            <li class="px-6 py-3 flex items-center justify-between gap-3 text-sm">
              <span class="text-gray-900">
                <?php if ($visit['tipo'] === 'socio'): ?>
                  <span class="status-badge bg-blue-100 text-blue-800"><?= __("Socio") ?></span>
                <?php else: ?>
                  <span class="status-badge bg-purple-100 text-purple-800"><?= __("Ospite") ?></span>
                <?php endif; ?>
                <?= HtmlHelper::e($visit['etichetta']) ?>
              </span>
              <span class="text-gray-500 whitespace-nowrap"><?= htmlspecialchars(format_date($visit['created_at'], true, '/'), ENT_QUOTES, 'UTF-8') ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <!-- Retention / GDPR -->
    <div class="card">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-user-shield text-gray-900"></i>
          <?= __("Conservazione dei dati (GDPR)") ?>
        </h2>
      </div>
      <div class="card-body space-y-4">
        <p class="text-sm text-gray-600" id="attendance-gdpr-note">
          <?php if ((int) $data['retentionDays'] > 0): ?>
            <?= sprintf(__("Le presenze sono conservate per %d giorni e poi cancellate automaticamente dalla manutenzione programmata."), (int) $data['retentionDays']) ?>
          <?php else: ?>
            <?= __("Le presenze sono conservate senza limite di tempo: imposta un numero di giorni per cancellarle automaticamente.") ?>
          <?php endif; ?>
          <?= __("Il registro serve solo a contare gli ingressi: per gli ospiti il nome è facoltativo, non raccogliere dati personali oltre lo stretto necessario.") ?>
        </p>
        <?php if ($data['isAdmin']): ?>
          <form method="post" action="<?= htmlspecialchars(url('/admin/attendance/settings'), ENT_QUOTES, 'UTF-8') ?>" id="attendance-retention-form" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <div>
              <label for="retention_days" class="form-label"><?= __("Giorni di conservazione") ?></label>
              <input id="retention_days" name="retention_days" type="number" min="0" max="3650" class="form-input" value="<?= (int) $data['retentionDays'] ?>" />
              <p class="text-xs text-gray-500 mt-1"><?= __("0 = conserva senza limite") ?></p>
            </div>
            <button type="submit" class="btn-primary">
              <i class="fas fa-save mr-2"></i><?= __("Salva") ?>
            </button>
          </form>
          <form method="post" action="<?= htmlspecialchars(url('/admin/attendance/purge'), ENT_QUOTES, 'UTF-8') ?>" id="attendance-purge-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn-secondary">
              <i class="fas fa-broom mr-2"></i><?= __("Applica ora la conservazione") ?>
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const card = document.getElementById('codice_tessera');
  if (card) { card.focus(); }
});
</script>
