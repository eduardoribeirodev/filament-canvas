<?php

namespace EduardoRibeiroDev\FilamentCanvas\Support;

use EduardoRibeiroDev\FilamentCanvas\Data\EditorContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaFiles
{
    public static function roots(): array
    {
        $disks = array_map(fn ($name) => Storage::disk($name)->path(''), array_keys(array_filter(config('filesystems.disks', []), fn ($disk) => ($disk['driver'] ?? null) === 'local')));

        return [storage_path('app'), public_path(), resource_path('fonts'), ...$disks, ...config('filament-canvas.file_roots', [])];
    }

    public static function trustedPath(?string $path): ?string
    {
        $real = $path && str_starts_with($path, '/') ? realpath($path) : false;
        if (! $real || ! is_file($real)) {
            return null;
        }
        foreach (self::roots() as $root) {
            $base = realpath($root);
            if ($base && $base !== DIRECTORY_SEPARATOR && str_starts_with($real, $base.DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }

        return null;
    }

    public static function url(?Media $media, ?EditorContext $context = null): ?string
    {
        if ($media === null) {
            return null;
        }
        if (config('filesystems.disks.'.$media->disk.'.visibility') === 'public') {
            return $media->getUrl();
        }
        if (config('filesystems.disks.'.$media->disk.'.driver') !== 'local') {
            return $media->getTemporaryUrl(now()->addMinutes(30));
        }

        return self::fileUrl($media->getPath(), $context);
    }

    private static function fileUrl(string $path, ?EditorContext $context): ?string
    {
        $real = self::trustedPath($path);
        $guard = $context?->panelId ? Filament::getPanel($context->panelId)->getAuthGuard() : config('auth.defaults.guard');
        $user = $context?->user ?? auth($guard)->user();
        if ($real === null || $user === null) {
            return null;
        }

        return URL::temporarySignedRoute('filament-canvas.file', now()->addMinutes(30), [
            'file' => strtr(Crypt::encryptString($real), '+/', '-_'), 'user' => $user->getAuthIdentifier(), 'guard' => $guard,
        ]);
    }

    /** Prepare trusted local media for Chromium or serve it through a signed preview URL. */
    public static function renderUrl(?string $path, array $options = [], bool $preview = false, ?string $cacheDirectory = null, ?EditorContext $context = null): ?string
    {
        if (! $path) {
            return null;
        }
        // Media Library supplies trusted absolute paths; relative paths have no trusted origin.
        if (! str_starts_with($path, '/')) {
            return null;
        }
        $real = realpath($path);
        if (! $real || ! is_file($real)) {
            return null;
        }
        foreach (self::roots() as $root) {
            if (($base = realpath($root)) && str_starts_with($real, $base.DIRECTORY_SEPARATOR)) {
                $prepared = (bool) ($options['pdf_prepared'] ?? false);
                $flattenWhite = (bool) ($options['pdf_flatten_white'] ?? false);
                $maxDimension = max(200, min(1200, (int) ($options['pdf_max_dimension'] ?? 1200)));
                $jpegQuality = max(65, min(95, (int) ($options['pdf_jpeg_quality'] ?? ($flattenWhite ? 82 : 88))));
                if ($cacheDirectory && in_array(strtolower(pathinfo($real, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'])
                    && (! $prepared || ($options['trim'] ?? false) || ($options['knockout'] ?? false))) {
                    File::ensureDirectoryExists($cacheDirectory);
                    $transparent = ! $flattenWhite && ($prepared
                        || ($options['knockout'] ?? false)
                        || ($options['preserve_alpha'] ?? false)
                        || str_ends_with(strtolower($real), '.png'));
                    $target = $cacheDirectory.'/'.sha1($real.filemtime($real).filesize($real).json_encode([$options['trim'] ?? false, $options['knockout'] ?? false, $options['preserve_alpha'] ?? false, $options['image_border'] ?? false, $prepared, $flattenWhite, $maxDimension, $jpegQuality])).($prepared && ! $flattenWhite ? '.webp' : ($transparent ? '.png' : '.jpg'));
                    if (! is_file($target)) {
                        $source = @imagecreatefromstring(file_get_contents($real));
                        if (! $source) {
                            return null;
                        }
                        $ratio = min(1, $maxDimension / imagesx($source), min(1000, $maxDimension) / imagesy($source));
                        $w = max(1, (int) (imagesx($source) * $ratio));
                        $h = max(1, (int) (imagesy($source) * $ratio));
                        $thumb = imagecreatetruecolor($w, $h);
                        imagealphablending($thumb, ! $transparent);
                        imagesavealpha($thumb, true);
                        imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 255, 255, 255, $transparent ? 127 : 0));
                        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $w, $h, imagesx($source), imagesy($source));
                        if (($options['trim'] ?? false) || ($options['knockout'] ?? false)) {
                            $minX = $w;
                            $minY = $h;
                            $maxX = -1;
                            $maxY = -1;
                            $clear = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
                            for ($py = 0; $py < $h; $py++) {
                                for ($px = 0; $px < $w; $px++) {
                                    $pixel = imagecolorat($thumb, $px, $py);
                                    $alpha = ($pixel >> 24) & 127;
                                    $ink = (255 - (.299 * (($pixel >> 16) & 255) + .587 * (($pixel >> 8) & 255) + .114 * ($pixel & 255))) * (1 - $alpha / 127);
                                    if ($ink > 18) {
                                        $minX = min($minX, $px);
                                        $minY = min($minY, $py);
                                        $maxX = max($maxX, $px);
                                        $maxY = max($maxY, $py);
                                    }
                                    if (($options['knockout'] ?? false) && $ink <= 8) {
                                        imagesetpixel($thumb, $px, $py, $clear);
                                    }
                                }
                            }
                            if (($options['trim'] ?? false) && $maxX >= $minX) {
                                // Leave a small margin around bordered photos to avoid clipping their outer pixels.
                                $margin = ($options['image_border'] ?? false) ? max(2, (int) ceil(min($w, $h) * .0125)) : 0;
                                $left = max(0, $minX - $margin);
                                $top = max(0, $minY - $margin);
                                $right = min($w - 1, $maxX + $margin);
                                $bottom = min($h - 1, $maxY + $margin);
                                $cropped = imagecrop($thumb, ['x' => $left, 'y' => $top, 'width' => $right - $left + 1, 'height' => $bottom - $top + 1]);
                                imagedestroy($thumb);
                                $thumb = $cropped;
                                imagesavealpha($thumb, true);
                            }
                        }
                        if ($prepared && ! $flattenWhite) {
                            imagewebp($thumb, $target, 84);
                        } elseif ($transparent) {
                            imagepng($thumb, $target, 6);
                        } else {
                            imagejpeg($thumb, $target, $jpegQuality);
                        }
                        imagedestroy($thumb);
                        imagedestroy($source);
                    }
                    $real = $target;
                }

                if ($preview) {
                    return self::fileUrl($real, $context);
                }

                return 'file://'.implode('/', array_map('rawurlencode', explode('/', $real)));
            }
        }

        return null;
    }
}
