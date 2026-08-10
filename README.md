# FetchIt

Компонент [MODX Revolution](https://modx.com/) для отправки форм через [Fetch API](https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API) без перезагрузки страницы.

![Логотип FetchIt](https://github.com/GulomovCreative/FetchIt/blob/master/fetchit-logo.svg?raw=true&v=3)

На сервере по умолчанию вызывается [FormIt](https://github.com/Sterc/FormIt). Можно указать свой сниппет.

## Особенности

- без jQuery, [jquery-form](https://github.com/jquery-form/form/) и [jGrowl](https://github.com/stanlemon/jGrowl) (в отличие от [AjaxForm](https://github.com/modx-pro/AjaxForm))
- минифицированный скрипт ~4 КБ, подключается с атрибутом `defer`
- своя вёрстка, уведомления и модалки через события и JS API

## Ветки и версии

| Ветка | Пакет | MODX |
|-------|-------|------|
| `master` | 1.x | 2.x |
| `next` | 3.x | 3.x |

На [extras.modx.com](https://extras.modx.com/package/fetchit): **3.1.2-pl** (MODX 3) и **1.1.2-pl** (MODX 2).

## Документация

[docs.modx.pro/components/fetchit](https://docs.modx.pro/components/fetchit/)

## Установка

Через Менеджер пакетов:

- [modstore.pro](https://modstore.pro/packages/utilities/fetchit) ([как подключить репозиторий](https://modstore.pro/faq))
- [modx.com](https://modx.com/extras/package/fetchit)

Либо соберите transport-пакет из `_build/` в этом репозитории.

---

Поддержать автора: [cloudtips.ru](https://pay.cloudtips.ru/p/d4668b6e)
