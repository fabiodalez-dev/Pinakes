<?php
/**
 * Book Club — surveys module: single survey page.
 *  - draft  → question builder (managers only; progressive enhancement,
 *             plain forms: add / move up-down / delete / publish);
 *  - open   → answer form for active members who have not answered yet,
 *             live results + close/export for managers;
 *  - closed → aggregated results (counts per option, average for scales,
 *             text answers — anonymized when the survey is anonymous).
 *
 * @var array<string, mixed> $club
 * @var array<string, mixed> $survey
 * @var list<array{key: string, type: string, label: string, options: list<string>, required: bool}> $schema
 * @var bool $isMember
 * @var bool $canManage
 * @var array<string, mixed>|null $myAnswer
 * @var array{total: int, questions: list<array<string, mixed>>}|null $results
 * @var array<string, string> $typeLabels
 * @var list<array{id: int|string, titolo: string}> $books   draft settings editor (managers)
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$surveyId = (int) $survey['id'];
$status = (string) $survey['status'];
$anonymous = (int) ($survey['anonymous'] ?? 0) === 1;
$csrf = \App\Support\Csrf::ensureToken();
$base = url('/book-club/' . $slug . '/surveys/' . $surveyId);
$statusBadges = [
    'draft' => ['bc-badge bc-badge-warn', __('Bozza')],
    'open' => ['bc-badge bc-badge-open', __('Aperto')],
    'closed' => ['bc-badge bc-badge-closed', __('Chiuso')],
];
[$badgeClass, $badgeLabel] = $statusBadges[$status] ?? $statusBadges['draft'];
$yesNoLabels = ['yes' => __('Sì'), 'no' => __('No')];
// Scheduled opening: published ('open') but not answerable before opens_at.
$notYetOpen = \App\Plugins\BookClub\SurveyRepo::notYetOpen($survey);
?>
<style>
  .bc-card{background:var(--white);border-radius:20px;box-shadow:var(--card-shadow);padding:clamp(1.5rem,3vw,2rem);margin-bottom:1.5rem}
  .bc-section-header{display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem}
  .bc-section-header i{color:var(--primary-color);font-size:1.15rem}
  .bc-section-header h2,.bc-section-header h1{font-size:1.35rem;font-weight:700;letter-spacing:-.02em;margin:0;color:var(--text-color)}
  .bc-btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;padding:.55rem 1.4rem;border-radius:999px;border:1.5px solid var(--button-color);background:var(--button-color);color:var(--button-text-color);font-weight:600;font-size:.9rem;cursor:pointer;text-decoration:none;transition:all .2s ease;white-space:nowrap}
  .bc-btn:hover{background:var(--button-hover);border-color:var(--button-hover);color:var(--button-text-color);transform:translateY(-1px)}
  .bc-btn-outline{background:transparent;color:var(--text-color);border:1px solid var(--border-color)}
  .bc-btn-outline:hover{border-color:var(--primary-color);color:var(--primary-color);background:transparent;transform:translateY(-1px)}
  .bc-btn-danger{background:transparent;border:1px solid var(--danger-color);color:var(--danger-color)}
  .bc-btn-danger:hover{background:var(--danger-color);border-color:var(--danger-color);color:#fff}
  .bc-btn-sm{padding:.3rem .9rem;font-size:.8rem}
  .bc-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .75rem;border-radius:999px;font-size:.75rem;font-weight:600}
  .bc-badge-open{background:rgba(16,185,129,.12);color:var(--success-color)}
  .bc-badge-closed{background:var(--accent-color);color:var(--text-light)}
  .bc-badge-warn{background:rgba(245,158,11,.14);color:#92400e}
  .bc-muted{color:var(--text-light);font-size:.85rem}
  .bc-hero{background:var(--primary-color);color:#fff;border-radius:22px;padding:clamp(1.75rem,4vw,2.5rem);margin-bottom:2rem}
  .bc-hero h1{font-size:clamp(1.8rem,4vw,2.5rem);font-weight:800;letter-spacing:-.03em;margin:0 0 .5rem;color:#fff}
  .bc-hero p{opacity:.9;margin:0}
  .bc-progress{height:8px;background:var(--accent-color);border-radius:999px;overflow:hidden}
  .bc-progress>span{display:block;height:100%;border-radius:999px;background:var(--primary-color)}
  .bc-list-item{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;padding:.9rem 0;border-top:1px solid var(--border-color)}
  .bc-list-item:first-child{border-top:none}
  .bc-cover{width:44px;height:64px;object-fit:cover;border-radius:8px;box-shadow:var(--card-shadow)}
  .bc-chip{display:inline-block;width:.8rem;height:.8rem;border-radius:50%;flex:none}
</style>
<div class="container py-4">
  <a href="<?= $e(url('/book-club/' . $slug . '/surveys')) ?>" class="bc-muted text-decoration-none">
    <i class="fas fa-arrow-left me-1"></i><?= $e(__('Tutti i questionari')) ?>
  </a>

  <!-- Header -->
  <div class="bc-card mt-3" style="border-top:6px solid <?= $e($club['color']) ?>">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
      <div>
        <h1 style="font-size:1.6rem;font-weight:700;letter-spacing:-.02em;margin:0;color:var(--text-color)"><?= $e($survey['title']) ?></h1>
        <div class="d-flex flex-wrap align-items-center gap-2 mt-2 bc-muted small">
          <span class="<?= $e($badgeClass) ?>"><?= $e($badgeLabel) ?></span>
          <?php if ($notYetOpen): ?>
            <span class="bc-badge bc-badge-closed"><i class="far fa-clock"></i><?= $e(__('Programmato')) ?></span>
          <?php endif; ?>
          <?php if (!empty($survey['book_title'])): ?>
            <span><i class="fas fa-book me-1"></i><?= $e($survey['book_title']) ?></span>
          <?php endif; ?>
          <?php if ($anonymous): ?>
            <span><i class="fas fa-user-secret me-1"></i><?= $e(__('Anonimo')) ?></span>
          <?php endif; ?>
          <span><i class="fas fa-reply me-1"></i><?= $e(sprintf(__('%d risposte'), (int) $survey['answer_count'])) ?></span>
          <?php if (!empty($survey['opens_at']) && ($status === 'draft' || $notYetOpen)): ?>
            <span><i class="far fa-clock me-1"></i><?= $e(__('Apre il')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $survey['opens_at']))) ?></span>
          <?php endif; ?>
          <?php if (!empty($survey['closes_at'])): ?>
            <span><i class="far fa-clock me-1"></i><?= $e(__('Chiude il')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $survey['closes_at']))) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($canManage): ?>
        <div class="d-flex flex-wrap align-items-center gap-2">
          <?php if ($status === 'open'): ?>
            <form method="post" action="<?= $e($base . '/close') ?>"
                  onsubmit="return confirm('<?= $e(__('Chiudere il questionario? Non accetterà più risposte.')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm">
                <i class="fas fa-lock"></i><?= $e(__('Chiudi questionario')) ?>
              </button>
            </form>
          <?php endif; ?>
          <?php if ($status !== 'draft'): ?>
            <a href="<?= $e($base . '/export.csv') ?>" class="bc-btn bc-btn-outline bc-btn-sm">
              <i class="fas fa-file-csv"></i><?= $e(__('Esporta CSV')) ?>
            </a>
          <?php else: ?>
            <form method="post" action="<?= $e($base . '/delete') ?>"
                  onsubmit="return confirm('<?= $e(__('Eliminare questa bozza? Le domande andranno perse.')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm">
                <i class="fas fa-trash"></i><?= $e(__('Elimina bozza')) ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : ($flash['type'] === 'warning' ? 'alert-warning' : 'alert-danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if ($status === 'draft' && $canManage): ?>
    <!-- ============================ BUILDER ============================ -->
    <section class="bc-card">
      <div class="bc-section-header mb-1">
        <i class="fas fa-list-check"></i>
        <h2><?= $e(__('Domande')) ?></h2>
      </div>
      <p class="bc-muted small mb-3"><?= $e(__('Dopo la pubblicazione le domande non saranno più modificabili.')) ?></p>

      <?php if ($schema === []): ?>
        <p class="bc-muted mb-3"><?= $e(__('Nessuna domanda: aggiungi la prima qui sotto.')) ?></p>
      <?php endif; ?>

      <?php foreach ($schema as $i => $q): ?>
        <div class="border rounded-3 px-3 py-3 mb-3">
          <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <div>
              <span class="bc-muted small me-2"><?= (int) $i + 1 ?>.</span>
              <span class="fw-medium"><?= $e($q['label']) ?></span>
              <?php if ($q['required']): ?><span class="ms-1" style="color:var(--danger-color)">*</span><?php endif; ?>
              <div class="bc-muted small mt-1">
                <?= $e($typeLabels[$q['type']] ?? $q['type']) ?>
                <?php if ($q['options'] !== []): ?>
                  · <?= $e(implode(' / ', $q['options'])) ?>
                <?php endif; ?>
              </div>
            </div>
            <div class="d-flex align-items-center gap-1">
              <?php if ($i > 0): ?>
                <form method="post" action="<?= $e($base . '/questions/' . (int) $i . '/move') ?>">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <input type="hidden" name="dir" value="up">
                  <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm" title="<?= $e(__('Sposta su')) ?>"><i class="fas fa-arrow-up"></i></button>
                </form>
              <?php endif; ?>
              <?php if ($i < count($schema) - 1): ?>
                <form method="post" action="<?= $e($base . '/questions/' . (int) $i . '/move') ?>">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <input type="hidden" name="dir" value="down">
                  <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm" title="<?= $e(__('Sposta giù')) ?>"><i class="fas fa-arrow-down"></i></button>
                </form>
              <?php endif; ?>
              <form method="post" action="<?= $e($base . '/questions/' . (int) $i . '/delete') ?>"
                    onsubmit="return confirm('<?= $e(__('Eliminare questa domanda?')) ?>');">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm" title="<?= $e(__('Elimina')) ?>"><i class="fas fa-trash"></i></button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <!-- Add question -->
      <form method="post" action="<?= $e($base . '/questions/add') ?>" class="row g-3 mt-2 border-top pt-3">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <div class="col-12">
          <label class="form-label small"><?= $e(__('Nuova domanda')) ?> *</label>
          <input type="text" name="label" maxlength="190" required placeholder="<?= $e(__('Es. Qual è il tuo personaggio preferito?')) ?>" class="form-control">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small"><?= $e(__('Tipo di domanda')) ?></label>
          <select name="type" class="form-select">
            <?php foreach ($typeLabels as $typeKey => $typeLabel): ?>
              <option value="<?= $e($typeKey) ?>"><?= $e($typeLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-6 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="required" value="1" id="question-required">
            <label class="form-check-label small" for="question-required"><?= $e(__('Risposta obbligatoria')) ?></label>
          </div>
        </div>
        <div class="col-12">
          <label class="form-label small"><?= $e(__('Opzioni (una per riga, solo per scelta singola/multipla)')) ?></label>
          <textarea name="options" rows="3" class="form-control" placeholder="<?= $e(__('Una opzione per riga')) ?>"></textarea>
        </div>
        <div class="col-12">
          <button type="submit" class="bc-btn">
            <i class="fas fa-plus"></i><?= $e(__('Aggiungi domanda')) ?>
          </button>
        </div>
      </form>
    </section>

    <!-- Draft settings (title, book, anonymity, opening/closing dates) -->
    <section class="bc-card">
      <div class="bc-section-header mb-1">
        <i class="fas fa-sliders"></i>
        <h2><?= $e(__('Modifica dettagli')) ?></h2>
      </div>
      <p class="bc-muted small mb-3"><?= $e(__('Titolo, libro, anonimato e date sono modificabili solo finché il questionario è una bozza.')) ?></p>
      <form method="post" action="<?= $e($base . '/update') ?>" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <div class="col-12">
          <label class="form-label small"><?= $e(__('Titolo')) ?> *</label>
          <input type="text" name="title" maxlength="190" required value="<?= $e($survey['title']) ?>" class="form-control">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small"><?= $e(__('Libro collegato (facoltativo)')) ?></label>
          <select name="club_book_id" class="form-select">
            <option value=""><?= $e(__('Nessun libro (questionario del club)')) ?></option>
            <?php foreach ($books as $book): ?>
              <option value="<?= (int) $book['id'] ?>" <?= (int) $book['id'] === (int) ($survey['club_book_id'] ?? 0) ? 'selected' : '' ?>><?= $e($book['titolo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-6 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="anonymous" value="1" id="survey-anon" <?= $anonymous ? 'checked' : '' ?>>
            <label class="form-check-label small" for="survey-anon">
              <?= $e(__('Questionario anonimo (i nomi dei rispondenti non saranno mai mostrati né esportati)')) ?>
            </label>
          </div>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small"><?= $e(__('Apertura programmata (facoltativa)')) ?></label>
          <input type="datetime-local" name="opens_at" class="form-control"
                 value="<?= !empty($survey['opens_at']) ? $e(date('Y-m-d\TH:i', (int) strtotime((string) $survey['opens_at']))) : '' ?>">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small"><?= $e(__('Chiusura automatica (facoltativa)')) ?></label>
          <input type="datetime-local" name="closes_at" class="form-control"
                 value="<?= !empty($survey['closes_at']) ? $e(date('Y-m-d\TH:i', (int) strtotime((string) $survey['closes_at']))) : '' ?>">
        </div>
        <div class="col-12">
          <button type="submit" class="bc-btn">
            <i class="fas fa-check"></i><?= $e(__('Salva modifiche')) ?>
          </button>
        </div>
      </form>
    </section>

    <!-- Publish -->
    <section class="bc-card">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <p class="bc-muted mb-0"><?= $e(__('Pronto? La pubblicazione apre il questionario ai membri e congela le domande.')) ?></p>
        <form method="post" action="<?= $e($base . '/publish') ?>"
              onsubmit="return confirm('<?= $e(__('Pubblicare il questionario? Le domande non saranno più modificabili.')) ?>');">
          <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
          <button type="submit" class="bc-btn" <?= $schema === [] ? 'disabled' : '' ?>>
            <i class="fas fa-paper-plane"></i><?= $e(__('Pubblica')) ?>
          </button>
        </form>
      </div>
    </section>

  <?php else: ?>

    <?php if ($status === 'open'): ?>
      <?php if ($notYetOpen): ?>
        <!-- Scheduled opening gate: no answer form before opens_at. -->
        <div class="bc-card bc-muted">
          <i class="far fa-clock me-1" style="color:var(--primary-color)"></i>
          <?= $e(sprintf(__('Il questionario aprirà il %s'), date('d/m/Y H:i', (int) strtotime((string) $survey['opens_at'])))) ?>
        </div>
      <?php elseif ($isMember && $myAnswer === null): ?>
        <!-- ========================= ANSWER FORM ========================= -->
        <section class="bc-card">
          <div class="bc-section-header mb-1">
            <i class="fas fa-pen"></i>
            <h2><?= $e(__('Le tue risposte')) ?></h2>
          </div>
          <?php if ($anonymous): ?>
            <p class="bc-muted small mb-3"><i class="fas fa-user-secret me-1"></i><?= $e(__('Questionario anonimo: il tuo nome non sarà mai mostrato. La partecipazione viene registrata solo per garantire una risposta a testa.')) ?></p>
          <?php else: ?>
            <p class="bc-muted small mb-3"><?= $e(__('Puoi rispondere una sola volta.')) ?></p>
          <?php endif; ?>

          <form method="post" action="<?= $e($base . '/answer') ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <?php foreach ($schema as $i => $q): ?>
              <?php $field = 'q_' . $q['key']; ?>
              <div class="py-3 border-bottom">
                <label class="form-label fw-medium">
                  <?= (int) $i + 1 ?>. <?= $e($q['label']) ?>
                  <?php if ($q['required']): ?><span style="color:var(--danger-color)">*</span><?php endif; ?>
                </label>

                <?php if ($q['type'] === 'short_text'): ?>
                  <input type="text" name="<?= $e($field) ?>" maxlength="500" <?= $q['required'] ? 'required' : '' ?> class="form-control">

                <?php elseif ($q['type'] === 'long_text'): ?>
                  <textarea name="<?= $e($field) ?>" rows="4" maxlength="5000" <?= $q['required'] ? 'required' : '' ?> class="form-control"></textarea>

                <?php elseif ($q['type'] === 'single_choice'): ?>
                  <?php foreach ($q['options'] as $oi => $option): ?>
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="<?= $e($field) ?>" value="<?= $e($option) ?>" id="<?= $e($field) ?>-<?= (int) $oi ?>" <?= $q['required'] ? 'required' : '' ?>>
                      <label class="form-check-label small" for="<?= $e($field) ?>-<?= (int) $oi ?>"><?= $e($option) ?></label>
                    </div>
                  <?php endforeach; ?>

                <?php elseif ($q['type'] === 'multi_choice'): ?>
                  <?php foreach ($q['options'] as $oi => $option): ?>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="<?= $e($field) ?>[]" value="<?= $e($option) ?>" id="<?= $e($field) ?>-<?= (int) $oi ?>">
                      <label class="form-check-label small" for="<?= $e($field) ?>-<?= (int) $oi ?>"><?= $e($option) ?></label>
                    </div>
                  <?php endforeach; ?>

                <?php elseif ($q['type'] === 'scale_1_5'): ?>
                  <div class="d-flex align-items-center gap-4">
                    <?php for ($v = 1; $v <= 5; $v++): ?>
                      <label class="d-flex flex-column align-items-center gap-1">
                        <input class="form-check-input" type="radio" name="<?= $e($field) ?>" value="<?= $v ?>" <?= $q['required'] ? 'required' : '' ?>>
                        <span class="bc-muted small"><?= $v ?></span>
                      </label>
                    <?php endfor; ?>
                  </div>

                <?php elseif ($q['type'] === 'yes_no'): ?>
                  <div class="d-flex align-items-center gap-4">
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="<?= $e($field) ?>" value="yes" id="<?= $e($field) ?>-yes" <?= $q['required'] ? 'required' : '' ?>>
                      <label class="form-check-label small" for="<?= $e($field) ?>-yes"><?= $e(__('Sì')) ?></label>
                    </div>
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="<?= $e($field) ?>" value="no" id="<?= $e($field) ?>-no">
                      <label class="form-check-label small" for="<?= $e($field) ?>-no"><?= $e(__('No')) ?></label>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>

            <div class="pt-3">
              <button type="submit" class="bc-btn">
                <i class="fas fa-paper-plane"></i><?= $e(__('Invia le risposte')) ?>
              </button>
            </div>
          </form>
        </section>
      <?php elseif ($isMember && $myAnswer !== null): ?>
        <div class="bc-card bc-muted">
          <i class="fas fa-check-circle me-1" style="color:var(--success-color)"></i>
          <?= $e(__('Hai già risposto a questo questionario.')) ?>
          <?php if ($results === null): ?>
            <?= $e(__('I risultati saranno visibili alla chiusura.')) ?>
          <?php endif; ?>
        </div>
      <?php elseif (!$isMember && !$canManage): ?>
        <p class="bc-muted mb-4"><?= $e(__('Solo i membri attivi del club possono rispondere ai questionari.')) ?></p>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($results !== null): ?>
      <!-- =========================== RESULTS =========================== -->
      <section class="bc-card">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <div class="bc-section-header mb-0">
            <i class="fas fa-chart-simple"></i>
            <h2><?= $e(__('Risultati')) ?></h2>
          </div>
          <span class="bc-muted small"><?= $e(sprintf(__('%d risposte'), (int) $results['total'])) ?><?= $status === 'open' ? ' · ' . $e(__('In corso')) : '' ?></span>
        </div>

        <?php if ((int) $results['total'] === 0): ?>
          <p class="bc-muted mb-0"><?= $e(__('Nessuna risposta ricevuta.')) ?></p>
        <?php endif; ?>

        <?php foreach ($results['questions'] as $i => $item): ?>
          <?php
            $q = $item['q'];
            $answered = (int) $item['answered'];
            $maxCount = 1;
            foreach ($item['counts'] as $n) {
                $maxCount = max($maxCount, (int) $n);
            }
          ?>
          <div class="py-3 border-bottom">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
              <h3 class="fw-medium mb-0" style="font-size:.95rem;color:var(--text-color)"><?= (int) $i + 1 ?>. <?= $e($q['label']) ?></h3>
              <span class="bc-muted small"><?= $e(sprintf(__('%d risposte'), $answered)) ?></span>
            </div>

            <?php if (in_array($q['type'], ['single_choice', 'multi_choice', 'yes_no', 'scale_1_5'], true)): ?>
              <?php if ($q['type'] === 'scale_1_5' && $item['avg'] !== null): ?>
                <p class="bc-muted small mb-2"><?= $e(__('Media')) ?>: <span class="fw-semibold" style="color:var(--text-color)"><?= $e(number_format((float) $item['avg'], 2)) ?></span> / 5</p>
              <?php endif; ?>
              <?php foreach ($item['counts'] as $optionKey => $count): ?>
                <?php $optionLabel = $q['type'] === 'yes_no' ? ($yesNoLabels[(string) $optionKey] ?? (string) $optionKey) : (string) $optionKey; ?>
                <div class="d-flex align-items-center gap-3 mb-2">
                  <div class="bc-muted small text-truncate flex-shrink-0" style="width:10rem" title="<?= $e($optionLabel) ?>"><?= $e($optionLabel) ?></div>
                  <div class="bc-progress flex-grow-1">
                    <span style="width: <?= number_format((int) $count / $maxCount * 100, 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></span>
                  </div>
                  <div class="text-end small fw-medium" style="width:2rem"><?= (int) $count ?></div>
                </div>
              <?php endforeach; ?>

            <?php else: ?>
              <?php if ($item['texts'] === []): ?>
                <p class="bc-muted small mb-0"><?= $e(__('Nessuna risposta testuale.')) ?></p>
              <?php endif; ?>
              <ul class="list-unstyled mb-0">
                <?php foreach ($item['texts'] as $entry): ?>
                  <li class="rounded-3 px-3 py-2 mb-2 small" style="background:var(--light-bg)">
                    <?= nl2br($e($entry['text'])) ?>
                    <div class="bc-muted small mt-1">
                      — <?= $entry['author'] !== null && $entry['author'] !== '' ? $e($entry['author']) : $e(__('Anonimo')) ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </section>
    <?php elseif ($status === 'closed'): ?>
      <p class="bc-muted"><?= $e(__('Nessun risultato disponibile.')) ?></p>
    <?php endif; ?>

  <?php endif; ?>
</div>
