/**
 * Installer JavaScript - Pinakes
 */

// Test database connection (AJAX)
function getInstallerTranslation(key, fallback) {
    const dict = window.installerTranslations || {};
    return dict[key] || fallback;
}

function testDatabaseConnection() {
    const btn = document.getElementById('test-connection-btn');
    const result = document.getElementById('connection-result');

    if (!btn || !result) return;

    btn.disabled = true;
    btn.innerHTML = `<span class="spinner"></span> ${getInstallerTranslation('testing', 'Testing...')}`;

    // Null-safe: if any field element is missing on the current step variant,
    // avoid a TypeError that would leave the button stuck in "Testing...".
    const getVal = (id) => document.getElementById(id)?.value ?? '';
    const formData = new FormData();
    formData.append('action', 'test_connection');
    formData.append('host', getVal('db_host'));
    formData.append('username', getVal('db_username'));
    formData.append('password', getVal('db_password'));
    formData.append('database', getVal('db_database'));
    formData.append('port', getVal('db_port'));
    formData.append('socket', getVal('db_socket'));

    fetch('index.php?step=2', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            result.className = 'alert alert-success';
            result.textContent = getInstallerTranslation('testSuccess', '✓ Connection successful! Database is empty and ready for installation.');
            document.getElementById('continue-btn').disabled = false;
        } else {
            result.className = 'alert alert-error';
            result.textContent = '✗ ' + (data.error || getInstallerTranslation('testFailure', 'Connection failed'));
            document.getElementById('continue-btn').disabled = true;
        }
        result.style.display = 'block';
    })
    .catch(error => {
        result.className = 'alert alert-error';
        result.textContent = '✗ ' + getInstallerTranslation('errorPrefix', 'Connection error:') + ' ' + error.message;
        result.style.display = 'block';
    })
    .finally(() => {
        btn.disabled = false;
        // Preserve the <i class="fas fa-plug"></i> icon that step2.php injects
        // inside the button. textContent would strip child nodes and delete
        // the icon on every test cycle — rebuild via safe DOM APIs instead.
        while (btn.firstChild) btn.removeChild(btn.firstChild);
        const icon = document.createElement('i');
        icon.className = 'fas fa-plug';
        btn.appendChild(icon);
        btn.appendChild(document.createTextNode(
            ' ' + getInstallerTranslation('testButton', 'Test Connection')
        ));
    });
}

// File upload preview
function handleFileUpload(input) {
    const file = input.files[0];
    const preview = document.getElementById('logo-preview');

    if (file && preview) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = `<img src="${e.target.result}" style="max-width: 200px; border-radius: 8px;" />`;
        };
        reader.readAsDataURL(file);
    }
}

// Auto-detect MySQL socket
function autoDetectSocket() {
    const host = document.getElementById('db_host')?.value;
    const socketField = document.getElementById('db_socket');

    // Only populate the socket if the user hasn't already entered one (or
    // the server-rendered value) — avoid clobbering on every host-change
    // event or on page load when the field is prefilled from a retry.
    if (host === 'localhost' && socketField && socketField.value.trim() === '') {
        fetch('index.php?action=detect_socket')
            .then(response => response.json())
            .then(data => {
                if (data.socket && socketField.value.trim() === '') {
                    socketField.value = data.socket;
                }
            })
            .catch(error => {
                console.warn('Socket auto-detection failed:', error);
            });
    }
}

// Confirm installer deletion
function confirmDeleteInstaller() {
    return confirm(getInstallerTranslation('confirmDelete', 'Sei sicuro di voler eliminare la cartella installer? Questa azione non può essere annullata.'));
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Auto-detect socket when host changes
    const hostInput = document.getElementById('db_host');
    if (hostInput) {
        hostInput.addEventListener('change', autoDetectSocket);
        autoDetectSocket(); // Run on load
    }

    // File upload handling
    const fileInput = document.getElementById('logo_file');
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            handleFileUpload(this);
        });
    }

    // Password confirmation validation
    const password = document.getElementById('password');
    const passwordConfirm = document.getElementById('password_confirm');

    if (password && passwordConfirm) {
        passwordConfirm.addEventListener('input', function() {
            if (this.value !== password.value) {
                this.setCustomValidity(getInstallerTranslation('passwordMismatch', 'Le password non corrispondono'));
            } else {
                this.setCustomValidity('');
            }
        });
    }
});

// (removed: simulateInstallProgress / animateProgress — dead code. Step 3
// template implements its own inline progress animation and never called
// these helpers. CodeRabbit nitpick.)
