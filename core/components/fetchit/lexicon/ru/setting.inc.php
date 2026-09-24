<?php

$_lang['area_fetchit_main'] = 'Основные настройки';

$_lang['setting_fetchit.frontend.js'] = 'Файл с JavaScript для подключения на фронтенде.';
$_lang['setting_fetchit.frontend.js.classname'] = 'Название JavaScript класса, чей экземпляр будет отвечать за обработку форм. По умолчанию "FetchIt".';
$_lang['setting_fetchit.frontend.input.invalid.class'] = 'CSS класс который будет добавлен элементу не прошедшему валидацию.';
$_lang['setting_fetchit.frontend.custom.invalid.class'] = 'CSS класс который будет добавлен кастомному элементу по ключу не прошедшему валидацию.';
$_lang['setting_fetchit.frontend.default.notifier'] = 'Показывать ответы уведомлениями.';
$_lang['setting_fetchit.frontend.default.notifier_desc'] = 'Если "Да", ответы сервера показываются ещё и уведомлениями в углу страницы, если сайт не задал свой FetchIt.Message. Уведомления встроены в скрипт FetchIt, других файлов не подключается.';

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
$_lang['setting_fetchit.protection.log_desc'] = '0: ничего. 1: проблемы и отказы, которые могут задеть людей (ловушка, лимит, недоступный для записи кеш, недоступный сервис капчи). 2: все отказы. Пишется в журнал ошибок MODX.';
$_lang['setting_fetchit.protection.proxies'] = 'Доверенные прокси';
$_lang['setting_fetchit.protection.proxies_desc'] = 'IP или CIDR-диапазоны ваших прокси или CDN через запятую. Только для запросов от них адрес посетителя берётся из заголовка ниже.';
$_lang['setting_fetchit.protection.ip_header'] = 'Заголовок с адресом посетителя';
$_lang['setting_fetchit.protection.ip_header_desc'] = 'Заголовок, в который доверенный прокси кладёт адрес посетителя, например X-Forwarded-For или CF-Connecting-IP.';
$_lang['setting_fetchit.protection.pow'] = 'Proof of work, бит';
$_lang['setting_fetchit.protection.pow_desc'] = 'Перед отправкой браузер решает небольшую задачу: при 16 компьютеру нужно в среднем около трети секунды, телефону дольше, а массовая рассылка становится дорогой. Не больше 24. Нужен JavaScript: формы без него отклоняются. 0 выключает.';
$_lang['area_fetchit_captcha'] = 'Капча';
$_lang['setting_fetchit.captcha'] = 'Капча';
$_lang['setting_fetchit.captcha_desc'] = 'turnstile (Cloudflare Turnstile), recaptcha (Google reCAPTCHA v3) или smartcaptcha (Яндекс SmartCaptcha); пусто, если не нужна. Работает, когда заданы оба ключа ниже и включена защита; нужен JavaScript.';
$_lang['setting_fetchit.captcha.site_key'] = 'Ключ сайта для капчи';
$_lang['setting_fetchit.captcha.site_key_desc'] = 'Публичный ключ сайта у провайдера капчи.';
$_lang['setting_fetchit.captcha.secret_key'] = 'Секретный ключ капчи';
$_lang['setting_fetchit.captcha.secret_key_desc'] = 'Секретный ключ у провайдера капчи, им ответы проверяются на сервере.';
$_lang['setting_fetchit.captcha.min_score'] = 'reCAPTCHA v3: минимальный балл';
$_lang['setting_fetchit.captcha.min_score_desc'] = 'От 0 до 1; ответы с меньшим баллом отклоняются. Google советует 0.5, оно же используется, если значение не число от 0 до 1.';
