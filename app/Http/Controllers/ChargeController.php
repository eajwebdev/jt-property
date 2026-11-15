<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\BillingAccount;
use App\Models\ChargeCategory;
use App\Models\OneTimeCharge;
use App\Models\RecurringCharge;
use App\Models\RentalAgreement;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChargeController extends Controller
{
    /** Add-ons, one-time charges and categories. Everyone signed in sees their properties' charges; only the owner changes them. */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $today = today()->toDateString();
        $canManage = $user->can('owner');
        $inProperty = fn (Builder $q) => $q->when($user->role === Role::Staff, fn ($w) => $w->whereIn('property_id', $user->properties()->select('properties.id')));
        $accounts = fn (Builder $q) => $q->whereHas('billingAccount', $inProperty);

        $recurring = RecurringCharge::query()
            ->whereHas('rentalAgreement', $accounts)
            ->with(['category:id,name', 'rentalAgreement.unit:id,code', 'rentalAgreement.bed:id,label', 'rentalAgreement.billingAccount.payer:id,full_name'])
            ->orderByDesc('start_date')->orderByDesc('id')
            ->get()
            ->map(fn (RecurringCharge $c) => [
                'id' => $c->id,
                'tenant' => $c->rentalAgreement->billingAccount->payer->full_name,
                'room' => $this->room($c->rentalAgreement),
                'category_id' => $c->charge_category_id,
                'category' => $c->category->name,
                'label' => $c->label,
                'amount_cents' => $c->amount_cents,
                'amount' => Money::format($c->amount_cents),
                'start_date' => $c->start_date->toDateString(),
                'end_date' => $c->end_date?->toDateString(),
                'state' => match (true) {
                    ! $c->active => 'paused',
                    $c->end_date !== null && $c->end_date->toDateString() < $today => 'ended',
                    $c->start_date->toDateString() > $today => 'scheduled',
                    default => 'running',
                },
            ]);

        $oneTime = OneTimeCharge::query()
            ->whereHas('billingAccount', $inProperty)
            ->with(['category:id,name', 'billingAccount:id,account_no,payer_tenant_id', 'billingAccount.payer:id,full_name'])
            ->orderByDesc('bill_month')->orderByDesc('id')
            ->get()
            ->map(fn (OneTimeCharge $c) => [
                'id' => $c->id,
                'tenant' => $c->billingAccount->payer->full_name,
                'account_no' => $c->billingAccount->account_no,
                'category' => $c->category->name,
                'label' => $c->label,
                'kind' => $c->kind,
                'amount' => Money::format($c->signed_cents),
                'bill_month' => $c->bill_month->format('Y-m'),
                'billed' => $c->invoice_id !== null,
                'reason' => $c->reason,
            ]);

        return Inertia::render('charges/index', [
            'recurring' => $recurring,
            'oneTime' => $oneTime,
            'categories' => ChargeCategory::orderBy('name')->get(['id', 'name', 'kind', 'is_system']),
            'canManage' => $canManage,
            'options' => $canManage ? $this->options($inProperty, $today) : null,
            'today' => $today,
        ]);
    }

    /** Agreements and accounts the owner can attach a charge to. */
    private function options(callable $inProperty, string $today): array
    {
        $agreements = RentalAgreement::query()
            ->currentOrFuture()
            ->whereDoesntHave('replacedBy')
            ->whereHas('billingAccount', $inProperty)
            ->with(['unit:id,code', 'bed:id,label', 'billingAccount.payer:id,full_name'])
            ->orderBy('id')
            ->get();

        return [
            'agreements' => $agreements->map(fn ($a) => ['id' => $a->id, 'label' => $a->billingAccount->payer->full_name.' - Room '.$this->room($a)]),
            'accounts' => BillingAccount::query()
                ->where('status', AccountStatus::Active)
                ->tap($inProperty)
                ->with('payer:id,full_name')
                ->orderBy('id')
                ->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'label' => $a->payer->full_name.' ('.$a->account_no.')',
                    'agreements' => $agreements->where('billing_account_id', $a->id)->map(fn ($g) => ['id' => $g->id, 'label' => 'Room '.$this->room($g)])->values(),
                ]),
        ];
    }

    private function room(RentalAgreement $a): string
    {
        return $a->unit->code.($a->bed ? ' / '.$a->bed->label : '');
    }
}
