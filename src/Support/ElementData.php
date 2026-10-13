<?php

namespace EduardoRibeiroDev\FilamentCanvas\Support;

/** Compatibility boundary for the original three property containers. */
final class ElementData
{
    public static function from(array $data): array
    {
        if (array_key_exists('dados', $data)) {
            return is_array($data['dados']) ? $data['dados'] : [];
        }

        return array_merge(
            is_array($data['configuracoes'] ?? null) ? $data['configuracoes'] : [],
            is_array($data['conteudo'] ?? null) ? $data['conteudo'] : [],
            array_filter(is_array($data['estilo'] ?? null) ? $data['estilo'] : [], fn ($value) => $value !== null && $value !== ''),
        );
    }

    public static function canonicalizeBlock(array $block): array
    {
        if (! is_array($block['data'] ?? null)) {
            return $block;
        }
        if (! array_key_exists('dados', $block['data'])) {
            $block['data']['dados'] = self::from($block['data']);
        }
        unset($block['data']['conteudo'], $block['data']['estilo'], $block['data']['configuracoes']);

        return $block;
    }

    public static function canonicalizeBlocks(array $blocks): array
    {
        return array_map(fn ($block) => is_array($block) ? self::canonicalizeBlock($block) : $block, $blocks);
    }
}
