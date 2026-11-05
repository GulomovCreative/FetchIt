# FetchIt 4.0.0 — что и куда загружать

Пакет 4.0.0-pl на обеих площадках уже лежит, а тексты карточек остались от версий 1.x и
3.x. Здесь — что заменить и чем.

> Описание на карточке сейчас вставлено в разметке, которую редактор modstore переделал:
> инлайновый код стал плашками во всю ширину, а в блоках кода виден `<code>` текстом.
> Файлы `modstore.*.description.html` пересобраны по правилам редактора — нужно вставить
> их заново.

## Пакет

| | |
|---|---|
| Файл | `fetchit-4.0.0-pl.transport.zip` (98 КБ) из [релиза v4.0.0](https://github.com/GulomovCreative/FetchIt/releases/tag/v4.0.0) |
| Версия / релиз | `4.0.0` / `pl` |
| Требования | MODX Revolution 2.8+ или 3.x, PHP 7.4+ |

Архив собирает GitHub Actions при публикации релиза. Локальный `_packages/fetchit-4.0.0-alpha.transport.zip`
— от пробной сборки, загружать его не нужно.

## modstore.pro

Пакет 4.0.0-pl опубликован 25.09.2026, менять нужно только карточку. Тексты —
[`modstore.ru.md`](modstore.ru.md) и [`modstore.en.md`](modstore.en.md).

| Поле формы | Что вставить | Что там сейчас |
|------------|--------------|----------------|
| Краткое описание | Формы без перезагрузки страницы, с защитой от спама из коробки. | «Легковесный компонент для обработки и отправки форм с помощью Fetch API» |
| Описание | целиком файл [`modstore.ru.description.html`](modstore.ru.description.html) | вставленный вариант с плашками кода посреди предложений — заменить целиком |
| Описание (In English) | целиком файл [`modstore.en.description.html`](modstore.en.description.html) | то же по-английски |
| Теги | formit, AjaxForm, формы, fetch, ajax, e-mail, защита от спама, капча, Turnstile, reCAPTCHA, SmartCaptcha, MODX 3, TypeScript, бесплатный | formit, AjaxForm, формы, e-mail |
| Логотип | `.github/logo/modstore@2x.png` — 640×480 | картинка старого логотипа |
| Документация | https://docs.modx.pro/components/fetchit/ | она же |
| GitHub | https://github.com/GulomovCreative/FetchIt | он же |

Картинка в описании уже лежит на file.modx.pro и вставлена ссылкой — загружать её не нужно.

## extras.modx.com

Версия 4.0.0-pl загружена и ждёт одобрения («This version of the Extra is not yet
approved»), заметки к релизу заполнены. Остальное на странице — от версии 1.x. Текст —
[`extras.en.md`](extras.en.md), русский вариант рядом.

| Поле формы | Что вставить | Что там сейчас |
|------------|--------------|----------------|
| Tagline | MODX forms without a page reload, protected from spam out of the box. | «An lightweight Extra for processing forms using the Fetch API» |
| About | раздел «Field: Description» из [`extras.en.md`](extras.en.md) | описание 1.x про FormIt и перезагрузку страницы |
| Supported Versions | 2.8 — Current | 2.7 — Current, а FetchIt 4 требует 2.8 |
| Tags | forms, formit, ajax, fetch, spam protection, captcha, turnstile, recaptcha, smartcaptcha, typescript, modx3, modx2 | — |
| Screenshots | файлы из `docs/images/`, порядок в конце `extras.en.md` | пусто |

## Скриншоты

Все — из `docs/images/`, пересоздаются `npm run screenshots`.

| Файл | Размер | Подпись |
|------|--------|---------|
| `notifier-readme-ru.png` / `-en.png` | 2000×1240 | Встроенные уведомления: ошибка и успешная отправка |
| `notifier-desktop-ru.png` / `-en.png` | 2560×1600 | Страница с формой целиком |
| `notifier-mobile-ru.png` / `-en.png` | 780×1328 | Уведомления на телефоне |
| `notifier-dark-ru.png` / `-en.png` | 2560×1600 | Уведомления на тёмной странице |
| `toast-success-*.png`, `toast-error-*.png` | 160 в высоту | Одно уведомление крупно |

## Чего пока нет

- **Документация описывает FetchIt 3.x.** На docs.modx.pro в `settings.md` перечислены
  только настройки `fetchit.frontend.*`: ни защиты от спама, ни капчи, ни доказательства
  работы, ни новых уведомлений там нет, а страницы про уведомления учат подключать Notyf.
  Обе карточки ведут на эту документацию.
- **Версия в тексте не упоминается нигде специально** — карточки описывают компонент как
  он есть, поэтому при следующем релизе править их нужно только там, где меняются
  возможности.
