# Filament Canvas

Editor visual reutilizável para Filament 5, Laravel 12/13 e Livewire 4. O pacote fornece a prancheta, tipos de elemento, página de edição, componente de Schema e persistência Eloquent. Catálogos, produtos e publicação continuam na aplicação.

## Instalação e uso padrão

```bash
composer require eduardoribeirodev/filament-canvas:^0.1
php artisan migrate
php artisan filament:assets
```

No desenvolvimento local, configure uma repository Composer `path` apontando para `../pacotes/filament-canvas`, com `options.symlink: true`. As migrations do pacote são carregadas automaticamente e criam `canvas_elements`. O model proprietário precisa existir na aplicação e ter suas próprias migrations e policies.

Adicione o contrato e o trait ao model que terá um Canvas:

```php
use EduardoRibeiroDev\FilamentCanvas\Contracts\CanvasOwner;
use EduardoRibeiroDev\FilamentCanvas\Models\Concerns\HasCanvas;
use Illuminate\Database\Eloquent\Model;

class Documento extends Model implements CanvasOwner
{
    use HasCanvas;
}
```

Registre o plugin no painel:

```php
use EduardoRibeiroDev\FilamentCanvas\CanvasPlugin;

$panel->plugins([
    CanvasPlugin::make(),
]);
```

O pacote carrega e salva os elementos, confere as policies `view`/`update` do proprietário, compara a revisão e grava em transação. Não é preciso criar um `DocumentStore` para o uso Eloquent padrão.

Para incorporar o editor em qualquer Schema:

```php
use EduardoRibeiroDev\FilamentCanvas\Schemas\Components\Canvas;

$schema->components([
    Canvas::make()
        ->owner(Documento::class, fn () => $this->record->getKey())
        ->columnSpanFull(),
]);
```

O proprietário deve estar salvo. O componente mantém o botão **Salvar composição**: ele salva os elementos na tabela do pacote, independentemente do submit do formulário externo. Não é um campo que desidrata um JSON no formulário.

Para usar a página pronta em um Resource:

```php
use EduardoRibeiroDev\FilamentCanvas\Pages\EditCanvas;

class EditarDocumento extends EditCanvas
{
    protected static string $resource = DocumentoResource::class;
}

// DocumentoResource::getPages():
'canvas' => EditarDocumento::route('/{record}/canvas'),
```

A subclasse só identifica o Resource. O pacote usa diretamente `DocumentoResource::getModel()`, sem registrar esse model novamente no provider. A página, o Schema e a persistência ficam no pacote. O componente Livewire `filament-canvas.editor` também pode ser incorporado diretamente em Blade.

O editor usa campos nativos Filament, incluindo `RichEditor`, `ColorPicker`, `Repeater` e `Select`. Oferece arraste, redimensionamento, rotação, enquadramento de imagens, zoom, desfazer/refazer e edição de texto por duplo clique. As camadas usam as alças de ordenação do Filament. Shift mantém proporção; Alt/Option redimensiona pelo centro. A pinça mantém fixo o ponto sob o cursor; o controle de zoom usa diretamente o componente Alpine do Slider do Filament, carregado com `FilamentAsset::getAlpineComponentSrc('slider', 'filament/forms')`, de 25% a 300% em incrementos de 1%, mantendo o centro da área visível. O slider acompanha a pinça e o ajuste à tela, atualiza a prancheta durante o arraste e não altera os dados salvos do documento. O estado do controle é local no Alpine, sem Schema de formulário nem propriedade Livewire para o zoom. A integração com o canvas evita reposicionamento durante o gesto e desativa a animação de sincronização; teclado e toque também são suportados. O seletor **Grade** oferece 1, 2, 5, 10 e 20 px, ou nenhum passo, ao mover e redimensionar. O passo considera pixels da prancheta a 100% de zoom e permanece igual ao ampliar ou reduzir; a geometria salva continua em pontos.

O controle **Guias** ativa o encaixe magnético nas bordas e centros da página e dos outros elementos visíveis. Ao mover ou redimensionar, as linhas de alinhamento usam a cor `primary` e desaparecem ao terminar o gesto. A tolerância é de 6 pixels na tela, independentemente do zoom. Ctrl/⌘ suspende o encaixe magnético durante o gesto, preservando o passo escolhido em **Grade**. Elementos girados podem ser alinhados ao mover; seu redimensionamento mantém os eixos locais. As preferências de encaixe e as guias não entram nos dados do documento.

