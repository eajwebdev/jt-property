<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreUserRequest;
use App\Http\Requests\Settings\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', User::class);

        return Inertia::render('settings/users', [
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'role', 'deactivated_at', 'created_at']),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $user = new User($request->validated());
        $user->email_verified_at = now();
        $user->save();

        return back()->with('success', "{$user->name} can now sign in.");
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $user->update($request->validated());

        return back()->with('success', "{$user->name} was updated.");
    }

    public function deactivate(User $user): RedirectResponse
    {
        Gate::authorize('deactivate', $user);

        if ($user->is(request()->user())) {
            throw ValidationException::withMessages(['user' => 'You cannot deactivate your own account.']);
        }

        if ($user->isOwner() && $user->isActive() && ! User::hasOtherActiveOwner($user)) {
            throw ValidationException::withMessages(['user' => 'There must be at least one active owner.']);
        }

        $user->forceFill(['deactivated_at' => now()])->save();

        return back()->with('success', "{$user->name} was deactivated and can no longer sign in.");
    }

    public function reactivate(User $user): RedirectResponse
    {
        Gate::authorize('deactivate', $user);

        $user->forceFill(['deactivated_at' => null])->save();

        return back()->with('success', "{$user->name} can sign in again.");
    }
}
