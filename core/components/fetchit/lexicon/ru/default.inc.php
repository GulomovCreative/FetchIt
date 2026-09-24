<?php
include_once 'setting.inc.php';

$_lang['fetchit'] = 'FetchIt';

$_lang['fetchit_submit'] = 'Отправить';
$_lang['fetchit_reset'] = 'Очистить';

$_lang['fetchit_label_name'] = 'Имя';
$_lang['fetchit_label_email'] = 'E-mail';
$_lang['fetchit_label_message'] = 'Сообщение';

$_lang['fetchit_err_action_ns'] = 'Не указан ключ формы (action).';
$_lang['fetchit_err_action_nf'] = 'Не могу найти указанный ключ формы (action).';
$_lang['fetchit_err_chunk_ns'] = 'Не указан чанк для обработки формы.';
$_lang['fetchit_err_chunk_nf'] = 'Не могу найти указанный чанк "[[+name]]" с формой.';
$_lang['fetchit_err_snippet_ns'] = 'Не указан сниппет для обработки формы.';
$_lang['fetchit_err_snippet_nf'] = 'Не могу найти указанный сниппет "[[+name]]" для обработки формы.';
$_lang['fetchit_err_request'] = 'Не удалось отправить форму. Попробуйте ещё раз.';
$_lang['fetchit_err_token'] = 'Форма устарела. Отправьте её ещё раз.';
$_lang['fetchit_err_too_fast'] = 'Форма отправлена слишком быстро. Отправьте её ещё раз.';
$_lang['fetchit_err_rate'] = 'Слишком много отправок. Попробуйте позже.';
$_lang['fetchit_err_store'] = 'Сейчас форму не удаётся проверить. Попробуйте позже.';
$_lang['fetchit_err_pow'] = 'Не удалось подтвердить отправку. Обновите страницу и отправьте форму ещё раз.';
$_lang['fetchit_err_captcha'] = 'Проверка на спам не пройдена. Обновите страницу и отправьте форму ещё раз.';
$_lang['fetchit_err_captcha_client'] = 'Не удалось пройти проверку на спам. Убедитесь, что её ничто не блокирует на странице, и попробуйте ещё раз.';
$_lang['fetchit_err_captcha_unavailable'] = 'Проверка на спам сейчас недоступна. Попробуйте ещё раз через несколько минут.';
$_lang['fetchit_notifier_close'] = 'Закрыть';
$_lang['fetchit_trap_label'] = 'Оставьте это поле пустым';
$_lang['fetchit_err_has_errors'] = 'Форма содержит ошибки';
$_lang['fetchit_success_submit'] = 'Форма успешно отправлена';
