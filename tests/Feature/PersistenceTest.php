<?php

namespace EduardoRibeiroDev\FilamentCanvas\Tests\Feature;

use EduardoRibeiroDev\FilamentCanvas\CanvasPlugin;
use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasOwner;
use EduardoRibeiroDev\FilamentCanvas\Data\DocumentReference;
use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use EduardoRibeiroDev\FilamentCanvas\Elements\TextElement;
use EduardoRibeiroDev\FilamentCanvas\Models\CanvasElement;
use EduardoRibeiroDev\FilamentCanvas\Models\Concerns\HasCanvas;
use EduardoRibeiroDev\FilamentCanvas\Persistence\EloquentDocumentStore;
use EduardoRibeiroDev\FilamentCanvas\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
class PersistenceTest extends TestCase
{
    private bool $persistenceAllowUpdate = true;
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        Schema::create('canvas_test_documents', function (Blueprint $table): void {
            $table->id();
            $table->boolean('locked')->default(false);
            $table->timestamps();
        });
        (require dirname(__DIR__, 2).'/database/migrations/2026_10_10_020000_create_canvas_elements_table.php')->up();
        $this->createMediaTable();
        Storage::fake('public');
        Gate::before(fn($user, $ability) => $ability === 'view' || $this->persistenceAllowUpdate);
    }
    public function test_default_model_store_saves_reorders_and_deletes_elements_for_an_arbitrary_owner(): void
    {
        $owner = CanvasTestDocument::create();
        $other = CanvasTestDocument::create();
        $store = CanvasPlugin::make()->documentModel('document', CanvasTestDocument::class)->resolveDocumentStore('document');
        $this->assertInstanceOf(EloquentDocumentStore::class, $store);
        $context = $this->persistenceContext($owner);
        $loaded = $store->load($context);
        $result = $store->save($context, [$this->persistenceBlock('Primeiro'), $this->persistenceBlock('Segundo')], $loaded->revision);
        $this->assertCount(2, $result->blocks);
        $this->assertSame(0, $other->canvasElements()->count());
        $element = $owner->canvasElements()->first();
        $this->assertInstanceOf(CanvasElement::class, $element);
        $this->assertTrue($owner->is($element->elementable));
        $this->assertSame(['text' => 'Primeiro', 'font_size' => 16, 'rich_text' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['textAlign' => 'center'], 'content' => [['type' => 'text', 'text' => 'Primeiro']]]]], 'html' => '<p style="text-align: center;">Primeiro</p>'], $element->dados);
        $reordered = $store->save($context, array_reverse($result->blocks), $result->revision);
        $this->assertSame(['Segundo', 'Primeiro'], array_column(array_column($reordered->blocks, 'data'), 'nome'));
        $remaining = $store->save($context, [$reordered->blocks[0]], $reordered->revision);
        $this->assertSame(1, $owner->canvasElements()->count());
        $this->assertSame('Segundo', $remaining->blocks[0]['data']['nome']);
        $owner->delete();
        $this->assertSame(0, CanvasElement::count());
    }
    public function test_default_store_rejects_stale_revisions_foreign_ids_and_duplicate_ids_without_partial_writes(): void
    {
        $owner = CanvasTestDocument::create();
        $other = CanvasTestDocument::create();
        $store = new EloquentDocumentStore(CanvasTestDocument::class);
        $context = $this->persistenceContext($owner);
        $initial = $store->load($context);
        $saved = $store->save($context, [$this->persistenceBlock('Original')], $initial->revision);
        $foreign = $store->save($this->persistenceContext($other), [$this->persistenceBlock('Alheio')], $store->load($this->persistenceContext($other))->revision);
        foreach ([[[$this->persistenceBlock('Conflito')], $initial->revision], [[$saved->blocks[0], $foreign->blocks[0]], $saved->revision], [[$saved->blocks[0], $saved->blocks[0]], $saved->revision]] as [$blocks, $revision]) {
            try {
                $store->save($context, $blocks, $revision);
                $this->fail('The invalid write must be rejected.');
            } catch (ValidationException) {
                $this->assertSame($saved->blocks, $store->load($context)->blocks);
                $this->assertSame(2, CanvasElement::count());
            }
        }
    }
    public function test_owner_policies_and_business_guards_are_enforced_by_default_persistence(): void
    {
        $owner = CanvasTestDocument::create();
        $store = new EloquentDocumentStore(CanvasTestDocument::class);
        $context = $this->persistenceContext($owner);
        $revision = $store->load($context)->revision;
        $this->persistenceAllowUpdate = false;
        try {
            $store->save($context, [$this->persistenceBlock('Bloqueado')], $revision);
            $this->fail('Update policy must run.');
        } catch (AuthorizationException) {
            $this->assertSame(0, CanvasElement::count());
        }
        $this->persistenceAllowUpdate = true;
        $owner->update(['locked' => true]);
        try {
            $store->save($context, [$this->persistenceBlock('Bloqueado')], $revision);
            $this->fail('Owner business rules must run even with an overridden policy.');
        } catch (ValidationException) {
            $this->assertSame(0, CanvasElement::count());
        }
    }
    public function test_configured_element_classes_are_used_in_the_panel_and_default_model_validation(): void
    {
        config()->set('filament-canvas.elements', [CanvasTestNoticeElement::class]);
        $owner = CanvasTestDocument::create();
        $plugin = CanvasPlugin::make()->documentModel('document', CanvasTestDocument::class);
        $this->assertContains('notice', $plugin->elements('document')->types());
        $store = $plugin->resolveDocumentStore('document');
        $context = $this->persistenceContext($owner);
        $block = $this->persistenceBlock('Aviso');
        $block['type'] = 'notice';
        $saved = $store->save($context, [$block], $store->load($context)->revision);
        $this->assertSame('notice', $saved->blocks[0]['type']);
        $this->assertSame('Aviso', $owner->canvasElements()->sole()->dados['text']);
    }
    public function test_default_media_persistence_copies_an_authorized_image_and_rejects_another_owner(): void
    {
        $owner = CanvasTestDocument::create();
        $other = CanvasTestDocument::create();
        $block = $this->persistenceBlock('Imagem');
        $block['type'] = 'asset';
        $block['data']['dados'] = [];
        $source = $owner->canvasElements()->create(['tipo' => 'asset', ...$block['data']]);
        $media = $source->addMedia(UploadedFile::fake()->image('fonte.png', 4, 4))->toMediaCollection('imagem');
        $hash = hash_file('sha256', $media->getPath());
        $store = new EloquentDocumentStore(CanvasTestDocument::class);
        $context = $this->persistenceContext($owner);
        $initial = $store->load($context);
        $block['data']['imagem_origem'] = $media->id;
        $saved = $store->save($context, [...$initial->blocks, $block], $initial->revision);
        $copy = $owner->canvasElements()->where('id', $saved->blocks[1]['data']['id'])->sole()->imagem();
        $this->assertNotSame($media->id, $copy->id);
        $this->assertSame($hash, hash_file('sha256', $copy->getPath()));
        $this->assertSame($copy->id, $saved->blocks[1]['data']['imagem_origem']);
        try {
            $store->save($this->persistenceContext($other), [$block], $store->load($this->persistenceContext($other))->revision);
            $this->fail('A media reference from another owner must be rejected.');
        } catch (ValidationException) {
            $this->assertSame(0, $other->canvasElements()->count());
            $this->assertSame(2, DB::table('media')->count());
        }
        $owner->delete();
        $this->assertSame(0, DB::table('media')->count());
        $this->assertFileDoesNotExist($media->getPath());
        $this->assertFileDoesNotExist($copy->getPath());
    }
    private function persistenceContext(CanvasTestDocument $owner): EditorContext
    {
        return new EditorContext(new DocumentReference('document', $owner->id), (new User())->forceFill(['id' => 1]));
    }
    private function persistenceBlock(string $name): array
    {
        return ['type' => 'text', 'data' => ['nome' => $name, 'x_pt' => 10, 'y_pt' => 10, 'largura_pt' => 100, 'altura_pt' => 50, 'rotacao_graus' => 0, 'visivel' => true, 'dados' => ['text' => $name, 'font_size' => 16]]];
    }
}
class CanvasTestNoticeElement extends TextElement
{
    public function type(): string
    {
        return 'notice';
    }
}
class CanvasTestDocument extends Model implements CanvasOwner
{
    use HasCanvas;
    protected $table = 'canvas_test_documents';
    protected $guarded = ['id'];
    public function assertCanvasEditable(): void
    {
        if ($this->locked) {
            throw ValidationException::withMessages(['document' => 'Documento bloqueado.']);
        }
    }
}
