<?php
/**
 * Dewey Editor View
 * Integrated with standard layout.php
 */

use App\Support\HtmlHelper;

$pageTitle = __('Editor Classificazione Dewey');
?>

<style>
    .dewey-tree { font-family: ui-monospace, monospace; font-size: 14px; }
    .dewey-node { border-left: 2px solid #e5e7eb; margin-left: 20px; padding-left: 12px; }
    .dewey-node.level-1 { border-left: none; margin-left: 0; padding-left: 0; }
    .dewey-item {
        display: flex; align-items: center; gap: 8px;
        padding: 6px 8px; border-radius: 4px; margin: 2px 0;
        transition: background-color 0.15s;
    }
    .dewey-item:hover { background-color: #f3f4f6; }
    .dewey-code {
        font-weight: 600; color: #1f2937; min-width: 80px;
        font-family: ui-monospace, monospace;
    }
    .dewey-name { flex: 1; color: #374151; }
    .dewey-actions { display: flex; gap: 4px; opacity: 0; transition: opacity 0.15s; }
    .dewey-item:hover .dewey-actions { opacity: 1; }
    .dewey-btn {
        padding: 4px 8px; border-radius: 4px; font-size: 12px;
        border: 1px solid #d1d5db; background: white; cursor: pointer;
        transition: all 0.15s;
    }
    .dewey-btn:hover { background: #f9fafb; border-color: #9ca3af; }
    .dewey-btn-add { color: #059669; border-color: #a7f3d0; }
    .dewey-btn-add:hover { background: #d1fae5; }
    .dewey-btn-edit { color: #2563eb; border-color: #bfdbfe; }
    .dewey-btn-edit:hover { background: #dbeafe; }
    .dewey-btn-delete { color: #dc2626; border-color: #fecaca; }
    .dewey-btn-delete:hover { background: #fee2e2; }
    .dewey-toggle {
        cursor: pointer; width: 20px; text-align: center;
        color: #6b7280; user-select: none;
    }
    .dewey-toggle:hover { color: #1f2937; }
    .dewey-children { display: none; }
    .dewey-children.open { display: block; }
    .tab-active { border-bottom: 2px solid #2563eb; color: #2563eb; }
    .stats-bar {
        display: flex; gap: 24px; padding: 12px 16px;
        background: #f9fafb; border-radius: 8px; margin-bottom: 16px;
        flex-wrap: wrap;
    }
    .stat-item { display: flex; flex-direction: column; }
    .stat-label { font-size: 12px; color: #6b7280; }
    .stat-value { font-size: 18px; font-weight: 600; color: #1f2937; }
</style>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900"><?= HtmlHelper::e($pageTitle) ?></h1>
                <p class="mt-2 text-sm text-gray-600"><?= __("Gestisci le classificazioni Dewey per italiano e inglese") ?></p>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                <button id="btn-export" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 inline-flex items-center">
                    <i class="fas fa-download mr-2"></i><?= __('Esporta') ?>
                </button>
                <button id="btn-import" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 inline-flex items-center">
                    <i class="fas fa-upload mr-2"></i><?= __('Importa') ?>
                </button>
                <button id="btn-backups" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 inline-flex items-center">
                    <i class="fas fa-history mr-2"></i><?= __('Backup') ?>
                </button>
                <button id="btn-save" class="px-4 py-2 bg-black text-white rounded-lg text-sm font-medium hover:bg-gray-800 disabled:opacity-50 inline-flex items-center" disabled>
                    <i class="fas fa-save mr-2"></i><?= __('Salva Modifiche') ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Tabs - Dynamic based on installed languages -->
    <?php
    $availableLocales = \App\Support\I18n::getAvailableLocales();
    $defaultLocale = array_key_first($availableLocales) ?: 'it_IT';
    // Flag emoji mapping for common locales
    $flagMap = [
        'it_IT' => '🇮🇹', 'en_US' => '🇬🇧', 'en_GB' => '🇬🇧', 'fr_FR' => '🇫🇷',
        'de_DE' => '🇩🇪', 'es_ES' => '🇪🇸', 'pt_PT' => '🇵🇹', 'pt_BR' => '🇧🇷',
        'nl_NL' => '🇳🇱', 'pl_PL' => '🇵🇱', 'ru_RU' => '🇷🇺', 'zh_CN' => '🇨🇳',
        'ja_JP' => '🇯🇵', 'ko_KR' => '🇰🇷', 'ar_SA' => '🇸🇦', 'he_IL' => '🇮🇱',
    ];
    ?>
    <div class="flex border-b border-gray-200 mb-6">
        <?php $first = true; foreach ($availableLocales as $localeCode => $localeName): ?>
        <button class="tab-btn px-6 py-3 text-sm font-medium text-gray-600 hover:text-gray-900<?= $first ? ' tab-active' : '' ?>" data-locale="<?= htmlspecialchars($localeCode) ?>">
            <span class="mr-2"><?= $flagMap[$localeCode] ?? '🌐' ?></span> <?= htmlspecialchars($localeName) ?>
        </button>
        <?php $first = false; endforeach; ?>
    </div>

    <!-- Stats -->
    <div class="stats-bar">
        <div class="stat-item">
            <span class="stat-label"><?= __('Voci totali') ?></span>
            <span class="stat-value" id="stat-total">-</span>
        </div>
        <div class="stat-item">
            <span class="stat-label"><?= __('Classi principali') ?></span>
            <span class="stat-value" id="stat-level1">-</span>
        </div>
        <div class="stat-item">
            <span class="stat-label"><?= __('Divisioni') ?></span>
            <span class="stat-value" id="stat-level2">-</span>
        </div>
        <div class="stat-item">
            <span class="stat-label"><?= __('Sezioni') ?></span>
            <span class="stat-value" id="stat-level3">-</span>
        </div>
        <div class="stat-item">
            <span class="stat-label"><?= __('Decimali') ?></span>
            <span class="stat-value" id="stat-decimals">-</span>
        </div>
    </div>

    <!-- Search -->
    <div class="mb-4">
        <input type="text" id="search-input" placeholder="<?= __('Cerca codice o nome...') ?>"
               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
    </div>

    <!-- Tree Container -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div id="dewey-tree" class="dewey-tree">
            <div class="text-center text-gray-500 py-8">
                <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
                <p><?= __('Caricamento...') ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Hidden file input for import -->
<input type="file" id="import-file" accept=".json" class="hidden">

<!-- Modal Template -->
<div id="dewey-modal-backdrop" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div id="dewey-modal-content" class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
        <!-- Content injected by JS -->
    </div>
</div>

<script>
(function() {
    'use strict';

    const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
    let currentLocale = <?= json_encode($defaultLocale) ?>;
    let deweyData = null;
    let originalData = null;
    let hasChanges = false;

    // DOM Elements
    const treeContainer = document.getElementById('dewey-tree');
    const saveBtn = document.getElementById('btn-save');
    const exportBtn = document.getElementById('btn-export');
    const importBtn = document.getElementById('btn-import');
    const backupsBtn = document.getElementById('btn-backups');
    const importFile = document.getElementById('import-file');
    const searchInput = document.getElementById('search-input');
    const tabBtns = document.querySelectorAll('.tab-btn');
    const modalBackdrop = document.getElementById('dewey-modal-backdrop');
    const modalContent = document.getElementById('dewey-modal-content');

    // Initialize
    document.addEventListener('DOMContentLoaded', () => {
        loadData(currentLocale);
        bindEvents();
    });

    function bindEvents() {
        // Tab switching
        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                if (hasChanges) {
                    if (!confirm(<?= json_encode(__('Hai modifiche non salvate. Vuoi continuare e perderle?')) ?>)) {
                        return;
                    }
                }
                tabBtns.forEach(b => b.classList.remove('tab-active'));
                btn.classList.add('tab-active');
                currentLocale = btn.dataset.locale;
                loadData(currentLocale);
            });
        });

        // Save
        saveBtn.addEventListener('click', saveData);

        // Export
        exportBtn.addEventListener('click', () => {
            window.location.href = `/api/dewey-editor/export/${currentLocale}`;
        });

        // Import - show mode selection dialog
        let importMode = 'replace';
        importBtn.addEventListener('click', async () => {
            const { value: mode } = await Swal.fire({
                title: <?= json_encode(__('Modalità di importazione')) ?>,
                input: 'radio',
                inputOptions: {
                    'merge': <?= json_encode(__('Merge - Aggiungi e aggiorna (mantiene dati esistenti)')) ?>,
                    'replace': <?= json_encode(__('Sostituisci - Sovrascrivi tutto')) ?>
                },
                inputValue: 'merge',
                showCancelButton: true,
                confirmButtonText: <?= json_encode(__('Seleziona file')) ?>,
                cancelButtonText: <?= json_encode(__('Annulla')) ?>,
                inputValidator: (value) => {
                    if (!value) {
                        return <?= json_encode(__('Seleziona una modalità')) ?>;
                    }
                }
            });

            if (mode) {
                importMode = mode;
                importFile.click();
            }
        });
        importFile.addEventListener('change', () => handleImport(importMode));

        // Backups
        backupsBtn.addEventListener('click', showBackups);

        // Search
        let searchTimeout;
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => filterTree(searchInput.value), 300);
        });

        // Modal close on backdrop click
        modalBackdrop.addEventListener('click', (e) => {
            if (e.target === modalBackdrop) closeModal();
        });

        // Warn before leaving with unsaved changes
        window.addEventListener('beforeunload', (e) => {
            if (hasChanges) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    async function loadData(locale) {
        treeContainer.innerHTML = `<div class="text-center text-gray-500 py-8">
            <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
            <p><?= __('Caricamento...') ?></p>
        </div>`;

        try {
            const response = await fetch(`/api/dewey-editor/data/${locale}`);
            const result = await response.json();

            if (result.success) {
                deweyData = result.data;
                originalData = JSON.parse(JSON.stringify(deweyData));
                renderTree();
                updateStats();
                hasChanges = false;
                saveBtn.disabled = true;
            } else {
                const errorMsg = result.error || <?= json_encode(__('Errore nel caricamento.')) ?>;
                treeContainer.innerHTML = `<div class="text-center text-red-500 py-8">
                    <i class="fas fa-exclamation-circle text-2xl mb-2"></i>
                    <p>${escapeHtml(errorMsg)}</p>
                </div>`;
            }
        } catch (error) {
            console.error('Load error:', error);
            treeContainer.innerHTML = `<div class="text-center text-red-500 py-8">
                <i class="fas fa-exclamation-circle text-2xl mb-2"></i>
                <p><?= __('Errore di connessione.') ?></p>
            </div>`;
        }
    }

    function renderTree() {
        treeContainer.innerHTML = '';
        if (!deweyData || !deweyData.length) {
            treeContainer.innerHTML = '<p class="text-gray-500"><?= __('Nessun dato.') ?></p>';
            return;
        }

        deweyData.forEach(node => {
            treeContainer.appendChild(renderNode(node, 1));
        });
    }

    function renderNode(node, depth) {
        const div = document.createElement('div');
        div.className = `dewey-node level-${node.level}`;
        div.dataset.code = node.code;

        const hasChildren = node.children && node.children.length > 0;
        const isDecimal = node.code.includes('.');
        const canDelete = isDecimal;

        const item = document.createElement('div');
        item.className = 'dewey-item';
        item.innerHTML = `
            <span class="dewey-toggle">${hasChildren ? '▶' : '·'}</span>
            <span class="dewey-code">${escapeHtml(node.code)}</span>
            <span class="dewey-name">${escapeHtml(node.name)}</span>
            <div class="dewey-actions">
                <button class="dewey-btn dewey-btn-edit" data-action="edit" title="<?= __('Modifica') ?>">
                    <i class="fas fa-pen"></i>
                </button>
                <button class="dewey-btn dewey-btn-add" data-action="add" title="<?= __('Aggiungi decimale') ?>">
                    <i class="fas fa-plus"></i>
                </button>
                ${canDelete ? `<button class="dewey-btn dewey-btn-delete" data-action="delete" title="<?= __('Elimina') ?>">
                    <i class="fas fa-trash"></i>
                </button>` : ''}
            </div>
        `;

        div.appendChild(item);

        // Children container
        if (hasChildren) {
            const childrenDiv = document.createElement('div');
            childrenDiv.className = 'dewey-children';
            node.children.forEach(child => {
                childrenDiv.appendChild(renderNode(child, depth + 1));
            });
            div.appendChild(childrenDiv);

            // Toggle
            const toggle = item.querySelector('.dewey-toggle');
            toggle.addEventListener('click', () => {
                const isOpen = childrenDiv.classList.toggle('open');
                toggle.textContent = isOpen ? '▼' : '▶';
            });
        }

        // Action buttons
        item.querySelectorAll('.dewey-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const action = btn.dataset.action;
                if (action === 'edit') showEditModal(node);
                else if (action === 'add') showAddModal(node);
                else if (action === 'delete') confirmDelete(node);
            });
        });

        return div;
    }

    function showEditModal(node) {
        modalContent.innerHTML = `
            <div class="p-6">
                <h3 class="text-lg font-semibold mb-4"><?= __('Modifica') ?> ${escapeHtml(node.code)}</h3>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= __('Nome') ?></label>
                    <input type="text" id="edit-name" value="${escapeHtml(node.name)}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="flex justify-end gap-3">
                    <button class="px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg" onclick="DeweyEditor.closeModal()">
                        <?= __('Annulla') ?>
                    </button>
                    <button class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700" onclick="DeweyEditor.saveEdit('${node.code}')">
                        <?= __('Salva') ?>
                    </button>
                </div>
            </div>
        `;
        modalBackdrop.classList.remove('hidden');
        document.getElementById('edit-name').focus();
    }

    function showAddModal(parentNode) {
        const parentCode = parentNode.code;
        // Get base code (first 3 digits) for suggested value
        const baseCode = parentCode.includes('.') ? parentCode.split('.')[0] : parentCode;
        const suggestedCode = baseCode + '.';
        const exampleCode = baseCode + '.1';

        modalContent.innerHTML = `
            <div class="p-6">
                <h3 class="text-lg font-semibold mb-4"><?= __('Aggiungi decimale sotto') ?> ${escapeHtml(parentCode)}</h3>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= __('Codice') ?></label>
                    <input type="text" id="add-code" value="${suggestedCode}" placeholder="es. ${exampleCode}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    <p class="text-xs text-gray-500 mt-1"><?= __('Formato: 3 cifre + punto + 1-4 cifre decimali') ?></p>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= __('Nome') ?></label>
                    <input type="text" id="add-name" placeholder="<?= __('Nome della classificazione') ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="flex justify-end gap-3">
                    <button class="px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg" onclick="DeweyEditor.closeModal()">
                        <?= __('Annulla') ?>
                    </button>
                    <button class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700" onclick="DeweyEditor.saveAdd('${parentCode}')">
                        <?= __('Aggiungi') ?>
                    </button>
                </div>
            </div>
        `;
        modalBackdrop.classList.remove('hidden');
        document.getElementById('add-code').focus();
    }

    function confirmDelete(node) {
        Swal.fire({
            title: <?= json_encode(__('Sei sicuro?')) ?>,
            text: `${<?= json_encode(__('Vuoi eliminare')) ?>} ${node.code} - ${node.name}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonText: <?= json_encode(__('Annulla')) ?>,
            confirmButtonText: <?= json_encode(__('Elimina')) ?>
        }).then((result) => {
            if (result.isConfirmed) {
                deleteNode(node.code);
            }
        });
    }

    function saveEditHandler(code) {
        const name = document.getElementById('edit-name').value.trim();
        if (name.length < 2) {
            Swal.fire(<?= json_encode(__('Errore')) ?>, <?= json_encode(__('Il nome deve avere almeno 2 caratteri.')) ?>, 'error');
            return;
        }

        const node = findNode(deweyData, code);
        if (node) {
            node.name = name;
            markChanged();
            renderTree();
        }
        closeModal();
    }

    function saveAddHandler(parentCode) {
        const code = document.getElementById('add-code').value.trim();
        const name = document.getElementById('add-name').value.trim();

        // Validate code format
        if (!/^[0-9]{3}\.[0-9]{1,4}$/.test(code)) {
            Swal.fire(<?= json_encode(__('Errore')) ?>, <?= json_encode(__('Formato codice non valido. Usa: XXX.Y (es. 599.1)')) ?>, 'error');
            return;
        }

        // Check code starts with parent base (first 3 digits)
        const baseCode = parentCode.includes('.') ? parentCode.split('.')[0] : parentCode;
        if (!code.startsWith(baseCode)) {
            Swal.fire(<?= json_encode(__('Errore')) ?>, <?= json_encode(__('Il codice deve iniziare con il prefisso del genitore.')) ?>, 'error');
            return;
        }

        // Check uniqueness
        if (findNode(deweyData, code)) {
            Swal.fire(<?= json_encode(__('Errore')) ?>, <?= json_encode(__('Questo codice esiste già.')) ?>, 'error');
            return;
        }

        if (name.length < 2) {
            Swal.fire(<?= json_encode(__('Errore')) ?>, <?= json_encode(__('Il nome deve avere almeno 2 caratteri.')) ?>, 'error');
            return;
        }

        // Find parent and add child
        const parent = findNode(deweyData, parentCode);
        if (parent) {
            if (!parent.children) parent.children = [];
            parent.children.push({
                code: code,
                name: name,
                level: parent.level + 1,
                children: []
            });
            // Sort children by code
            parent.children.sort((a, b) => a.code.localeCompare(b.code));
            markChanged();
            renderTree();
            updateStats();
        }
        closeModal();
    }

    function deleteNode(code) {
        deleteNodeRecursive(deweyData, code);
        markChanged();
        renderTree();
        updateStats();
    }

    function deleteNodeRecursive(nodes, code) {
        for (let i = 0; i < nodes.length; i++) {
            if (nodes[i].code === code) {
                nodes.splice(i, 1);
                return true;
            }
            if (nodes[i].children && deleteNodeRecursive(nodes[i].children, code)) {
                return true;
            }
        }
        return false;
    }

    function findNode(nodes, code) {
        for (const node of nodes) {
            if (node.code === code) return node;
            if (node.children) {
                const found = findNode(node.children, code);
                if (found) return found;
            }
        }
        return null;
    }

    function markChanged() {
        hasChanges = true;
        saveBtn.disabled = false;
    }

    async function saveData() {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i><?= __('Salvataggio...') ?>';

        try {
            const response = await fetch(`/api/dewey-editor/save/${currentLocale}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({ data: deweyData, csrf_token: CSRF_TOKEN })
            });

            const result = await response.json();

            if (result.success) {
                originalData = JSON.parse(JSON.stringify(deweyData));
                hasChanges = false;
                Swal.fire({
                    icon: 'success',
                    title: <?= json_encode(__('Salvato')) ?>,
                    text: result.message
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: <?= json_encode(__('Errore')) ?>,
                    text: result.error
                });
            }
        } catch (error) {
            console.error('Save error:', error);
            Swal.fire({
                icon: 'error',
                title: <?= json_encode(__('Errore')) ?>,
                text: <?= json_encode(__('Errore di connessione.')) ?>
            });
        }

        saveBtn.innerHTML = '<i class="fas fa-save mr-2"></i><?= __('Salva Modifiche') ?>';
        saveBtn.disabled = !hasChanges;
    }

    async function handleImport(mode = 'replace') {
        const file = importFile.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('file', file);
        formData.append('csrf_token', CSRF_TOKEN);

        // Use merge endpoint if mode is merge, otherwise use import (replace)
        const endpoint = mode === 'merge'
            ? `/api/dewey-editor/merge/${currentLocale}`
            : `/api/dewey-editor/import/${currentLocale}`;

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'X-CSRF-Token': CSRF_TOKEN },
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                const title = mode === 'merge'
                    ? <?= json_encode(__('Merge completato')) ?>
                    : <?= json_encode(__('Importato')) ?>;
                Swal.fire(title, result.message, 'success');
                loadData(currentLocale);
            } else {
                const lines = [];
                if (result.error) {
                    lines.push(result.error);
                }
                if (Array.isArray(result.errors) && result.errors.length) {
                    lines.push(...result.errors.slice(0, 5));
                    if (result.errors.length > 5) {
                        lines.push('... e altri ' + (result.errors.length - 5) + ' errori');
                    }
                }
                Swal.fire({
                    icon: 'error',
                    title: <?= json_encode(__('Errore')) ?>,
                    text: lines.join('\n')
                });
            }
        } catch (error) {
            console.error('Import error:', error);
            Swal.fire(<?= json_encode(__('Errore')) ?>, <?= json_encode(__('Errore di connessione.')) ?>, 'error');
        }

        importFile.value = '';
    }

    async function showBackups() {
        try {
            const response = await fetch(`/api/dewey-editor/backups/${currentLocale}`);
            const result = await response.json();

            if (!result.success || !result.backups.length) {
                Swal.fire(<?= json_encode(__('Backup')) ?>, <?= json_encode(__('Nessun backup disponibile.')) ?>, 'info');
                return;
            }

            let html = '<div class="p-6"><h3 class="text-lg font-semibold mb-4"><?= __('Backup disponibili') ?></h3>';
            html += '<div class="space-y-2 max-h-64 overflow-y-auto">';
            result.backups.forEach(b => {
                // Sanitize values for safe HTML insertion
                const safeDate = escapeHtml(b.date);
                const safeSize = escapeHtml((b.size / 1024).toFixed(1));
                const safeFilename = encodeURIComponent(b.filename);
                html += `<div class="flex items-center justify-between p-2 bg-gray-50 rounded">
                    <div>
                        <div class="font-medium">${safeDate}</div>
                        <div class="text-xs text-gray-500">${safeSize} KB</div>
                    </div>
                    <button class="px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700"
                            onclick="DeweyEditor.restoreBackup(decodeURIComponent('${safeFilename}'))">
                        <?= __('Ripristina') ?>
                    </button>
                </div>`;
            });
            html += '</div>';
            html += '<div class="mt-4 flex justify-end"><button class="px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg" onclick="DeweyEditor.closeModal()"><?= __('Chiudi') ?></button></div></div>';

            modalContent.innerHTML = html;
            modalBackdrop.classList.remove('hidden');
        } catch (error) {
            console.error('Backups error:', error);
            Swal.fire(<?= json_encode(__('Errore')) ?>, <?= json_encode(__('Errore nel caricamento dei backup.')) ?>, 'error');
        }
    }

    async function restoreBackupHandler(filename) {
        const confirm = await Swal.fire({
            title: <?= json_encode(__('Ripristinare questo backup?')) ?>,
            text: <?= json_encode(__('I dati attuali verranno sostituiti.')) ?>,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: <?= json_encode(__('Ripristina')) ?>,
            cancelButtonText: <?= json_encode(__('Annulla')) ?>
        });

        if (!confirm.isConfirmed) return;

        try {
            const response = await fetch(`/api/dewey-editor/restore/${currentLocale}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({ filename, csrf_token: CSRF_TOKEN })
            });

            const result = await response.json();

            if (result.success) {
                closeModal();
                Swal.fire(<?= json_encode(__('Ripristinato')) ?>, result.message, 'success');
                loadData(currentLocale);
            } else {
                Swal.fire(<?= json_encode(__('Errore')) ?>, result.error, 'error');
            }
        } catch (error) {
            console.error('Restore error:', error);
            Swal.fire(<?= json_encode(__('Errore')) ?>, <?= json_encode(__('Errore di connessione.')) ?>, 'error');
        }
    }

    function filterTree(query) {
        query = query.toLowerCase().trim();
        const items = treeContainer.querySelectorAll('.dewey-node');

        if (!query) {
            items.forEach(item => item.style.display = '');
            return;
        }

        items.forEach(item => {
            const code = item.dataset.code.toLowerCase();
            const name = item.querySelector('.dewey-name')?.textContent.toLowerCase() || '';
            const match = code.includes(query) || name.includes(query);
            item.style.display = match ? '' : 'none';

            // Expand parents of matching items
            if (match) {
                let parent = item.parentElement;
                while (parent && parent.classList.contains('dewey-children')) {
                    parent.classList.add('open');
                    const toggle = parent.previousElementSibling?.querySelector('.dewey-toggle');
                    if (toggle) toggle.textContent = '▼';
                    parent = parent.parentElement?.parentElement;
                }
            }
        });
    }

    function updateStats() {
        const stats = { total: 0, level1: 0, level2: 0, level3: 0, decimals: 0 };
        countStats(deweyData, stats);

        document.getElementById('stat-total').textContent = stats.total;
        document.getElementById('stat-level1').textContent = stats.level1;
        document.getElementById('stat-level2').textContent = stats.level2;
        document.getElementById('stat-level3').textContent = stats.level3;
        document.getElementById('stat-decimals').textContent = stats.decimals;
    }

    function countStats(nodes, stats) {
        nodes.forEach(node => {
            stats.total++;
            if (node.level === 1) stats.level1++;
            else if (node.level === 2) stats.level2++;
            else if (node.level === 3) stats.level3++;
            else stats.decimals++;

            if (node.children) countStats(node.children, stats);
        });
    }

    function closeModal() {
        modalBackdrop.classList.add('hidden');
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Expose to global for onclick handlers
    window.DeweyEditor = {
        closeModal,
        saveEdit: saveEditHandler,
        saveAdd: saveAddHandler,
        restoreBackup: restoreBackupHandler
    };
})();
</script>
