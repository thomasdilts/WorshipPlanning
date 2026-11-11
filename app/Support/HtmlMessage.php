<?php

namespace App\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;

final class HtmlMessage
{
    public static function isHtml(?string $content): bool
    {
        if ($content === null || $content === '') {
            return false;
        }

        return $content !== strip_tags($content);
    }

    /** @var list<string> */
    private const ALLOWED_ATTRIBUTES = ['href', 'src', 'alt', 'title', 'style', 'colspan', 'rowspan', 'width', 'height', 'align'];

    /**
     * HTML for email and the in-app previews, which are shown inside other people's admin sessions:
     * common formatting tags only, no event handlers, and links/images only to web, mail or phone
     * addresses (or {{placeholders}} that become such addresses).
     */
    public static function sanitize(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $allowed = '<p><br><br/><strong><b><em><i><u><ul><ol><li><a><h1><h2><h3><h4><blockquote><span><div><table><thead><tbody><tr><th><td><hr><img>';
        $html = strip_tags($html, $allowed);

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8"?><div id="html-message-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (iterator_to_array((new DOMXPath($document))->query('//@*') ?: []) as $attribute) {
            if (! $attribute instanceof DOMAttr || ! $attribute->ownerElement instanceof DOMElement) {
                continue;
            }

            $element = $attribute->ownerElement;
            $name = strtolower($attribute->nodeName);

            if ($name === 'id' && $element->getAttribute('id') === 'html-message-root') {
                continue;
            }

            if (! in_array($name, self::ALLOWED_ATTRIBUTES, true) || ! self::isSafeAttributeValue($name, $attribute->value)) {
                $element->removeAttributeNode($attribute);
            }
        }

        $root = $document->getElementById('html-message-root');
        $clean = '';

        foreach ($root?->childNodes ?? [] as $child) {
            $clean .= $document->saveHTML($child);
        }

        return $clean;
    }

    private static function isSafeAttributeValue(string $name, string $value): bool
    {
        /* Browsers ignore whitespace and control characters inside a scheme ("java\tscript:"). */
        $compact = strtolower((string) preg_replace('/[\x00-\x20\x7F]+/', '', $value));

        if ($name === 'style') {
            return ! str_contains($compact, 'url(')
                && ! str_contains($compact, 'expression(')
                && ! str_contains($compact, 'javascript:')
                && ! str_contains($compact, '@import');
        }

        if ($name !== 'href' && $name !== 'src') {
            return true;
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $compact, $match) !== 1) {
            return true; // relative link, #anchor or {{placeholder}}
        }

        if (in_array($match[1], ['http', 'https', 'mailto', 'tel'], true)) {
            return true;
        }

        return $name === 'src' && preg_match('#^data:image/(png|jpe?g|gif|webp);#', $compact) === 1;
    }

    public static function toPlainText(?string $content): string
    {
        if ($content === null || $content === '') {
            return '';
        }

        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ["\n", "\n", "\n", "\n\n"], $content)));

        return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", $text)) ?? $text);
    }

    public static function forEmailBody(?string $content): string
    {
        $content ??= '';

        if (! self::isHtml($content)) {
            return nl2br(e($content));
        }

        return self::sanitize($content);
    }

    public static function excerpt(?string $content, int $limit = 80): string
    {
        return Str::limit(self::toPlainText($content), $limit);
    }
}