O estilo padrão acompanha automaticamente a fonte e a paleta do painel, inclusive as cores definidas por `Panel::colors()` no `AdminPanelProvider`. A toolbar lateral exibe apenas ícones e usa os estilos de `sidebar.item`, com tooltips laterais do Filament no hover e no foco. Botões, abas, badges e campos usam componentes nativos do Filament; seleção, alças e zoom usam a cor `primary`. Há suporte aos temas claro e escuro e ao layout responsivo. Na página pronta, a área de edição se ajusta à altura disponível; no Schema, usa uma altura própria. Os estilos são registrados pelo pacote e publicados com `php artisan filament:assets`, sem configuração adicional no plugin.

## Templates e opções da página

Sem opções adicionais, o editor abre uma folha branca com os tipos genéricos. O model precisa implementar `CanvasOwner`; `HasCanvas` fornece os comportamentos padrão. O plugin não precisa receber o model do Resource.

Os padrões do painel são opcionais:

```php
CanvasPlugin::make()
    ->template('canvas.templates.documento')
    ->extraElements([BadgeElement::class])
    ->assetLibrary(MyAssetLibrary::class);
```

A página pode sobrescrevê-los:

```php
class EditarDocumento extends EditCanvas
{
    protected static string $resource = DocumentoResource::class;
    protected static ?string $canvasTemplate = 'canvas.templates.contrato';
    protected static ?array $canvasElements = [TextElement::class, ImageElement::class];
    protected static ?array $canvasExtraElements = [BadgeElement::class];
    protected static ?string $canvasAssetLibrary = MyAssetLibrary::class;
    protected static ?string $canvasEditorView = 'canvas.editor';
}
```

A prioridade é **página → painel → pacote**. `elements([...])` / `$canvasElements` definem a lista base; `extraElements([...])` / `$canvasExtraElements` acrescentam tipos e substituem um tipo com o mesmo identificador. Uma lista base na página substitui também os extras padrão do painel, salvo os extras declarados na própria página. `null` herda a opção; `[]` define uma lista vazia. Nas páginas e Schemas, use nomes de classes; instâncias de elementos com argumentos próprios podem ser configuradas no painel.

No Schema, as mesmas opções são fluentes:

```php
Canvas::make()
    ->owner(Documento::class, fn () => $this->record->getKey())
    ->template('canvas.templates.contrato')
    ->elements([TextElement::class])
    ->extraElements([BadgeElement::class]);
```

O template é uma view Blade, sem `<html>` ou `<body>`. Ela recebe `$document` e `$context` ao renderizar um documento, `$blocks` com os elementos posicionados, `$data` com dados adicionais, `$preview`, `$width` e `$height`. Por exemplo:

```blade
<style>.page{position:relative;width:{{ $width }}pt;height:{{ $height }}pt;background:white;overflow:hidden}.block{position:absolute}</style>
<section class="page">
    <div class="cabecalho">{{ $data['title'] ?? '' }}</div>
    {!! $blocks !!}
</section>
```

Para usar a folha padrão e acrescentar decoração, inclua `filament-canvas::templates.blank` e acrescente CSS ou conteúdo posicionado. O pacote envolve a mesma view em `filament-canvas::document`: somente o modo de saída muda, com a escala de pt para px na prévia e `@page` na impressão.

O contrato opcional `CanvasRenderData` permite ao model fornecer `canvasRenderData(CanvasDocument $document, EditorContext $context): array`. O documento recebido contém a composição ainda não salva. Esse método prepara dados e verifica regras do domínio; a apresentação permanece nas views. Não é necessário implementar `PreviewRenderer` no model.

Para exportar um documento usando as opções da página:

```php
$plugin = EditarDocumento::getCanvasPlugin('admin');
$type = EditarDocumento::getCanvasDocumentType();
$document = $plugin->resolveDocumentStore($type)->load($context);
$html = $plugin->resolveRenderer($type)->renderDocument($document, $context);
```

`renderDocument()` usa o mesmo template, com impressão por padrão; `render()` usa a prévia. `renderOutput($data, $variables)` serve a exportadores com dados congelados ou várias páginas. A aplicação fornece seu contexto completo ao template; a folha padrão aceita `pageContents` com o HTML interno de cada página. `renderTemplate($view, $data, $preview, $variables)` é a entrada independente de painel. O PDF em si continua a cargo do exportador da aplicação.

