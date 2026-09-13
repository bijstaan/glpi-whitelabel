// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// The whitelabel plugin, in the only place it can be judged: a browser.
//
// Every claim this plugin makes is about what a *page* looks like, so there is
// nothing here the CLI could have checked. It drives the settings form the way
// an administrator would — including the file uploads — and then reads the
// login page, the technician interface and the self-service portal back.
const { chromium } = require('playwright');
const { openDark, audit } = require('./dark');
const { execSync } = require('child_process');
const { fullPage } = require('./shot');
const fs = require('fs');
const path = require('path');

const BASE = 'http://localhost:8081';
const SHOTS = process.env.SHOT_DIR || '.';
const DARK_SHOTS = path.join(SHOTS, 'dark');
// The placeholder logos live with the plugin rather than in a scratch
// directory, so this check runs from a clean checkout. Relative to this file,
// which is tests/browser/ — the path used to be written from the repository
// root and stopped resolving when the checks moved beside the plugin.
const PICS = process.env.WL_PICS || path.join(__dirname, '..');

const fail = [];
function check(name, cond, detail) {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${detail ? ' :: ' + String(detail).slice(0, 150) : ''}`);
  if (!cond) fail.push(name);
}

const php = (code) =>
  execSync('docker exec -i glpi-glpi-1 php', {
    encoding: 'utf8',
    input:
      '<?php require "/var/www/glpi/vendor/autoload.php";'
      + '(new Glpi\\Kernel\\Kernel(Glpi\\Application\\Environment::PRODUCTION->value))->boot();'
      + '(new Auth())->login("glpi","glpi",true);(new Plugin())->init(true);'
      + code,
  }).trim();

// What GLPI's identity looks like from inside the page.
const brand = (p) =>
  p.evaluate(() => ({
    title: document.title,
    favicon: Array.from(document.querySelectorAll('link[rel~="icon"]')).map((l) => l.getAttribute('href')),
    logo: (() => {
      const el = document.querySelector('.glpi-logo');
      return el ? getComputedStyle(el).backgroundImage.slice(0, 120) : '(no logo element)';
    })(),
    logoVar: getComputedStyle(document.documentElement).getPropertyValue('--glpi-logo').trim().slice(0, 120),
    creditVisible: (() => {
      const c = document.querySelector('a.copyright');
      return c ? getComputedStyle(c).display !== 'none' : null;
    })(),
    creditText: document.querySelector('a.copyright')?.textContent.replace(/\s+/g, ' ').trim() || '',
    // What is on screen, not what is in the DOM behind it. GLPI renders this
    // select through select2, so the <option> and the visible text are two
    // different things and only one of them is what a user reads.
    authVisible: Array.from(document.querySelectorAll('.select2-selection__rendered'))
      .map((o) => o.textContent.trim()),
    authOptions: Array.from(document.querySelectorAll('select[name="auth"] option'))
      .map((o) => o.textContent.trim()),
  }));

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1500, height: 1000 } });
  const errs = [];
  page.on('pageerror', (e) => errs.push(e.message));

  // Start from GLPI's own identity, so the "after" means something.
  php('GlpiPlugin\\Whitelabel\\Assets::forgetAll(); '
    + 'GlpiPlugin\\Whitelabel\\Settings::save(["name"=>"","short_name"=>"","local_auth_label"=>"","doc_url"=>"","hide_credit"=>"0"]); echo "reset";');

  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  const before = await brand(page);
  check('unconfigured, GLPI still looks like GLPI', /GLPI/.test(before.title), before.title);
  check('and still serves its own favicon',
    before.favicon.some((f) => /pics\/favicon/.test(f)), before.favicon.join(','));

  await fullPage(page, `${SHOTS}/whitelabel-01-before.png`);

  // --- configure it the way an administrator would -----------------------

  await page.fill('#login_name', 'glpi');
  await page.fill('input[type=password]', 'glpi');
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');

  await page.goto(`${BASE}/plugins/whitelabel/front/config.php`, { waitUntil: 'networkidle' });
  check('the settings page renders', await page.locator('input[name=name]').count() === 1);

  await page.fill('input[name=name]', 'Bijstaan Service Desk');
  await page.fill('input[name=short_name]', 'Bijstaan');
  await page.fill('input[name=local_auth_label]', 'Bijstaan account');
  await page.setInputFiles('input[name=logo]', path.join(PICS, 'wl-logo.png'));
  await page.setInputFiles('input[name=logo_reduced]', path.join(PICS, 'wl-reduced.png'));
  await page.setInputFiles('input[name=logo_login]', path.join(PICS, 'wl-login.png'));
  await page.setInputFiles('input[name=favicon]', path.join(PICS, 'wl-favicon.png'));
  await page.click('button[name=update]');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(800);

  const saved = await page.evaluate(() => document.body.innerText);
  check('it reports what is now branded', /Page titles/.test(saved) && /✓/.test(saved));
  await fullPage(page, `${SHOTS}/whitelabel-02-settings.png`);

  // --- the technician interface -----------------------------------------

  await page.goto(`${BASE}/front/central.php`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(600);
  const central = await brand(page);

  check('the page title carries the product name',
    /Bijstaan Service Desk/.test(central.title) && !/GLPI/.test(central.title), central.title);
  check('the sidebar logo is the uploaded one',
    /whitelabel\/front\/asset\.php/.test(central.logo), central.logo);
  check('the favicon is the uploaded one',
    central.favicon.length > 0 && central.favicon.every((f) => /whitelabel/.test(f)),
    central.favicon.join(','));
  check('and GLPI\'s own favicon link is gone',
    !central.favicon.some((f) => /pics\/favicon/.test(f)), central.favicon.join(','));

  await fullPage(page, `${SHOTS}/whitelabel-03-central.png`);

  // --- the login page, which is where entities actually arrive ----------

  await page.goto(`${BASE}/front/logout.php`, { waitUntil: 'networkidle' });
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(600);
  const login = await brand(page);

  check('the login page is branded too',
    /Bijstaan Service Desk/.test(login.title) && !/GLPI/.test(login.title), login.title);
  check('the login card shows the uploaded logo',
    /whitelabel\/front\/asset\.php/.test(login.logo) || /whitelabel/.test(login.logoVar),
    login.logo + ' | ' + login.logoVar);
  check('the built-in login source is renamed in the form',
    login.authOptions.length === 0 || !login.authOptions.some((o) => /GLPI internal database/.test(o)),
    login.authOptions.join(' / '));

  check('and in the widget the user actually reads',
    login.authVisible.length === 0 || !login.authVisible.some((o) => /GLPI internal database/.test(o)),
    login.authVisible.join(' / '));

  await fullPage(page, `${SHOTS}/whitelabel-04-login.png`);

  // --- the credit line ---------------------------------------------------

  check('the copyright line is left alone by default', login.creditVisible !== false,
    String(login.creditVisible));
  check('but no longer starts with GLPI when a name is set',
    login.creditText === '' || !/^GLPI\b/.test(login.creditText), login.creditText);

  php('GlpiPlugin\\Whitelabel\\Settings::save(["hide_credit"=>"1"]); echo "hidden";');
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  const hidden = await brand(page);
  check('hiding it works', hidden.creditVisible === false || hidden.creditVisible === null,
    String(hidden.creditVisible));

  // --- and it all comes back --------------------------------------------

  php('GlpiPlugin\\Whitelabel\\Assets::forgetAll(); '
    + 'GlpiPlugin\\Whitelabel\\Settings::save(["name"=>"","short_name"=>"","local_auth_label"=>"","doc_url"=>"","hide_credit"=>"0"]); echo "reset";');

  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  const after = await brand(page);
  check('removing the branding gives GLPI its own name back', /GLPI/.test(after.title), after.title);
  check('and its own favicon', after.favicon.some((f) => /pics\/favicon/.test(f)),
    after.favicon.join(','));

  check('no uncaught JavaScript errors', errs.length === 0, errs.join(' | '));


  // --- The dark palette --------------------------------------------------
  //
  // This plugin's whole job is the ground the logo sits on, so the one surface
  // worth re-reading on a black body is its own settings page — where the
  // uploaded images are previewed against it.
  fs.mkdirSync(DARK_SHOTS, { recursive: true });
  console.log('\nswitching to the dark palette...');

  const dark = await openDark(browser, { plugin: 'whitelabel' });

  await dark.goto(`${BASE}/plugins/whitelabel/front/config.php`, { waitUntil: 'networkidle' });
  await dark.waitForTimeout(500);
  const bad = await audit(dark, 'whitelabel-');
  check('[dark] settings: no near-white panel carrying dark-body text',
    bad.whiteBg.length === 0, JSON.stringify(bad.whiteBg));
  check('[dark] settings: muted text meets 4.5:1',
    bad.lowContrast.length === 0, JSON.stringify(bad.lowContrast));
  await fullPage(dark, `${DARK_SHOTS}/whitelabel-dark-01-settings.png`);

  check('[dark] no page errors', dark.__darkErrors.length === 0, dark.__darkErrors.join(' | '));

  await browser.close();
  console.log(fail.length ? `\n${fail.length} failed: ${fail.join(', ')}` : '\nall checks passed');
  process.exit(fail.length ? 1 : 0);
})();
