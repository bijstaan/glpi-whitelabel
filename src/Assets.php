<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Whitelabel;

/**
 * The uploaded images: stored, validated, and served.
 *
 * An upload form that takes a file and puts it somewhere the web server will
 * execute is the oldest hole there is, so nothing here trusts the request. The
 * name comes from a fixed list rather than from the post; the extension is
 * decided by asking the file what it *is* rather than what it is called; and
 * the result is written outside the document root and only ever reaches a
 * browser through {@see self::send()}, which sets the type itself.
 */
final class Assets
{
    /**
     * What may be uploaded, and the extension each becomes.
     */
    private const ALLOWED = [
        IMAGETYPE_PNG  => ['png', 'image/png'],
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_GIF  => ['gif', 'image/gif'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
        IMAGETYPE_ICO  => ['ico', 'image/x-icon'],
    ];

    /**
     * SVG, which getimagesize() cannot speak for.
     *
     * It is the right format for a logo — one file, sharp at every size, and
     * the only sane answer to a 2x display — and it is also an XML document
     * that can carry script, styles and external references. Served from GLPI's
     * own origin, an unfiltered one is stored cross-site scripting with an
     * upload form in front of it.
     *
     * So it is accepted and rewritten, never stored as supplied: {@see
     * self::sanitiseSvg()} parses it, keeps the drawing, and drops everything
     * that could act. Three things make that safe enough to offer:
     *
     *   - uploading takes the plugin's configuration right, which is an
     *     administrator, not an ordinary user;
     *   - a logo reaches a page through `background-image` and `<img>`, and
     *     neither executes script in an SVG;
     *   - the response carries a CSP that forbids everything anyway, for the
     *     one case that is not either of those — somebody opening the asset
     *     URL directly.
     */
    private const SVG_EXTENSION = 'svg';
    private const SVG_MIME      = 'image/svg+xml';

    /** A logo is a logo. Anything this size is a mistake or an attack. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** @return array<int,string> every extension a slot may be stored under */
    private static function extensions(): array
    {
        $out = [self::SVG_EXTENSION];
        foreach (self::ALLOWED as [$extension, $_mime]) {
            $out[] = $extension;
        }

        return $out;
    }

    private static function dir(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/whitelabel';
    }

    /** The stored file for one slot, or null. */
    public static function path(string $name): ?string
    {
        if (!array_key_exists($name, Settings::IMAGES)) {
            return null;
        }

        foreach (self::extensions() as $extension) {
            $candidate = self::dir() . '/' . $name . '.' . $extension;

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function has(string $name): bool
    {
        return self::path($name) !== null;
    }

    /**
     * Accept an upload into one slot.
     *
     * @param array<string,mixed> $file one entry from $_FILES
     * @return string '' on success, otherwise why not
     */
    public static function store(string $name, array $file): string
    {
        if (!array_key_exists($name, Settings::IMAGES)) {
            return __('Unknown image.', 'whitelabel');
        }

        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return '';
        }

        if ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            return __('The upload did not complete.', 'whitelabel');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');

        // The one check that matters: PHP moved this here, not the client.
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return __('That was not an uploaded file.', 'whitelabel');
        }

        if (filesize($tmp) > self::MAX_BYTES) {
            return sprintf(__('Images must be under %d KB.', 'whitelabel'), self::MAX_BYTES / 1024);
        }

        // getimagesize reads the header rather than the name, so a .png that is
        // really a PHP script fails here rather than later and louder. It has
        // nothing to say about SVG, which is XML, so that is decided separately
        // — and only after getimagesize has declined, so a real raster image is
        // never run through an XML parser.
        $info = @getimagesize($tmp);
        $type = is_array($info) ? (int) ($info[2] ?? 0) : 0;

        $svg = null;

        if (!isset(self::ALLOWED[$type])) {
            $svg = self::sanitiseSvg((string) @file_get_contents($tmp));

            if ($svg === null) {
                return __('That is not a PNG, JPEG, GIF, WebP, ICO or SVG image.', 'whitelabel');
            }
        }

        $extension = $svg !== null ? self::SVG_EXTENSION : self::ALLOWED[$type][0];

        if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0o770, true) && !is_dir(self::dir())) {
            return __('Could not create the storage directory.', 'whitelabel');
        }

        // The slot holds one image, so an upload in a different format has to
        // take the previous one with it — otherwise path() would keep finding
        // the old file first and the new logo would never appear.
        self::forget($name);

        $target = self::dir() . '/' . $name . '.' . $extension;

        // An SVG is written from the sanitiser's output, not moved. What lands
        // on disk is a document this code produced from the parts of theirs it
        // was willing to keep — so there is no version of the original file
        // anywhere for anything to later serve by mistake.
        if ($svg !== null) {
            if (@file_put_contents($target, $svg) === false) {
                return __('Could not store the image.', 'whitelabel');
            }
        } elseif (!@move_uploaded_file($tmp, $target)) {
            return __('Could not store the image.', 'whitelabel');
        }

        @chmod($target, 0o640);

        Settings::bump();

