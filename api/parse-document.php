<?php

require_once __DIR__ . '/../includes/gemini.php';

$user = auth_require();
$method = get_method();

if ($method !== 'POST') {
    json_error('Method not allowed', 405);
}

csrf_verify();

if (!gemini_configured()) {
    json_error('AI document parser is not configured. Set GEMINI_API_KEY in server environment.', 503);
}

$file = $_FILES['file'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $msg = match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds upload limit',
        UPLOAD_ERR_PARTIAL => 'Upload incomplete — try again',
        UPLOAD_ERR_NO_FILE => 'No file uploaded',
        default => 'Upload failed',
    };
    json_error($msg, 400);
}

if (($file['size'] ?? 0) > MAX_UPLOAD_SIZE) {
    json_error('File exceeds maximum size of ' . MAX_UPLOAD_SIZE . ' bytes', 400);
}

$filename = basename($file['name'] ?? 'document.pdf');
$mime = gemini_mime_for_filename($filename);
if ($mime === null) {
    json_error('Unsupported file type. Allowed: PDF, PNG, JPG, WEBP, XLS, XLSX', 400);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$detected = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
if ($finfo) {
    finfo_close($finfo);
}
if ($detected && is_string($detected)) {
    $allowed = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/webp',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/octet-stream',
    ];
    if (in_array($detected, $allowed, true) && $detected !== 'application/octet-stream') {
        $mime = $detected;
    }
}

try {
    $parsed = gemini_parse_remittance_document($file['tmp_name'], $mime, $filename);
} catch (Throwable $e) {
    json_error('Document parsing failed: ' . $e->getMessage(), 502);
}

if (empty($parsed['transactions'])) {
    json_error('No transactions found in document. Try a different file or paste text manually.', 422);
}

json_response([
    'success' => true,
    'parser' => 'gemini',
    'model' => defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.0-flash',
    'transaction_count' => count($parsed['transactions']),
    'parsed' => $parsed,
]);