A impressão pode aguardar `window.canvasReady === true`. Se o template executar trabalho assíncrono, atribua sua Promise a `window.canvasTemplateReady`: o pacote aguarda essa Promise, fontes e imagens antes de sinalizar conclusão.

As definições escolhidas pela página também são utilizadas durante a persistência e a renderização, sem alterar os padrões de outras páginas. Em jobs/importações que manipulam os models diretamente, use o store da página ou `usingElements()` para aplicar a mesma configuração; alternativamente, mantenha os tipos compartilhados em `canvasElementRegistry()` / `filament-canvas.elements`. Não é preciso repetir classes em um adaptador de salvamento.

## Estado do elemento

O envelope continua sendo `{type, data}`. A identidade, geometria, visibilidade e referências de mídia ficam fora das propriedades específicas do tipo. Todas as propriedades específicas ficam em **um único objeto plano `dados`**:

```json
{
  "type": "text",
  "data": {
    "id": 42,
    "nome": "Legenda",
    "x_pt": 30,
    "y_pt": 40,
    "largura_pt": 200,
    "altura_pt": 50,
    "rotacao_graus": 0,
    "visivel": true,
    "dados": {
      "text": "Meu documento",
      "font_size": 12,
      "color": "#222222",
      "align": "left"
    }
  }
}
```

Estruturas intrinsecamente compostas continuam sendo objetos ou listas dentro de `dados`: documento `rich_text`, transformação `image_transform`, traço `drawing`, colunas e linhas da tabela. Não existem grupos separados de conteúdo, estilo e configurações. A organização visual do formulário não determina o formato do JSON.

`Support\ElementData::from($data)` lê o novo objeto e aceita o formato anterior: configurações, depois conteúdo, depois valores não vazios do estilo. `canonicalizeBlock()`/`canonicalizeBlocks()` convertem o envelope e removem os três grupos anteriores, preservando geometria e referências. Um `dados` já existente é sempre a fonte autoritativa, inclusive quando está vazio. A persistência salva apenas o formato canônico.

Cada classe declara suas regras. O núcleo valida geometria e rejeita propriedades novas ou alteradas de `dados` que não estejam declaradas no tipo. Propriedades legadas já persistidas podem continuar se estiverem inalteradas. A aplicação valida também seus vínculos, permissões e regras de negócio.

## Uma classe por tipo

Os tipos genéricos incluídos são:

| Classe | Tipo | Recursos |
|---|---|---|
| `TextElement` | `text` | RichEditor, tipografia e edição direta |
| `TitleElement` | `title` | Texto com valores iniciais de título |
| `ImageElement` | `asset` | Enquadramento, espelhamento, opacidade e mídias |
| `DrawingElement` | `drawing` | Linhas contínuas, tracejadas e pontilhadas |
| `DataTableElement` | `data-table` | Colunas e linhas editáveis |

Os aliases `image`, `photo` e `table` permanecem compatíveis com documentos anteriores, mas ficam ocultos na toolbar genérica. A aplicação pode substituí-los por classes próprias e ativar sua toolbar. Na AK, título de catálogo, imagem de produto, texto vinculado, tabela de produtos e grade pertencem ao projeto principal; podem estender as classes genéricas.

A tabela genérica oferece um formulário para os títulos das colunas e outro para as linhas, com campos de célula identificados pelos títulos. As chaves das colunas são internas, geradas pelo schema e preservadas ao renomear ou reordenar. O JSON continua em `dados.columns` (`label`, `field`) e `dados.rows` (valores por chave). O cabeçalho e as bordas possuem estilo básico na própria view, inclusive sem um template específico. Para apresentações próprias, estenda `DataTableElement` e substitua `view()` e, se necessário, `properties()`; a prévia respeita a view da classe. Tabelas ligadas a produtos continuam na aplicação.

Estenda `Elements\CanvasElement`:

```php
use EduardoRibeiroDev\FilamentCanvas\Elements\CanvasElement;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class BadgeElement extends CanvasElement
{
    public function type(): string { return 'badge'; }

    public function label(): string { return 'Selo'; }

    public function icon(): string { return 'heroicon-o-tag'; }

    public function properties(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('text')->label('Texto')->default('Novo selo')->required()->rules(['string'])->maxLength(255),
            Toggle::make('highlighted')->label('Destacar')->default(true),
        ]);
    }

    public function view(): string { return 'canvas.elements.badge'; }
}
```

