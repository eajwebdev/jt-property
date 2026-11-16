<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Jobs\GenerateMonthlyBillsJob;
use App\Models\User;
use App\Services\Billing\BillingPreviewService;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class GenerateScheduledBills extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:generate-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue eligible upcoming monthly bills when automatic generation is enabled';

    /**
     * Execute the console command.
     */
    public function handle(BillingPreviewService $preview): int
    {
        Cache::put('billing.scheduler.last_run', now()->toDateTimeString(), now()->addDays(2));
        if (! Settings::get('auto_generate')) {
            $this->info('Automatic generation is off.');

            return self::SUCCESS;
        }

        $owner = User::where('role', Role::Owner)->whereNull('deactivated_at')->first();
        if ($owner === null) {
            $this->error('No active owner can authorize scheduled billing.');

            return self::FAILURE;
        }

        $today = CarbonImmutable::now('Asia/Manila')->startOfDay();
        $horizon = $today->addDays((int) Settings::get('generate_lead_days'));
        for ($offset = 0; $offset <= 2; $offset++) {
            $target = $today->startOfMonth()->addMonthsNoOverflow($offset);
            $result = $preview->preview($target->year, $target->month);
            $ids = collect($result['rows'])
                ->filter(fn ($row) => $row->status === 'eligible'
                    && CarbonImmutable::parse($row->issueDate)->startOfDay()->lessThanOrEqualTo($horizon)
                    && CarbonImmutable::parse($row->periodEnd)->startOfDay()->greaterThanOrEqualTo($today))
                ->pluck('agreementId')->all();
            if ($ids !== []) {
                GenerateMonthlyBillsJob::dispatch($target->year, $target->month, $owner->id, null, $ids)->afterCommit();
                $this->info(count($ids)." bills queued for {$target->format('F Y')}.");
            }
        }

        return self::SUCCESS;
    }
}
