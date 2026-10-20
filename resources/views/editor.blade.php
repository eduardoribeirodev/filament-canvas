<div class="canvas-livewire-shell">
<div class="fc-editor"
    x-load x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('canvas', 'eduardoribeirodev/filament-canvas') }}"
    x-data="canvas(@js($initial), @js($assets), @js($width), @js($height), @js($previewHtml), {labels: @js($elementLabels), elementTypes: @js($elementTypes), templates: @js($elementTemplates), data: @js($metadata), messages: @js(__('filament-canvas::canvas')), minZoom: @js(config('filament-canvas.min_zoom', 0.25)), maxZoom: @js(config('filament-canvas.max_zoom', 3)), fitViewport: true})"
    wire:ignore.self x-bind:aria-busy="saving"
    @pointerdown.window.capture="finishInlineEditing($event)" @scroll.window="positionInlineEditor()"
    @canvas-rich-text-change.stop="applyRichText($event.detail,$event.detail.key)"
    @canvas-rich-text-ready="syncInspector()"
    @canvas-inspector-ready.stop="syncInspector()"
    @canvas-asset-selected.stop="selectAsset($event.detail.id,$event.detail.key,$event.detail.collection)"
    @canvas-reference-selected.stop="selectReference($event.detail.id,$event.detail.key)"
