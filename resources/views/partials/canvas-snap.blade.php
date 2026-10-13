<label class="canvas-alignment-control" x-tooltip="{content:'Encaixar nas bordas e centros. Ctrl/⌘ ignora as guias durante o arraste.',theme:$store.theme}">
    <x-filament::input.checkbox x-model="alignmentSnap" @change="snapGuides=[]" />
    <span>Guias</span>
</label>
