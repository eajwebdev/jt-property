<?php

namespace App\Console\Commands;

use App\Services\Billing\DueReminderService;
use Illuminate\Console\Command;

class SendDueReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:send-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue configured due date reminders for unpaid invoices';

    /**
     * Execute the console command.
     */
    public function handle(DueReminderService $reminders): int
    {
        $count = $reminders->queueForDate(today());
        $this->info("{$count} reminders queued.");

        return self::SUCCESS;
    }
}
