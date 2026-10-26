<?php

declare(strict_types=1);

namespace App\Audit;

enum AuditMode: string
{
    case Ai = 'ai';
    case Machine = 'machine';
    case Fusion = 'fusion';

    public function label(): string
    {
        return match ($this) {
            self::Ai => 'Full Vision AI (Standar)',
            self::Machine => 'Mesin Kilat (Zero-Token)',
            self::Fusion => 'Fusion Dual-Engine (Auto-Learning)',
        };
    }

    public function needsAi(): bool
    {
        return $this === self::Ai || $this === self::Fusion;
    }

    public function needsMachine(): bool
    {
        return $this === self::Machine || $this === self::Fusion;
    }
}
