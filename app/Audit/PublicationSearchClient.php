<?php

declare(strict_types=1);

namespace App\Audit;

interface PublicationSearchClient
{
    /**
     * @return array<int, array{rank: int, title: string, url: string, snippet?: string}>
     */
    public function search(string $query, int $limit = 5): array;
}
