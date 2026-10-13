<?php

namespace EduardoRibeiroDev\FilamentCanvas;

use EduardoRibeiroDev\FilamentCanvas\Livewire\CanvasEditor;
use EduardoRibeiroDev\FilamentCanvas\Support\ElementRegistry;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class CanvasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filament-canvas.php', 'filament-canvas');
        $this->app->singleton(ElementRegistry::class, fn () => ElementRegistry::configured());
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-canvas');
        $this->publishes([__DIR__.'/../config/filament-canvas.php' => config_path('filament-canvas.php')], 'filament-canvas-config');
        Livewire::component('filament-canvas.editor', CanvasEditor::class);
        FilamentAsset::register([
            AlpineComponent::make('canvas', __DIR__.'/../resources/dist/canvas.js'),
            Css::make('editor', __DIR__.'/../resources/dist/editor.css'),
        ], package: 'eduardoribeirodev/filament-canvas');
        $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/filament-canvas')], 'filament-canvas-views');
    }
}
