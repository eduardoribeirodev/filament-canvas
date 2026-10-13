<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests;

use EduardoRibeiroDev\FilamentCanvas\CanvasPlugin;
use EduardoRibeiroDev\FilamentCanvas\CanvasServiceProvider;
use EduardoRibeiroDev\FilamentCanvas\Tests\Support\TestAssetLibrary;
use EduardoRibeiroDev\FilamentCanvas\Tests\Support\TestDocument;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            \Livewire\LivewireServiceProvider::class,
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,
            \Filament\Support\SupportServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Schemas\SchemasServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\FilamentServiceProvider::class,
            \Spatie\MediaLibrary\MediaLibraryServiceProvider::class,
            CanvasServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
        ]);
    }

    protected function createMediaTable(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->morphs('model');
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->nullableTimestamps();
        });
    }

    protected function useCanvasFixtures(): void
    {
        Schema::create('fixture_documents', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
        (require dirname(__DIR__).'/database/migrations/2026_10_10_020000_create_canvas_elements_table.php')->up();
        $this->createMediaTable();
        Storage::fake('public');
        auth()->setUser((new User)->forceFill(['id' => 1, 'name' => 'Editor', 'email' => 'editor@example.test']));
    }

    protected function canvasPanel(string $id): Panel
    {
        $panel = Panel::make()->id($id)->plugin(CanvasPlugin::make()
            ->documentModel('test-document', TestDocument::class)->assetLibrary(TestAssetLibrary::class));
        app(PanelRegistry::class)->register($panel);
        Filament::setCurrentPanel($panel);
        Filament::bootCurrentPanel();

        return $panel;
    }
}
