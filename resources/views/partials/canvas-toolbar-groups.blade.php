                @foreach ($elementGroups as $group)
                    @if ($group['grouped'])
                        <li class="canvas-toolbar-group" @if($group['label']) aria-label="{{ $group['label'] }}" @endif>
                            @if ($group['label'])<span class="canvas-toolbar-group-label">{{ $group['label'] }}</span>@endif
                            <ul class="canvas-toolbar-items">
                    @endif
                    @foreach ($group['types'] as $type)
                        <x-filament-canvas::toolbar-item :label="__('filament-canvas::canvas.add_element', ['label' => $elementLabels[$type]])" :icon="$elementIcons[$type] ?? null"
                            data-canvas-insert="{{ $type }}" @click="add({{ \Illuminate\Support\Js::from($type) }})" />
                    @endforeach
                    @if ($group['grouped'])
                            </ul>
                        </li>
                    @endif
                @endforeach
