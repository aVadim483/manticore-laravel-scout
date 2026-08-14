<?php

declare(strict_types=1);

namespace avadim\Manticore\Scout;

use avadim\Manticore\Laravel\Manager;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Laravel\Scout\EngineManager;

/**
 * Class ServiceProvider
 *
 * Adds the "manticore" driver to Scout. The connection itself belongs to the query builder
 * package - this only asks its Manager for one, so that an application keeps a single pool of
 * connections and a single config/manticore.php.
 *
 * @package avadim\Manticore\Scout
 * @codeCoverageIgnore
 */
class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom($this->configSource(), 'scout.manticore');
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->app instanceof LaravelApplication) {
            $this->publishes([$this->configSource() => config_path('manticore-scout.php')], 'config');
        }

        $this->app->make(EngineManager::class)->extend('manticore', function ($app) {
            return new ManticoreEngine(
                $app->make(Manager::class),
                (array)$app['config']->get('scout.manticore', []),
                (bool)$app['config']->get('scout.soft_delete', false)
            );
        });
    }

    /**
     * Path of the file with the defaults of the scout.manticore section
     *
     * @return string
     */
    protected function configSource(): string
    {
        return __DIR__ . '/../../../config/manticore-scout.php';
    }
}
