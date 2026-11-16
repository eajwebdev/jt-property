<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Billing\InvoiceGenerationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Generates a month's bills in the background. Idempotent: running it twice bills nothing twice. */
class GenerateMonthlyBillsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public int $year, public int $month, public int $userId, public ?int $propertyId = null) {}

    public function uniqueId(): string
    {
        return "{$this->year}-{$this->month}-".($this->propertyId ?? 'all');
    }

    public function handle(InvoiceGenerationService $service): void
    {
        $service->generate($this->year, $this->month, User::findOrFail($this->userId), $this->propertyId);
    }
}