Os nomes dos campos e das regras são relativos a `dados`; o editor adiciona o caminho de estado do elemento selecionado. **Não há método `defaults()`**: na criação, o editor preenche e desidrata o schema Filament para avaliar os valores iniciais dos próprios campos. Na edição, carrega os valores existentes, preservando `false`, `0` e `null`.

O painel mantém os campos em estado local diferido; somente o fluxo de atualização do canvas sincroniza e valida as propriedades, evitando requisições live concorrentes.

O pacote extrai as regras dos próprios campos Filament com `Support\ElementValidation`: obrigatoriedade, limites, opções e regras customizadas também são aplicados no salvamento e nos models, sem hidratar ou substituir os valores do payload. Aplicações que importam documentos podem usar `ElementValidation::rules($element, $dados, $prefix)` ou `ElementValidation::validate($element, $dados)` para a mesma validação. Campos ocultos conservam a validação dos valores fornecidos, sem exigir preenchimento. `validationRules()` é opcional nas subclasses de `CanvasElement` e acrescenta somente regras para propriedades internas ou de compatibilidade que não possuem um campo no formulário.

A view recebe `$dados`, `$context`, `$media` e `$block` (envelope completo, incluindo geometria):

```blade
<div style="font-weight:{{ ($dados['highlighted'] ?? false) ? 700 : 400 }}">
    {{ $dados['text'] ?? '' }}
</div>
```

O canvas cuida da posição e dos controles de seleção. A view produz apenas o conteúdo interno e deve escapar dados com `{{ }}`. O método opcional `viewData(array $dados, EditorContext $context, array $media = []): array` acrescenta dados resolvidos pela aplicação sem copiá-los para o JSON. `normalize(array $dados): array` pode normalizar valores e gerar propriedades derivadas.

Publique a configuração com `php artisan vendor:publish --tag=filament-canvas-config` e registre tipos personalizados em `config/filament-canvas.php`:

```php
'elements' => [\App\Canvas\Elements\BadgeElement::class],
```

Essa lista é compartilhada pelo painel e pelo model, inclusive em jobs e comandos sem painel ativo. O container Laravel resolve cada classe. Para registros específicos de um documento, você pode usar `->element()`/`->registerElements()` no painel e sobrescrever `canvasElementRegistry()` no proprietário com o mesmo registro. A AK usa essa opção para suas classes comerciais.

`documentElements('document', ['title', 'text', 'badge'])` é opcional e restringe os tipos exibidos e aceitos pelo editor daquele documento. O registro do proprietário deve admitir os mesmos tipos. O registro fornece nome, ícone, formulário e view; tipos próprios funcionam por atualização Livewire sem exigir um renderer JavaScript. As views genéricas têm atualização local otimizada; ao substituir `view()`, o editor usa o HTML daquela classe.

`capabilities(): array` declara comportamentos locais opcionais, por exemplo `['text' => true, 'inline_text' => true]` ou `['image' => true]`. O comportamento local não depende de `instanceof ImageElement`. Use `toolbar => false` para um tipo somente de compatibilidade. Uma classe da aplicação que substitui `photo`/`table` pode declarar `toolbar => true`.

Para personalizar apenas o campo de texto, `TextElement` oferece `protected textField(): RichEditor`. Também é possível compor o schema de `parent::properties($schema)` e adicionar campos. Cada propriedade persistida deve ter um campo no schema ou uma regra complementar quando for uma estrutura interna sem controles visíveis.

### Texto formatado

`dados.rich_text` contém o documento Tiptap autoritativo. O servidor gera `dados.html` sanitizado e `dados.text` como derivados; ambos são recompostos quando o documento muda. `Support\RichText` limita a estrutura e fornece HTML/CSS compartilhados pela prévia e impressão. Texto simples com apenas `dados.text` continua válido.

O RichEditor aceita negrito parcial, itálico, sublinhado, tachado, marcação, cores, links e listas. Duplo clique abre o RichEditor nativo diretamente na prancheta. Selecionar um trecho exibe a barra flutuante com formatação, alinhamento, listas, cores e links. Os modais de cor e link preservam a edição e devolvem o foco ao texto. Clicar fora ou Ctrl/Cmd + Enter confirma; Esc cancela. O JSON Tiptap é preservado, incluindo formatação parcial; alinhamento, negrito e itálico ficam no RichEditor, sem campos globais duplicados. Textos antigos são convertidos para marcas e atributos do documento ao carregar ou salvar. Os controles flutuantes mantêm seu tamanho durante o zoom. A alteração entra no histórico local.