        return '';
    }

    /**
     * Parse an SVG and give back only the parts that draw.
     *
     * An allowlist, not a blocklist. The list of things that can execute in an
     * SVG is long, versioned, and gets longer — `onload`, `<script>`,
     * `<foreignObject>`, `javascript:` in an `href`, a `<use>` pointing at
     * another document, an `<image>` fetching a URL, entity declarations that
     * read files off the server. Enumerating them means being one browser
     * release behind forever. Enumerating what a logo actually needs does not:
     * shapes, paths, groups, gradients, and the attributes that position and
     * colour them.
     *
     * @return string|null the rewritten document, or null if it is not an SVG
     */
    private static function sanitiseSvg(string $raw): ?string
    {
        if (trim($raw) === '' || !str_contains($raw, '<svg')) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        // LIBXML_NONET stops the parser fetching anything, and NOENT is *not*
        // passed: expanding entities is how an SVG reads /etc/passwd into the
        // logo. Any document with a DOCTYPE is rejected outright below, which
        // is the same protection said twice on purpose.
        $document = new \DOMDocument();
        $ok = $document->loadXML($raw, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$ok || $document->documentElement === null) {
            return null;
        }

        if (strtolower($document->documentElement->localName) !== 'svg') {
            return null;
        }

        // A logo has no reason to declare a doctype, and every reason somebody
        // else might want one is a reason to refuse it.
        foreach (iterator_to_array($document->childNodes) as $node) {
            if ($node instanceof \DOMDocumentType) {
                return null;
            }
        }

        self::stripSvg($document->documentElement);

        $out = $document->saveXML($document->documentElement);

        return is_string($out) && $out !== '' ? $out : null;
    }

    /** Elements a logo is allowed to be made of. */
    private const SVG_ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'title', 'desc', 'metadata',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan',
        'lineargradient', 'radialgradient', 'stop', 'pattern',
        'clippath', 'mask', 'filter',
        'fegaussianblur', 'feoffset', 'feblend', 'femerge', 'femergenode',
        'fecolormatrix', 'fecomposite', 'feflood',
        'style',
    ];

    /**
     * Attributes that position, shape and colour. Presentation and geometry
     * only — nothing that names a document, and nothing beginning `on`.
     */
    private const SVG_ATTRIBUTES = [
        'id', 'class', 'style', 'transform', 'viewbox', 'version',
        'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'width', 'height', 'd', 'points', 'preserveaspectratio',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width',
        'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray',
        'stroke-dashoffset', 'stroke-opacity', 'stroke-miterlimit',
        'opacity', 'color', 'stop-color', 'stop-opacity', 'offset',
        'gradientunits', 'gradienttransform', 'spreadmethod',
        'clip-path', 'clip-rule', 'mask', 'filter',
        'font-family', 'font-size', 'font-weight', 'font-style',
        'text-anchor', 'letter-spacing', 'dominant-baseline',
        'xmlns', 'xmlns:xlink',
    ];

    /** Walk the tree, dropping anything not on the two lists. */
    private static function stripSvg(\DOMElement $element): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                if (!in_array(strtolower($child->localName), self::SVG_ELEMENTS, true)) {
                    $element->removeChild($child);
                    continue;
                }

                self::stripSvg($child);
                continue;
            }

            // Comments can carry a payload for something that later reads the
            // file as HTML, and a logo does not need them.
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $element->removeChild($child);
            }
        }

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (!in_array($name, self::SVG_ATTRIBUTES, true)) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            // `style` survives the name check and still has to be read: a URL
            // inside it can fetch, and older engines ran `expression()`.
            if ($name === 'style' && preg_match('/url\s*\(|expression\s*\(|@import/i', $attribute->nodeValue ?? '')) {
                $element->removeAttribute($attribute->nodeName);
            }
        }
    }

    public static function forget(string $name): void
    {
        if (!array_key_exists($name, Settings::IMAGES)) {
            return;
        }

        foreach (self::extensions() as $extension) {
            $candidate = self::dir() . '/' . $name . '.' . $extension;

            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }

        Settings::bump();
    }

    /** Remove everything. Used by the uninstall. */
    public static function forgetAll(): void
    {
        foreach (array_keys(Settings::IMAGES) as $name) {
            self::forget($name);
        }

        if (is_dir(self::dir())) {
            @rmdir(self::dir());
        }
    }

    /**
     * Write one image to the response.
     *
     * The content type comes from re-inspecting the file rather than from its
     * name, because the name is something this class chose and the bytes are
     * something a person uploaded — and only one of those is worth trusting
     * about what it contains.
     */
    public static function send(string $name): bool
    {
        $path = self::path($name);

        if ($path === null) {
            return false;
        }

        $info = @getimagesize($path);
        $type = is_array($info) ? (int) ($info[2] ?? 0) : 0;
        $svg  = str_ends_with($path, '.' . self::SVG_EXTENSION);

        if (!isset(self::ALLOWED[$type]) && !$svg) {
            return false;
        }

        header('Content-Type: ' . ($svg ? self::SVG_MIME : self::ALLOWED[$type][1]));
        header('Content-Length: ' . (string) filesize($path));

        if ($svg) {
            // The stored file has already been stripped of anything that could
            // act, so this is the second lock on the same door — for the one
            // request that is not an <img> or a background: somebody pasting
            // the asset URL into the address bar, where an SVG is a document
            // and not a picture.
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
            header('Content-Disposition: inline');
        }
        // Immutable because the URL carries the revision: a change to the image
        // changes the URL, so nothing has to expire.
        header('Cache-Control: public, max-age=604800, immutable');
        header('X-Content-Type-Options: nosniff');

        readfile($path);

        return true;
    }

    /** For the settings page: what a slot currently holds. */
    public static function describe(string $name): string
    {
        $path = self::path($name);

        if ($path === null) {
            return '';
        }

        $kb = (int) round(filesize($path) / 1024);

        if (str_ends_with($path, '.' . self::SVG_EXTENSION)) {
            // No pixel size to report, and that is the point of the format —
            // saying so is more useful than reporting whatever width happens to
            // be written on the root element.
            return sprintf(__('SVG, scales to any size, %d KB', 'whitelabel'), $kb);
        }

        $info = @getimagesize($path);

        return is_array($info)
            ? sprintf('%d×%d, %d KB', (int) $info[0], (int) $info[1], $kb)
            : '';
    }
}
