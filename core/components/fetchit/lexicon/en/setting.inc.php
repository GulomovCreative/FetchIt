<?php

$_lang['area_fetchit_main'] = 'Main settings';

$_lang['setting_fetchit.frontend.js'] = 'The JavaScript file to include on the frontend.';
$_lang['setting_fetchit.frontend.js.classname'] = 'The JavaScript class name whose instance will be responsible for form processing. The default value is "FetchIt".';
$_lang['setting_fetchit.frontend.input.invalid.class'] = 'CSS class that will be added to invalid input field.';
$_lang['setting_fetchit.frontend.custom.invalid.class'] = 'CSS class that will be added to invalid custom element.';
$_lang['setting_fetchit.frontend.default.notifier'] = 'Show the answers as notifications.';
$_lang['setting_fetchit.frontend.default.notifier_desc'] = 'If "Yes", the answers of the server are also shown as notifications in a corner of the page, unless the site sets its own FetchIt.Message. The notifier is part of the FetchIt script: no other files are loaded.';

$_lang['area_fetchit_protection'] = 'Spam protection';
$_lang['setting_fetchit.protection'] = 'Spam protection';
$_lang['setting_fetchit.protection_desc'] = 'Signed single-use token, minimum fill time, a hidden trap field and a submission limit for every FetchIt form. Turn it off only for debugging; on a development site set the fill time and the limit to 0 instead.';
$_lang['setting_fetchit.protection.secret'] = 'Token signing key';
$_lang['setting_fetchit.protection.secret_desc'] = 'Filled with a random key when the package is installed. Changing it refuses the forms already open in browsers once: sent through FetchIt they are refused as expired, sent without JavaScript they show the same message.';
$_lang['setting_fetchit.protection.min_time'] = 'Minimum fill time, seconds';
$_lang['setting_fetchit.protection.min_time_desc'] = 'A form sent sooner than this many seconds after the page was rendered is refused and can be sent again. A retry keeps counting from the first render. 0 turns the check off.';
$_lang['setting_fetchit.protection.token_ttl'] = 'Token lifetime, seconds';
$_lang['setting_fetchit.protection.token_ttl_desc'] = 'How long a page with a form stays valid. An older page is refused once; sent through FetchIt it is retried at once with a new token. 0 means 30 days.';
$_lang['setting_fetchit.protection.rate_limit'] = 'Submissions per address';
$_lang['setting_fetchit.protection.rate_limit_desc'] = 'How many times one address may send one form within the window below. Behind a proxy or CDN set the trusted proxies below, or all visitors share one address. 0 turns the limit off.';
$_lang['setting_fetchit.protection.rate_window'] = 'Limit window, seconds';
$_lang['setting_fetchit.protection.rate_window_desc'] = 'The period the submission limit counts over. 0 turns the limit off.';
$_lang['setting_fetchit.protection.log'] = 'Log of the protection';
$_lang['setting_fetchit.protection.log_desc'] = '0: nothing. 1: problems and refusals that may hit people (the trap, the limit, an unwritable cache, a captcha provider that cannot be reached). 2: every refusal. Written to the MODX error log.';
$_lang['setting_fetchit.protection.proxies'] = 'Trusted proxies';
$_lang['setting_fetchit.protection.proxies_desc'] = 'Comma-separated IPs or CIDR ranges of your proxies or CDN. Only for requests from them the client address is taken from the header below.';
$_lang['setting_fetchit.protection.ip_header'] = 'Client address header';
$_lang['setting_fetchit.protection.ip_header_desc'] = 'The header a trusted proxy puts the client address into, e.g. X-Forwarded-For or CF-Connecting-IP.';
$_lang['setting_fetchit.protection.pow'] = 'Proof of work, bits';
$_lang['setting_fetchit.protection.pow_desc'] = 'The browser solves a small puzzle before sending: 16 takes a computer about a third of a second on average and a phone longer, and makes mass sending costly. At most 24. Needs JavaScript: forms sent without it are refused. 0 turns it off.';
$_lang['area_fetchit_captcha'] = 'Captcha';
$_lang['setting_fetchit.captcha'] = 'Captcha';
$_lang['setting_fetchit.captcha_desc'] = 'turnstile (Cloudflare Turnstile), recaptcha (Google reCAPTCHA v3) or smartcaptcha (Yandex SmartCaptcha); empty for none. Works once both keys below are set, with the protection on; needs JavaScript.';
$_lang['setting_fetchit.captcha.site_key'] = 'Captcha site key';
$_lang['setting_fetchit.captcha.site_key_desc'] = 'The public key of the site from the captcha provider.';
$_lang['setting_fetchit.captcha.secret_key'] = 'Captcha secret key';
$_lang['setting_fetchit.captcha.secret_key_desc'] = 'The secret key from the captcha provider, used to check the answers on the server.';
$_lang['setting_fetchit.captcha.min_score'] = 'reCAPTCHA v3: minimum score';
$_lang['setting_fetchit.captcha.min_score_desc'] = 'From 0 to 1; lower scores are refused. Google suggests 0.5, which is also used for a value that is not a number from 0 to 1.';
