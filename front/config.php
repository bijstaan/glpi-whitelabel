<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Whitelabel\Assets;
use GlpiPlugin\Whitelabel\Settings;

Session::checkRight('plugin_whitelabel_config', READ);

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if (!empty($_POST['update'])) {
    // No explicit Session::checkCSRF: GLPI 11's CheckCsrfListener validated and
    // consumed the token before this page ran, so a second check always fails.
    Session::checkRight('plugin_whitelabel_config', UPDATE);

    // Only write the text settings when the form that carries them was the
    // thing submitted.
    //
    // Every one of them is rebuilt from `$_POST` below, and a checkbox that is
    // absent is indistinguishable from one that was unticked — so a POST
    // carrying only, say, an uploaded file blanks the instance name, the short
    // name, the login label and the documentation URL, and switches the credit
    // line back on. Nothing errors and the upload succeeds, so the only way to
    // notice is to come back and read the page.
    //
    // The browser always sends this marker because it is a hidden field in the
    // one form. Anything that does not is not the settings form and has no
    // business rewriting settings.
    if (!empty($_POST['branding'])) {
        Settings::save([
            'name'             => (string) ($_POST['name'] ?? ''),
            'short_name'       => (string) ($_POST['short_name'] ?? ''),
            'local_auth_label' => (string) ($_POST['local_auth_label'] ?? ''),
            'doc_url'          => (string) ($_POST['doc_url'] ?? ''),
            'hide_credit'      => !empty($_POST['hide_credit']) ? '1' : '0',
            'theme_follow_system' => !empty($_POST['theme_follow_system']) ? '1' : '0',
            'theme_dark_palette'  => (string) ($_POST['theme_dark_palette'] ?? ''),
        ]);

        // The documentation links are core's own settings rather than this
        // plugin's. Writing them through here means an administrator rebrands
        // in one place instead of knowing which half of the branding GLPI
        // happens to own, but they are still core's values and are left alone
        // when empty.
        $doc = trim((string) ($_POST['doc_url'] ?? ''));
        if ($doc !== '') {
            Config::setConfigurationValues('core', [
                'central_doc_url'  => $doc,
                'helpdesk_doc_url' => $doc,
            ]);
        }
    }

    foreach (array_keys(Settings::IMAGES) as $image) {
        if (!empty($_POST['remove_' . $image])) {
            Assets::forget($image);
            continue;
        }

        if (isset($_FILES[$image])) {
            $problem = Assets::store($image, (array) $_FILES[$image]);

            if ($problem !== '') {
                Session::addMessageAfterRedirect(
                    $e(sprintf('%s: %s', $image, $problem)),
                    false,
                    ERROR
                );
            }
        }
    }

    Session::addMessageAfterRedirect(__s('Branding saved.', 'whitelabel'));
    Html::back();
}

Html::header(__('Whitelabel', 'whitelabel'), $_SERVER['PHP_SELF'], 'config', 'plugins');

$cfg      = Settings::all();
$can_edit = Session::haveRight('plugin_whitelabel_config', UPDATE);

// Helper text on the dark palette is handled by whitelabel.css (see its
// "dark theme" section): the inline property override that used to live here
// was a measured no-op for `.text-muted` — core declares it with !important,
// which no property override beats — and its 28% formula measured under the
// 4.5:1 floor for `.form-text` on auror_dark anyway. whitelabel.css redefines
// the `--tblr-muted` / `--tblr-secondary-color` VARIABLES inside
// `.whitelabel-config` instead.
echo "<div class='container-fluid whitelabel-config' style='max-width:960px'>";
// No action attribute, deliberately. `$_SERVER['PHP_SELF']` is `/index.php`
// under GLPI 11's front controller, not this script — a form pointed at it
// posts to the router, which routes the post nowhere and redirects to the
// dashboard. The page then looks like it saved and has not. Omitting the
// attribute posts to the current URL, which is the only thing here that is
// reliably right.
//
// enctype, or the uploads below arrive as nothing at all and the page appears
// simply not to work in a second, quieter way.
echo "<form method='post' enctype='multipart/form-data'>";
// Says "these settings were on screen and are being submitted", which is what
// lets the handler tell a real save from a POST that happens to carry `update`.
echo Html::hidden('branding', ['value' => 1]);
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

// --------------------------------------------------------------------- name

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Name', 'whitelabel') . '</h3></div><div class="card-body">';

echo '<p class="text-muted">'
   . __s('GLPI puts its own name in the browser tab of every page, at the foot of every '
       . 'notification it sends, and on the entry a two-factor app stores against an account. '
       . 'All three come from one setting, so all three change together.', 'whitelabel')
   . '</p>';

echo "<div class='row'>";

echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __s('Product name', 'whitelabel') . '</label>';
echo "<input type='text' class='form-control' name='name' value='" . $e($cfg['name'])
   . "' placeholder='GLPI'>";
