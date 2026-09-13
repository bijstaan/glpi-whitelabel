// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
/**
 * The parts of GLPI's identity that only exist in the page.
 *
 * Most of a whitelabel is done before this file runs: the name comes from
 * `app_name`, the logos from CSS custom properties. What is left is text that
 * core writes into templates as literals, plus a favicon that core adds *after*
 * anything a plugin can inject — so the last one wins and the last one is
 * GLPI's.
 *
 * The rule this file follows, and the reason it is written the way it is:
 * **never search the page for the word "GLPI".** A blanket text substitution
 * would eventually rewrite a ticket whose author mentioned GLPI, a knowledge
 * article explaining the migration off it, or an entity's own name. Every
 * replacement below is anchored to a selector that identifies core's chrome.
 */
(function () {
    'use strict';

    // Handed over on the stylesheet link core lets this plugin inject; see
    // Settings::headerTags() for why it travels as an attribute.
    var CFG = (function () {
        var el = document.querySelector('link[data-whitelabel]');

        if (!el) {
            return null;
        }

        try {
            return JSON.parse(el.getAttribute('data-whitelabel'));
        } catch (e) {
            return null;
        }
    })();

    if (!CFG) {
        return;
    }

    /**
     * Take the favicon.
     *
     * Core renders `<link rel="shortcut icon">` at the end of the head, after
     * the tags plugins are allowed to add. Browsers disagree about whether the
     * first or last icon wins, so the only reliable answer is to remove core's
     * and leave ours.
     */
    function favicon() {
        if (!CFG.favicon) {
            return;
        }

        document.querySelectorAll('link[rel~="icon"]').forEach(function (link) {
            if (link.getAttribute('href') !== CFG.favicon) {
                link.remove();
            }
        });

        var link = document.createElement('link');
        link.rel = 'icon';
        link.href = CFG.favicon;
        document.head.appendChild(link);
    }

    /**
     * The copyright and version line.
     *
     * One element, `a.copyright`, in both the places core renders it — the
     * login page footer and the About dialog. Hiding it is CSS and already
     * done; this only handles the case where a name is set but the credit is
     * kept, where leaving the word "GLPI" in the middle of it would be odd.
     */
    function credit() {
        if (CFG.hide_credit || !CFG.name) {
            return;
        }

        document.querySelectorAll('a.copyright').forEach(function (el) {
            if (el.dataset.whitelabelled === '1') {
                return;
            }
            el.dataset.whitelabelled = '1';

            // Only the leading product name, and only when it is leading. The
            // rest of the line is somebody else's copyright notice and is not
            // ours to edit.
            el.childNodes.forEach(function (node) {
                if (node.nodeType === Node.TEXT_NODE && /^\s*GLPI\b/.test(node.textContent)) {
                    node.textContent = node.textContent.replace(/^\s*GLPI\b/, CFG.name);
                }
            });
        });
    }

    /**
     * The name of the built-in authentication source.
     *
     * "GLPI internal database" is what the login form calls local accounts, and
     * it is the one string on that page an entity is guaranteed to read.
     * Scoped to the picker itself rather than to every option on the page.
     */
    var GLPI_LOCAL = /GLPI internal database/i;

    function authSource() {
        if (!CFG.local_auth) {
            return;
        }

        document.querySelectorAll('select[name="auth"] option').forEach(function (option) {
            if (GLPI_LOCAL.test(option.textContent)) {
                option.textContent = CFG.local_auth;
            }
        });

        // GLPI renders that select through select2, which builds its own
        // element from the options and then stops looking at them. Renaming the
        // option alone changes what the form submits and *nothing the user
        // sees* — the old name stays on screen, which is the one thing this
        // setting exists to prevent.
        document.querySelectorAll('.select2-selection__rendered').forEach(function (el) {
            if (GLPI_LOCAL.test(el.textContent)) {
                el.textContent = CFG.local_auth;

                // select2 puts the same string in a tooltip.
                if (el.hasAttribute('title') && GLPI_LOCAL.test(el.getAttribute('title'))) {
                    el.setAttribute('title', CFG.local_auth);
                }
            }
        });
    }

    /**
     * Catch the widgets that are built after this file runs.
     *
     * select2 initialises on document-ready, which may be after or before us
     * depending on script order and how long the page took. Rather than guess,
     * watch for a few seconds and re-apply when something appears. Bounded
     * deliberately: a permanent observer on a page this size is a permanent
     * cost, and nothing legitimately renders a login form thirty seconds in.
     */
    function watchLate() {
        if (!CFG.local_auth || typeof MutationObserver !== 'function') {
            return;
        }

        var observer = new MutationObserver(function () {
            authSource();
        });

        observer.observe(document.body, { childList: true, subtree: true });

        setTimeout(function () {
            observer.disconnect();
        }, 5000);
    }

    /** The `title` attribute on the logo, which is a tooltip saying "GLPI". */
    function logoTitle() {
        if (!CFG.name) {
            return;
        }

        document.querySelectorAll('.glpi-logo[title], img[title="GLPI Logo"]').forEach(function (el) {
            el.setAttribute('title', CFG.name);
        });
    }

    // --- following the system's light/dark setting -----------------------

    /**
     * Switch GLPI's palette to match the operating system.
     *
     * GLPI already ships every palette in one stylesheet on every page, and
     * chooses between them with two attributes on <html>: `data-glpi-theme` for
     * the palette key and `data-glpi-theme-dark` for whether it is a dark one.
     * Its own preferences page flips exactly these to preview a palette. So
     * following the system is not a stylesheet to load or a request to make —
     * it is two attributes, and the browser is the only thing that knows what
     * the system is set to.
     *
     * The stylesheet has already painted the correct colours by the time this
     * runs — this is the handover, not the fix. Setting the attribute switches
     * off the stand-in rules and lets core's real palette apply, and because
     * both were built from the same source files the change is invisible.
     *
     * What the server chose is captured first and restored whenever the system
     * goes light again. That matters: the light side is the user's own palette
     * choice, and this is only supposed to answer the dark case. Coming back
     * from dark should return them to what they picked, not to a default.
     *
     * Nothing is saved. This changes appearance for the session in front of
     * you; the stored preference is untouched, so the same account on a
     * light-set machine still gets their palette.
     */
    function followSystem() {
        if (!CFG.follow_system || !CFG.dark_palette || !window.matchMedia) {
            return;
        }

        var root = document.documentElement;

        // Read before anything is changed, or the first switch back to light
        // restores whatever dark left behind.
        var chosen = {
            theme: root.getAttribute('data-glpi-theme'),
            dark: root.getAttribute('data-glpi-theme-dark')
        };

        // Already dark by the user's own choice: leave it entirely alone. They
        // have picked a dark palette and it is not this code's business to
        // replace it with a different one when the machine agrees.
        if (chosen.dark === '1') {
            return;
        }

        var query = window.matchMedia('(prefers-color-scheme: dark)');

        var render = function () {
            if (query.matches) {
                root.setAttribute('data-glpi-theme', CFG.dark_palette);
                root.setAttribute('data-glpi-theme-dark', '1');
                return;
            }

            if (chosen.theme === null) {
                root.removeAttribute('data-glpi-theme');
            } else {
                root.setAttribute('data-glpi-theme', chosen.theme);
            }

            root.setAttribute('data-glpi-theme-dark', chosen.dark === null ? '0' : chosen.dark);
        };

        render();

        // Live, because somebody changing their system theme at dusk should not
        // have to reload GLPI to stop being dazzled.
        if (typeof query.addEventListener === 'function') {
            query.addEventListener('change', render);
        } else if (typeof query.addListener === 'function') {
            // Safari before 14.
            query.addListener(render);
        }
    }

    function apply() {
        favicon();
        credit();
        authSource();
        logoTitle();
        watchLate();
    }

    // Run before the rest, but do not mistake this for being early.
    //
    // GLPI emits plugin JavaScript at the *end of the body*, not in the head —
    // measured at byte 247,558 of a 247,668-byte page — so by the time this
    // executes the document has been parsed and painted. Nothing done here can
    // prevent a flash, which is why the pre-paint colours are a stylesheet
    // instead (see Brand::css). What this does is set the attribute that hands
    // control to core's own palette, and keep it in step afterwards.
    followSystem();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
    } else {
        apply();
    }
})();
