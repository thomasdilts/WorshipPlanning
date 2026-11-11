<?php

namespace App\Support;

use Closure;
use Illuminate\Http\UploadedFile;

/**
 * Shared files are served from the public disk on the same web address as the admin panel. A file a
 * browser would run (a web page, SVG, script) could act as whoever opens it, so those are refused;
 * documents, audio, video and ordinary images are fine.
 */
final class SharedFileUploadRule
{
    /** @var list<string> */
    public const BLOCKED_EXTENSIONS = [
        'htm', 'html', 'shtml', 'xhtml', 'xht', 'svg', 'svgz', 'xml', 'xsl', 'xslt',
        'js', 'mjs', 'php', 'phtml', 'phar', 'swf',
    ];

    /** @var list<string> */
    public const BLOCKED_MIME_TYPES = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml', 'application/xml',
        'text/javascript', 'application/javascript', 'application/x-httpd-php', 'text/x-php',
        'application/x-shockwave-flash',
    ];

    public static function make(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $extensions = array_filter([
                strtolower($value->getClientOriginalExtension()),
                strtolower((string) $value->guessExtension()),
            ]);

            if (array_intersect($extensions, self::BLOCKED_EXTENSIONS) !== []
                || in_array(strtolower((string) $value->getMimeType()), self::BLOCKED_MIME_TYPES, true)) {
                $fail(__('Web pages, SVG images and scripts cannot be shared. Save the file as a PDF or picture instead.'));
            }
        };
    }
}
