<?php

namespace App\Jobs;

use App\Enums\BatchItemStatus;
use App\Models\User;
use App\Services\Billing\BillingEmailService;
use App\Services\Billing\InvoiceGenerationService;
use App\Support\Settings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Generates a month's bills in the background. Idempotent: running it twice bills nothing twice. */
class GenerateMonthlyBillsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** @param list<int>|null $agreementIds */
    public function __construct(public int $year, public int $month, public int $userId, public ?int $propertyId = null, public ?array $agreementIds = null) {}

    public function uniqueId(): string
    {
        return "{$this->year}-{$this->month}-".($this->propertyId ?? 'all').($this->agreementIds === null ? '' : '-'.hash('sha256', implode(',', $this->agreementIds)));
    }

    public function handle(InvoiceGenerationService $service, ?BillingEmailService $email = null): void
    {
        $batch = $service->generate($this->year, $this->month, User::findOrFail($this->userId), $this->propertyId, $this->agreementIds);
        if (Settings::get('auto_send_email')) {
            $email ??= app(BillingEmailService::class);
            foreach ($batch->items->where('status', BatchItemStatus::Generated) as $item) {
                if ($item->invoice->property->auto_send_email) {
                    $email->send($item->invoice);
                }
            }
        }
    }
}
