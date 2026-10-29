<?php

declare(strict_types=1);

return [
    'rule_version' => env('AUDIT_RULE_VERSION', 'bps-2026.10.05-v1'),
    'target_rule_version' => 'bps-2026.10.18-v2',
    'rules_v2_enabled' => (bool) env('AUDIT_RULES_V2_ENABLED', true),
    'visual_pair_enabled' => (bool) env('AUDIT_VISUAL_PAIR_ENABLED', false),
    'external_references_enabled' => (bool) env('AUDIT_EXTERNAL_REFERENCES_ENABLED', true),
    'bps_webapi_key' => env('BPS_WEBAPI_KEY', '48d12534ec7c655e55239dde51e54869be3413d86dfc5ae1076e89258a4fc8ff'),
    'pdfinfo' => env('PDFINFO_BINARY', 'pdfinfo'),
    'pdftoppm' => env('PDFTOPPM_BINARY', 'pdftoppm'),
    'pdftotext' => env('PDFTOTEXT_BINARY', 'pdftotext'),
    'text_layer_enabled' => (bool) env('AUDIT_TEXT_LAYER_ENABLED', true),
    'chapter_map_enabled' => (bool) env('AUDIT_CHAPTER_MAP_ENABLED', true),
    'numeric_checks_enabled' => (bool) env('AUDIT_NUMERIC_CHECKS_ENABLED', true),
    'chapter_crosscheck_enabled' => (bool) env('AUDIT_CHAPTER_CROSSCHECK_ENABLED', true),
    'spellcheck_enabled' => (bool) env('AUDIT_SPELLCHECK_ENABLED', true),
    'max_pages' => 500,
    'max_upload_kb' => 153600,
    'dpi' => 160,
    'high_dpi' => 240,
    'ai_timeout' => 180,
    'ai_concurrency' => (int) env('AUDIT_AI_CONCURRENCY', 5),
    'fallback_model' => env('AUDIT_FALLBACK_MODEL', 'claude-sonnet-4-6'),
    'allowed_ai_hosts' => array_filter(explode(',', env('AI_ALLOWED_HOSTS', '9router.com,api.9router.com,limitrouter.com,api.limitrouter.com,abc-tunnel.us,trycloudflare.com,ngrok-free.app,ngrok.io,loca.lt,pinggy.link,tailscale.net,ts.net'))),
    'chatgpt_plan_enabled' => false,
];
