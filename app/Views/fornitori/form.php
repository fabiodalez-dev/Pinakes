<?php
/**
 * Create / edit form for a supplier, modelled on editori/crea_editore.php.
 *
 * @var array $data { fornitore: array<string,mixed>|null }
 */
use App\Support\Csrf;
use App\Support\HtmlHelper;

$csrf = Csrf::ensureToken();
$fornitore = $data['fornitore'];
$isEdit = is_array($fornitore);
$action = $isEdit ? url('/admin/suppliers/update/' . (int) $fornitore['id']) : url('/admin/suppliers/create');
$v = static fn (string $key): string => HtmlHelper::e((string) ($fornitore[$key] ?? ''));
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Header -->
    <?php
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-3xl font-bold text-gray-900 mb-2"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?>
        <?= $isEdit ? __("Modifica Fornitore") : __("Aggiungi Nuovo Fornitore") ?>
      <?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>

      <p class="text-gray-600"><?= __("Compila i dati del fornitore da cui la biblioteca acquista i libri") ?></p>
    <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?><nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
      <ol>
        <li>
          <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Home') ?></a>
        </li>

        <li>
          <a href="<?= htmlspecialchars(url('/admin/suppliers'), ENT_QUOTES, 'UTF-8') ?>">
            <?= __("Fornitori") ?>
          </a>
        </li>

        <li aria-current="page"><?= $isEdit ? __("Modifica") : __("Nuovo") ?></li>
      </ol>
    </nav><?php
$workspaceHeader['breadcrumb'] = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

    <!-- Main Form -->
    <form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" id="form-fornitore" class="space-y-6">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

      <!-- Basic Information Section -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-truck text-gray-900"></i>
            <?= __("Informazioni Base") ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-2">
            <div>
              <label for="nome" class="form-label">
                <?= __("Nome Fornitore") ?> <span class="text-red-500">*</span>
              </label>
              <input id="nome" name="nome" required aria-required="true" maxlength="255" class="form-input" value="<?= $v('nome') ?>" placeholder="<?= htmlspecialchars(__("Ragione sociale del fornitore"), ENT_QUOTES, 'UTF-8') ?>" />
            </div>
            <div>
              <label for="partita_iva" class="form-label"><?= __("Partita IVA") ?></label>
              <input id="partita_iva" name="partita_iva" type="text" maxlength="20" class="form-input" value="<?= $v('partita_iva') ?>" />
            </div>
          </div>

          <div class="form-grid-2">
            <div>
              <label for="email" class="form-label"><?= __("Email Contatto") ?></label>
              <input id="email" name="email" type="email" maxlength="255" class="form-input" value="<?= $v('email') ?>" />
            </div>
            <div>
              <label for="telefono" class="form-label"><?= __("Telefono") ?></label>
              <input id="telefono" name="telefono" type="tel" maxlength="30" class="form-input" value="<?= $v('telefono') ?>" />
            </div>
          </div>

          <div class="form-grid-2">
            <div>
              <label for="referente" class="form-label"><?= __("Referente") ?></label>
              <input id="referente" name="referente" type="text" maxlength="150" class="form-input" value="<?= $v('referente') ?>" />
            </div>
            <div>
              <label for="sito_web" class="form-label"><?= __("Sito Web") ?></label>
              <input id="sito_web" name="sito_web" type="text" maxlength="255" class="form-input" value="<?= $v('sito_web') ?>" placeholder="https://" />
            </div>
          </div>

          <div>
            <label for="indirizzo" class="form-label"><?= __("Indirizzo") ?></label>
            <textarea id="indirizzo" name="indirizzo" rows="3" class="form-input"><?= $v('indirizzo') ?></textarea>
          </div>

          <div>
            <label for="note" class="form-label"><?= __("Note") ?></label>
            <textarea id="note" name="note" rows="3" class="form-input"><?= $v('note') ?></textarea>
          </div>
        </div>
      </div>

      <!-- Submit Section -->
      <div class="pk-form-actions flex flex-col sm:flex-row gap-4 justify-end">
        <a href="<?= htmlspecialchars(url('/admin/suppliers'), ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary order-2 sm:order-1 text-center">
          <i class="fas fa-times mr-2"></i>
          <?= __("Annulla") ?>
        </a>
        <button type="submit" class="btn-primary order-1 sm:order-2">
          <i class="fas fa-save mr-2"></i>
          <?= __("Salva Fornitore") ?>
        </button>
      </div>
    </form>
  </div>
</div>
