<?php

namespace Code16\Systempay;

use Illuminate\Support\Facades\Blade;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SystemPayServiceProvider extends PackageServiceProvider
{
    protected $defer = false;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-systempay')
            ->hasConfigFile()
            ->hasViews();
    }

    public function bootingPackage()
    {
        Blade::componentNamespace('Code16\\Systempay\\Components', 'systempay');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'systempay');
    }
}
