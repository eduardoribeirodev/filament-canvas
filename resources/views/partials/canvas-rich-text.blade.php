<div x-cloak x-show="current && ['title','text'].includes(current.type)" class="canvas-native-fields space-y-4">
    {{ $this->richTextForm }}
    {{ $this->textStyleForm }}
    <p class="text-xs text-gray-500 dark:text-gray-400">Formate apenas o trecho selecionado. Dois cliques no texto da prancheta permitem editar diretamente; Esc cancela a edição.</p>
</div>
