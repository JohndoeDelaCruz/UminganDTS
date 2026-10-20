<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Received = 'received';
    case Awaiting = 'awaiting_acknowledgment';
    case Processing = 'in_progress';
    case Hold = 'on_hold';
    case Returned = 'returned';
    case Completed = 'completed';
    case Released = 'released';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Awaiting => 'Awaiting acknowledgment',
            self::Processing => 'In progress',
            self::Hold => 'On hold',
            default => ucfirst($this->value),
        };
    }

    public function terminal(): bool
    {
        return in_array($this, [self::Completed, self::Released, self::Cancelled], true);
    }

    public function next(): array
    {
        return match ($this) {
            self::Received => [self::Processing, self::Hold, self::Completed, self::Cancelled],
            self::Processing => [self::Hold, self::Completed, self::Cancelled],
            self::Hold, self::Returned => [self::Processing, self::Cancelled],
            self::Completed => [self::Released],
            default => [],
        };
    }

    public function publicLabel(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Awaiting, self::Processing => 'Processing',
            self::Hold, self::Returned => 'Action required',
            self::Completed => 'Ready for release',
            self::Released => 'Released',
            self::Cancelled => 'Closed',
        };
    }
}
