<?php
/**
 * Validator Class - Input Validation for Installer
 */

class Validator {

    private $errors = [];

    /**
     * Validate email address
     */
    public function validateEmail($email, $field = 'Email') {
        if (empty($email)) {
            $this->errors[$field] = sprintf(__("%s è richiesto"), $field);
            return false;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = sprintf(__("%s non è valido"), $field);
            return false;
        }

        return true;
    }

    /**
     * Validate password strength
     */
    public function validatePassword($password, $field = 'Password') {
        if (empty($password)) {
            $this->errors[$field] = "{$field} è richiesta";
            return false;
        }

        if (strlen($password) < 8) {
            $this->errors[$field] = "{$field} deve essere di almeno 8 caratteri";
            return false;
        }

        return true;
    }

    /**
     * Validate password confirmation
     */
    public function validatePasswordConfirmation($password, $confirmation) {
        if ($password !== $confirmation) {
            $this->errors['password_confirm'] = __("Le password non corrispondono");
            return false;
        }

        return true;
    }

    /**
     * Validate required field
     */
    public function validateRequired($value, $field) {
        if (empty(trim($value))) {
            $this->errors[$field] = sprintf(__("%s è richiesto"), $field);
            return false;
        }

        return true;
    }

    /**
     * Validate database connection
     */
    public function validateDatabaseConnection($host, $username, $password, $database, $port, $socket = null) {
        try {
            // Build DSN
            $dsn = "mysql:";

            if (!empty($socket)) {
                $dsn .= "unix_socket={$socket};";
            } else {
                $hostForTcp = ($host === 'localhost') ? '127.0.0.1' : $host;
                $dsn .= "host={$hostForTcp};port={$port};";
            }

            $dsn .= "charset=utf8mb4";

            // Try to connect (without database first to check credentials)
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // Now try to select the database
            if (!empty($database)) {
                $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = " . $pdo->quote($database));
                $dbExists = $stmt->fetch();

                if (!$dbExists) {
                    $this->errors['database'] = sprintf(__("Database '%s' non esiste. Crealo prima di procedere."), $database);
                    return false;
                }

                // Check if database is empty
                $pdo->exec("USE `{$database}`");
                $stmt = $pdo->query("SHOW TABLES");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

                if (count($tables) > 0) {
                    $this->errors['database'] = sprintf(__("Il database '%s' non è vuoto. Deve essere un database vuoto."), $database);
                    return false;
                }
            }

            return true;

        } catch (PDOException $e) {
            $this->errors['connection'] = __("Connessione al database fallita") . ": " . $e->getMessage();
            return false;
        }
    }

    /**
     * Validate system requirements
     */
    public function validateSystemRequirements() {
        $requirements = [
            'php_version' => version_compare(PHP_VERSION, '8.1.0', '>='),
            'pdo' => extension_loaded('pdo'),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'mysqli' => extension_loaded('mysqli'),
            'mbstring' => extension_loaded('mbstring'),
            'json' => extension_loaded('json'),
            'gd' => extension_loaded('gd'),
            'fileinfo' => extension_loaded('fileinfo')
        ];

        $allMet = true;
        foreach ($requirements as $req => $met) {
            if (!$met) {
                $allMet = false;
                $this->errors[$req] = sprintf(__("Requisito '%s' non soddisfatto"), $req);
            }
        }

        return $allMet;
    }

    /**
     * Validate directory permissions
     */
    public function validateDirectoryPermissions($baseDir) {
        $directories = [
            $baseDir,
            $baseDir . '/storage',
            $baseDir . '/backups'
        ];

        $allWritable = true;
        foreach ($directories as $dir) {
            // Create directory if it doesn't exist
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    $this->errors['permissions'] = sprintf(__("Non è possibile creare la directory: %s"), $dir);
                    $allWritable = false;
                    continue;
                }
            }

            if (!is_writable($dir)) {
                $this->errors['permissions'] = sprintf(__("Directory non scrivibile: %s"), $dir);
                $allWritable = false;
            }
        }

        return $allWritable;
    }

    /**
     * Validate file upload
     */
    public function validateFileUpload($file, $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], $maxSize = 5242880) {
        if (!isset($file['error']) || is_array($file['error'])) {
            $this->errors['file'] = __("Errore nel caricamento del file");
            return false;
        }

        // Check for upload errors
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                return true; // No file uploaded is OK (optional)
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $this->errors['file'] = __("Il file supera la dimensione massima consentita");
                return false;
            default:
                $this->errors['file'] = __("Errore sconosciuto durante il caricamento");
                return false;
        }

        // Check file size
        if ($file['size'] > $maxSize) {
            $this->errors['file'] = sprintf(__("Il file supera la dimensione massima di %s MB"), ($maxSize / 1024 / 1024));
            return false;
        }

        // Check MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, $allowedTypes)) {
            $this->errors['file'] = sprintf(__("Tipo di file non consentito. Sono ammessi solo: %s"), implode(', ', $allowedTypes));
            return false;
        }

        return true;
    }

    /**
     * Get all errors
     */
    public function getErrors() {
        return $this->errors;
    }

    /**
     * Get first error
     */
    public function getFirstError() {
        return !empty($this->errors) ? reset($this->errors) : null;
    }

    /**
     * Check if there are errors
     */
    public function hasErrors() {
        return !empty($this->errors);
    }

    /**
     * Clear errors
     */
    public function clearErrors() {
        $this->errors = [];
    }

    /**
     * Sanitize input
     */
    public function sanitize($input) {
        if (is_array($input)) {
            return array_map([$this, 'sanitize'], $input);
        }

        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
}
