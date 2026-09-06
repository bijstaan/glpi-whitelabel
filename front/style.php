<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The generated stylesheet.
 *
 * Served to every page, logged in or not, so it does the least it possibly can:
 * reads the settings, prints custom properties, and never touches the session.
 * There is deliberately no rights check — this is the branding of the login
 * page, which by definition nobody has authenticated to see, and it contains
 * nothing but the URLs of images that are themselves public.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Whitelabel\Brand;

header('Content-Type: text/css; charset=UTF-8');

// The URL carries a revision that changes whenever anything here would, so this
// can be cached hard. Without it every page load would ask again for a file
// that changes twice a year.
header('Cache-Control: public, max-age=604800, immutable');

echo Brand::css();
