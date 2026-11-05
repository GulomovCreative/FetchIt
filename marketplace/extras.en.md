# FetchIt — extras.modx.com listing (EN)

The text for the fields of the form on extras.modx.com. Pictures are in `docs/images/`,
the logo in `.github/logo/`.

---

## Field: Extra Name

FetchIt

## Field: Logo

> Uploaded as a file in the form, not as a link.

`.github/logo/modstore@2x.png` — 640×480; next to it `modstore.png` 320×240.

## Field: Tagline / Short description

> Up to 85 characters.

MODX forms without a page reload, protected from spam out of the box.

## Field: Category

Utilities / Forms

## Field: Tags

forms, formit, ajax, fetch, spam protection, captcha, turnstile, recaptcha, smartcaptcha, typescript, modx3, modx2

## Field: License

GPL-2.0-only

## Field: Supported versions

MODX Revolution 2.8+ and 3.x, PHP 7.4+

---

## Field: Description

FetchIt submits the forms of a MODX site with the Fetch API: no page reload, no jQuery or other libraries. The forms are processed by FormIt, with all its hooks and validation, or by a snippet of your own that returns `success`, `message` and `data`. One package installs on MODX 2.8 and on MODX 3.

**Your own markup.** All it takes is a form chunk with attributes: `data-error` for the errors of fields, `data-success` and `data-validation-error` for the messages of the form. The classes of invalid fields are system settings, and chunks go through pdoTools, with Fenom and `@FILE`, on both versions of MODX. A ready example comes with the package.

**Spam protection, on by default.** Every form gets a signed single-use token that needs no session, a minimum fill time, a hidden trap field with a random name per installation, and a limit of submissions per form and address, which also works behind trusted proxies and CDNs. The checks run before FormIt and before your snippet, for submissions through FetchIt and for forms sent without JavaScript alike, and the service fields are removed from `$_POST` before FormIt reads them. When the token of a page is stale — a page from a cache, a page open for long, a new key — the script sends the form once more with a new token by itself.

**Proof of work and captcha**, both off by default, for when spam gets through the main checks. The proof of work asks the browser for a SHA-256 with a given number of zero bits, solved while the visitor fills the form, so every submission costs a bot processor time. The captcha is Cloudflare Turnstile, Google reCAPTCHA v3 or Yandex SmartCaptcha: FetchIt adds the provider's script, gets its answer before sending and checks it on the server last, so bots without a token never reach the provider.

**Rules of your own.** A plugin on the `OnFetchItBeforeProcess` event gets the fields sent and the properties of the form and refuses a submission with a message for the visitor.

**Without JavaScript** a form with FormIt is sent the usual way, and the messages and the values entered come back through its placeholders — the protection included. Only the proof of work and the captcha need JavaScript.

**Notifications.** The answers of the server can be shown as notifications in a corner of the page. They are FetchIt's own: no library is loaded. Screen readers hear them from two live regions, a notification stays while hovered or focused, and its colours are CSS variables. A site that shows messages its own way sets `FetchIt.Message` and uses any library.

**Events and types.** `fetchit:before`, `fetchit:after`, `fetchit:success`, `fetchit:error` and `fetchit:reset` are dispatched on `document` with the form, its data and the answer of the server, and any of them can be cancelled. TypeScript types ship next to the script, and the script is type-checked against them.

**No dependencies.** The native Fetch API and `FormData`, files included. The minified script is 19 KB, about 7 KB in gzip, loaded with `defer`. A page can hold as many forms as you like, each with its own key and handler.

**Upgrading.** FetchIt 4 replaces the 1.x line for MODX 2 and the 3.x line for MODX 3 and installs over either of them, keeping the system settings and chunks; the snippet calls need no changes. The API of both lines works on both versions of MODX. What may affect your code is listed in the README, under Upgrading to FetchIt 4.

## Field: Requirements

- MODX Revolution 2.8+ or 3.x (checked on 2.8.6 and 3.2.4)
- PHP 7.4+
- FormIt, when it processes the forms; it is installed with FetchIt
- pdoTools, for form chunks on Fenom or in files

## Field: System settings

All settings are in the `fetchit` namespace: the script and the classes of invalid fields (`fetchit.frontend.*`), the spam protection (`fetchit.protection*`: the fill time, the token lifetime, the limit and its window, trusted proxies, the log, the proof of work) and the captcha (`fetchit.captcha*`: the provider, the keys, the minimum score). The full table is in the README.

## Field: Links

- Repository: https://github.com/GulomovCreative/FetchIt
- Documentation: https://docs.modx.pro/components/fetchit/ (in Russian)
- Issues: https://github.com/GulomovCreative/FetchIt/issues

---

## Pictures

| # | File | Caption |
|---|------|---------|
| 1 | `docs/images/notifier-readme-en.png` | The built-in notifications of FetchIt: an error and a successful submission |
| 2 | `docs/images/notifier-mobile-en.png` | The notifications on a phone |
| 3 | `docs/images/notifier-dark-en.png` | The notifications on a dark page |
