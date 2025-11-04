<div class="container py-5">
  <div class="row">
    <div class="col-12">
      <!-- Welcome Section -->
      <div class="mb-5 text-center">
        <h1 class="display-4 fw-bold mb-3">
          Benvenuto, 
          <span class="text-primary"><?php echo htmlspecialchars((string)($_SESSION['user']['nome'] ?? $_SESSION['user']['email'] ?? 'Utente'), ENT_QUOTES, 'UTF-8'); ?></span>!
        </h1>
        <p class="lead text-muted">Scopri le funzionalità disponibili per te nella nostra biblioteca digitale</p>
      </div>

      <!-- Stats Cards -->
      <div class="row g-4 mb-5">
        <div class="col-md-3">
          <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center">
              <div class="mb-3">
                <i class="fas fa-book text-primary fa-2x"></i>
              </div>
              <h3 class="card-title"><?php echo $stats['libri'] ?? 0; ?></h3>
              <p class="text-muted">Libri Totali</p>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center">
              <div class="mb-3">
                <i class="fas fa-bookmark text-success fa-2x"></i>
              </div>
              <h3 class="card-title"><?php echo $stats['prestiti_in_corso'] ?? 0; ?></h3>
              <p class="text-muted">Prestiti Attivi</p>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center">
              <div class="mb-3">
                <i class="fas fa-heart text-danger fa-2x"></i>
              </div>
              <h3 class="card-title"><?php echo $stats['preferiti'] ?? 0; ?></h3>
              <p class="text-muted">Nei Preferiti</p>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center">
              <div class="mb-3">
                <i class="fas fa-history text-info fa-2x"></i>
              </div>
              <h3 class="card-title"><?php echo $stats['storico_prestiti'] ?? 0; ?></h3>
              <p class="text-muted">Storico Prestiti</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Activity Sections -->
      <div class="row g-4">
        <!-- Recently Added Books -->
        <div class="col-lg-6">
          <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pb-3">
              <h3 class="card-title mb-0">
                <i class="fas fa-plus-circle text-primary me-2"></i>
                Ultimi Arrivi
              </h3>
            </div>
            <div class="card-body">
              <?php if (empty($ultimiArrivi)): ?>
                <div class="text-center py-4">
                  <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                  <p class="text-muted">Nessun libro recente disponibile</p>
                </div>
              <?php else: ?>
                <div class="row g-3">
                  <?php foreach ($ultimiArrivi as $libro): ?>
                    <div class="col-12">
                      <div class="d-flex align-items-center border rounded p-3">
                        <div class="flex-shrink-0 me-3">
                          <div class="bg-light border d-flex align-items-center justify-content-center" style="width: 60px; height: 80px;">
                            <i class="fas fa-book text-muted"></i>
                          </div>
                        </div>
                        <div class="flex-grow-1">
                          <h6 class="mb-1"><?php echo htmlspecialchars((string)($libro['titolo'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h6>
                          <small class="text-muted">
                            <?php echo htmlspecialchars((string)($libro['autore'] ?? 'Autore non specificato'), ENT_QUOTES, 'UTF-8'); ?>
                          </small>
                          <div class="mt-2">
                            <span class="badge <?php echo !empty($libro['copie_disponibili']) ? 'bg-success' : 'bg-danger'; ?>">
                              <?php echo !empty($libro['copie_disponibili']) ? 'Disponibile' : 'Non disponibile'; ?>
                            </span>
                          </div>
                        </div>
                        <div class="ms-3">
                          <a href="/libro/<?php echo (int)($libro['id'] ?? 0); ?>" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-eye"></i>
                          </a>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Active Loans -->
        <div class="col-lg-6">
          <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pb-3">
              <h3 class="card-title mb-0">
                <i class="fas fa-handshake text-success me-2"></i>
                Prestiti Attivi
              </h3>
            </div>
            <div class="card-body">
              <?php if (empty($prestitiAttivi)): ?>
                <div class="text-center py-4">
                  <i class="fas fa-book-reader fa-3x text-muted mb-3"></i>
                  <p class="text-muted">Nessun prestito attivo</p>
                  <a href="/catalogo.php" class="btn btn-outline-primary">
                    <i class="fas fa-search me-2"></i>
                    Esplora il catalogo
                  </a>
                </div>
              <?php else: ?>
                <div class="row g-3">
                  <?php foreach ($prestitiAttivi as $prestito): ?>
                    <div class="col-12">
                      <div class="d-flex align-items-center border rounded p-3">
                        <div class="flex-shrink-0 me-3">
                          <div class="bg-light border d-flex align-items-center justify-content-center" style="width: 60px; height: 80px;">
                            <i class="fas fa-book text-muted"></i>
                          </div>
                        </div>
                        <div class="flex-grow-1">
                          <h6 class="mb-1"><?php echo htmlspecialchars((string)($prestito['titolo_libro'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h6>
                          <small class="text-muted">
                            Scadenza: <?php echo date('d/m/Y', strtotime((string)($prestito['data_scadenza'] ?? ''))); ?>
                          </small>
                          <div class="mt-2">
                            <span class="badge <?php echo strtotime((string)($prestito['data_scadenza'] ?? '')) < time() ? 'bg-danger' : 'bg-warning'; ?>">
                              <?php echo strtotime((string)($prestito['data_scadenza'] ?? '')) < time() ? 'Scaduto' : 'In corso'; ?>
                            </span>
                          </div>
                        </div>
                        <div class="ms-3">
                          <a href="/libro/<?php echo (int)($prestito['libro_id'] ?? 0); ?>" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-info-circle"></i>
                          </a>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="row mt-5">
        <div class="col-12">
          <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pb-3">
              <h3 class="card-title mb-0">
                <i class="fas fa-bolt text-warning me-2"></i>
                Azioni Veloci
              </h3>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-3">
                  <a href="/catalogo.php" class="btn btn-outline-primary w-100">
                    <i class="fas fa-search me-2"></i>
                    Cerca Libri
                  </a>
                </div>
                <div class="col-md-3">
                  <a href="/wishlist" class="btn btn-outline-danger w-100">
                    <i class="fas fa-heart me-2"></i>
                    I Miei Preferiti
                  </a>
                </div>
                <div class="col-md-3">
                  <a href="/prenotazioni" class="btn btn-outline-info w-100">
                    <i class="fas fa-bookmark me-2"></i>
                    Le Mie Prenotazioni
                  </a>
                </div>
                <div class="col-md-3">
                  <a href="/profilo" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-user me-2"></i>
                    Il Mio Profilo
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Initialize any needed JavaScript
document.addEventListener('DOMContentLoaded', function() {
});
</script>