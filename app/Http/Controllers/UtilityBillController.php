<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use App\Models\UnitUtility;
use App\Models\UtilityBill;
use App\Rules\Decimal;
use App\Rules\Pesos;
use App\Services\Billing\UtilityAllocationService;
use App\Support\Audit;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UtilityBillController extends Controller
{
    public function __construct(private UtilityAllocationService $utilities) {}

    /** Enter or correct a month's meter reading or master bill. Only while the bill is still a draft. */
    public function store(Request $request): RedirectResponse
    {
        $unit = Unit::findOrFail((int) $request->input('unit_id'));
        Gate::authorize('enterUtilities', $unit);

        $data = $request->validate([
            'unit_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(array_keys(UtilityAllocationService::TYPES))],
            'bill_month' => ['required', 'date_format:Y-m'],
            'current_reading' => ['nullable', 'string', new Decimal(3, 'reading')],
            'previous_reading' => ['nullable', 'string', new Decimal(3, 'previous reading')],
            'rate' => ['nullable', 'string', new Decimal(4, 'rate per unit')],
            'base_charge' => ['nullable', 'string', new Pesos('base charge', allowZero: true)],
            'amount' => ['nullable', 'string', new Pesos('bill amount')],
            'reading_date' => ['nullable', 'date'],
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $config = UnitUtility::where('unit_id', $unit->id)->where('type', $data['type'])->first();
        if (! in_array($config?->mode, ['metered', 'shared'], true)) {
            throw ValidationException::withMessages(['type' => 'This room does not have a metered or shared '.$data['type'].' bill.']);
        }

        $month = Carbon::createFromFormat('!Y-m', $data['bill_month']);
        $bill = UtilityBill::firstOrNew(['unit_id' => $unit->id, 'type' => $data['type'], 'bill_month' => $month->toDateString()]);
        if ($bill->isBilled()) {
            throw ValidationException::withMessages(['bill_month' => 'That month is already billed and cannot be changed.']);
        }

        $bill->fill($config->mode === 'metered' ? $this->metered($data, $unit, $config, $month) : $this->shared($data, $unit, $config, $month));
        $bill->fill(['reading_date' => $data['reading_date'] ?? null, 'entered_by' => $bill->entered_by ?? auth()->id(), 'status' => 'draft']);

        $newFile = $request->hasFile('evidence') ? $request->file('evidence')->store('utility-evidence', 'local') : null;
        $replaced = $bill->evidence_path;

        try {
            DB::transaction(function () use ($bill, $data, $newFile) {
                $old = $bill->exists ? $bill->getOriginal() : null;
                if ($newFile !== null) {
                    $bill->evidence_path = $newFile;
                }
                $bill->save();
                Audit::log('utility_bill.recorded', $bill, [
                    'old' => $old ? ['amount_cents' => $old['amount_cents'], 'current_reading_e3' => $old['current_reading_e3']] : null,
                    'new' => $bill->only(['unit_id', 'type', 'previous_reading_e3', 'current_reading_e3', 'rate_e4', 'base_charge_cents', 'amount_cents']) + ['bill_month' => $data['bill_month']],
                ]);
            });
        } catch (\Throwable $e) {
            // Nothing was saved, so the uploaded file would be orphaned.
            if ($newFile !== null) {
                Storage::disk('local')->delete($newFile);
            }

            // Two people entering the same month at once: the second one gets a normal error, not a 500.
            throw $e instanceof UniqueConstraintViolationException
                ? ValidationException::withMessages(['bill_month' => 'Someone just entered this month. Reload and check the figures.'])
                : $e;
        }

        if ($newFile !== null && $replaced !== null) {
            Storage::disk('local')->delete($replaced);
        }

        return back()->with('success', ucfirst($data['type']).' for Room '.$unit->code.': '.Money::format($bill->amount_cents).'.');
    }

    public function evidence(UtilityBill $bill): StreamedResponse
    {
        Gate::authorize('view', $bill->unit);
        abort_if($bill->evidence_path === null || ! Storage::disk('local')->exists($bill->evidence_path), 404);

        return Storage::disk('local')->download($bill->evidence_path);
    }

    /** @return array<string, mixed> */
    private function metered(array $data, Unit $unit, UnitUtility $config, Carbon $month): array
    {
        if (blank($data['current_reading'] ?? null)) {
            throw ValidationException::withMessages(['current_reading' => 'Enter the current meter reading.']);
        }
        $current = Quantity::toE3($data['current_reading']);

        // Last month's closing reading is the opening one; only the very first reading is typed in.
        $prior = UtilityBill::where('unit_id', $unit->id)->where('type', $data['type'])->whereDate('bill_month', '<', $month->toDateString())
            ->whereNotNull('current_reading_e3')->orderByDesc('bill_month')->first();
        if ($prior) {
            $previous = $prior->current_reading_e3;
        } elseif (filled($data['previous_reading'] ?? null)) {
            $previous = Quantity::toE3($data['previous_reading']);
        } else {
            throw ValidationException::withMessages(['previous_reading' => 'Enter the starting reading for this meter.']);
        }

        if ($current < $previous) {
            throw ValidationException::withMessages(['current_reading' => 'The current reading cannot be lower than the previous reading ('.Quantity::formatE3($previous).').']);
        }

        $rate = filled($data['rate'] ?? null) ? Quantity::rateToE4($data['rate']) : $config->rate_e4;
        if ($rate === null) {
            throw ValidationException::withMessages(['rate' => 'Enter the rate per unit.']);
        }
        $base = filled($data['base_charge'] ?? null) ? Money::toCents($data['base_charge']) : ($config->base_charge_cents ?? 0);

        $fields = ['previous_reading_e3' => $previous, 'current_reading_e3' => $current, 'rate_e4' => $rate, 'base_charge_cents' => $base];
        $fields['amount_cents'] = $this->utilities->amountFor(new UtilityBill($fields));
        $this->checkManualShares($config, $unit, $month, $fields['amount_cents'], 'current_reading');

        return $fields;
    }

    /** @return array<string, mixed> */
    private function shared(array $data, Unit $unit, UnitUtility $config, Carbon $month): array
    {
        if (blank($data['amount'] ?? null)) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount on the master bill.']);
        }
        $amount = Money::toCents($data['amount']);
        $this->checkManualShares($config, $unit, $month, $amount, 'amount');

        return ['previous_reading_e3' => null, 'current_reading_e3' => null, 'rate_e4' => null, 'base_charge_cents' => 0, 'amount_cents' => $amount];
    }

    /** Manual shares are fixed centavo amounts, so they must add up to the bill. */
    private function checkManualShares(UnitUtility $config, Unit $unit, Carbon $month, int $amount, string $key): void
    {
        if ($config->split_method !== 'manual') {
            return;
        }

        $present = $this->utilities->eligibleAgreements($unit, $month->year, $month->month)->keys();
        $sum = (int) $config->shares()->whereIn('rental_agreement_id', $present)->sum('manual_cents');

        if ($sum !== $amount) {
            throw ValidationException::withMessages([$key => 'The manual shares add up to '.Money::format($sum).' but the bill is '.Money::format($amount).'. Fix the shares or the bill.']);
        }
    }
}
