<div {{ $getExtraAttributeBag() }}>
    @php($parameters = $getEditorParameters())
    @livewire('filament-canvas.editor', $parameters, key($getLivewire()->getId().'-'.$getKey().'-'.$parameters['documentType'].'-'.$parameters['documentId']))
</div>