>
    <style>
        {!! \EduardoRibeiroDev\FilamentCanvas\Support\RichText::styles() !!}
        .canvas-native-fields .fi-fo-rich-editor-content{min-height:140px;max-height:320px;overflow-y:auto}
    </style>
    <header class="fc-header" wire:ignore>
        <div class="fc-heading">
            <span class="fc-heading-icon"><x-filament::icon icon="heroicon-o-document" /></span>
            <div>
                <h2>{{ __('filament-canvas::canvas.visual_editor') }}</h2>
                <p><span x-text="pageWidth.toLocaleString(@js(str_replace('_', '-', app()->getLocale())),{maximumFractionDigits:1})+' × '+pageHeight.toLocaleString(@js(str_replace('_', '-', app()->getLocale())),{maximumFractionDigits:1})+' pt'"></span> · <span x-text="messages.element_count.replace(':count', blocks.length)"></span></p>
            </div>
        </div>
        <div class="fc-actions">
            <x-filament::badge color="gray"><span aria-live="polite" x-text="saving ? @js(__('filament-canvas::canvas.saving')) : dirty ? @js(__('filament-canvas::canvas.unsaved_changes')) : @js(__('filament-canvas::canvas.all_saved'))"></span></x-filament::badge>
            <div class="fc-history">
                <x-filament::icon-button type="button" color="gray" icon="heroicon-o-arrow-uturn-left" label="{{ __('filament-canvas::canvas.undo') }}" title="{{ __('filament-canvas::canvas.undo') }}" @click="undo()" x-bind:disabled="saving || !undoStack.length" />
                <x-filament::icon-button type="button" color="gray" icon="heroicon-o-arrow-uturn-right" label="{{ __('filament-canvas::canvas.redo') }}" title="{{ __('filament-canvas::canvas.redo') }}" @click="redo()" x-bind:disabled="saving || !redoStack.length" />
            </div>
            <x-filament::button type="button" icon="heroicon-o-check" @click="save($wire)" x-bind:disabled="saving || !dirty" data-canvas-save>
                <span x-text="saving ? @js(__('filament-canvas::canvas.saving')) : @js(__('filament-canvas::canvas.save_composition'))"></span>
            </x-filament::button>
        </div>
    </header>
    <div role="alert" x-cloak x-show="error" class="fc-error" wire:ignore>
        <x-filament::icon icon="heroicon-o-exclamation-triangle" /><p x-text="error"></p>
    </div>
    <div class="fc-layout" x-ref="workspace">
        <nav class="fc-toolbar" aria-label="{{ __('filament-canvas::canvas.add_elements') }}" wire:ignore>
            <ul class="canvas-toolbar-items">
                @include('filament-canvas::partials.canvas-toolbar-groups')
            </ul>
            @isset($toolbarView) @include($toolbarView) @endisset
        </nav>
        <div class="fc-stage-column">
            <div class="fc-stage-viewport">
                @include('filament-canvas::partials.canvas-drawing-controls')
            <div x-ref="stage" class="fc-stage" wire:ignore tabindex="0" aria-label="{{ __('filament-canvas::canvas.canvas') }}"
                @keydown="key($event)" @pointerdown.self="if(drawingSession && !finishDrawing())cancelDrawing();commitTextEditing();cropIndex=null;selected=null;$el.focus({preventScroll:true})"
                @pointermove.stop="move($event)" @pointerup.stop="endGesture()" @pointercancel.stop="endGesture()" @lostpointercapture="endGesture()"
                @scroll.passive="positionInlineEditor()" @wheel="wheel($event)" @gesturestart="pinchStart($event)" @gesturechange="pinchChange($event)" @gestureend="pinchEnd($event)">
                <div x-ref="viewport" :style="viewportStyle()" @pointerdown.self="if(drawingSession && !finishDrawing())cancelDrawing();commitTextEditing();cropIndex=null;selected=null;$refs.stage.focus({preventScroll:true})">
                    <div class="fc-paper" x-ref="paper" :style="paperStyle()" @pointerdown.capture="drawingPointerDown($event)" @pointermove.capture="drawingPointerMove($event)" @pointerdown.self="if(drawingSession && !finishDrawing())cancelDrawing();commitTextEditing();selected=null;cropIndex=null">
                        <iframe x-ref="preview" title="{{ __('filament-canvas::canvas.preview') }}" class="fc-preview" @load="onPreviewLoad()"></iframe>
                        <template x-for="(b,i) in blocks" :key="b._key">
                            <div class="fc-selection" :class="{'fc-selected':selected===i}" :style="style(b,i)" @dblclick.stop="isText(b) ? startTextEditing($event,i) : (isDrawing(b) ? editDrawing($event,i) : editCrop($event,i))" @pointerdown.stop="if(!isTextEditing(b)) start($event,i,'move')" tabindex="0" @focus="selected=i" :aria-label="b.data.name">

                            @include('filament-canvas::partials.canvas-drawing-points')
                                <template x-if="selected===i && !isTextEditing(b) && !isDrawingEditing(b)">
                                    <div :style="cropIndex===i ? {...imageStyle(b,false),outline:'2px solid var(--fc-crop)'} : {position:'absolute',inset:0,pointerEvents:'none'}" @pointerdown.stop="start($event,i,'move')">
                                        <button type="button" aria-label="{{ __('filament-canvas::canvas.rotate_selection') }}" class="fc-handle" style="top:-28px;left:calc(50% - 7px);border-radius:50%;cursor:grab" @pointerdown.stop="start($event,i,'rotate')"></button>
                                        @foreach(['nw' => 'top:-7px;left:-7px;cursor:nwse-resize', 'ne' => 'top:-7px;right:-7px;cursor:nesw-resize', 'sw' => 'bottom:-7px;left:-7px;cursor:nesw-resize', 'se' => 'bottom:-7px;right:-7px;cursor:nwse-resize'] as $corner => $style)
                                            <button type="button" aria-label="{{ __('filament-canvas::canvas.resize', ['corner' => $corner]) }}" class="fc-handle" style="{{ $style }}" @pointerdown.stop="start($event,i,'resize','{{ $corner }}')"></button>
                                        @endforeach
                                    </div>
                                </template>
                            </div>
                        </template>
                    @include('filament-canvas::partials.canvas-guides')
                    </div>
                </div>
            </div>
            </div>
            @include('filament-canvas::partials.canvas-inline-text')
            <div class="fc-footer" wire:ignore>
                <label class="fc-snap">
                    <span>{{ __('filament-canvas::canvas.grid') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select aria-label="{{ __('filament-canvas::canvas.snap_pixels') }}" x-model.number="snapPixels">
                            <option value="0">{{ __('filament-canvas::canvas.no_grid') }}</option>
                            @foreach([1, 2, 5, 10, 20] as $pixels)
                                <option value="{{ $pixels }}">{{ $pixels }} px</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <x-filament::button type="button" color="gray" size="sm" icon="heroicon-o-arrows-pointing-out" @click="fit()">{{ __('filament-canvas::canvas.fit_screen') }}</x-filament::button>
                @include('filament-canvas::partials.canvas-snap')
                @include('filament-canvas::partials.canvas-zoom')
            </div>
        </div>
        <aside :inert="Boolean(drawingSession?.creating)" class="fc-sidebar" aria-label="{{ __('filament-canvas::canvas.details_layers') }}">
            <div class="fc-tabs" wire:ignore>
                <x-filament::tabs label="{{ __('filament-canvas::canvas.editor_panel') }}" role="tablist">
                    <x-filament::tabs.item icon="heroicon-o-adjustments-horizontal" role="tab" alpine-active="panel === 'properties'" x-bind:aria-selected="panel==='properties'" @click="panel='properties'">{{ __('filament-canvas::canvas.properties') }}</x-filament::tabs.item>
                    <x-filament::tabs.item icon="heroicon-o-square-3-stack-3d" role="tab" alpine-active="panel === 'layers'" x-bind:aria-selected="panel==='layers'" @click="panel='layers'">{{ __('filament-canvas::canvas.layers') }}<x-slot:badge><x-filament::badge color="gray"><span x-text="blocks.length"></span></x-filament::badge></x-slot:badge></x-filament::tabs.item>
                </x-filament::tabs>
            </div>
            <div class="fc-inspector">
                <div x-cloak x-show="panel==='layers'" wire:ignore>
                    <p class="fc-caption">{{ __('filament-canvas::canvas.layer_help') }}</p>
                    <div x-sortable @end.stop="sortLayers($event)" class="fc-layer-list" data-canvas-layers>
                        <template x-for="b in [...blocks].reverse()" :key="b._key">
                            <div :x-sortable-item="b._key" class="fc-layer" :class="{'fc-layer-selected':current?._key===b._key}">
                                <x-filament::icon-button type="button" color="gray" icon="heroicon-o-bars-3" label="{{ __('filament-canvas::canvas.drag_layer') }}" x-sortable-handle class="fc-layer-handle" />
                                <button type="button" @click="selectBlock(blocks.findIndex(item=>item._key===b._key))" class="fc-layer-label" x-bind:aria-pressed="current?._key===b._key">
                                    <span class="fc-layer-text"><span class="fc-layer-name" x-text="b.data.name"></span><span class="fc-layer-type" x-text="labels[b.type] || b.type"></span></span>
                                    <x-filament::icon icon="heroicon-o-eye-slash" x-show="!b.data.visible" aria-label="{{ __('filament-canvas::canvas.hidden_element') }}" />
                                </button>
                            </div>
                        </template>
                    </div>
                    <x-filament::empty-state x-show="!blocks.length" heading="{{ __('filament-canvas::canvas.no_elements') }}" description="{{ __('filament-canvas::canvas.add_toolbar_hint') }}" icon="heroicon-o-square-3-stack-3d" icon-color="gray" :contained="false" />
                </div>
                <div x-show="panel==='properties'" class="fc-properties">
                    <x-filament::empty-state x-show="!current" class="fc-empty" heading="{{ __('filament-canvas::canvas.select_element') }}" description="{{ __('filament-canvas::canvas.select_hint') }}" icon="heroicon-o-cursor-arrow-rays" icon-color="gray" :contained="false" />
                    <div wire:ignore><template x-if="current"><fieldset :disabled="saving" class="fc-fields canvas-native-fields">
                        <div class="fc-section-heading"><h3>{{ __('filament-canvas::canvas.element') }}</h3><x-filament::badge color="gray"><span x-text="labels[current.type] || current.type"></span></x-filament::badge></div>
                        <x-filament-canvas::field label="{{ __('filament-canvas::canvas.name') }}">
                            <x-filament::input.wrapper><x-filament::input x-model="current.data.name" @focus="checkpoint()" /></x-filament::input.wrapper>
                        </x-filament-canvas::field>
                        <x-filament-canvas::field label="{{ __('filament-canvas::canvas.visible_on_page') }}" inline>
                            <x-filament::input.checkbox x-model="current.data.visible" @click="checkpoint()" />
                        </x-filament-canvas::field>
                        <div class="fc-pair">
                            <x-filament::button type="button" color="gray" size="sm" icon="heroicon-o-document-duplicate" @click="duplicate()">{{ __('filament-canvas::canvas.duplicate') }}</x-filament::button>
                            <x-filament::button type="button" color="danger" size="sm" icon="heroicon-o-trash" @click="remove()">{{ __('filament-canvas::canvas.delete') }}</x-filament::button>
                            <x-filament::button type="button" color="gray" size="sm" icon="heroicon-o-arrow-up" @click="layer(1)">{{ __('filament-canvas::canvas.bring_forward') }}</x-filament::button>
                            <x-filament::button type="button" color="gray" size="sm" icon="heroicon-o-arrow-down" @click="layer(-1)">{{ __('filament-canvas::canvas.send_backward') }}</x-filament::button>
                        </div>
                    </fieldset></template></div>
                    @include('filament-canvas::partials.canvas-properties')
                    @include('filament-canvas::partials.canvas-image-library')
                    @isset($propertiesView) @include($propertiesView) @endisset
                    <div wire:ignore><template x-if="current"><fieldset :disabled="saving" class="fc-fields fc-geometry canvas-native-fields">
                        <div class="fc-section-heading"><h3>{{ __('filament-canvas::canvas.position_dimensions') }}</h3></div>
                        <div class="fc-pair">
                            <template x-for="(label,key) in {x_pt:'X (pt)',y_pt:'Y (pt)',width_pt:@js(__('filament-canvas::canvas.width_pt')),height_pt:@js(__('filament-canvas::canvas.height_pt')),rotation_deg:@js(__('filament-canvas::canvas.rotation_deg'))}" :key="key">
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
        <summary><x-filament::icon icon="heroicon-o-information-circle" />{{ __('filament-canvas::canvas.shortcuts') }}</summary>
        <div class="fc-help-content">
            <p>{{ __('filament-canvas::canvas.help_edit') }}</p>
            <p>{{ __('filament-canvas::canvas.help_zoom') }}</p>
            <p>{{ __('filament-canvas::canvas.help_shift') }}</p>
            <p>{{ __('filament-canvas::canvas.help_alt') }}</p>
            <p>{{ __('filament-canvas::canvas.help_ctrl') }}</p>
            <p>{{ __('filament-canvas::canvas.help_delete') }}</p>
            <p>{{ __('filament-canvas::canvas.help_esc') }}</p>
        </div>
    </details>
</div>
    @if ($errors->any())
        <div role="alert" class="fc-error"><x-filament::icon icon="heroicon-o-exclamation-triangle" /><p>{{ $errors->first() }}</p></div>
    @endif
    <x-filament-actions::modals />
</div>
