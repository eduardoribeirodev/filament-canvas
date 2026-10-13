<div x-cloak x-show="current && mediaCollections(current).length" class="canvas-native-fields space-y-4">
    {{ $this->imageForm }}
    <template x-if="current">
        <div class="space-y-3">
            <template x-for="collection in mediaCollections(current)" :key="collection.name">
                <div class="grid grid-cols-2 gap-2">
                    <template x-for="media in mediaImages(current,collection.name)" :key="String(media.id)">
                        <div class="overflow-hidden rounded-lg border border-gray-200 bg-gray-50 p-2 dark:border-white/10 dark:bg-white/5">
                            <img data-canvas-media-preview :src="safeImageUrl(media.image)" :alt="media.name || 'Prévia da mídia selecionada'" class="h-28 w-full object-contain" style="width:100%;height:110px;object-fit:contain">
                            <p class="mt-1 truncate text-xs text-gray-500" x-text="media.name"></p>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </template>
    @if ($canUploadAssets)
        <details class="rounded-lg border border-gray-200 p-3 dark:border-white/10" @toggle="if($event.target.open) cropIndex=null">
            <summary class="cursor-pointer text-xs font-semibold">Enviar nova mídia</summary>
            <div class="mt-3 space-y-3">
                {{ $this->imageUploadForm }}
                @foreach ($this->elementMediaCollections() as $collection)
                    <x-filament::button type="button" size="sm" icon="heroicon-o-arrow-up-tray" @click="uploadImage($wire,'{{ $collection['name'] }}')" x-bind:disabled="imageUploading || saving">
                        <span x-text="imageUploading ? 'Enviando…' : @js('Enviar e usar '.$collection['label'])"></span>
                    </x-filament::button>
                @endforeach
                <p class="text-xs text-gray-500">A mídia entra na biblioteca. Salve a composição para confirmar seu uso na página.</p>
            </div>
        </details>
    @endif
</div>