### Mídias

Uma classe pode implementar `Contracts\HasElementMedia`; `ImageElement` já implementa:

```php
public function mediaCollections(): array
{
    return ['image' => [
        'label' => 'Imagem',
        'accepted_mime_types' => ['image/png', 'image/jpeg', 'image/webp'],
        'max_files' => 1,
        'max_size' => 10240, // KB
        'reference_field' => 'imagem_origem',
    ]];
}

public function withAssetLibrary(AssetLibrary $library): static;
public function resolveMedia(array $block, EditorContext $context): array;
```

A declaração descreve coleções e seus limites; a implementação resolve mídias autorizadas no contexto atual. O editor injeta a biblioteca do documento antes de renderizar. `withAssetLibrary()` retorna a instância a usar, permitindo clones sem contaminar o estado de outros documentos. Uma implementação própria pode declarar coleções adicionais e resolvê-las para sua view. `ImageElement::resolveMedia()` percorre essas declarações: uma coleção de arquivo único recebe um asset e uma coleção múltipla recebe uma lista, sempre limitada às mídias da biblioteca autorizada. O validador aplica os limites de referências declarados. O model Eloquent padrão usa Spatie Media Library. Um `DocumentStore` próprio pode usar outro armazenamento.

A biblioteca implementa `AssetLibrary::assets(EditorContext): array` e devolve `[['id' => 1, 'name' => 'Logo', 'image' => '/media/logo.webp']]`, contendo apenas assets autorizados. A imagem genérica guarda `imagem_origem` fora de `dados`; `image_transform`, `fit`, `crop_x/y` e `flip_x/y` ficam em `dados`.

Para upload, implemente `UploadableAssetLibrary::upload(EditorContext, UploadedFile): array`. Autorize e armazene o arquivo no domínio hospedeiro; devolva os mesmos dados de uma imagem da biblioteca. As coleções definem os tipos aceitos, limite de arquivos e tamanho máximo; o padrão genérico oferece PNG/JPEG/WebP até 10 MB. Cada coleção recebe seu próprio seletor e formulário de upload; coleções com `max_files > 1` guardam uma lista de IDs na referência declarada. O upload adiciona uma mídia à biblioteca; salvar a composição continua usando a revisão do documento.

## Persistência Eloquent

`Models\CanvasElement` pertence ao pacote e usa `elementable(): MorphTo`. O trait `HasCanvas` acrescenta `canvasElements(): MorphMany` ao proprietário. A tabela `canvas_elements` contém identidade, tipo, geometria, camada, visibilidade, um JSON `dados` e timestamps. O proprietário pode ser uma página de catálogo, documento, apresentação ou outro model.

`Persistence\EloquentDocumentStore` e `ElementPersistence` carregam, validam, criam, ordenam, atualizam e removem os elementos. O salvamento trava o proprietário, compara a revisão, rejeita IDs alheios/duplicados e executa tudo em transação. Remoções usam eventos dos models. Excluir o proprietário por Eloquent também exclui seus elementos e mídias; deletes em massa por query/SQL não disparam esses eventos.

Os métodos do proprietário permitem adaptar regras locais:

- `canvasSize()`: largura e altura em pontos; padrão A4 de `filament-canvas.width`/`height`.
- `assertCanvasEditable()`: bloqueios comerciais, por exemplo impedir edição de uma publicação; padrão sem bloqueio adicional às policies.
- `canvasMetadata()`: dados necessários pela interface ou pelas views; padrão vazio.
- `canvasElementRegistry()`: tipos compartilhados pelo model e pelo editor; A página combina as definições do proprietário com os padrões; uma lista explícita na página/painel controla os tipos disponíveis. Use `ElementRegistry::from([TextElement::class, BadgeElement::class])` para uma lista própria.
- `canvasElementModel()`: subclasse de `Models\CanvasElement` quando existem relações próprias da aplicação.

Uma subclasse pode estender `canvasData()` e `syncCanvasData(array $data, ?EditorContext $context)` para carregar/salvar vínculos comerciais. O núcleo continua cuidando da tabela, geometria, `dados`, ordenação e revisão. A AK mantém `App\Models\CatalogoElemento` somente para produtos, regras editoriais e compatibilidade das identidades de mídia existentes.

