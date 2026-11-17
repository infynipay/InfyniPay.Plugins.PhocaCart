<?php
/**
 * Centralised provider icon resolver.
 *
 * Both ProvidersField (admin gateway list) and Infynipay (checkout card)
 * call ProviderIconHelper::inline() so that the SVG/raster inlining logic
 * lives in exactly one place and is kept consistent.
 */

namespace Joomla\Plugin\PhocacartPayment\Infynipay\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Log\Log;

/**
 * Resolves a remote icon URL into an inline data URI.
 *
 * Resolution rules (in order):
 *  1. Empty string → returns ''.
 *  2. Already a data URI → returned as-is.
 *  3. Not an absolute http(s) URL → returns ''.
 *  4. Server downloads the resource (SVG or raster).
 *     - SVG: preamble (BOM, XML decl, DOCTYPE, comments) is stripped;
 *       if `<svg` is then at position 0, returns data:image/svg+xml;base64.
 *     - PNG / WebP / JPEG / GIF: detected by Content-Type header or magic
 *       bytes; returns data:<mime>;base64.
 *  5. Inline failed but the source was https:// → returns the original URL
 *     so the browser can attempt a direct fetch.
 *  6. Inline failed and source was http:// → returns '' (mixed-content risk).
 *
 * Results are cached in a static array for the duration of the request.
 */
final class ProviderIconHelper
{
    /** @var array<string, string> Request-scope cache: uri → resolved value */
    private static array $cache = [];

    /**
     * Resolve an icon URI to an inline data URI (or the original https URL
     * as a fallback).
     *
     * @param  string      $iconUri      The icon URL from the API.
     * @param  string|null $logCategory  Joomla log category for warnings, or
     *                                   null to suppress logging.
     * @return string  data:image/... URI, https:// URL, or empty string.
     */
    public static function inline(string $iconUri, ?string $logCategory = null): string
    {
        if ($iconUri === '') {
            return '';
        }

        // Already inlined — return as-is.
        if (str_starts_with($iconUri, 'data:image/')) {
            return $iconUri;
        }

        if (isset(self::$cache[$iconUri])) {
            return self::$cache[$iconUri];
        }

        // Only process absolute HTTP(S) URLs.
        if (!str_starts_with($iconUri, 'http://') && !str_starts_with($iconUri, 'https://')) {
            return self::$cache[$iconUri] = '';
        }

        try {
            $response = HttpFactory::getHttp()->get(
                $iconUri,
                ['Accept' => 'image/svg+xml,image/png,image/webp,image/jpeg,image/gif,image/*,*/*'],
                10
            );
        } catch (\RuntimeException $e) {
            self::log('ProviderIconHelper: icon unreachable — ' . $iconUri . ' — ' . $e->getMessage(), Log::WARNING, $logCategory);
            // Let the browser try directly if the URL is https.
            return self::$cache[$iconUri] = str_starts_with($iconUri, 'https://') ? $iconUri : '';
        }

        if ($response->code !== 200) {
            self::log('ProviderIconHelper: HTTP ' . $response->code . ' for ' . $iconUri, Log::WARNING, $logCategory);
            return self::$cache[$iconUri] = str_starts_with($iconUri, 'https://') ? $iconUri : '';
        }

        $body  = (string) $response->body;
        $ctRaw = $response->headers['Content-Type'] ?? $response->headers['content-type'] ?? '';
        $contentType = is_array($ctRaw) ? (string) ($ctRaw[0] ?? '') : (string) $ctRaw;

        // --- SVG handling ---
        if (stripos($contentType, 'svg') !== false || stripos($iconUri, '.svg') !== false) {
            $svgBody = self::stripSvgPreamble($body);

            if (stripos($svgBody, '<svg') === 0) {
                return self::$cache[$iconUri] = 'data:image/svg+xml;base64,' . base64_encode($svgBody);
            }
        }

        // --- Raster image handling (PNG, WebP, JPEG, GIF) ---
        $mime = self::detectMime($body, $contentType);

        if ($mime !== '') {
            return self::$cache[$iconUri] = 'data:' . $mime . ';base64,' . base64_encode($body);
        }

        // Could not determine type — fall back to original HTTPS URL only.
        self::log('ProviderIconHelper: unrecognised content for ' . $iconUri . ' (Content-Type: ' . $contentType . ')', Log::WARNING, $logCategory);
        return self::$cache[$iconUri] = str_starts_with($iconUri, 'https://') ? $iconUri : '';
    }

    /**
     * Strips everything before the root <svg> element:
     *  - UTF-8 BOM
     *  - XML processing instructions (xml declaration, processing instructions)
     *  - DOCTYPE declarations
     *  - XML / HTML comments (<!-- ... -->)
     *  - Leading whitespace
     *
     * The loop repeats (up to 8 passes) until no more preamble nodes remain.
     */
    public static function stripSvgPreamble(string $raw): string
    {
        // Remove UTF-8 BOM (EF BB BF)
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        // Strip preamble nodes iteratively (max 8 passes).
        for ($i = 0; $i < 8; $i++) {
            $before = $raw;

            // XML / HTML processing instruction
            $raw = (string) preg_replace('/^\s*<[?][^?]*[?]>/s', '', $raw);

            // DOCTYPE declaration
            $raw = (string) preg_replace('/^\s*<!DOCTYPE\b[^>]*>/si', '', $raw);

            // XML / HTML comment
            $raw = (string) preg_replace('/^\s*<!--.*?-->/s', '', $raw);

            if ($raw === $before) {
                break;
            }
        }

        return ltrim($raw);
    }

    /**
     * Detects the MIME type of a binary blob using the Content-Type header
     * and/or magic bytes.
     *
     * @return string  e.g. 'image/png', or '' if unrecognised.
     */
    private static function detectMime(string $body, string $contentType): string
    {
        // Prefer the Content-Type header when it names a known raster format.
        if (preg_match('#^image/(png|webp|jpeg|gif)#i', $contentType, $m)) {
            return 'image/' . strtolower($m[1]);
        }

        // PNG magic bytes: \x89 P N G \r \n \x1a \n
        if (str_starts_with($body, "\x89PNG")) {
            return 'image/png';
        }

        // WebP: RIFF????WEBP
        if (str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        // JPEG magic bytes: FF D8 FF
        if (str_starts_with($body, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        // GIF87a / GIF89a
        if (str_starts_with($body, 'GIF87a') || str_starts_with($body, 'GIF89a')) {
            return 'image/gif';
        }

        return '';
    }

    /**
     * Writes a warning to the Joomla log, but only when a log category is
     * provided (callers can pass null to suppress all logging).
     */
    private static function log(string $message, int $priority, ?string $category): void
    {
        if ($category === null) {
            return;
        }

        Log::add($message, $priority, $category);
    }
}
