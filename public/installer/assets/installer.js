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

    const formData = new FormData();
    formData.append('action', 'test_connection');
    formData.append('host', document.getElementById('db_host').value);
    formData.append('username', document.getElementById('db_username').value);
    formData.append('password', document.getElementById('db_password').value);
    formData.append('database', document.getElementById('db_database').value);
    formData.append('port', document.getElementById('db_port').value);
    formData.append('socket', document.getElementById('db_socket').value);

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
        btn.textContent = getInstallerTranslation('testButton', 'Test Connection');
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

    if (host === 'localhost' && socketField) {
        fetch('index.php?action=detect_socket')
            .then(response => response.json())
            .then(data => {
                if (data.socket) {
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

// Progress animation for installation step
function animateProgress(percentage) {
    const progressBar = document.querySelector('.progress-bar-fill');
    if (progressBar) {
        progressBar.style.width = percentage + '%';
    }
}

// Simulate installation progress (for Step 3)
function simulateInstallProgress() {
    let progress = 0;
    const interval = setInterval(() => {
        progress += 10;
        animateProgress(progress);

        if (progress >= 100) {
            clearInterval(interval);
        }
    }, 300);
}
