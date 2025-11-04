<?php use App\Support\HtmlHelper; ?>

<div class="p-6">
  <div class="mb-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Notifiche</h1>
        <p class="text-sm text-gray-600 mt-1">Tutte le notifiche del sistema</p>
      </div>
      <div class="flex gap-2">
        <button onclick="markAllAsRead()" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white border border-gray-300 text-gray-700 text-sm font-semibold hover:bg-gray-50 transition-colors">
          <i class="fas fa-check-double"></i>
          Segna tutte come lette
        </button>
      </div>
    </div>
  </div>

  <!-- Notifications List -->
  <div class="space-y-3">
    <?php if (empty($notifications)): ?>
    <div class="bg-white border border-gray-200 rounded-2xl p-12 text-center">
      <i class="fas fa-bell-slash text-5xl text-gray-300 mb-4"></i>
      <p class="text-gray-500">Nessuna notifica</p>
    </div>
    <?php else: ?>
      <?php foreach ($notifications as $notification): ?>
      <div class="notification-item bg-white border border-gray-200 rounded-2xl p-4 hover:shadow-md transition-shadow <?php echo !$notification['is_read'] ? 'border-l-4 border-l-blue-500' : ''; ?>" data-id="<?php echo $notification['id']; ?>">
        <div class="flex items-start gap-4">
          <!-- Icon -->
          <div class="flex-shrink-0">
            <?php
            $iconClass = 'fas fa-bell';
            $iconBg = 'bg-gray-100 text-gray-600';

            switch ($notification['type']) {
                case 'new_message':
                    $iconClass = 'fas fa-envelope';
                    $iconBg = 'bg-blue-100 text-blue-600';
                    break;
                case 'new_reservation':
                    $iconClass = 'fas fa-book';
                    $iconBg = 'bg-green-100 text-green-600';
                    break;
                case 'new_user':
                    $iconClass = 'fas fa-user-plus';
                    $iconBg = 'bg-purple-100 text-purple-600';
                    break;
                case 'overdue_loan':
                    $iconClass = 'fas fa-exclamation-triangle';
                    $iconBg = 'bg-red-100 text-red-600';
                    break;
                case 'new_loan_request':
                    $iconClass = 'fas fa-calendar-check';
                    $iconBg = 'bg-orange-100 text-orange-600';
                    break;
            }
            ?>
            <div class="<?php echo $iconBg; ?> rounded-xl w-12 h-12 flex items-center justify-center">
              <i class="<?php echo $iconClass; ?>"></i>
            </div>
          </div>

          <!-- Content -->
          <div class="flex-1 min-w-0">
            <div class="flex flex-col md:flex-row items-start md:justify-between gap-4">
              <div class="flex-1">
                <h3 class="text-sm font-semibold text-gray-900 mb-1">
                  <?php echo HtmlHelper::e($notification['title']); ?>
                  <?php if (!$notification['is_read']): ?>
                  <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                    Nuovo
                  </span>
                  <?php endif; ?>
                </h3>
                <p class="text-sm text-gray-600">
                  <?php echo HtmlHelper::e($notification['message']); ?>
                </p>
                <div class="flex items-center gap-4 mt-2">
                  <span class="text-xs text-gray-500">
                    <i class="far fa-clock mr-1"></i>
                    <?php
                    $date = new DateTime($notification['created_at']);
                    $now = new DateTime();
                    $diff = $now->diff($date);

                    if ($diff->days == 0) {
                        if ($diff->h == 0) {
                            if ($diff->i == 0) {
                                echo 'Adesso';
                            } else {
                                echo $diff->i . ' minut' . ($diff->i == 1 ? 'o' : 'i') . ' fa';
                            }
                        } else {
                            echo $diff->h . ' or' . ($diff->h == 1 ? 'a' : 'e') . ' fa';
                        }
                    } elseif ($diff->days == 1) {
                        echo 'Ieri alle ' . $date->format('H:i');
                    } else {
                        echo $date->format('d/m/Y H:i');
                    }
                    ?>
                  </span>
                  <?php if (!empty($notification['link'])): ?>
                  <a href="<?php echo HtmlHelper::e($notification['link']); ?>" class="text-xs text-gray-900 hover:text-gray-700 font-medium">
                    <i class="fas fa-arrow-right mr-1"></i>
                    Vai
                  </a>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Actions -->
              <div class="flex items-center gap-2">
                <?php if (!$notification['is_read']): ?>
                <button onclick="markAsRead(<?php echo $notification['id']; ?>)" class="text-gray-400 hover:text-gray-900 p-2" title="Segna come letto">
                  <i class="fas fa-check"></i>
                </button>
                <?php endif; ?>
                <button onclick="deleteNotification(<?php echo $notification['id']; ?>)" class="text-gray-400 hover:text-red-600 p-2" title="Elimina">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if ($unreadCount > 0): ?>
  <div class="mt-6 text-center">
    <p class="text-sm text-gray-600">
      <?php echo $unreadCount; ?> notific<?php echo $unreadCount == 1 ? 'a' : 'he'; ?> non lett<?php echo $unreadCount == 1 ? 'a' : 'e'; ?>
    </p>
  </div>
  <?php endif; ?>
</div>

<script>
function markAsRead(id) {
  csrfFetch(`/admin/notifications/${id}/read`, { method: 'POST' })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        location.reload();
      }
    })
    .catch(error => console.error('Error:', error));
}

function markAllAsRead() {
  csrfFetch('/admin/notifications/mark-all-read', { method: 'POST' })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        location.reload();
      }
    })
    .catch(error => console.error('Error:', error));
}

function deleteNotification(id) {
  if (confirm('Sei sicuro di voler eliminare questa notifica?')) {
    csrfFetch(`/admin/notifications/${id}`, { method: 'DELETE' })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          location.reload();
        }
      })
      .catch(error => console.error('Error:', error));
  }
}
</script>
