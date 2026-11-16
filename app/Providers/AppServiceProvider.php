<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
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
        Queue::looping(function (): void {
            Settings::forget();
            static $lastHeartbeatAt = 0;
            if (time() - $lastHeartbeatAt >= 30) {
                Cache::put('billing.worker.last_seen', now()->toDateTimeString(), now()->addMinutes(2));
                $lastHeartbeatAt = time();
            }
        });
    }
}
