<div class="canvas-livewire-shell">
<div class="fc-editor"
    x-load x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('canvas', 'eduardoribeirodev/filament-canvas') }}"
    x-data="canvas(@js($initial), @js($assets), @js($width), @js($height), @js($previewHtml), {labels: @js($elementLabels), elementTypes: @js($elementTypes), fitViewport: true})"
    wire:ignore.self x-bind:aria-busy="saving"
    @pointerdown.window.capture="finishInlineEditing($event)" @scroll.window="positionInlineEditor()"
    @canvas-rich-text-change.stop="applyRichText($event.detail,$event.detail.key)"
    @canvas-rich-text-ready="syncInspector()"
    @canvas-inspector-ready.stop="syncInspector()"
    @canvas-asset-selected.stop="selectAsset($event.detail.id,$event.detail.key,$event.detail.collection)"
    @canvas-reference-selected.stop="selectReference($event.detail.id,$event.detail.key)"
    @canvas-style-changed.stop="applyTextStyle($event.detail)">
    <style>
        {!! \EduardoRibeiroDev\FilamentCanvas\Support\RichText::styles() !!}
        .canvas-native-fields .fi-fo-rich-editor-content{min-height:140px;max-height:320px;overflow-y:auto}
    </style>
    <header class="fc-header" wire:ignore>
        <div class="fc-heading">
            <span class="fc-heading-icon"><x-filament::icon icon="heroicon-o-document" /></span>
            <div>
                <h2>Editor visual</h2>
                <p><span x-text="pageWidth.toLocaleString('pt-BR',{maximumFractionDigits:1})+' × '+pageHeight.toLocaleString('pt-BR',{maximumFractionDigits:1})+' pt'"></span> · <span x-text="blocks.length+' elemento(s)'"></span></p>
            </div>
        </div>
        <div class="fc-actions">
            <x-filament::badge color="gray"><span aria-live="polite" x-text="saving ? 'Salvando…' : dirty ? 'Alterações pendentes' : 'Tudo salvo'"></span></x-filament::badge>
            <div class="fc-history">
                <x-filament::icon-button type="button" color="gray" icon="heroicon-o-arrow-uturn-left" label="Desfazer" title="Desfazer" @click="undo()" x-bind:disabled="saving || !undoStack.length" />
                <x-filament::icon-button type="button" color="gray" icon="heroicon-o-arrow-uturn-right" label="Refazer" title="Refazer" @click="redo()" x-bind:disabled="saving || !redoStack.length" />
            </div>
            <x-filament::button type="button" icon="heroicon-o-check" @click="save($wire)" x-bind:disabled="saving || !dirty" data-canvas-save>
                <span x-text="saving ? 'Salvando…' : 'Salvar composição'"></span>
            </x-filament::button>
        </div>
    </header>
    <div role="alert" x-cloak x-show="error" class="fc-error" wire:ignore>
        <x-filament::icon icon="heroicon-o-exclamation-triangle" /><p x-text="error"></p>
    </div>
    <div class="fc-layout" x-ref="workspace">
        <nav class="fc-toolbar" aria-label="Adicionar elementos" wire:ignore>
            <ul class="canvas-toolbar-items">
                @foreach ($elementLabels as $type => $label)
                    @continue(! ($elementTypes[$type]['toolbar'] ?? true))
                    <x-filament-canvas::toolbar-item :label="'Adicionar '.$label" :icon="$elementTypes[$type]['icon'] ?? null"
                        data-canvas-insert="{{ $type }}" @click="add({{ \Illuminate\Support\Js::from($type) }})" />
                @endforeach
            </ul>
            @isset($toolbarView) @include($toolbarView) @endisset
        </nav>
        <div class="fc-stage-column">
            <div x-ref="stage" class="fc-stage" wire:ignore tabindex="0" aria-label="Prancheta do editor visual"
                @keydown="key($event)" @pointerdown.self="commitTextEditing();cropIndex=null;selected=null;$el.focus({preventScroll:true})"
                @pointermove.stop="move($event)" @pointerup.stop="endGesture()" @pointercancel.stop="endGesture()" @lostpointercapture="endGesture()"
                @scroll.passive="positionInlineEditor()" @wheel="wheel($event)" @gesturestart="pinchStart($event)" @gesturechange="pinchChange($event)" @gestureend="pinchEnd($event)">
                <div x-ref="viewport" :style="viewportStyle()" @pointerdown.self="commitTextEditing();cropIndex=null;selected=null;$refs.stage.focus({preventScroll:true})">
                    <div class="fc-paper" x-ref="paper" :style="paperStyle()" @pointerdown.self="commitTextEditing();selected=null;cropIndex=null">
                        <iframe x-ref="preview" title="Prévia da composição" class="fc-preview" @load="onPreviewLoad()"></iframe>
                        <template x-for="(b,i) in blocks" :key="b._key">
                            <div class="fc-selection" :class="{'fc-selected':selected===i}" :style="style(b,i)" @dblclick.stop="hasCapability(b,'text') ? startTextEditing($event,i) : editCrop($event,i)" @pointerdown.stop="if(!isTextEditing(b)) start($event,i,'move')" tabindex="0" @focus="selected=i" :aria-label="b.data.nome">

                                <template x-if="selected===i && !isTextEditing(b)">
                                    <div :style="cropIndex===i ? {...imageStyle(b,false),outline:'2px solid var(--fc-crop)'} : {position:'absolute',inset:0,pointerEvents:'none'}" @pointerdown.stop="start($event,i,'move')">
                                        <button type="button" aria-label="Girar seleção" class="fc-handle" style="top:-28px;left:calc(50% - 7px);border-radius:50%;cursor:grab" @pointerdown.stop="start($event,i,'rotate')"></button>
                                        @foreach(['nw' => 'top:-7px;left:-7px;cursor:nwse-resize', 'ne' => 'top:-7px;right:-7px;cursor:nesw-resize', 'sw' => 'bottom:-7px;left:-7px;cursor:nesw-resize', 'se' => 'bottom:-7px;right:-7px;cursor:nwse-resize'] as $corner => $style)
                                            <button type="button" aria-label="Redimensionar {{ $corner }}" class="fc-handle" style="{{ $style }}" @pointerdown.stop="start($event,i,'resize','{{ $corner }}')"></button>
                                        @endforeach
                                    </div>
                                </template>
                            </div>
                        </template>
                        @include('filament-canvas::partials.canvas-guides')
                    </div>
                </div>
            </div>
            @include('filament-canvas::partials.canvas-inline-text')
            <div class="fc-footer" wire:ignore>
                <label class="fc-snap">
                    <span>Grade</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select aria-label="Encaixe em pixels" x-model.number="snapPixels">
                            <option value="0">Sem grade</option>
                            @foreach([1, 2, 5, 10, 20] as $pixels)
                                <option value="{{ $pixels }}">{{ $pixels }} px</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <x-filament::button type="button" color="gray" size="sm" icon="heroicon-o-arrows-pointing-out" @click="fit()">Ajustar à tela</x-filament::button>
                @include('filament-canvas::partials.canvas-snap')
                @include('filament-canvas::partials.canvas-zoom')
            </div>
        </div>
        <aside class="fc-sidebar" aria-label="Detalhes e camadas">
            <div class="fc-tabs" wire:ignore>
                <x-filament::tabs label="Painel do editor" role="tablist">
                    <x-filament::tabs.item icon="heroicon-o-adjustments-horizontal" role="tab" alpine-active="panel === 'properties'" x-bind:aria-selected="panel==='properties'" @click="panel='properties'">Propriedades</x-filament::tabs.item>
                    <x-filament::tabs.item icon="heroicon-o-square-3-stack-3d" role="tab" alpine-active="panel === 'layers'" x-bind:aria-selected="panel==='layers'" @click="panel='layers'">Camadas<x-slot:badge><x-filament::badge color="gray"><span x-text="blocks.length"></span></x-filament::badge></x-slot:badge></x-filament::tabs.item>
                </x-filament::tabs>
            </div>
            <div class="fc-inspector">
                <div x-cloak x-show="panel==='layers'" wire:ignore>
                    <p class="fc-caption">Da frente para o fundo. Arraste a alça para reordenar.</p>
                    <div x-sortable @end.stop="sortLayers($event)" class="fc-layer-list" data-canvas-layers>
                        <template x-for="b in [...blocks].reverse()" :key="b._key">
                            <div :x-sortable-item="b._key" class="fc-layer" :class="{'fc-layer-selected':current?._key===b._key}">
                                <x-filament::icon-button type="button" color="gray" icon="heroicon-o-bars-3" label="Arrastar camada" x-sortable-handle class="fc-layer-handle" />
                                <button type="button" @click="selectBlock(blocks.findIndex(item=>item._key===b._key))" class="fc-layer-label" x-bind:aria-pressed="current?._key===b._key">
                                    <span class="fc-layer-text"><span class="fc-layer-name" x-text="b.data.nome"></span><span class="fc-layer-type" x-text="labels[b.type] || b.type"></span></span>
                                    <x-filament::icon icon="heroicon-o-eye-slash" x-show="!b.data.visivel" aria-label="Elemento oculto" />
                                </button>
                            </div>
                        </template>
                    </div>
                    <x-filament::empty-state x-show="!blocks.length" heading="Nenhum elemento" description="Adicione um elemento pela barra de ferramentas." icon="heroicon-o-square-3-stack-3d" icon-color="gray" :contained="false" />
                </div>
                <div x-show="panel==='properties'" class="fc-properties">
                    <x-filament::empty-state x-show="!current" class="fc-empty" heading="Selecione um elemento" description="Clique na prancheta para editar conteúdo, aparência e posição." icon="heroicon-o-cursor-arrow-rays" icon-color="gray" :contained="false" />
                    <div wire:ignore><template x-if="current"><fieldset :disabled="saving" class="fc-fields canvas-native-fields">
                        <div class="fc-section-heading"><h3>Elemento</h3><x-filament::badge color="gray"><span x-text="labels[current.type] || current.type"></span></x-filament::badge></div>
                        <x-filament-canvas::field label="Nome">
                            <x-filament::input.wrapper><x-filament::input x-model="current.data.nome" @focus="checkpoint()" /></x-filament::input.wrapper>
                        </x-filament-canvas::field>
                        <x-filament-canvas::field label="Visível na página" inline>
                            <x-filament::input.checkbox x-model="current.data.visivel" @click="checkpoint()" />
                        </x-filament-canvas::field>
                        <div class="fc-pair">
                            <x-filament::button type="button" color="gray" size="sm" icon="heroicon-o-document-duplicate" @click="duplicate()">Duplicar</x-filament::button>
                            <x-filament::button type="button" color="danger" size="sm" icon="heroicon-o-trash" @click="remove()">Excluir</x-filament::button>
                            <x-filament::button type="button" color="gray" size="sm" icon="heroicon-o-arrow-up" @click="layer(1)">À frente</x-filament::button>
                            <x-filament::button type="button" color="gray" size="sm" icon="heroicon-o-arrow-down" @click="layer(-1)">Atrás</x-filament::button>
                        </div>
                    </fieldset></template></div>
                    @include('filament-canvas::partials.canvas-properties')
                    @include('filament-canvas::partials.canvas-image-library')
                    @isset($propertiesView) @include($propertiesView) @endisset
                    <div wire:ignore><template x-if="current"><fieldset :disabled="saving" class="fc-fields fc-geometry canvas-native-fields">
                        <div class="fc-section-heading"><h3>Posição e dimensões</h3></div>
                        <div class="fc-pair">
                            <template x-for="(label,key) in {x_pt:'X (pt)',y_pt:'Y (pt)',largura_pt:'Largura (pt)',altura_pt:'Altura (pt)',rotacao_graus:'Rotação (°)'}" :key="key">
                                <x-filament-canvas::field>
                                    <x-slot:label><span x-text="label"></span></x-slot:label>
                                    <x-filament::input.wrapper><x-filament::input type="number" step=".5" x-model.number="current.data[key]" @focus="checkpoint()" @change="normalize(current)" /></x-filament::input.wrapper>
                                </x-filament-canvas::field>
                            </template>
                        </div>
                    </fieldset></template></div>
                </div>
            </div>
        </aside>
    </div>
    <details class="fc-help" x-ref="help" @toggle="resizeEditorViewport()">
        <summary><x-filament::icon icon="heroicon-o-information-circle" />Gestos e atalhos</summary>
        <div class="fc-help-content">
            <p><kbd>Duplo clique</kbd> no texto para editar na prancheta.</p>
            <p><kbd>Pinça</kbd> para ampliar. Dois dedos para mover a área visível.</p>
            <p><kbd>Shift</kbd> mantém proporção e gira em passos de 15°.</p>
            <p><kbd>Alt/Option</kbd> redimensiona pelo centro.</p>
            <p><kbd>Ctrl/⌘</kbd> ignora as guias de alinhamento durante o arraste.</p>
            <p><kbd>Delete</kbd> exclui o elemento selecionado.</p>
            <p><kbd>Esc</kbd> cancela a edição ou o enquadramento.</p>
        </div>
    </details>
</div>
    @if ($errors->any())
        <div role="alert" class="fc-error"><x-filament::icon icon="heroicon-o-exclamation-triangle" /><p>{{ $errors->first() }}</p></div>
    @endif
    <x-filament-actions::modals />
</div>
