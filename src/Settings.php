<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Whitelabel;

use Config;
use Plugin;

/**
 * What the brand is, and where its files live.
 *
 * Images are not kept in the config table. They go under GLPI's own plugin
 * document directory, which is the place GLPI already guarantees is writable,
 * is already excluded from the web root, and is already part of whatever backs
 * the instance up. The config table holds only which of them exist and what
 * their content type is — enough for {@see Assets} to serve one without
 * guessing, and small enough that reading the settings does not mean reading a
 * megabyte of base64 on every request.
 */
final class Settings
{
    public const DEFAULTS = [
        // Replaces "GLPI" in page titles, notification footers and the 2FA
        // enrolment label. Empty means "leave GLPI alone", which is what an
        // installed-but-unconfigured plugin should do.
        'name'         => '',

        // Shown where there is no room for the full name — currently only the
        // collapsed sidebar's alt text. Falls back to `name`.
        'short_name'   => '',

        // The copyright and version line, which is one `a.copyright` element
        // in both places core renders it.
        'hide_credit'  => 0,

        // Replaces the "GLPI internal database" label on the login form's
        // authentication-source picker.
        'local_auth_label' => '',

        // Follow the operating system's light/dark setting.
        //
        // Off by default: it overrides a preference each user has already made,
        // and turning that on for a whole instance is a decision rather than a
        // default. What it fixes when it is on is the person who runs their
        // machine dark, opens GLPI, and is handed a full-screen white page.
        'theme_follow_system' => 0,

        // The palette to switch to when the system asks for dark. Only used
        // when the above is on; the light side is left as whatever the user
        // chose, because they chose it.
        'theme_dark_palette' => 'midnight',

        // Where the help links point. Core already makes these configurable;
        // this only surfaces them next to everything else so that rebranding is
        // one page rather than two.
        'doc_url'      => '',

        // Bumped on every save. The stylesheet and the images are served by
        // PHP, so without this a browser would hold the previous logo until it
        // felt like asking again.
        'revision'     => 1,
    ];

    /** The images this plugin manages, and what each one is for. */
    /**
     * The image slots, what each is for, and what to upload.
     *
     * `box` is the size core actually paints the image at, read out of GLPI's
     * own stylesheets rather than guessed: `.page .glpi-logo` is 100×55,
     * the collapsed sidebar's is 40×40, and `.page-anonymous .glpi-logo` is
     * 200×110. `suggest` is twice that, because these are backgrounds on a
     * screen that is very often 2x and a logo uploaded at exactly its box is
     * soft on every modern laptop — core's own files are oversized for the same
     * reason (it ships 250×138 for a 200×110 slot).
     *
     * `dark` names the slot that replaces this one under a dark palette. Only
     * the three logos have one; a favicon cannot, because the browser paints
     * the tab and no stylesheet of ours reaches it.
     */
    public const IMAGES = [
        'logo' => [
            'label'   => 'Main logo',
            'what'    => 'Top-left on every page.',
            'box'     => '100×55',
            'suggest' => '200×110',
            'dark'    => 'logo_dark',
        ],
        'logo_dark' => [
            'label'   => 'Main logo, dark theme',
            'what'    => 'Used instead of the above when the palette is dark. Optional.',
            'box'     => '100×55',
            'suggest' => '200×110',
            'variant' => 'logo',
        ],
        'logo_reduced' => [
            'label'   => 'Collapsed sidebar',
            'what'    => 'Where only an icon fits, so usually a mark rather than a wordmark.',
            'box'     => '40×40',
            'suggest' => '80×80',
            'dark'    => 'logo_reduced_dark',
        ],
        'logo_reduced_dark' => [
            'label'   => 'Collapsed sidebar, dark theme',
            'what'    => 'Optional.',
            'box'     => '40×40',
            'suggest' => '80×80',
            'variant' => 'logo_reduced',
        ],
        'logo_login' => [
            'label'   => 'Login card',
            'what'    => 'The login and password-reset pages.',
            'box'     => '200×110',
            'suggest' => '400×220',
            'dark'    => 'logo_login_dark',
        ],
        'logo_login_dark' => [
            'label'   => 'Login card, dark theme',
            'what'    => 'Optional.',
            'box'     => '200×110',
            'suggest' => '400×220',
            'variant' => 'logo_login',
        ],
        'favicon' => [
            'label'   => 'Favicon',
            'what'    => 'The browser tab.',
            'box'     => '16×16',
            'suggest' => '32×32, or an SVG',
            // No dark variant: the tab is painted by the browser and no
            // stylesheet here reaches it. An SVG favicon can carry its own
            // `prefers-color-scheme` rules internally, which is the only way to
            // do this and belongs inside the file rather than in a second slot.
        ],
    ];

