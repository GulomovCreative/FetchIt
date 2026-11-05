# История изменений FetchIt

[English](core/components/fetchit/docs/changelog.txt) | **Русский**

Здесь описаны все заметные изменения проекта. Формат основан на
[Keep a Changelog](https://keepachangelog.com/ru/1.1.0/), проект следует
[семантическому версионированию](https://semver.org/lang/ru/spec/v2.0.0.html).

FetchIt 1.x был пакетом для MODX 2, FetchIt 3.x — пакетом для MODX 3.
FetchIt 4 заменяет оба одним пакетом и ставится поверх любого из них.

## [4.0.0] - 2026-09-25

Один пакет для MODX 2.8 и MODX 3, с защитой от спама, включённой по умолчанию.
Ставится поверх FetchIt 1.x на MODX 2 и FetchIt 3.x на MODX 3; системные настройки
и чанки сохраняются. То, что может задеть ваш код, помечено ниже как **Ломающее** и
разобрано в [README](README.ru.md#переход-на-fetchit-4).

### Добавлено

- Защита от спама для каждой формы, включена по умолчанию — и для отправок через FetchIt, и для форм, отправленных без JavaScript. `fetchit.protection` выключает её целиком.
  - Подписанный одноразовый токен в каждой форме (`fetchit_token`), сессия не нужна. Следующий токен, в заголовке `X-FetchIt-Token`, получает только запрос с верно подписанным токеном своей формы. Если токен страницы устарел (страница из кеша, долго открытая страница, новый ключ), скрипт сам отправляет форму ещё раз с новым токеном.
  - Минимальное время заполнения (`fetchit.protection.min_time`, 3 секунды), считается от первого вывода страницы, включая повторные отправки.
  - Скрытое поле-ловушка со случайным именем для каждой установки. Бот, который его заполнил, получает мнимый успех, а форма не обрабатывается.
  - Ограничение числа отправок на форму и адрес клиента (`fetchit.protection.rate_limit`, `fetchit.protection.rate_window`), в том числе за доверенными прокси и CDN (`fetchit.protection.proxies`, `fetchit.protection.ip_header`).
  - Использованные токены помечаются атомарно в `core/cache/fetchit/tokens/`, «Очистить кеш» этот каталог не трогает. Если пометку записать не удалось, форма отклоняется, а причина попадает в лог.
  - Необязательное доказательство работы (`fetchit.protection.pow`, в битах, по умолчанию выключено): браузер ищет `n`, при котором SHA-256 от `token:n` начинается с такого числа нулевых битов, и начинает, как только посетитель вошёл в форму. Если страница из кеша просит меньше, чем требует сервер, отказ несёт заголовок `X-FetchIt-Pow`, и скрипт решает задачу и отправляет ещё раз.
  - Необязательные капчи: Cloudflare Turnstile, Google reCAPTCHA v3 и Яндекс SmartCaptcha (`fetchit.captcha`, `fetchit.captcha.site_key`, `fetchit.captcha.secret_key`, `fetchit.captcha.min_score`). FetchIt подключает скрипт провайдера, получает его ответ перед каждой отправкой и проверяет его на сервере последним. Недоступный провайдер отклоняет форму своим сообщением `fetchit_err_captcha_unavailable`; капча, которой нечего отправить, останавливает отправку с `fetchit_err_captcha_client`.
  - Лог отказов и неполадок (`fetchit.protection.log`) и ключ подписи, который создаётся при установке (`fetchit.protection.secret`). Ошибки в настройках капчи и доказательства работы пишутся в лог.
- Событие `OnFetchItBeforeProcess`: плагин получает `$action`, `$fields`, `$properties` и `$FetchIt` и отклоняет отправку через `$modx->event->output()`. Срабатывает и с выключенной защитой.
- Поддержка MODX 3 в том же пакете, собранном на MODX 2.8: он ставится начисто и на MODX 2.8, и на MODX 3.
- API FetchIt 3.x на обеих версиях: класс `FetchIt\FetchIt`, `saveActionProperties()` и `getActionProperties()`, а на MODX 3 — `$modx->services->get('FetchIt')`.
- `FetchIt::service()` — один общий экземпляр на MODX 2 и MODX 3; способы получить FetchIt из 1.x и 3.x возвращают его же.
- `FetchIt::pdoTools()` — находит pdoTools 2 на MODX 2 и pdoTools 3 на MODX 3, для Fenom и чанков `@FILE`.
- `FetchIt::prepareForm()` — проставляет тегам формы в чанке метод POST и ключ формы.
- Типы TypeScript, `assets/components/fetchit/js/fetchit.d.ts`: `FetchIt`, конфиг, `FetchIt.Message`, экземпляры форм и события `fetchit:*` с их `detail`. Скрипт проверяется по ним.
- `FetchIt.createNotifier()` — встроенные уведомления для сайта, который сам задаёт `FetchIt.Message`. `duration: 0` оставляет уведомление до закрытия.
- `detail.error` в `fetchit:error`, когда запрос не удался, и строка лексикона `fetchit_err_request`, которую посетитель тогда видит.
- Записи в лог MODX, когда скрипт не удаётся добавить на страницу с формой: нет `<head>` или `fetchit.frontend.js` указывает не на файл `.js`.
- Обновление с включённой `fetchit.frontend.default.notifier` пишет в лог, что Notyf больше не подключается, и предупреждает, если `fetchit.frontend.js` указывает на скрипт сайта.

### Изменено

- **Ломающее:** служебные поля защиты ставятся сразу после тега формы и убираются из `$_POST` до того, как их прочитает FormIt, поэтому в письма они не попадают. Свой скрипт вместо встроенного должен отправлять `fetchit_token` и брать следующий токен из заголовка `X-FetchIt-Token` каждого ответа.
- **Ломающее:** уведомления `fetchit.frontend.default.notifier` теперь собственные, а не Notyf, и `window.Notyf` больше не подключается. Стили для `.notyf__toast` и скрипты с `new Notyf()` придётся поправить. Новые уведомления доступны (живые области для скринридеров, кнопка закрытия с подписью, работа с фокусом), не исчезают под курсором и при фокусе и берут цвета из CSS-переменных — по умолчанию пастельные зелёный и красный из Tailwind CSS 4. При Content-Security-Policy берут nonce скрипта FetchIt.
- **Ломающее:** `fetchit:error` срабатывает и при неудачном запросе, тогда `detail.response` равен `null`.
- **Ломающее:** сниппет обработки получает в `fields` только отправленную форму: `$_POST`, а для отправок через FetchIt ещё и `$_FILES`. Раньше приходил `$_REQUEST` — со значениями GET и, в зависимости от `request_order`, с cookie.
- При включённой защите сниппет по-прежнему запускает FormIt на каждый показ страницы, ради его preHooks, но отправкой считается только POST с токеном своей формы.
- Скрипт собирается синтаксисом ES2019, поэтому работает во всех браузерах из browserslist проекта.
- Хуки `FetchIt.Message` и события получают строку `message` и объект `data`, даже если сниппет обработки их не вернул. Исключение в хуке пишется в лог с его именем и больше не мешает форме получить ответ.
- `fetchit:success` можно отменить: `event.preventDefault()` оставляет поля заполненными.
- Повторная отправка, пока идёт запрос, игнорируется.
- На MODX 3 плагин и `action.php` берут FetchIt из контейнера сервисов, а не через устаревший `getService()`.
- Строчный вызов `FetchIt.create()` проверяет, что класс из `fetchit.frontend.js.classname` загружен, поэтому страница без скрипта откатывается к обычной отправке, а не падает. `FetchIt.create()` предупреждает в консоли, если ни одна форма не подошла.
- `method` и `data-fetchit` идут последними среди атрибутов тега формы, а `?v=` в адресе скрипта сохраняет строку запроса и якорь.

### Удалено

- `lib/notyf.min.js` и `lib/notyf.min.css` больше не поставляются. При обновлении прежние копии остаются на месте.

### Исправлено

- Форма, отправленная без JavaScript, не показывала сообщение об успехе или ошибке, если чанк применял выходные фильтры к плейсхолдерам FormIt, как в примере чанка: чанк отрисовывался до запуска FormIt.
- Тег формы со своим `data-fetchit` ломался, похожие теги вроде `<form-field>` менялись, как и `formmethod` и `data-fetchit` у элементов внутри формы. Теги формы теперь разбираются по одному, вместе со значениями в кавычках.
- Скрипт не добавлялся на сайтах без анонимных сессий, а также когда `<head>` был написан заглавными или имел атрибуты.
- FormIt не ставился вместе с FetchIt на MODX 3. Установщик теперь ещё и сообщает о каждой причине, по которой скачивание или установка FormIt может не удаться.
- Два одинаковых вызова сниппета на странице привязывали форму дважды, и она отправлялась дважды.
- Поля, отключённые в разметке, после отправки становились доступными.
- Ошибка сети или ответ не от FetchIt (страница ошибки PHP, HTML после редиректа, JSON от файрвола) попадали только в консоль. Теперь посетитель видит `fetchit_err_request`.
- Плейсхолдер ошибки FormIt, содержащий только `&nbsp;`, помечал поле как неверное.
- Имена полей с кавычками ломали селекторы ошибок.
- Несуществующий набор свойств сниппета ронял `action.php` на PHP 8. Теперь сниппет работает без него, а в лог MODX попадает имя пропавшего набора.
- Без настройки `fetchit.frontend.js` плагин подключал несуществующий `js/default.js`.
- Уведомления об устаревании на PHP 8.1, когда настроек классов невалидности нет.

### Безопасность

- POST прямо на адрес страницы с формой FetchIt доходил до FormIt и его хуков, включая письма, без единой проверки. Теперь он обязан нести верный токен своей формы.
- FormIt 5.2 и новее сохранял свойства каждой формы FetchIt, вместе с хуками, для собственного `action.php`, который обрабатывает форму без проверок FetchIt. Теперь FetchIt выключает этот AJAX-режим для своих форм: убирает сохранённые свойства, плейсхолдер `ajaxToken` и `formit.js`. Собственные формы FormIt на той же странице свой AJAX-режим сохраняют.

## [3.1.4] - 2026-09-09

### Исправлено

- Сообщения об успехе и о проверке не показывались при отправке через AJAX. Теперь они попадают в элементы формы `[data-success]` и `[data-validation-error]` ([#15]).
- Ошибки полей FormIt из одних пробелов помечали форму как неуспешную ([#14]).
- Предупреждение PHP 8 о неопределённом `$_SESSION['fetchit_called']` ([#13]) и о неопределённом `$_SESSION['FetchIt']`.
- GET-запрос к `action.php` не перенаправлялся.
- `successMessage` из вызова сниппета не использовался в AJAX-ответе FormIt.

### Изменено

- Фронтенд собирается на Rollup 4.

## [3.1.3] - 2026-07-21

### Исправлено

- Фатальная ошибка `Serialization of 'PDO' is not allowed` на MODX 3.2.2 и PHP 8.3 ([#17], [#18]). Свойства формы больше не хранят экземпляр FetchIt: свои сниппеты получают его через `$modx->services->get('FetchIt')`.

## [3.1.2] - 2023-11-11

### Добавлено

- Ошибка reCAPTCHA v3 от FormIt возвращается как ошибка поля `recaptcha` ([#6]).

### Исправлено

- `action.php` не находил `config.core.php`, когда корень документов не совпадает с корнем MODX ([#4]).

## [3.1.1] - 2023-07-14

### Исправлено

- Фатальная ошибка на PHP 8 при добавлении скрипта на страницу.

## [3.1.0] - 2023-06-12

### Добавлено

- Настройка `fetchit.frontend.default.notifier`: ответы дополнительно показываются уведомлениями Notyf.

## [3.0.0] - 2023-06-06

### Добавлено

- Пакет для MODX 3 на основе FetchIt 1.0.1.

## [1.1.3] - 2026-08-12

### Исправлено

- Фатальная ошибка `Serialization of 'PDO' is not allowed` на PHP 8.3 ([#17]). Свойства формы больше не хранят экземпляр FetchIt: свои сниппеты получают его через `$modx->getService('fetchit', 'FetchIt', MODX_CORE_PATH . 'components/fetchit/model/')`.
- Сообщения об успехе и о проверке не показывались при отправке через AJAX ([#11], [#15]). Теперь они попадают в элементы формы `[data-success]` и `[data-validation-error]`; своим чанкам нужны эти атрибуты вместо условий `[[+fi.success:…]]`.
- Ошибки полей FormIt из одних пробелов помечали форму как неуспешную ([#10], [#14]).
- Предупреждение PHP 8 о неопределённом `$_SESSION['fetchit_called']` ([#7], [#9], [#13]) и о неопределённом `$_SESSION['FetchIt']`.
- GET-запрос к `action.php` не перенаправлялся.
- `successMessage` из вызова сниппета не использовался в AJAX-ответе FormIt ([#5]).

## [1.1.2] - 2023-11-11

### Добавлено

- Ошибка reCAPTCHA v3 от FormIt возвращается как ошибка поля `recaptcha` ([#6]).

## [1.1.1] - 2023-07-14

### Исправлено

- Фатальная ошибка на PHP 8 при добавлении скрипта на страницу.

## [1.1.0] - 2023-06-12

### Добавлено

- Настройка `fetchit.frontend.default.notifier`: ответы дополнительно показываются уведомлениями Notyf.

## [1.0.1] - 2023-03-25

### Исправлено

- Скрипт пропадал, когда сниппет вызывали из Fenom. Теперь плагин на `OnWebPagePrerender` добавляет его в `<head>`.

## [1.0.0] - 2023-03-20

### Исправлено

- Неопределённая переменная `$hash`.

## [1.0.0-beta] - 2023-02-23

### Добавлено

- Первый релиз: формы отправляются через Fetch API и обрабатываются FormIt или собственным сниппетом.

[4.0.0]: https://github.com/GulomovCreative/FetchIt/compare/v1.1.3...v4.0.0
[3.1.4]: https://github.com/GulomovCreative/FetchIt/compare/a2f6d4d...v3.1.4
[3.1.3]: https://github.com/GulomovCreative/FetchIt/compare/7ac236f...a2f6d4d
[3.1.2]: https://github.com/GulomovCreative/FetchIt/compare/730b50d...7ac236f
[3.1.1]: https://github.com/GulomovCreative/FetchIt/compare/3.1.0-pl...730b50d
[3.1.0]: https://github.com/GulomovCreative/FetchIt/compare/3.0.0-pl...3.1.0-pl
[3.0.0]: https://github.com/GulomovCreative/FetchIt/compare/1.0.1-pl...3.0.0-pl
[1.1.3]: https://github.com/GulomovCreative/FetchIt/compare/c89c7fe...v1.1.3
[1.1.2]: https://github.com/GulomovCreative/FetchIt/compare/1.1.1-pl...c89c7fe
[1.1.1]: https://github.com/GulomovCreative/FetchIt/compare/1.1.0-pl...1.1.1-pl
[1.1.0]: https://github.com/GulomovCreative/FetchIt/compare/1.0.1-pl...1.1.0-pl
[1.0.1]: https://github.com/GulomovCreative/FetchIt/compare/1.0.0-pl...1.0.1-pl
[1.0.0]: https://github.com/GulomovCreative/FetchIt/compare/1.0.0-beta...1.0.0-pl
[1.0.0-beta]: https://github.com/GulomovCreative/FetchIt/releases/tag/1.0.0-beta
[#4]: https://github.com/GulomovCreative/FetchIt/issues/4
[#5]: https://github.com/GulomovCreative/FetchIt/issues/5
[#6]: https://github.com/GulomovCreative/FetchIt/pull/6
[#7]: https://github.com/GulomovCreative/FetchIt/issues/7
[#9]: https://github.com/GulomovCreative/FetchIt/issues/9
[#10]: https://github.com/GulomovCreative/FetchIt/issues/10
[#11]: https://github.com/GulomovCreative/FetchIt/issues/11
[#13]: https://github.com/GulomovCreative/FetchIt/pull/13
[#14]: https://github.com/GulomovCreative/FetchIt/pull/14
[#15]: https://github.com/GulomovCreative/FetchIt/pull/15
[#17]: https://github.com/GulomovCreative/FetchIt/issues/17
[#18]: https://github.com/GulomovCreative/FetchIt/pull/18
