# FetchIt — modstore.pro package page (EN)

The text for the fields of the package form. Pictures are in `docs/images/`, the logo in
`.github/logo/`.

---

## Field: Name

FetchIt

## Field: Logo

> Uploaded as a file in the form, not as a link.

`.github/logo/modstore@2x.png` — 640×480; next to it `modstore.png` 320×240.

## Field: Short description

> Up to 85 characters. Shown in the list of extras.

Forms without a page reload, protected from spam out of the box.

## Field: Category

Utilities (`utilities`)

## Field: Tags

formit, AjaxForm, forms, fetch, ajax, e-mail, spam protection, captcha, Turnstile, reCAPTCHA, SmartCaptcha, MODX 3, TypeScript, free

## Field: Price

Free (GPL-2.0)

---

## Field: Full description

> **modstore takes HTML, not markdown.** The version ready to paste is in
> [`modstore.en.description.html`](modstore.en.description.html); it is built from this
> section with `node marketplace/build.mjs`. Edit the markdown, rebuild the html.

FetchIt submits the forms of a site with the Fetch API: no page reload, no jQuery or other libraries. The forms are processed by FormIt, with all its hooks and validation, or by a snippet of your own. Every form is protected from spam from the start: a single-use token, a fill time, a trap field and a limit of submissions, and a proof of work and a captcha when you need them. One package installs on MODX 2.8 and on MODX 3.

### The snippet call

The call must be uncached: every output of the form has a token of its own.

```
[[!FetchIt?
  &form=`myForm.tpl`
  &hooks=`email`
  &emailTo=`info@example.com`
  &validate=`name:required,email:email:required`
  &successMessage=`Your message has been sent`
]]
```

The properties `&hooks`, `&validate`, `&emailTo` and the rest go to FormIt as they are, with no wrappers. Instead of FormIt you can name a snippet of your own: it gets the fields sent and answers through `$FetchIt->success()` or `$FetchIt->error()`.

### Your own markup

The component brings no ready-made form: all it takes is a chunk with attributes. `data-error="name"` gets the error of the field, the field itself gets `aria-invalid` and the class from a system setting, and `data-success` and `data-validation-error` get the message of the form.

```
<form action="[[~[[*id]]]]" method="post">
  <input type="email" name="email" value="[[+fi.email]]">
  <span data-error="email">[[+fi.error.email]]</span>
  <button type="submit">Send</button>
</form>
```

Chunks go through pdoTools, with Fenom and `@FILE`, on MODX 2 and MODX 3. A ready example, `tpl.FetchIt.example`, comes with the package.

### Spam protection out of the box

The checks run before FormIt and before your snippet, both for FetchIt submissions and for a form sent without JavaScript.

- **Token.** The form gets a hidden field with a signature of the key of the form, the time it was output and a random number. A token is used once and needs no session. A new one is given only in answer to a well-signed token of the same form, so a bot cannot send the form without loading the page.
- **Fill time.** A form sent sooner than `fetchit.protection.min_time` seconds after the page was output (3 by default) is refused.
- **Trap.** A hidden field with a random name per installation: people do not see it and browser autofill does not recognise it. A bot that fills it gets a success answer, but the form is not processed and no email goes out.
- **Limit.** At most `fetchit.protection.rate_limit` submissions of a form from one address within `fetchit.protection.rate_window` seconds — 10 in 10 minutes by default. Every attempt with a well-signed token counts, refused ones too.

The service fields are removed from `$_POST` before FormIt runs and never reach emails. The signing key is generated at install. Behind a proxy or a CDN the visitor's address is taken from the header named in the settings — otherwise the limit would be one for the whole site.

A refusal comes as an ordinary error of the form: a message from the lexicon, the `fetchit:error` event and a notification. When the token of the page is stale — a page from a cache, a page open for long, a new key — FetchIt sends the form once more with a new token by itself, and the visitor notices nothing.

### Proof of work and captcha

Both are off by default and help when spam gets through the main checks.

**Proof of work.** `fetchit.protection.pow` sets the difficulty in bits. The browser looks for a number whose SHA-256 of `token:number` starts with that many zero bits and sends it with the form. Solving starts as soon as the visitor enters the form, so the solution is usually ready by the time they press the button, while every submission costs a bot processor time. 16 bits are about 65 thousand hashes, about a third of a second on a computer; every bit doubles the time.

**Captcha.** Cloudflare Turnstile, Google reCAPTCHA v3 or Yandex SmartCaptcha, chosen with a setting, with the keys from the provider's dashboard. FetchIt adds the provider's script, gets its answer before sending and checks it on the server last among the checks, so bots without a token never reach the provider. When the provider is unavailable or does not accept the key, the form is refused with a message of its own and the cause is logged.

### It works without JavaScript too

With FormIt the form is sent the usual way, and the messages and the values entered come back through its placeholders. The protection works here as well. The exceptions are the proof of work and the captcha: those need JavaScript.

### Notifications

With `fetchit.frontend.default.notifier` the answers of the server are shown as notifications in a corner of the page. The component pulls in no libraries of its own: the notifications are its own.

