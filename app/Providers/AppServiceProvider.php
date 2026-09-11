<?php

namespace App\Providers;

use App\Contracts\FootballDataProvider;
use App\Models\User;
use App\Services\Providers\SampleFootballProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FootballDataProvider::class, SampleFootballProvider::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
        Gate::define('manage-catalog', fn (User $user) => $user->is_admin);
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('football-read', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('predictions', fn (Request $r) => Limit::perMinute(20)->by($r->user()?->id ?: $r->ip()));
    }
}
