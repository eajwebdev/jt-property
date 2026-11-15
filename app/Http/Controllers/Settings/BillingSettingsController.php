<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateBillingSettingsRequest;
use App\Models\AppSetting;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BillingSettingsController extends Controller
{
    private const PUBLIC_KEYS = ['company_name', 'company_address', 'company_phone', 'company_email', 'payment_instructions'];

    public function edit(): Response
    {
        Gate::authorize('viewAny', AppSetting::class);

        $canEdit = Gate::allows('update', AppSetting::class);
        $settings = $canEdit ? Settings::all() : array_intersect_key(Settings::all(), array_flip(self::PUBLIC_KEYS));

        return Inertia::render('settings/billing', ['settings' => $settings, 'canEdit' => $canEdit]);
    }

    public function update(UpdateBillingSettingsRequest $request): RedirectResponse
    {
        Gate::authorize('update', AppSetting::class);

        DB::transaction(function () use ($request) {
            foreach ($request->settings() as $key => $value) {
                if (Settings::get($key) !== $value) {
                    Settings::set($key, $value, $request->user());
                }
            }
        });

        return back()->with('success', 'Billing settings saved.');
    }
}