    /** The slots an administrator uploads a primary image for. */
    public static function primaryImages(): array
    {
        return array_filter(self::IMAGES, static fn(array $slot): bool => !isset($slot['variant']));
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        $stored = Config::getConfigurationValues(
            PLUGIN_WHITELABEL_CONFIG_CONTEXT,
            array_keys(self::DEFAULTS)
        );

        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value     = $stored[$key] ?? null;
            $out[$key] = ($value === null || $value === '') ? (string) $default : (string) $value;
        }

        return $out;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? (string) (self::DEFAULTS[$key] ?? '');
    }

    public static function flag(string $key): bool
    {
        return (int) self::get($key) === 1;
    }

    /** @param array<string,string> $input */
    public static function save(array $input): void
    {
        $values = [];

        foreach ($input as $key => $value) {
            if (array_key_exists($key, self::DEFAULTS) && $key !== 'revision') {
                $values[$key] = trim((string) $value);
            }
        }

        if ($values !== []) {
            Config::setConfigurationValues(PLUGIN_WHITELABEL_CONFIG_CONTEXT, $values);
        }

        self::bump();
    }

    /**
     * Invalidate what browsers are holding.
     *
     * Called on every change, including an image upload that touches no
     * setting at all — the whole point is that the URL changes when the bytes
     * behind it do.
     */
    public static function bump(): void
    {
        Config::setConfigurationValues(
            PLUGIN_WHITELABEL_CONFIG_CONTEXT,
            ['revision' => (string) (((int) self::get('revision')) + 1)]
        );
    }

    /** Is there anything configured worth applying? */
    public static function isBranded(): bool
    {
        if (self::get('name') !== '' || self::flag('hide_credit')) {
            return true;
        }

        foreach (array_keys(self::IMAGES) as $image) {
            if (Assets::has($image)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `<link>` tags injected into every page's head.
     *
     * A tag rather than a plugin CSS file because the content depends on
     * configuration, and `add_css` can only name a static file under the
     * plugin's own `public/` directory. Core renders these from a
     * `{tag, properties}` shape, and only as self-closing elements — which
     * rules out an inline `<style>` and makes a linked stylesheet the way in.
     *
     * @return array<int,array{tag:string,properties:array<string,string>}>
     */
    public static function headerTags(): array
    {
        $root = self::webRoot();
        $rev  = (int) self::get('revision');

        $tags = [[
            'tag'        => 'link',
            'properties' => [
                'rel'  => 'stylesheet',
                'type' => 'text/css',
                'href' => $root . '/front/style.php?v=' . $rev,
                // The browser half's configuration, riding on the one element
                // this plugin is allowed to put in the head. Core renders these
                // tags as `<tag key="value" />` and escapes each value, so the
                // JSON arrives intact and `dataset` hands it back decoded.
                //
                // The alternative would be an inline <script>, which the tag
                // shape cannot express — it only emits self-closing elements —
                // and a second PHP endpoint for four short strings is more
                // moving parts than an attribute.
                'data-whitelabel' => (string) json_encode(self::forBrowser()),
            ],
        ]];

        // Added here as well as replaced from the browser. This one is for the
        // browsers that take the first icon they are given; the JavaScript is
        // for the ones that take the last, which is core's — see
        // public/js/whitelabel.js.
        if (Assets::has('favicon')) {
            $tags[] = [
                'tag'        => 'link',
                'properties' => [
                    'rel'  => 'icon',
                    'href' => $root . '/front/asset.php?name=favicon&v=' . $rev,
                ],
            ];
        }

        return $tags;
    }

    /**
     * The plugin's URL, root-relative and prefix-aware.
     *
     * `Plugin::getWebDir($key, false)` returns `plugins/whitelabel` with no
     * leading slash, which in an `href` resolves against the *current page* —
     * so the stylesheet would be requested from `/front/plugins/...` on one
     * page and `/plugins/...` on another, and be a 404 on most of them.
     * `getPrefixedUrl()` is what core itself uses to turn a root-relative path
     * into one that also survives GLPI being installed under a subdirectory.
     */
    private static function webRoot(): string
    {
        return \Html::getPrefixedUrl('/plugins/whitelabel');
    }

    /** Everything the browser half needs, as one object. */
    /**
     * Dark palettes GLPI knows about, for the settings dropdown.
     *
     * Read from core's own theme registry rather than listed here: the set
     * changes between releases, an installation can add its own, and a
     * hardcoded list would quietly offer a palette that no longer exists.
     *
     * @return array<string,string> key => name
     */
    public static function darkPalettes(): array
    {
        $out = [];

        foreach (\Glpi\UI\ThemeManager::getInstance()->getAllThemes() as $theme) {
            if ($theme->isDarkTheme()) {
                $out[$theme->getKey()] = $theme->getName();
            }
        }

        return $out;
    }

    /** The configured dark palette, or the first one that exists. */
    public static function darkPalette(): string
    {
        $palettes = self::darkPalettes();
        $chosen   = self::get('theme_dark_palette');

        if (isset($palettes[$chosen])) {
            return $chosen;
        }

        // The stored palette has gone — an upgrade dropped it, or a custom
        // theme was deleted. Falling back beats writing an unknown key onto the
        // html element, which selects no palette at all and leaves a page
        // half-styled with no clue why.
        return (string) (array_key_first($palettes) ?? '');
    }

    /**
     * Every custom property the chosen dark palette declares.
     *
     * Read from GLPI's own sources at runtime, not copied here: the dark base
     * in `css/includes/_palette_dark.scss` first, then the palette's own file
     * over the top, which is the order core applies them in.
     *
     * This exists because the script that follows the system theme cannot run
     * early enough to prevent a flash — GLPI emits plugin JavaScript at the end
     * of the body, so it executes after the entire page has been parsed and
     * painted, white. A stylesheet has no such problem. Restating the palette
     * as CSS under a `prefers-color-scheme` query means the first paint is
     * already right, and the script's job shrinks to setting the attribute that
     * hands control back to core.
     *
     * Parsing someone else's stylesheet is not free of risk, so it is
     * deliberately narrow: only `--name: value;` pairs inside the first
     * `:root…{ }` block of each file, comments stripped, everything else
     * ignored. A palette that does something cleverer than a flat list loses
     * the clever part for a few milliseconds and nothing else — the attribute
     * still arrives and core still applies the real thing.
     *
     * @return array<string,string> property name => value
     */
    public static function darkVariables(): array
    {
        $files = [\GLPI_ROOT . '/css/includes/_palette_dark.scss'];

        $theme = \Glpi\UI\ThemeManager::getInstance()->getTheme(self::darkPalette());
        if ($theme !== null) {
            // Core palettes are SCSS partials, so the file on disk carries a
            // leading underscore that getPath() does not know about. Custom
            // themes are whole files. Try what it says, then the partial form.
            $path    = $theme->getPath(false);
            $files[] = $path;
            $files[] = dirname($path) . '/_' . basename($path);
        }

        $out = [];

        foreach ($files as $file) {
            if (!is_readable($file)) {
                continue;
            }

            $source = (string) file_get_contents($file);

            // The first `:root…{ … }` block only. A palette file also carries
            // component rules further down, and those are selectors rather than
            // properties — copying them would be copying core's stylesheet.
            if (preg_match('/:root[^{]*\{(.*?)\n\}/s', $source, $block) !== 1) {
                continue;
            }

            foreach (explode("\n", $block[1]) as $line) {
                // Trailing `// …` notes are SCSS, not CSS, and would make the
                // declaration invalid if they travelled with the value.
                $line = trim(preg_replace('#//.*$#', '', $line) ?? '');

                if (preg_match('/^(--[a-z0-9-]+)\s*:\s*(.+?);$/i', $line, $m) === 1) {
                    $out[$m[1]] = trim($m[2]);
                }
            }
        }

        return $out;
    }

    public static function forBrowser(): array
    {
        $root = self::webRoot();
        $rev  = (int) self::get('revision');

        return [
            'name'        => self::get('name'),
            'short_name'  => self::get('short_name') !== '' ? self::get('short_name') : self::get('name'),
            'hide_credit' => self::flag('hide_credit'),
            'local_auth'  => self::get('local_auth_label'),
            'favicon'     => Assets::has('favicon')
                ? $root . '/front/asset.php?name=favicon&v=' . $rev
                : '',
            // The browser is the only thing that knows what the operating
            // system is set to, so the decision has to happen there. All the
            // server can say is whether it is allowed and which palette to use.
            'follow_system' => self::flag('theme_follow_system'),
            'dark_palette'  => self::darkPalette(),
        ];
    }
}
