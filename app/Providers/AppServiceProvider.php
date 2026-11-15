<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('owner', fn (User $user) => $user->isOwner());

        // Long-running queue workers must not serve settings cached before an owner changed them.
        Event::listen(JobProcessing::class, fn () => Settings::forget());
        Queue::looping(fn () => Settings::forget());
    }
}