O model genérico implementa `HasMedia`, registra as coleções declaradas pelo tipo e sincroniza referências no salvamento. Só aceita mídias que pertençam ao mesmo proprietário ou aos seus elementos. A coleção `image` é armazenada como `imagem` para compatibilidade. `filament-canvas.media_disk` define o disco de destino. A tabela `media` e os discos devem estar configurados pela aplicação; instale/publique as migrations da Media Library quando ainda não existirem.

A biblioteca visual e o upload continuam substituíveis por `AssetLibrary`/`UploadableAssetLibrary`: configure `->assetLibrary('document', MyAssetLibrary::class)` para fornecer arquivos autorizados do domínio. A biblioteca padrão é vazia. Declarações de coleções não criam uma biblioteca de uploads automaticamente.

## Persistência alternativa e renderização

`Data\DocumentReference` identifica tipo e ID; `Data\EditorContext` traz usuário autenticado e painel. `Data\CanvasDocument` contém `blocks`, `width`, `height`, `revision` e `metadata`. `SaveResult` devolve os blocos persistidos e a nova revisão.

```php
interface DocumentStore
{
    public function authorize(EditorContext $context, string $ability): void;
    public function load(EditorContext $context): CanvasDocument;
    public function save(EditorContext $context, array $blocks, string $expectedRevision): SaveResult;
}
```

Para armazenamento alternativo, registre `->documentStore('document', MyDocumentStore::class)`. Esse adaptador deve autorizar `view`, `preview` e `update`, comparar revisão e gravar na mesma transação ou trava. No caminho Eloquent, `preview` usa a policy `update`, pois é uma operação do editor. Toda chamada do editor autoriza novamente. O editor bloqueia tipo de documento, ID, painel e revisão e recebe as opções do Schema em uma configuração autenticada e vinculada ao documento/painel; o cliente não pode escolher classes PHP.

`Examples\JsonDocumentStore` é um exemplo opcional em arquivos locais, com trava, conversão legada e rejeição de revisões antigas. Defina uma Gate `canvas-document` que decida acesso pelo usuário antes de utilizá-lo.

`Rendering\CanvasRenderer` usa as views dos tipos, injeta mídias e envolve o conteúdo com geometria. As integrações legadas com `Canvas::document()` ainda aceitam `->previewRenderer('document', MyPreviewRenderer::class)`, cujo contrato é `render(CanvasDocument, EditorContext): string`. A página pronta baseada em Resource usa o renderer de templates do pacote. Um renderer próprio deve produzir `.page` e `.block[data-editor-index]` e escapar conteúdo. A prévia usa pt e `body { zoom: .75 }` para representar 1 pt como 1 px na prancheta.

### Interface da aplicação

`->editorView('canvas.my-editor')` troca a interface externa. A view recebe `initial`, `assets`, `width`, `height`, `previewHtml`, `elementLabels`, `elementTypes`, `elementDefinitions`, `metadata` e `canUploadAssets`. O schema dinâmico de propriedades está em `$this->propertiesForm`.

`metadata.toolbarView`/`propertiesView` podem incluir controles específicos da aplicação. `metadata.reference_options` aceita `{id,label,image}` para referências autorizadas do domínio. A extensão JavaScript pode acrescentar comportamentos a esses controles; nenhuma regra de produto pertence ao núcleo.

Importe o bundle distribuído pelo Composer para não depender do `node_modules` do pacote na aplicação. A factory ESM permite uma extensão opcional que preserva os descriptors:

```js
import createCanvas from '../../vendor/eduardoribeirodev/filament-canvas/resources/dist/canvas.js'

export default (initial, assets, width, height, previewHtml) =>
    createCanvas(initial, assets, width, height, previewHtml, {
        labels: {badge: 'Selo'},
        elementTypes: {badge: {icon: 'heroicon-o-tag'}},
        extension(core) { return { /* comportamento específico */ } },
    })
```

## Desenvolvimento

```bash
npm run build
npm test
```

Execute `npm ci` antes de compilar. O build empacota o editor em `resources/dist/canvas.js` e os estilos em `resources/dist/editor.css`. O zoom usa os assets do Slider já fornecidos pelo Filament, sem dependência adicional de noUiSlider no pacote. Inclua o dist no release Composer e execute `php artisan filament:assets` na aplicação após alterar o módulo ou os estilos.
