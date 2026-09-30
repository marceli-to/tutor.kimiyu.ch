<?php

namespace App\Providers;

use Anthropic\Client;
use App\Lessons\Ai\ClaudeLanguageModel;
use App\Lessons\Ai\FakeLanguageModel;
use App\Lessons\Ai\LanguageModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LanguageModel::class, function () {
            if (config('lessons.fake_ai')) {
                return new FakeLanguageModel;
            }

            return new ClaudeLanguageModel(
                client: new Client(apiKey: (string) config('services.anthropic.key')),
                model: config('services.anthropic.model'),
                effort: config('services.anthropic.effort'),
                fallbacks: config('services.anthropic.fallbacks'),
                timeout: config('services.anthropic.timeout'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
