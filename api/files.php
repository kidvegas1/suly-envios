<?php
$user = auth_require();
$method = get_method();

if ($method !== 'GET') {
    json_error('Method not allowed', 405);
}

$ref = trim((string)($_GET['ref'] ?? ''));
if ($ref === '') {
    json_error('Missing ref parameter', 400);
}

if (!storage_is_remote($ref)) {
    json_error('Invalid storage reference', 400);
}

$inline = isset($_GET['inline']) && $_GET['inline'] !== '0';
storage_serve($ref, [
    'inline' => $inline,
]);
