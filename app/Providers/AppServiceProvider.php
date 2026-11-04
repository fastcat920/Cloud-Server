<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        config(['cache.default' => 'file', 'session.driver' => 'file', 'queue.default' => 'sync']);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app['validator']->setPresenceVerifier(new \App\Support\FileStore\PresenceVerifier);
        $this->app['view']->addNamespace('theme', public_path() . '/theme');
    }
}
