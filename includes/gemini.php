<?php

/**
 * Server-side Gemini document parsing for remittance / agency reports.
 * Requires GEMINI_API_KEY in environment (never expose to browser).
 */

function gemini_configured(): bool {
    return defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '';
}

function gemini_mime_for_filename(string $filename): ?string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match ($ext) {
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls' => 'application/vnd.ms-excel',
        'csv' => 'text/csv',
        default => null,
    };
}

function gemini_is_text_document(string $mimeType, string $filename): bool {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return $mimeType === 'text/csv' || $ext === 'csv';
}

function gemini_parse_remittance_document(string $filePath, string $mimeType, string $filename): array {
    if (!gemini_configured()) {
        throw new RuntimeException('Gemini API is not configured');
    }
    if (!is_readable($filePath)) {
        throw new RuntimeException('Document file is not readable');
    }

    $size = filesize($filePath);
    if ($size === false || $size <= 0) {
        throw new RuntimeException('Document file is empty');
    }
    if ($size > MAX_UPLOAD_SIZE) {
        throw new RuntimeException('Document exceeds maximum upload size');
    }

    $binary = file_get_contents($filePath);
    if ($binary === false) {
        throw new RuntimeException('Failed to read document file');
    }

    $prompt = gemini_remittance_prompt($filename);
    if (gemini_is_text_document($mimeType, $filename)) {
        $textBody = file_get_contents($filePath);
        if ($textBody === false || trim($textBody) === '') {
            throw new RuntimeException('CSV/text document is empty');
        }
        if (strlen($textBody) > MAX_UPLOAD_SIZE) {
            throw new RuntimeException('Document exceeds maximum upload size');
        }
        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt . "\n\nDocument text:\n" . $textBody],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
            ],
        ];
    } else {
        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data' => base64_encode($binary),
                        ],
                    ],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
            ],
        ];
    }

    $model = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-3.5-flash';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model)
        . ':generateContent?key=' . rawurlencode(GEMINI_API_KEY);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 120,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Gemini request failed: ' . ($curlErr ?: 'unknown error'));
    }

    $decoded = json_decode($raw, true);
    if ($httpCode >= 400) {
        $msg = $decoded['error']['message'] ?? ('HTTP ' . $httpCode);
        throw new RuntimeException('Gemini API error: ' . $msg);
    }

    $text = gemini_extract_text($decoded);
    if ($text === '') {
        throw new RuntimeException('Gemini returned an empty response');
    }

    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        throw new RuntimeException('Gemini returned invalid JSON for the report');
    }

    return gemini_sanitize_parsed_report($parsed);
}

function gemini_extract_text(array $response): string {
    $parts = $response['candidates'][0]['content']['parts'] ?? [];
    $chunks = [];
    foreach ($parts as $part) {
        if (!empty($part['text'])) {
            $chunks[] = $part['text'];
        }
    }
    return trim(implode("\n", $chunks));
}

