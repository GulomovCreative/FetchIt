# FetchIt

Компонент для MODX Revolution для отправки форм с помощью Fetch API.

![Логотип FetchIt](https://github.com/GulomovCreative/FetchIt/blob/master/fetchit-logo.svg?raw=true&v=3)

В CMS/CMF MODX Revolution есть компонент [FormIt](https://github.com/Sterc/FormIt): он отправляет и обрабатывает формы стандартным методом браузера, с перезагрузкой страницы. FetchIt использует FormIt (или ваш сниппет) и обрабатывает формы «на лету» через Fetch API.

Близкий по серверной части аналог — [AjaxForm](https://github.com/modx-pro/AjaxForm). У FetchIt другие плюсы на фронте:

## Никаких зависимостей

FetchIt не тянет внешних JS-библиотек. У AjaxForm их три: [jQuery](https://github.com/jquery/jquery), [jquery-form](https://github.com/jquery-form/form/) и [jGrowl](https://github.com/stanlemon/jGrowl).

Уведомления (jGrowl) можно переопределить и в AjaxForm. jQuery и jquery-form заменить сложнее.

## Современный код

Минифицированный скрипт весит около 6 КБ, в gzip меньше 2 КБ. Сниппет подключает его с атрибутом `defer`, чтобы не блокировать загрузку страницы. На фронте — нативный Fetch API и `FormData` (включая файлы).

## Удобство

Свою вёрстку оставляете как есть: достаточно чанка формы и атрибутов `data-error`. Всплывающие сообщения и модалки подключаете через события и `FetchIt.Message`. В документации есть готовые примеры под Bootstrap, Bulma, UIKit, Notyf, SweetAlert2 и другие.

## Возможности

- **FormIt из коробки.** Параметры вроде `&hooks`, `&validate`, `&emailTo` передаются в FormIt без обёрток.
- **Свой сниппет.** В `&snippet` указываете обработчик, который возвращает JSON (`success`, `message`, `data`).
- **Ошибки полей.** Элементы с `data-error="fieldName"` получают текст валидации; к полям можно повесить CSS-классы из системных настроек.
- **События.** `fetchit:before` (можно отменить отправку и дополнить `FormData`), `fetchit:after`, `fetchit:success`, `fetchit:error`, `fetchit:reset`.
- **Уведомления.** Свой `FetchIt.Message` или встроенный [Notyf](https://carlosroso.com/notyf/) через настройку `fetchit.frontend.default.notifier`.
- **Несколько форм на странице.** Каждая форма получает свой `data-fetchit` и экземпляр обработчика.
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

Нужны Node.js 22.12+ (версия для CI — в `.node-version`), PHP 7.4+ с Composer и Docker.

```sh
npm ci && composer install

npm run build       # src/index.ts → assets/components/fetchit/js/ (rolldown)
npm run lint        # oxlint
npm run typecheck   # tsc
npm test            # Vitest
vendor/bin/phpunit  # PHPUnit
```

Локально поднимаются два сайта — MODX 2.8.6 на PHP 7.4 и MODX 3.2.4 на PHP 8.3; папки компонента из репозитория подключены в оба:

```sh
docker compose up -d --build
# MODX 2: http://localhost:8052/, MODX 3: http://localhost:8053/ (admin / FetchItDev2026)

# пакет собирается на MODX 2 и ставится на оба сайта
docker compose exec -u www-data -e PKG_DIST=1 modx2 php /extra/_build/build.php
docker compose exec -u www-data modx2 php /extra/_build/ci/install.php /extra/_packages/fetchit-<версия>.transport.zip
docker compose exec -u www-data modx3 php /extra/_build/ci/install.php /extra/_packages/fetchit-<версия>.transport.zip
```

CI проверяет каждый PR: синтаксис PHP 7.4–8.4, PHPUnit, линтер, типы, тесты и актуальность собранного JS, workflow и shell-скрипты, согласованность версий. Затем собирает пакет на MODX 2.8.6, ставит его на MODX 2.8.6 и 3.2.4 и отправляет формы через HTTP и из браузера (Playwright).

Релиз: поднять версию в `_build/config.inc.php`, `core/components/fetchit/model/fetchit.class.php` и `package.json`, добавить раздел в `core/components/fetchit/docs/changelog.txt` и опубликовать GitHub-релиз с тегом `vX.Y.Z`. Пакет и заметки из истории коммитов ([conventional commits](https://www.conventionalcommits.org/ru/)) прикрепятся к релизу автоматически.

---

💗 Угостить автора чашкой кофе: [cloudtips.ru](https://pay.cloudtips.ru/p/d4668b6e)
