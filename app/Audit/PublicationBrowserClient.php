<?php

declare(strict_types=1);

namespace App\Audit;

interface PublicationBrowserClient
{
    /**
     * @return array{
     *   access_status: 'opened'|'blocked'|'failed',
     *   read_status: 'readable'|'unreadable'|'uncertain',
     *   metadata: array<string, mixed>,
     *   artifacts: array<string, mixed>,
     *   error_code: ?string,
     *   error_message: ?string
     * }
     */
    public function inspect(string $url): array;
}
