<?php

declare(strict_types=1);

return [
    'rule_version' => 'bps-2026.10.05-v1',
    'pdfinfo' => env('PDFINFO_BINARY', 'pdfinfo'),
    'pdftoppm' => env('PDFTOPPM_BINARY', 'pdftoppm'),
    'max_pages' => 500,
    'max_upload_kb' => 153600,
    'dpi' => 160,
    'high_dpi' => 240,
    'ai_timeout' => 180,
    'ai_concurrency' => (int) env('AUDIT_AI_CONCURRENCY', 2),
    'allowed_ai_hosts' => array_filter(explode(',', env('AI_ALLOWED_HOSTS', '9router.com,limitrouter.com'))),
    'chatgpt_plan_enabled' => false,
];
