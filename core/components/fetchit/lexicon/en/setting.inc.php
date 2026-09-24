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
$_lang['setting_fetchit.protection_desc'] = 'Signed single-use token, minimum fill time, a hidden trap field and a submission limit for every FetchIt form. Turn it off only for debugging.';
$_lang['setting_fetchit.protection.secret'] = 'Token signing key';
$_lang['setting_fetchit.protection.secret_desc'] = 'Leave empty to use a key derived from this MODX installation. Changing it invalidates the forms already open in browsers.';
$_lang['setting_fetchit.protection.min_time'] = 'Minimum fill time, seconds';
$_lang['setting_fetchit.protection.min_time_desc'] = 'A form sent sooner after the page loaded is refused and can be sent again. 0 turns the check off.';
$_lang['setting_fetchit.protection.token_ttl'] = 'Token lifetime, seconds';
$_lang['setting_fetchit.protection.token_ttl_desc'] = 'How long a page with a form stays valid. 0 means no limit.';
$_lang['setting_fetchit.protection.rate_limit'] = 'Submissions per address';
$_lang['setting_fetchit.protection.rate_limit_desc'] = 'How many times one address may send one form within the window below. 0 turns the limit off.';
$_lang['setting_fetchit.protection.rate_window'] = 'Limit window, seconds';
$_lang['setting_fetchit.protection.rate_window_desc'] = 'The period the submission limit counts over.';
