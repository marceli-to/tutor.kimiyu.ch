<?php

namespace App\Providers;

use Anthropic\Client;
use App\Lessons\Ai\ClaudeLanguageModel;
use App\Lessons\Ai\FakeLanguageModel;
use App\Lessons\Ai\LanguageModel;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
	/**
	 * Register any application services.
	 */
	public function register(): void
	{
		$this->app->singleton(ClaudeLanguageModel::class, fn () => new ClaudeLanguageModel(
			client: new Client(apiKey: (string) config('services.anthropic.key')),
			fallbacks: config('services.anthropic.fallbacks'),
			timeout: config('services.anthropic.timeout'),
		));

		$this->app->singleton(LanguageModel::class, fn ($app) => config('lessons.fake_ai')
			? new FakeLanguageModel
			: $app->make(ClaudeLanguageModel::class));
	}

	/**
	 * Bootstrap any application services.
	 */
	public function boot(): void
	{
		$this->configureDefaults();

		RateLimiter::for('answers', fn (Request $request) => Limit::perMinute(240)->by($request->ip()));
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
