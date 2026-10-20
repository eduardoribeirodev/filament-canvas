<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use EduardoRibeiroDev\FilamentCanvas\CanvasPlugin;
use EduardoRibeiroDev\FilamentCanvas\CanvasServiceProvider;
use EduardoRibeiroDev\FilamentCanvas\Tests\Support\TestDocument;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as PackageTestCase;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

abstract class TestCase extends PackageTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            NotificationsServiceProvider::class,
            FilamentServiceProvider::class,
            MediaLibraryServiceProvider::class,
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

    protected function textDocument(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['textAlign' => 'center'], 'content' => [['type' => 'text', 'text' => $text]]]]];
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
            ->documentModel('test-document', TestDocument::class));
        app(PanelRegistry::class)->register($panel);
        Filament::setCurrentPanel($panel);
        Filament::bootCurrentPanel();

        return $panel;
    }
}