function gemini_remittance_prompt(string $filename): string {
    return <<<PROMPT
You are a data extraction engine for money-transfer agency reports (Barri, Viamericas, Intercambio, Intermex, Ria / Dandelion).

Analyze the attached document "{$filename}" and return ONLY valid JSON matching this schema (no markdown, no commentary):

{
  "agency_number": "string",
  "agency_name": "string",
  "agency_address": "string",
  "operator_number": "string",
  "date_from": "YYYY-MM-DD",
  "date_to": "YYYY-MM-DD",
  "currency": "USD",
  "beginning_balance": number,
  "ending_balance": number,
  "company": "Barri|Viamericas|Intercambio|Intermex|Ria",
  "store_name": "string",
  "transactions": [
    {
      "transaction_date": "YYYY-MM-DD",
      "time": "HH:MM or empty",
      "type": "string",
      "reference": "string",
      "customer_name": "string",
      "beneficiary": "string",
      "operator": "string",
      "qty": number,
      "principal": number,
      "fee": number,
      "tax": number,
      "total": number,
      "balance": number,
      "agcomm": number,
      "var_fee": number,
      "var_fx": number
    }
  ],
  "totals": {
    "qty": number,
    "principal": number,
    "fee": number,
    "tax": number,
    "total": number,
    "agcomm": number,
    "var_fee": number,
    "var_fx": number
  }
}

Rules:
- Extract every transaction row you can find. Use 0 for missing numeric fields.
- Dates must be ISO YYYY-MM-DD. If only one report date range exists, set date_from and date_to accordingly.
- company must reflect the report vendor (Barri, Viamericas, Intercambio, Intermex, or Ria).
- agency_number is REQUIRED when present in the document: Viamericas A-prefix (e.g. A22592), Intermex TX-prefix (e.g. TX3499), Barri numeric agency (e.g. 240247), Intercambio store codes. Check headers labeled Agencia, Agency, Sucursal, and transaction refs like A22592-12866.
- operator_number when present: Barri Operador (a12345) or Viamericas Cajero/SULY codes (SULY2022). Also set store_name when the document names the branch.
- totals should match document summary when present; otherwise sum transactions.
- Do not invent transactions that are not in the document.
- Viamericas ViaRemote "Creación de Envíos" PDFs: rows may span multiple lines — agency ref (A22592 -), transaction number, customer/beneficiary names, SULY#### + Pagado/Cancelado, then C$ amounts or C($...) for cancelled.
- Viamericas email/table reports: refs like A10556-75771 with date, sender, beneficiary, principal, fee.
- If the document is not a remittance report, return {"agency_name":"Unknown","date_from":"","date_to":"","transactions":[],"totals":{"qty":0,"principal":0,"fee":0,"tax":0,"total":0,"agcomm":0,"var_fee":0,"var_fx":0}}
PROMPT;
}

