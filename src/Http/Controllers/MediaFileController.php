<?php

namespace EduardoRibeiroDev\FilamentCanvas\Http\Controllers;

use EduardoRibeiroDev\FilamentCanvas\Support\MediaFiles;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaFileController
{
    public function __invoke(Request $request, string $file): BinaryFileResponse
    {
        $user = auth($request->query('guard'))->user();
        abort_unless($user && (string) $user->getAuthIdentifier() === (string) $request->query('user'), 403);
        try {
            $path = MediaFiles::trustedPath(Crypt::decryptString(strtr($file, '-_', '+/')));
        } catch (DecryptException) {
            abort(403);
        }
        abort_unless($path, 404);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = mime_content_type($path);
        abort_unless(in_array($extension, ['ttf', 'woff', 'woff2'], true)
            || (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)
                && in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)), 403);

        return response()->file($path, ['Cache-Control' => 'private, max-age=1800', 'X-Content-Type-Options' => 'nosniff']);
    }
}
