<?php

$_lang['area_fetchit_main'] = 'Основные настройки';

$_lang['setting_fetchit.frontend.js'] = 'Файл с JavaScript для подключения на фронтенде.';
$_lang['setting_fetchit.frontend.js.classname'] = 'Название JavaScript класса, чей экземпляр будет отвечать за обработку форм. По умолчанию "FetchIt".';
$_lang['setting_fetchit.frontend.input.invalid.class'] = 'CSS класс который будет добавлен элементу не прошедшему валидацию.';
$_lang['setting_fetchit.frontend.custom.invalid.class'] = 'CSS класс который будет добавлен кастомному элементу по ключу не прошедшему валидацию.';
$_lang['setting_fetchit.frontend.default.notifier'] = 'Подключить дефолтную библиотеку уведомлений.';
$_lang['setting_fetchit.frontend.default.notifier_desc'] = 'Если выбрать "Да", то при взаимодействии с вашими формами пользователю будут отображаться уведомления с помощью библиотеки <a href="https://carlosroso.com/notyf/">Notyf</a>.';

$_lang['area_fetchit_protection'] = 'Защита от спама';
$_lang['setting_fetchit.protection'] = 'Защита от спама';
$_lang['setting_fetchit.protection_desc'] = 'Подписанный одноразовый токен, минимальное время заполнения, скрытое поле-ловушка и лимит отправок для каждой формы FetchIt. Выключайте только для отладки.';
$_lang['setting_fetchit.protection.secret'] = 'Ключ подписи токенов';
$_lang['setting_fetchit.protection.secret_desc'] = 'Оставьте пустым, чтобы использовать ключ, выведенный из этой установки MODX. После смены ключа уже открытые в браузерах формы придётся отправить ещё раз.';
$_lang['setting_fetchit.protection.min_time'] = 'Минимальное время заполнения, секунд';
$_lang['setting_fetchit.protection.min_time_desc'] = 'Форма, отправленная быстрее после загрузки страницы, отклоняется, и её можно отправить ещё раз. 0 выключает проверку.';
$_lang['setting_fetchit.protection.token_ttl'] = 'Срок жизни токена, секунд';
$_lang['setting_fetchit.protection.token_ttl_desc'] = 'Сколько страница с формой остаётся действительной. 0 значит без ограничения.';
$_lang['setting_fetchit.protection.rate_limit'] = 'Отправок с одного адреса';
$_lang['setting_fetchit.protection.rate_limit_desc'] = 'Сколько раз один адрес может отправить одну форму за окно ниже. 0 выключает лимит.';
$_lang['setting_fetchit.protection.rate_window'] = 'Окно лимита, секунд';
$_lang['setting_fetchit.protection.rate_window_desc'] = 'Период, за который считается лимит отправок.';
