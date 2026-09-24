<?php

$_lang['area_fetchit_main'] = 'Main settings';

$_lang['setting_fetchit.frontend.js'] = 'The JavaScript file to include on the frontend.';
$_lang['setting_fetchit.frontend.js.classname'] = 'The JavaScript class name whose instance will be responsible for form processing. The default value is "FetchIt".';
$_lang['setting_fetchit.frontend.input.invalid.class'] = 'CSS class that will be added to invalid input field.';
$_lang['setting_fetchit.frontend.custom.invalid.class'] = 'CSS class that will be added to invalid custom element.';
$_lang['setting_fetchit.frontend.default.notifier'] = 'Load the default notification library.';
$_lang['setting_fetchit.frontend.default.notifier_desc'] = 'If you select "Yes", FetchIt will load notification library <a href="https://carlosroso.com/notyf/">Notyf</a>.';

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
$_lang['setting_fetchit.protection.log_desc'] = '0: nothing. 1: problems and refusals that may hit people (the trap, the limit, an unwritable cache). 2: every refusal. Written to the MODX error log.';
$_lang['setting_fetchit.protection.proxies'] = 'Trusted proxies';
$_lang['setting_fetchit.protection.proxies_desc'] = 'Comma-separated IPs or CIDR ranges of your proxies or CDN. Only for requests from them the client address is taken from the header below.';
$_lang['setting_fetchit.protection.ip_header'] = 'Client address header';
$_lang['setting_fetchit.protection.ip_header_desc'] = 'The header a trusted proxy puts the client address into, e.g. X-Forwarded-For or CF-Connecting-IP.';
