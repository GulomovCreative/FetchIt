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

## Ветки и версии

| Ветка | Пакет | MODX |
|-------|-------|------|
| `master` | 1.x | 2.x |
| `next` | 3.x | 3.x |

На [extras.modx.com](https://extras.modx.com/package/fetchit): **3.1.2-pl** (MODX 3) и **1.1.2-pl** (MODX 2).

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
docker compose exec -u www-data modx2 php /extra/_build/ci/install.php /extra/_packages/fetchit-<версия>-pl.transport.zip
docker compose exec -u www-data modx3 php /extra/_build/ci/install.php /extra/_packages/fetchit-<версия>-pl.transport.zip
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
