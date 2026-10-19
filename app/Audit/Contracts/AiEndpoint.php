<?php

declare(strict_types=1);

namespace App\Audit\Contracts;

interface AiEndpoint
{
    public function baseUrl(): string;

    public function apiKey(): string;

    public function model(): string;

    public function capacityKey(): string;

    public function agentId(): ?int;
}
