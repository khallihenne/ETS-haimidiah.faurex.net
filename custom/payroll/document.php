<?php
/* Copyright (C) 2025 Your Company
 *
 * This program is free software
 */

/**
 * \file    custom/payroll/document.php
 * \ingroup payroll
 * \brief   Wrapper to download payroll documents
 */

// Load Dolibarr environment
$res = 0;
$sep = DIRECTORY_SEPARATOR;
for ($i = 0; $i < 5; $i++) {
    $dir = str_repeat('..' . $sep, $i);
    if (@include_once $dir . 'main.inc.php') {
        $res = 1;
        break;
    }
}
if (!$res) {
    die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

$modulepart = GETPOST('modulepart', 'alpha');
$file = GETPOST('file', 'alpha');
$entity = GETPOST('entity', 'int') ? GETPOST('entity', 'int') : $conf->entity;

// Security check
if (empty($user->rights->payroll->payrun->read) && empty($user->rights->payroll->payslip->read)) {
    accessforbidden();
}

// Basic security: avoid directory traversal
if (strpos($file, '..') !== false) {
    accessforbidden();
}

$ref = '';
if (preg_match('/payrun\/([^\/]+)\//', $file, $matches)) {
    $ref = $matches[1];
}

// Define storage area
$storage_area = $conf->payroll->dir_output;
$full_path = $storage_area . '/' . $file;

// Check if file exists
if (!file_exists($full_path)) {
    dol_syslog("File not found: " . $full_path, LOG_ERR);
    httponly_accessforbidden('File not found');
}

// MIME type
$type = dol_mimetype($file);
$filename = basename($full_path);

// Download the file
top_httphead($type);
header('Content-Description: File Transfer');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . dol_filesize($full_path));

readfileLowMemory(dol_osencode($full_path));

$db->close();
