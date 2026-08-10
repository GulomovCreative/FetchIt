# FetchIt

Компонент для MODX Revolution для отправки форм с помощью Fetch API.

![Логотип FetchIt](https://github.com/GulomovCreative/FetchIt/blob/master/fetchit-logo.svg?raw=true&v=3)

В CMS/CMF MODX Revolution есть компонент [FormIt](https://github.com/Sterc/FormIt): он отправляет и обрабатывает формы стандартным методом браузера, с перезагрузкой страницы. FetchIt использует FormIt (или ваш сниппет) и обрабатывает формы «на лету» через Fetch API.

Близкий по серверной части аналог — [AjaxForm](https://github.com/modx-pro/AjaxForm). У FetchIt другие плюсы на фронте:

## Никаких зависимостей

FetchIt не тянет внешних JS-библиотек. У AjaxForm их три: [jQuery](https://github.com/jquery/jquery), [jquery-form](https://github.com/jquery-form/form/) и [jGrowl](https://github.com/stanlemon/jGrowl).

Уведомления (jGrowl) можно переопределить и в AjaxForm. jQuery и jquery-form заменить сложнее.

## Современный код

Минифицированный скрипт весит около 4 КБ. Сниппет подключает его с атрибутом `defer`, чтобы не блокировать загрузку страницы.

## Удобство

Свою вёрстку, всплывающие сообщения и модальные окна подключаете через события и JS API. Примеры есть в документации.

## Ветки и версии

| Ветка | Пакет | MODX |
|-------|-------|------|
| `master` | 1.x | 2.x |
| `next` | 3.x | 3.x |

На [extras.modx.com](https://extras.modx.com/package/fetchit): **3.1.2-pl** (MODX 3) и **1.1.2-pl** (MODX 2).

## Документация

Подробная [документация](https://docs.modx.pro/components/fetchit/) с примерами: разметка, уведомления, модалки, валидация.

# Установка

Компонент бесплатно ставится через Менеджер пакетов из:

- маркетплейса [modstore.pro](https://modstore.pro/packages/utilities/fetchit)
  - [инструкция](https://modstore.pro/faq) по подключению репозитория
- официального репозитория [modx.com](https://modx.com/extras/package/fetchit)

Либо соберите transport-пакет из `_build/` в этом репозитории.

---

💗 Угостить автора чашкой кофе: [cloudtips.ru](https://pay.cloudtips.ru/p/d4668b6e)
