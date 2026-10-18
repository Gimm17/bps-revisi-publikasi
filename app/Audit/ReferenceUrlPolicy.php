<?php

declare(strict_types=1);

namespace App\Audit;

class ReferenceUrlPolicy
{
    public function allows(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Exact bps.go.id or subdomain *.bps.go.id
        if ($host !== 'bps.go.id' && ! str_ends_with($host, '.bps.go.id')) {
            return false;
        }

        $port = $parts['port'] ?? 443;
        if ($port !== 443) {
            return false;
        }

        return true;
    }

    public function sanitize(string $text): string
    {
        return preg_replace('/([?&](?:token|key|api_key|secret)=)[^&\s"\']+/i', '$1[REDACTED]', $text) ?? $text;
    }
}
