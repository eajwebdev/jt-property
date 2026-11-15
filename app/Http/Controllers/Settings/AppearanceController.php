<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AppearanceController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'appearance' => ['required', 'in:light,dark,system'],
        ]);

        $request->user()->update($validated);

        return back();
    }
}