<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

use GlpiPlugin\Whitelabel\Assets;
use GlpiPlugin\Whitelabel\Settings;

/**
 * Install: no tables, one right, and the settings defaults.
 *
 * There is nothing here worth a table. A brand is a handful of strings and four
 * images, and the images belong on disk — putting a logo in the database would
 * make every page that renders it a database read, and would put a megabyte of
 * base64 in the middle of a config table people read by hand.
 */
function plugin_whitelabel_install()
{
    plugin_whitelabel_install_rights();

    Config::setConfigurationValues(PLUGIN_WHITELABEL_CONFIG_CONTEXT, Settings::DEFAULTS);

    return true;
}

/**
 * One right, held by whoever configures GLPI.
 *
 * Branding is an instance-wide decision — there is no such thing as a logo one
 * technician sees — so it belongs with the people who already decide
 * instance-wide things, and nowhere else.
 */
function plugin_whitelabel_install_rights()
{
    /** @var DBmysql $DB */
    global $DB;

    $right = 'plugin_whitelabel_config';

    $exists = false;
    foreach (
        $DB->request([
            'SELECT' => ['name'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => $right],
            'LIMIT'  => 1,
        ]) as $row
    ) {
        $exists = true;
    }

    // GLPI runs the install hook on upgrade too, and addProfileRights() inserts
    // unconditionally — calling it for a right that already exists raises a
    // duplicate-key error that aborts the whole upgrade.
    if (!$exists) {
        ProfileRight::addProfileRights([$right]);
    }

    // Mirrored from `config` rather than granted wholesale.
    //
    // The obvious shape — "everyone who can see the configuration gets the full
    // set" — hands UPDATE to the Read-Only profile, because Read-Only holds
    // `config` at READ. That profile exists precisely to guarantee somebody
    // cannot change anything, and branding is about as visible a thing to
    // change as this instance has.
    //
    // So a profile that can *read* the configuration can read this, and only a
    // profile that can *update* it can update this.
    foreach (
        $DB->request([
            'SELECT' => ['profiles_id', 'rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => 'config', 'rights' => ['>', 0]],
        ]) as $row
    ) {
        $config = (int) $row['rights'];
        $grant  = READ;

        if (($config & UPDATE) === UPDATE) {
            $grant |= UPDATE;
        }

        ProfileRight::updateProfileRights((int) $row['profiles_id'], [$right => $grant]);
    }
}

/**
 * Uninstall: put GLPI's own name and face back.
 *
 * The images go too. A logo left behind after an uninstall is somebody's
 * trademark sitting in a directory nobody is looking after any more, and the
 * plugin that was asked to remove itself is the one that should deal with it.
 */
function plugin_whitelabel_uninstall()
{
    Assets::forgetAll();

    ProfileRight::deleteProfileRights(['plugin_whitelabel_config']);

    Config::deleteConfigurationValues(
        PLUGIN_WHITELABEL_CONFIG_CONTEXT,
        array_keys(Settings::DEFAULTS)
    );

    return true;
}
