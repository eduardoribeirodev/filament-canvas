<?php

namespace EduardoRibeiroDev\FilamentCanvas\Support;

use EduardoRibeiroDev\FilamentCanvas\Contracts\ElementType;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final class EditorConfiguration
{
    public static function encode(string $type, string|int $id, array $options, ?string $panelId = null): string
    {
        foreach (['elements', 'extraElements'] as $key) {
            foreach ($options[$key] ?? [] as $element) {
                if (! is_string($element) || ! is_a($element, ElementType::class, true)) {
                    throw new \InvalidArgumentException('Schema and page element definitions must be ElementType class names.');
                }
            }
        }

        return Crypt::encryptString(json_encode(['type' => $type, 'id' => (string) $id, 'panel' => $panelId, 'options' => $options], JSON_THROW_ON_ERROR));
    }

    public static function decode(string $token, string $type, string $id, ?string $panelId = null): array
    {
        try {
            $configuration = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            abort(403);
        }
        abort_unless($configuration['type'] === $type && $configuration['id'] === $id && $configuration['panel'] === $panelId, 403);

        return $configuration['options'];
    }
}