echo "<div class='form-text'>"
   . __s('Leave empty to keep GLPI\'s own name. Everything else on this page works whether or '
       . 'not this is set.', 'whitelabel')
   . '</div></div>';

echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __s('Short name', 'whitelabel') . '</label>';
echo "<input type='text' class='form-control' name='short_name' value='" . $e($cfg['short_name']) . "'>";
echo "<div class='form-text'>" . __s('For the few places with no room for the full one. '
       . 'Falls back to the product name.', 'whitelabel') . '</div></div>';

echo "<div class='col-md-6 mb-3'><label class='form-label'>"
   . __s('Name of the built-in login source', 'whitelabel') . '</label>';
echo "<input type='text' class='form-control' name='local_auth_label' value='"
   . $e($cfg['local_auth_label']) . "' placeholder='GLPI internal database'>";
echo "<div class='form-text'>"
   . __s('What the login form calls an account held here rather than in your directory. It is '
       . 'the one piece of GLPI vocabulary every user is guaranteed to read.', 'whitelabel')
   . '</div></div>';

echo "<div class='col-md-6 mb-3'><label class='form-label'>" . __s('Documentation link', 'whitelabel')
   . '</label>';
echo "<input type='text' class='form-control' name='doc_url' value='" . $e($cfg['doc_url'])
   . "' placeholder='https://help.example.com'>";
echo "<div class='form-text'>"
   . __s('Where the help links in both interfaces point. Left alone when empty.', 'whitelabel')
   . '</div></div>';

echo '</div>';

$credit = ((int) $cfg['hide_credit']) === 1 ? "checked='checked'" : '';
echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='hide_credit' value='1' $credit>";
echo "<span class='form-check-label'>" . __s('Hide the version and copyright line', 'whitelabel')
   . '</span></label>';
echo "<div class='form-text'>"
   . __s('The "GLPI 11.0.8 Copyright (C) …" line, which appears under the login form and in the '
       . 'About dialog. Worth knowing before you tick it: GLPI is GPL, and the licence has things '
       . 'to say about removing authorship notices. Hiding it inside your own helpdesk is your '
       . 'call to make; this setting does not make it for you.', 'whitelabel')
   . '</div>';

echo '</div></div>';

// ------------------------------------------------------------------- images

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Images', 'whitelabel') . '</h3></div><div class="card-body">';

echo '<p class="text-muted">'
   . sprintf(
       __s('PNG, JPEG, GIF, WebP, ICO or SVG, up to %d KB. An SVG is rewritten on upload rather '
           . 'than stored as you supplied it: it is an XML document that can carry script and '
           . 'external references, so it is parsed and everything but the drawing is dropped. '
           . 'What that costs you is animation and embedded fonts; what it buys is a logo that '
           . 'is sharp at any size.', 'whitelabel'),
       Assets::MAX_BYTES / 1024
   )
   . '</p>';

echo "<div class='row'>";

/** One upload slot: what it holds now, and a field to replace it. */
$slot = static function (string $image, array $meta) use ($e, $cfg): void {
    $held = Assets::describe($image);

    echo "<div class='mb-2'>";
    echo "<label class='form-label mb-1'>" . $e(__($meta['label'], 'whitelabel')) . '</label>';

    if ($held !== '') {
        // `--tblr-bg-surface-tertiary`, not `-secondary`. Core's dark palette
        // redefines `--tblr-light` to #e6e6e6 and `-secondary` is declared as
        // `var(--tblr-light)`, so that token paints a near-white panel on a
        // black page — with body-coloured text on it, which is the same
        // near-white. The tertiary surface is derived from the body background
        // and follows the theme.
        // whitelabel-preview: the tertiary tint is slightly lighter than the
        // card on auror_dark, and the 12% muted formula measured 4.38:1 on
        // it — whitelabel.css tightens the mix to 8% for this box only.
        echo "<div class='mb-2 p-2 border rounded d-flex align-items-center gap-2 whitelabel-preview' "
           . "style='background:var(--tblr-bg-surface-tertiary,#f2f5f8)'>";
        echo "<img src='" . $e(Html::getPrefixedUrl('/plugins/whitelabel'))
           . '/front/asset.php?name=' . $e($image) . '&v=' . (int) $cfg['revision']
           . "' alt='' style='max-height:40px;max-width:160px'>";
        echo "<span class='form-text mb-0'>" . $e($held) . '</span>';
        echo "<label class='form-check mb-0 ms-auto'>";
        echo "<input type='checkbox' class='form-check-input' name='remove_" . $e($image) . "' value='1'>";
        echo "<span class='form-check-label'>" . __s('Remove', 'whitelabel') . '</span></label>';
        echo '</div>';
    }

    echo "<input type='file' class='form-control' name='" . $e($image)
       . "' accept='image/png,image/jpeg,image/gif,image/webp,image/svg+xml,image/x-icon,.ico,.svg'>";

    echo "<div class='form-text'>" . $e(__($meta['what'], 'whitelabel'));
    echo ' <span class="text-nowrap">'
       . $e(sprintf(
           __('Shown at %1$s — upload %2$s.', 'whitelabel'),
           $meta['box'],
           $meta['suggest']
       ))
       . '</span></div>';
    echo '</div>';
};