![The built-in notifications of FetchIt: an error and a successful submission](https://file.modx.pro/files/f/f/8/ff89684caf0c699222cbaf7c8b84fb68.png)

Screen readers hear them from two hidden live regions: an error at once, a success when the reader is idle. A notification closes with its button or by itself after six seconds, and the countdown stops while the pointer or the focus is on it. The colours are CSS variables, by default the pastel shades of Tailwind CSS 4 with a text contrast of about 6.5:1. When the site shows messages its own way, `FetchIt.Message` takes any library: Bootstrap, SweetAlert2, anything.

### Events and TypeScript

`fetchit:before`, `fetchit:after`, `fetchit:success`, `fetchit:error` and `fetchit:reset` are dispatched on `document`, with the form, its data and the answer of the server in `detail`. Any of them can be cancelled: add fields before sending, show a modal, send an event to analytics.

```
document.addEventListener('fetchit:success', ({ detail }) => {
  gtag('event', 'form_submit', { form_id: detail.form.id })
})
```

The TypeScript types ship next to the script — `fetchit.d.ts` with the class, the instances of forms and the `detail` of every event. The script itself is type-checked against that same file, so the types and the code cannot drift apart.

### No dependencies

The script needs no jQuery or other libraries: the native Fetch API and `FormData`, files included. Minified it is 19 KB, about 7 KB over the network in gzip. It is loaded with `defer` and loads nothing else, except the script of the captcha provider when a captcha is on. A page can hold as many forms as you like, each with its own key and its own handler.

### One package for MODX 2.8 and MODX 3

There used to be two: the 1.x line for MODX 2 and 3.x for MODX 3. FetchIt 4 installs over either of them, and the system settings and chunks stay as they are; the snippet calls on your pages need no changes. The API of the earlier versions works on both versions of MODX: `FetchIt::service($modx)`, the `FetchIt\FetchIt` class, the earlier names of the methods.

What may affect your code: forms get the service fields of the protection, so a script of your own instead of the bundled one must send the token; the notifications no longer load Notyf; `fetchit:error` now fires on a failed request too. The full list is in the changelog and in the README on GitHub.

---

## Field: Requirements

- MODX Revolution 2.8+ or 3.x (checked on 2.8.6 and 3.2.4)
- PHP 7.4+
- FormIt, when it processes the forms; it is installed with FetchIt
- pdoTools, for form chunks on Fenom or in files

## Field: System settings

All settings are in the `fetchit` namespace.

| Key | Default | What it does |
|-----|---------|--------------|
| `fetchit.frontend.js` | `[[+assetsUrl]]js/fetchit.js` | The script of the component; the minified `js/fetchit.min.js` is next to it |
| `fetchit.frontend.js.classname` | `FetchIt` | The class that handles the forms, when the bundled one is extended |
| `fetchit.frontend.input.invalid.class` | `is-invalid` | The class of an invalid field |
| `fetchit.frontend.custom.invalid.class` | | The class for the `data-custom` elements of an invalid field |
| `fetchit.frontend.default.notifier` | `No` | Show the answers as the built-in notifications |
| `fetchit.protection` | `Yes` | Spam protection; turn it off only for debugging |
| `fetchit.protection.secret` | random | The key that signs the tokens, generated at install |
| `fetchit.protection.min_time` | `3` | The minimum fill time, seconds; 0 turns it off |
| `fetchit.protection.token_ttl` | `86400` | How many seconds a page with a form stays valid |
| `fetchit.protection.rate_limit` | `10` | Submissions of a form from one address within the window; 0 turns it off |
| `fetchit.protection.rate_window` | `600` | The window of the limit, seconds |
| `fetchit.protection.log` | `1` | The log of the protection: 0 nothing, 1 problems, 2 every refusal |
| `fetchit.protection.proxies` | | Trusted proxies and CDNs: IPs or CIDR ranges separated by commas |
| `fetchit.protection.ip_header` | `X-Forwarded-For` | The header with the visitor's address from a trusted proxy |
| `fetchit.protection.pow` | `0` | The difficulty of the proof of work, bits; 0 turns it off, 24 at most |
| `fetchit.captcha` | | `turnstile`, `recaptcha` or `smartcaptcha`; empty for none |
| `fetchit.captcha.site_key` | | The site key from the provider's dashboard |
| `fetchit.captcha.secret_key` | | The secret key of the provider |
| `fetchit.captcha.min_score` | `0.5` | reCAPTCHA v3: the minimum score, from 0 to 1 |

## Field: Links

- Documentation: https://docs.modx.pro/components/fetchit/ (in Russian)
- Repository: https://github.com/GulomovCreative/FetchIt
- License: GPL-2.0

---

## Pictures in the description

| # | File | Caption | Link |
|---|------|---------|------|
| 1 | `docs/images/notifier-readme-en.png` | The built-in notifications of FetchIt: an error and a successful submission | https://file.modx.pro/files/f/f/8/ff89684caf0c699222cbaf7c8b84fb68.png |
