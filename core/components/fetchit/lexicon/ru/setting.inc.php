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
$_lang['setting_fetchit.protection_desc'] = 'Подписанный одноразовый токен, минимальное время заполнения, скрытое поле-ловушка и лимит отправок для каждой формы FetchIt. Выключайте только для отладки; на сайте для разработки лучше поставьте время заполнения и лимит в 0.';
$_lang['setting_fetchit.protection.secret'] = 'Ключ подписи токенов';
$_lang['setting_fetchit.protection.secret_desc'] = 'Заполняется случайным ключом при установке пакета. После смены ключа уже открытые формы один раз получат отказ: отправленные через FetchIt как устаревшие, без JavaScript с тем же сообщением.';
$_lang['setting_fetchit.protection.min_time'] = 'Минимальное время заполнения, секунд';
$_lang['setting_fetchit.protection.min_time_desc'] = 'Форма, отправленная быстрее, чем через столько секунд после вывода страницы, отклоняется, и её можно отправить ещё раз. Повтор считается от первого вывода. 0 выключает проверку.';
$_lang['setting_fetchit.protection.token_ttl'] = 'Срок жизни токена, секунд';
$_lang['setting_fetchit.protection.token_ttl_desc'] = 'Сколько страница с формой остаётся действительной. Более старая страница один раз получит отказ; через FetchIt отправка сразу повторится с новым токеном. 0 значит 30 дней.';
$_lang['setting_fetchit.protection.rate_limit'] = 'Отправок с одного адреса';
$_lang['setting_fetchit.protection.rate_limit_desc'] = 'Сколько раз один адрес может отправить одну форму за окно ниже. За прокси или CDN укажите доверенные прокси ниже, иначе у всех посетителей будет один адрес. 0 выключает лимит.';
$_lang['setting_fetchit.protection.rate_window'] = 'Окно лимита, секунд';
$_lang['setting_fetchit.protection.rate_window_desc'] = 'Период, за который считается лимит отправок. 0 выключает лимит.';
$_lang['setting_fetchit.protection.log'] = 'Журнал защиты';
$_lang['setting_fetchit.protection.log_desc'] = '0: ничего. 1: проблемы и отказы, которые могут задеть людей (ловушка, лимит, недоступный для записи кеш). 2: все отказы. Пишется в журнал ошибок MODX.';
$_lang['setting_fetchit.protection.proxies'] = 'Доверенные прокси';
$_lang['setting_fetchit.protection.proxies_desc'] = 'IP или CIDR-диапазоны ваших прокси или CDN через запятую. Только для запросов от них адрес посетителя берётся из заголовка ниже.';
$_lang['setting_fetchit.protection.ip_header'] = 'Заголовок с адресом посетителя';
$_lang['setting_fetchit.protection.ip_header_desc'] = 'Заголовок, в который доверенный прокси кладёт адрес посетителя, например X-Forwarded-For или CF-Connecting-IP.';
