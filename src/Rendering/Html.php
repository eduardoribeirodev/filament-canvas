<?php

namespace EduardoRibeiroDev\FilamentCanvas\Rendering;

final class Html
{
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function color(mixed $value, string $fallback = '#222222'): string
    {
        return is_string($value) && preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{6}|[a-f0-9]{8})$/iD', $value) ? $value : $fallback;
    }

    public static function linkUrl(mixed $value): string
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL) || ! in_array(strtolower(parse_url($value, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)) {
            return '';
        }

        return self::escape($value);
    }

    /** Safe URL for Blade's escaped interpolation. */
    public static function rawImageUrl(mixed $value): string
    {
        return self::imageUrl($value) !== '' ? $value : '';
    }

    public static function imageUrl(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }
        if ((str_starts_with($value, '/') && ! str_starts_with($value, '//')) || preg_match('~^(https?://|data:image/(?:png|jpeg|webp|gif);base64,)~i', $value)) {
            return self::escape($value);
        }

        return '';
    }
}
