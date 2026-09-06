<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * One branding image.
 *
 * Same reasoning as style.php: the login page needs the logo before anybody has
 * logged in, so there is no rights check. What there *is* instead is a fixed
 * set of names — the request picks a slot, never a path — and a content type
 * derived from the file's own header rather than from anything a client said.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Whitelabel\Assets;

$name = (string) ($_GET['name'] ?? '');

if (!Assets::send($name)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'not found';
}
