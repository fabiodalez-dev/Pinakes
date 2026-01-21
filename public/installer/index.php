<?php
/**
 * Installer Bootstrap for Apache/standard webservers
 *
 * This file allows the installer to work when document root is /public/
 * It simply includes the real installer from the parent directory.
 */

// Change to installer directory so relative paths work
chdir(dirname(dirname(__DIR__)) . '/installer');

// Include the real installer
require dirname(dirname(__DIR__)) . '/installer/index.php';