foreach (Settings::primaryImages() as $image => $meta) {
    echo "<div class='col-md-6 mb-4'>";
    $slot($image, $meta);

    // The dark-theme file sits under the one it replaces rather than in a
    // column of its own. It is the same logo, and pairing them is what makes it
    // obvious that leaving the second one empty is a normal thing to do.
    if (isset($meta['dark'], Settings::IMAGES[$meta['dark']])) {
        echo "<div class='ms-3 ps-2 border-start'>";
        $slot($meta['dark'], Settings::IMAGES[$meta['dark']]);
        echo '</div>';
    }

    echo '</div>';
}

echo '</div>';

echo "<div class='form-text'>"
   . __s('The main logo covers the sidebar, the collapsed sidebar and the login card unless you '
       . 'upload something more specific for those. A dark-theme file is optional: without one, '
       . 'both palettes use the same image — which is fine for a logo that reads on either, and '
       . 'the reason to upload a second is that yours does not. Sizes above are what GLPI paints '
       . 'the image at; the suggestion is twice that, because most screens are 2x and a logo '
       . 'uploaded at exactly its box looks soft on them. An SVG sidesteps that entirely.',
       'whitelabel')
   . '</div>';

echo '</div></div>';

// -------------------------------------------------------------------- theme

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Theme', 'whitelabel') . '</h3></div><div class="card-body">';

echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='theme_follow_system' value='1' "
   . (((int) $cfg['theme_follow_system']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>"
   . __s('Follow each machine\'s light or dark setting', 'whitelabel') . '</span></label>';
echo "<div class='form-text mb-3'>"
   . __s('GLPI ships every palette on every page and picks one with two attributes on the page '
       . 'element, so this switches palette without loading anything or asking the server. When '
       . 'the operating system says dark, the palette below is used; when it says light, the '
       . 'palette the person chose for themselves comes back. Somebody who has already chosen a '
       . 'dark palette is left alone entirely — they have answered this question.', 'whitelabel')
   . '</div>';

echo "<div class='row'>";
echo "<div class='col-md-6 mb-3'><label class='form-label'>"
   . __s('Palette to use when the system is dark', 'whitelabel') . '</label>';
Dropdown::showFromArray('theme_dark_palette', Settings::darkPalettes(), [
    'value' => Settings::darkPalette(),
]);
echo "<div class='form-text'>"
   . __s('Read from GLPI\'s own list of dark palettes, so a custom one appears here too.',
         'whitelabel')
   . '</div></div>';
echo '</div>';

echo "<div class='form-text'>"
   . __s('Nothing is saved against the account: this changes how GLPI looks on the machine in '
       . 'front of you, for as long as its system setting says so. The same person on a '
       . 'light-set machine still gets the palette they picked, and their preference page still '
       . 'shows it.', 'whitelabel')
   . '</div>';

echo '</div></div>';

// ------------------------------------------------------------------- status

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('What is branded', 'whitelabel') . '</h3></div><div class="card-body">';

$surfaces = [
    __('Page titles, notification footers, 2FA label', 'whitelabel') => $cfg['name'] !== '',
    __('Sidebar and login logos', 'whitelabel')                      => Assets::has('logo')
        || Assets::has('logo_login') || Assets::has('logo_reduced'),
    __('Browser tab icon', 'whitelabel')                             => Assets::has('favicon'),
    __('Login source name', 'whitelabel')                            => $cfg['local_auth_label'] !== '',
    __('Version and copyright line', 'whitelabel')                   => ((int) $cfg['hide_credit']) === 1,
    __('Help links', 'whitelabel')                                   => $cfg['doc_url'] !== '',
];

foreach ($surfaces as $label => $done) {
    echo "<span class='badge " . ($done ? 'bg-green-lt' : 'bg-secondary-lt') . " me-1 mb-1'>"
       . ($done ? '✓ ' : '') . $e($label) . '</span>';
}

echo "<div class='form-text mt-2'>"
   . __s('Anything not ticked keeps GLPI\'s own. A half-configured whitelabel should leave the '
       . 'rest looking like GLPI rather than like a broken version of it.', 'whitelabel')
   . '</div>';

echo '</div></div>';

if ($can_edit) {
    echo "<div class='text-end mb-4'><button type='submit' name='update' value='1' class='btn btn-primary'>"
       . __s('Save', 'whitelabel') . '</button></div>';
}

echo '</form></div>';

Html::footer();
