# FetchIt

Компонент для MODX Revolution для отправки форм с помощью Fetch API.

![Логотип FetchIt](https://github.com/GulomovCreative/FetchIt/blob/master/fetchit-logo.svg?raw=true&v=3)

В CMS/CMF MODX Revolution есть компонент [FormIt](https://github.com/Sterc/FormIt): он отправляет и обрабатывает формы стандартным методом браузера, с перезагрузкой страницы. FetchIt использует FormIt (или ваш сниппет) и обрабатывает формы «на лету» через Fetch API.

Близкий по серверной части аналог — [AjaxForm](https://github.com/modx-pro/AjaxForm). У FetchIt другие плюсы на фронте:

## Никаких зависимостей

FetchIt не тянет внешних JS-библиотек. У AjaxForm их три: [jQuery](https://github.com/jquery/jquery), [jquery-form](https://github.com/jquery-form/form/) и [jGrowl](https://github.com/stanlemon/jGrowl).

Уведомления (jGrowl) можно переопределить и в AjaxForm. jQuery и jquery-form заменить сложнее.

## Современный код

Минифицированный скрипт весит около 4 КБ. Сниппет подключает его с атрибутом `defer`, чтобы не блокировать загрузку страницы. На фронте — нативный Fetch API и `FormData` (включая файлы).

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

---

💗 Угостить автора чашкой кофе: [cloudtips.ru](https://pay.cloudtips.ru/p/d4668b6e)
