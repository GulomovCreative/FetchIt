# FetchIt

Компонент для MODX Revolution для отправки форм с помощью Fetch API.

![Логотип FetchIt](https://github.com/GulomovCreative/FetchIt/blob/master/fetchit-logo.svg?raw=true&v=3)

В CMS/CMF MODX Revolution есть компонент [FormIt](https://github.com/Sterc/FormIt): он отправляет и обрабатывает формы стандартным методом браузера, с перезагрузкой страницы. FetchIt использует FormIt (или ваш сниппет) и обрабатывает формы «на лету» через Fetch API.

Близкий по серверной части аналог — [AjaxForm](https://github.com/modx-pro/AjaxForm). У FetchIt другие плюсы на фронте:

## Никаких зависимостей

FetchIt не тянет внешних JS-библиотек. У AjaxForm их три: [jQuery](https://github.com/jquery/jquery), [jquery-form](https://github.com/jquery-form/form/) и [jGrowl](https://github.com/stanlemon/jGrowl).

Уведомления (jGrowl) можно переопределить и в AjaxForm. jQuery и jquery-form заменить сложнее.

## Современный код

Минифицированный скрипт весит около 6 КБ, в gzip около 2 КБ. Сниппет подключает его с атрибутом `defer`, чтобы не блокировать загрузку страницы. На фронте — нативный Fetch API и `FormData` (включая файлы).

## Удобство

Свою вёрстку оставляете как есть: достаточно чанка формы и атрибутов `data-error`. Всплывающие сообщения и модалки подключаете через события и `FetchIt.Message`. В документации есть готовые примеры под Bootstrap, Bulma, UIKit, Notyf, SweetAlert2 и другие.

## Возможности

- **FormIt из коробки.** Параметры вроде `&hooks`, `&validate`, `&emailTo` передаются в FormIt без обёрток.
- **Свой сниппет.** В `&snippet` указываете обработчик, который возвращает JSON (`success`, `message`, `data`).
- **Ошибки полей.** Элементы с `data-error="fieldName"` получают текст валидации; к полям можно повесить CSS-классы из системных настроек.
- **События.** `fetchit:before` (можно отменить отправку и дополнить `FormData`), `fetchit:after`, `fetchit:success`, `fetchit:error`, `fetchit:reset`.
- **Уведомления.** Свой `FetchIt.Message` или встроенный [Notyf](https://carlosroso.com/notyf/) через настройку `fetchit.frontend.default.notifier`.
- **Несколько форм на странице.** Каждый вызов сниппета получает свой ключ `data-fetchit`, каждая форма свой экземпляр обработчика.
- **Защита от спама.** Включена по умолчанию: одноразовый подписанный токен, минимальное время заполнения, скрытое поле-ловушка и лимит отправок, и при отправке через FetchIt, и без JavaScript. Свои правила добавляются плагином на событие `OnFetchItBeforeProcess`.
- **Очистка после успеха.** Параметр `&clearFieldsOnSuccess` (по умолчанию включён).
- **Fenom.** Вызов через pdoTools/`{'!FetchIt' | snippet}` поддерживается.

Минимальный вызов:

```modx
[[!FetchIt?
  &snippet=`FormIt`
  &form=`myForm.tpl`
  &hooks=`email`
  &emailTo=`info@domain.com`
  &validate=`name:required,email:required`
  &successMessage=`Сообщение отправлено`
]]
```

## Защита от спама

Каждая форма FetchIt защищена без настройки. Проверки проходят до FormIt или вашего сниппета, и при отправке через FetchIt, и при обычной отправке формы без JavaScript:

- **Токен.** В форму добавляется скрытое поле `fetchit_token` с подписью ключа формы, времени вывода и случайного числа. Токен одноразовый и не требует сессии. Новый токен выдаётся только в ответ на правильно подписанный токен этой формы, так что бот без загрузки страницы не отправит форму. Каждый ответ через FetchIt приносит следующий токен, и форму можно отправлять повторно без перезагрузки.
- **Время заполнения.** Форма, отправленная быстрее `fetchit.protection.min_time` секунд после вывода страницы (по умолчанию 3), отклоняется. Повторная отправка считается от того же вывода, поэтому человек, который исправил поле после ошибки, отказ не получит.
- **Ловушка.** Скрытое поле со случайным для каждой установки именем люди не видят, а автозаполнение браузера его не узнаёт. Если оно заполнено, бот получает ответ об успешной отправке с `successMessage` формы, но форма не обрабатывается и письмо не уходит. Такие случаи пишутся в журнал.
- **Лимит.** Не больше `fetchit.protection.rate_limit` отправок одной формы с одного адреса за `fetchit.protection.rate_window` секунд (по умолчанию 10 за 10 минут). Считаются все попытки с правильно подписанным токеном, в том числе отклонённые, так что повтором старого токена лимит не обойти.

Служебные поля убираются из `$_POST` до FormIt, в письма они не попадают. Ключ подписи генерируется при установке пакета (`fetchit.protection.secret`).

### Что видит посетитель

Отказ приходит как обычная ошибка формы: сообщение `fetchit_err_token` (форма устарела), `fetchit_err_too_fast`, `fetchit_err_rate` или `fetchit_err_store` из лексикона, событие `fetchit:error` и уведомление. Если токен страницы устарел (страница из кеша, была долго открыта, сменился ключ), FetchIt сам один раз отправит форму заново с новым токеном, и посетитель ничего не заметит. Без JavaScript сообщение выводится в `[[+fi.validation_error_message]]`, а введённые значения сохраняются, как в примере `tpl.FetchIt.example`.

### Что стоит учесть

- **Кеш всей страницы** (nginx, CDN, плагины статического кеша) отдаёт всем посетителям один и тот же токен. Через FetchIt это работает за счёт повторной отправки, но страницы с формами лучше исключить из такого кеша: без JavaScript первая отправка будет отклонена.
- **Формы, загруженные через AJAX,** получают токен, только если их выводит некешируемый вызов `[[!FetchIt]]`.
- **Свой JS вместо встроенного скрипта** должен отправлять поле `fetchit_token` из формы и после каждого ответа брать следующий токен из заголовка `X-FetchIt-Token`. Если ответ пришёл с заголовком `X-FetchIt-Refused: token`, отправьте форму ещё раз с новым токеном.
- **За прокси или CDN** все посетители приходят с одного адреса, и лимит становится общим на сайт. Перечислите адреса прокси в `fetchit.protection.proxies` (IP или CIDR через запятую) и заголовок с адресом посетителя в `fetchit.protection.ip_header` (`X-Forwarded-For`, `CF-Connecting-IP`).
- **Отметки использованных токенов** хранятся в `core/cache/fetchit/tokens/`, «Очистить кеш» их не трогает. Если папка недоступна для записи, формы отклоняются, а причина пишется в журнал. На нескольких серверах без общего `core/cache` токен можно использовать по разу на каждом сервере.
- **Журнал:** `fetchit.protection.log` 0 выключает его, 1 (по умолчанию) пишет проблемы и отказы, которые могут задеть людей (ловушка, лимит, запись отметок), 2 пишет все отказы.
- **На сайте для разработки** и в автотестах поставьте `fetchit.protection.min_time` и `fetchit.protection.rate_limit` в 0. Выключать всю защиту (`fetchit.protection`) стоит только для отладки.

### Свои правила

Плагин на событие `OnFetchItBeforeProcess` получает `$action`, `$fields` (при отправке через FetchIt без файлов), `$properties` (может быть `null`) и `$FetchIt`. Чтобы отклонить отправку, он выводит сообщение для посетителя или ключ лексикона через `$modx->event->output()`. Возврат строки через `return` отправку не отклоняет: MODX только запишет её в журнал.

```php
// Плагин на событие OnFetchItBeforeProcess
$email = isset($fields['email']) && is_string($fields['email']) ? $fields['email'] : '';
if (preg_match('/@(mailinator|tempmail)\./i', $email)) {
    $modx->event->output('Одноразовые адреса не принимаются.');
}
```

Событие срабатывает и при выключенной защите.

## Ветки и версии

| Ветка | Пакет | MODX |
|-------|-------|------|
| `master` | 1.x | 2.x |
| `4.x` | 4.x (в разработке) | 2.x и 3.x |
| `next` | 3.x | 3.x |

FetchIt 4 будет одним пакетом для MODX 2 и MODX 3 и заменит линии 1.x и 3.x. Он разрабатывается в ветке `4.x`; с выходом 4.0 она станет `master`, а `next` будет заморожена.

На [extras.modx.com](https://extras.modx.com/package/fetchit): **3.1.2-pl** (MODX 3) и **1.1.2-pl** (MODX 2).

## Переход на FetchIt 4

FetchIt 4 это один пакет для MODX 2.8 и MODX 3 вместо линий 1.x и 3.x. Он ставится поверх установленной версии через Менеджер пакетов: системные настройки и чанки остаются как есть. Сниппет и плагин FetchIt при обновлении заменяются, так что правки в их коде пропадут; вызовы сниппета на страницах менять не нужно. Обновление проверяется в CI с 1.1.3 на MODX 2 и с 3.1.4 на MODX 3.

Прежний API работает на обеих версиях MODX:

- в своих сниппетах берите FetchIt так: `$FetchIt = FetchIt::service($modx);`. Вызовы из 1.x (`$modx->getService('fetchit', 'FetchIt', MODX_CORE_PATH . 'components/fetchit/model/')`) и из 3.x (`$modx->services->get('FetchIt')`, только на MODX 3) возвращают тот же объект;
- класс `FetchIt\FetchIt` из 3.x это тот же класс, `instanceof \FetchIt\FetchIt` работает. `get_class()` теперь возвращает `FetchIt`;
- `storeActionProperties()`/`loadActionProperties()` и их имена из 3.x `saveActionProperties()`/`getActionProperties()`;
- чанки через pdoTools (Fenom, `@FILE`) на MODX 2 и MODX 3.

Что может задеть ваш код:

- `fetchit:error` срабатывает и при сбое запроса; тогда `detail.response` равен `null`, а ошибка лежит в `detail.error`;
- обрабатывающий сниппет получает в `fields` только отправленную форму: `$_POST` и файлы из `$_FILES` при отправке через FetchIt, только `$_POST` при обычной отправке формы; без GET-параметров и cookies;
- `fetchit:success` можно отменить: `event.preventDefault()` оставит поля заполненными;
- повторная отправка, пока идёт запрос, игнорируется;
- `method` и `data-fetchit` ставятся последними атрибутами тега формы.

Полный список изменений в [changelog](core/components/fetchit/docs/changelog.txt).

## Документация

Подробная [документация](https://docs.modx.pro/components/fetchit/) с примерами: разметка, уведомления, модалки, клиентская валидация, JS API.

# Установка

Компонент бесплатно ставится через Менеджер пакетов из:

- маркетплейса [modstore.pro](https://modstore.pro/packages/utilities/fetchit)
  - [инструкция](https://modstore.pro/faq) по подключению репозитория
- официального репозитория [modx.com](https://modx.com/extras/package/fetchit)

Либо соберите transport-пакет из `_build/` в этом репозитории.

# Разработка

Нужны Node.js 22.22+ или 24.15+ (версия для CI в `.node-version`), PHP 7.4+ с Composer и Docker.

```sh
npm ci && composer install

npm run build       # src/index.ts → assets/components/fetchit/js/, Notyf → assets/components/fetchit/lib/
npm run lint        # oxlint
npm run typecheck   # tsc
npm test            # Vitest
vendor/bin/phpunit  # PHPUnit
```

`npm run build` нужен и перед сборкой пакета: папки `lib/` нет в git, без неё `build.php` остановится с ошибкой.

## Локальные сайты

Два сайта, MODX 2.8.6 на PHP 7.4 и MODX 3.2.4 на PHP 8.3. Папки компонента из репозитория подключены в оба, поэтому правки PHP и собранного JS видны сразу. Правки в `src/` видны после `npm run build`.

```sh
# если ваши uid/gid не 1000: export HOST_UID=$(id -u) HOST_GID=$(id -g)
docker compose up -d --build
docker compose logs -f modx2 modx3   # дождаться строк «[fetchit] Manager: …»
# MODX 2: http://localhost:8052/, MODX 3: http://localhost:8053/ (admin / FetchItDev2026)
```

Пакет собирается на MODX 2 и ставится на оба сайта так же, как в CI:

```sh
docker compose exec -u www-data -e PKG_DIST=1 modx2 php /extra/_build/build.php
docker compose exec -u www-data modx2 php /extra/_build/ci/install.php /extra/_packages/fetchit-<версия>-<релиз>.transport.zip
docker compose exec -u www-data modx3 php /extra/_build/ci/install.php /extra/_packages/fetchit-<версия>-<релиз>.transport.zip
```

Без `PKG_DIST=1` `build.php` сразу ставит пакет на тот сайт, где его собирают.

Проверки через HTTP и браузерные тесты на локальном сайте:

```sh
fixtures=$(docker compose exec -T -u www-data modx2 php /extra/_build/ci/fixtures.php)
_build/ci/smoke.sh http://localhost:8052 "$fixtures"
npx playwright install chromium
BASE_URL=http://localhost:8052 FIXTURES="$fixtures" npm run e2e
```

## CI и релизы

CI проверяет каждый PR: синтаксис PHP 7.4–8.4, PHPUnit, линтер, типы, тесты и актуальность собранного JS, workflow и shell-скрипты, согласованность версий. Затем собирает пакет на MODX 2.8.6, ставит его на MODX 2.8.6 и 3.2.4 и отправляет формы через HTTP (с анонимными сессиями и без) и из браузера (Playwright), в том числе со встроенным уведомителем.

Релиз:

1. Поднять версию в `_build/config.inc.php` и `core/components/fetchit/model/fetchit.class.php`, в `package.json` и `package-lock.json` через `npm version X.Y.Z --no-git-tag-version`.
2. Добавить в `core/components/fetchit/docs/changelog.txt` раздел `## [X.Y.Z] - ГГГГ-ММ-ДД`.
3. Слить это в `master` и запушить тег: `git tag vX.Y.Z && git push origin vX.Y.Z`.

Workflow проверит версию, соберёт пакет, прогонит его на MODX 2 и 3 и только потом создаст GitHub-релиз с пакетом. Заметки к релизу собираются из коммитов `feat`, `fix`, `perf` и `refactor` ([conventional commits](https://www.conventionalcommits.org/ru/)); если таких нет, берётся раздел из changelog.

---

💗 Угостить автора чашкой кофе: [cloudtips.ru](https://pay.cloudtips.ru/p/d4668b6e)
