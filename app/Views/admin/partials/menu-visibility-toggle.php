<?php
/**
 * "Entry in the public menu" card for a plugin section (Emeroteca, Archive),
 * the same card the Events page uses for its own visibility. Only the menu
 * entry is switched: the section's pages stay reachable, because catalogue
 * and search results link to them.
 *
 * Expects:
 *   $menuToggle = [
 *     'action'  => string  absolute URL the form posts to (already url()-ed),
 *     'enabled' => bool    current state,
 *     'title'   => string  translated card title,
 *   ];
 */
$menuToggleEnabled = (bool) ($menuToggle['enabled'] ?? true);
?>
<div class="mb-6 bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
  <form action="<?= htmlspecialchars((string) ($menuToggle['action'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" method="post" id="menuVisibilityForm">
    <input type="hidden" name="csrf_token"
      value="<?= \App\Support\HtmlHelper::e(\App\Support\Csrf::ensureToken()) ?>">

    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
      <div class="flex-1">
        <div class="flex items-center gap-3 mb-2">
          <div
            class="w-12 h-12 rounded-xl <?= $menuToggleEnabled ? 'bg-green-100' : 'bg-gray-100' ?> flex items-center justify-center">
            <i
              class="fas <?= $menuToggleEnabled ? 'fa-eye text-green-600' : 'fa-eye-slash text-gray-500' ?> text-xl" aria-hidden="true"></i>
          </div>
          <div>
            <h3 class="text-lg font-semibold text-gray-900">
              <?= \App\Support\HtmlHelper::e((string) ($menuToggle['title'] ?? '')) ?>
            </h3>
            <p class="text-sm <?= $menuToggleEnabled ? 'text-green-700 font-medium' : 'text-gray-600' ?>">
              <?= $menuToggleEnabled ? __("Visibile nel menu del sito") : __("Nascosta dal menu del sito") ?>
            </p>
          </div>
        </div>
        <p class="text-sm text-gray-600 mt-2">
          <?= __("Quando è nascosta, la voce sparisce dal menu del sito pubblico. Le pagine restano raggiungibili dai risultati del catalogo e della ricerca.") ?>
        </p>
      </div>

      <div class="flex items-center gap-4">
        <label class="flex items-center gap-3 cursor-pointer">
          <input type="checkbox" name="in_menu" value="1" <?= $menuToggleEnabled ? 'checked' : '' ?>
            class="toggle-checkbox sr-only" onchange="document.getElementById('menuVisibilityForm').submit()">
          <div class="toggle-switch">
            <div class="toggle-slider"></div>
          </div>
          <span class="text-sm font-medium text-gray-700">
            <?= $menuToggleEnabled ? __("Visibile") : __("Nascosta") ?>
          </span>
        </label>
      </div>
    </div>
  </form>
</div>

<style>
  /* Toggle switch, as on the Events page */
  #menuVisibilityForm .toggle-switch {
    position: relative;
    width: 44px;
    height: 24px;
    background-color: #e5e7eb;
    border-radius: 9999px;
    transition: background-color 0.3s ease;
  }

  #menuVisibilityForm .toggle-checkbox:checked+.toggle-switch {
    background-color: #111827;
  }

  #menuVisibilityForm .toggle-slider {
    position: absolute;
    top: 2px;
    left: 2px;
    width: 20px;
    height: 20px;
    background-color: white;
    border: 1px solid #d1d5db;
    border-radius: 9999px;
    transition: transform 0.3s ease;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
  }

  #menuVisibilityForm .toggle-checkbox:checked+.toggle-switch .toggle-slider {
    transform: translateX(20px);
  }

  #menuVisibilityForm .toggle-checkbox:focus-visible+.toggle-switch {
    outline: 2px solid #111827;
    outline-offset: 2px;
  }
</style>