function gemini_sanitize_parsed_report(array $data): array {
    $cleanStr = static function ($v): string {
        $s = trim((string)($v ?? ''));
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        return mb_substr($s, 0, 500);
    };
    $cleanNum = static function ($v): float {
        if (is_numeric($v)) {
            return round((float)$v, 2);
        }
        $s = preg_replace('/[^0-9.\-]/', '', (string)$v) ?? '0';
        return round((float)$s, 2);
    };
    $cleanDate = static function ($v) use ($cleanStr): string {
        $s = $cleanStr($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return $s;
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[1], (int)$m[2]);
        }
        return '';
    };

    $out = [
        'agency_number' => $cleanStr($data['agency_number'] ?? ''),
        'agency_name' => $cleanStr($data['agency_name'] ?? '') ?: 'Unknown Agency',
        'agency_address' => $cleanStr($data['agency_address'] ?? ''),
        'operator_number' => $cleanStr($data['operator_number'] ?? ''),
        'date_from' => $cleanDate($data['date_from'] ?? $data['report_date_from'] ?? ''),
        'date_to' => $cleanDate($data['date_to'] ?? $data['report_date_to'] ?? ''),
        'currency' => $cleanStr($data['currency'] ?? 'USD') ?: 'USD',
        'beginning_balance' => $cleanNum($data['beginning_balance'] ?? 0),
        'ending_balance' => $cleanNum($data['ending_balance'] ?? 0),
        'company' => $cleanStr($data['company'] ?? ''),
        'store_name' => $cleanStr($data['store_name'] ?? ''),
        'transactions' => [],
        'totals' => [
            'qty' => 0,
            'principal' => 0,
            'fee' => 0,
            'tax' => 0,
            'total' => 0,
            'agcomm' => 0,
            'var_fee' => 0,
            'var_fx' => 0,
        ],
    ];

    $txns = $data['transactions'] ?? [];
    if (!is_array($txns)) {
        $txns = [];
    }

    $maxTx = 5000;
    foreach (array_slice($txns, 0, $maxTx) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $principal = $cleanNum($row['principal'] ?? $row['amount'] ?? 0);
        if ($principal == 0.0 && $cleanNum($row['total'] ?? 0) == 0.0) {
            continue;
        }
        $out['transactions'][] = [
            'transaction_date' => $cleanDate($row['transaction_date'] ?? $row['date'] ?? $out['date_from']),
            'time' => $cleanStr($row['time'] ?? $row['transaction_time'] ?? ''),
            'type' => $cleanStr($row['type'] ?? $row['transaction_type'] ?? ''),
            'reference' => $cleanStr($row['reference'] ?? $row['reference_number'] ?? ''),
            'customer_name' => $cleanStr($row['customer_name'] ?? $row['name'] ?? ''),
            'beneficiary' => $cleanStr($row['beneficiary'] ?? ''),
            'operator' => $cleanStr($row['operator'] ?? ''),
            'qty' => (int)max(0, (int)($row['qty'] ?? 1)),
            'principal' => $principal,
            'fee' => $cleanNum($row['fee'] ?? 0),
            'tax' => $cleanNum($row['tax'] ?? 0),
            'total' => $cleanNum($row['total'] ?? ($principal + $cleanNum($row['fee'] ?? 0))),
            'balance' => $cleanNum($row['balance'] ?? $row['running_balance'] ?? 0),
            'agcomm' => $cleanNum($row['agcomm'] ?? $row['ag_commission'] ?? 0),
            'var_fee' => $cleanNum($row['var_fee'] ?? 0),
            'var_fx' => $cleanNum($row['var_fx'] ?? 0),
        ];
    }

    $totals = is_array($data['totals'] ?? null) ? $data['totals'] : [];
    if (!empty($totals)) {
        $out['totals'] = [
            'qty' => (int)max(0, (int)($totals['qty'] ?? count($out['transactions']))),
            'principal' => $cleanNum($totals['principal'] ?? 0),
            'fee' => $cleanNum($totals['fee'] ?? 0),
            'tax' => $cleanNum($totals['tax'] ?? 0),
            'total' => $cleanNum($totals['total'] ?? 0),
            'agcomm' => $cleanNum($totals['agcomm'] ?? 0),
            'var_fee' => $cleanNum($totals['var_fee'] ?? 0),
            'var_fx' => $cleanNum($totals['var_fx'] ?? 0),
        ];
    } elseif (count($out['transactions']) > 0) {
        foreach ($out['transactions'] as $t) {
            $out['totals']['qty']++;
            $out['totals']['principal'] += $t['principal'];
            $out['totals']['fee'] += $t['fee'];
            $out['totals']['tax'] += $t['tax'];
            $out['totals']['total'] += $t['total'];
            $out['totals']['agcomm'] += $t['agcomm'];
            $out['totals']['var_fee'] += $t['var_fee'];
            $out['totals']['var_fx'] += $t['var_fx'];
        }
        foreach (['principal', 'fee', 'tax', 'total', 'agcomm', 'var_fee', 'var_fx'] as $k) {
            $out['totals'][$k] = round($out['totals'][$k], 2);
        }
    }

    if ($out['date_from'] === '' && count($out['transactions']) > 0) {
        $out['date_from'] = $out['transactions'][0]['transaction_date'] ?: date('Y-m-d');
    }
    if ($out['date_to'] === '') {
        $out['date_to'] = $out['date_from'] ?: date('Y-m-d');
    }

    return gemini_enrich_agency_metadata($out);
}

function gemini_enrich_agency_metadata(array $parsed): array {
    if (!$parsed['agency_number'] && !empty($parsed['transactions']) && is_array($parsed['transactions'])) {
        foreach ($parsed['transactions'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ref = (string)($row['reference'] ?? '');
            if (preg_match('/^(A\d{4,6})-\d+/i', $ref, $m)) {
                $parsed['agency_number'] = strtoupper($m[1]);
                break;
            }
        }
    }

    if (!$parsed['operator_number'] && !empty($parsed['transactions']) && is_array($parsed['transactions'])) {
        foreach ($parsed['transactions'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $op = strtoupper(trim((string)($row['operator'] ?? '')));
            if ($op !== '' && preg_match('/^(SULY|A)\d+$/i', $op)) {
                $parsed['operator_number'] = $op;
                break;
            }
        }
    }

    if (!empty($parsed['agency_number'])) {
        $parsed['agency_number'] = strtoupper(trim(str_replace(' ', '', (string)$parsed['agency_number'])));
    }
    if (!empty($parsed['operator_number'])) {
        $parsed['operator_number'] = strtoupper(trim(str_replace(' ', '', (string)$parsed['operator_number'])));
    }

    return $parsed;
}
