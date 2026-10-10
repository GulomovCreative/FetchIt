// Types of the FetchIt script (window.FetchIt) for sites written in
// TypeScript. Copy this file into the project or reference it:
//
//   /// <reference path="path/to/assets/components/fetchit/js/fetchit.d.ts" />
//
// document.addEventListener('fetchit:success', event => ...) then knows
// event.detail, and FetchIt.Message, FetchIt.observe() and FetchIt.init()
// their arguments.
// The script is type-checked against this file: FetchIt, its instances and
// the detail of every event it dispatches. Remove any FetchIt declaration of
// your own (declare var FetchIt: any): the two would clash.

/**
 * The config of a form: the snippet writes it into data-fetchit-config.
 * Its notifier options are read by create() only (pages cached by FetchIt
 * 4.0); otherwise the page passes them to observe().
 */
interface FetchItConfig extends FetchItObserveOptions {
  /** The key of the form: its data-fetchit attribute and the X-FetchIt-Action header */
  action: string;
  actionUrl: string;
  assetsUrl?: string | undefined;
  /** Classes for an invalid field, separated by spaces */
  inputInvalidClass?: string | undefined;
  /** Classes for [data-custom] elements of an invalid field, separated by spaces */
  customInvalidClass?: string | undefined;
  clearFieldsOnSuccess?: boolean | undefined;
  /** data-fetchit-state on the form: sending, success, error (&formState); on unless false */
  formState?: boolean | undefined;
  /** aria-busy="true" on the form while it is sent (&ariaBusy); on unless false */
  ariaBusy?: boolean | undefined;
  /**
   * Shown when the form could not be sent: a network error, an answer that
   * is not FetchIt's, a failed proof of work (and a captcha that gave no
   * answer, without captchaErrorMessage)
   */
  requestErrorMessage?: string | undefined;
  /**
   * Bits of the proof of work from fetchit.protection.pow, capped by the
   * server (FetchItGuard::MAX_POW); 0 or none for no proof of work. A
   * refusal of the server can raise it during the visit.
   */
  pow?: number | undefined;
  captcha?: FetchItCaptchaConfig | null | undefined;
  /** Shown when the captcha gave no answer to send */
  captchaErrorMessage?: string | undefined;
  /**
   * Exchange the token of the page for a fresh one on the first focus on
   * the form, or before the first submission if there was none:
   * fetchit.page_cache, for pages from a full-page cache
   */
  freshToken?: boolean | undefined;
  pageId: number | string;
}

interface FetchItCaptchaConfig {
  provider: 'turnstile' | 'recaptcha' | 'smartcaptcha';
  siteKey: string;
}

/**
 * The answer of the server as the hooks and events get it: message is for
 * the visitor ('' when the processing snippet sent none), data holds the
 * errors of the fields by name (or whatever the snippet sent; {} when none).
 */
interface FetchItResponse {
  success: boolean;
  message: string;
  data: Record<string, unknown> | unknown[];
}

/**
 * What FetchIt.isResponse() checks: an object with a boolean success.
 */
interface FetchItAnswer {
  success: boolean;
  message?: unknown;
  data?: unknown;
  /** The page to go to after a success (&redirectTo, the redirect hook of FormIt) */
  redirect?: unknown;
}

/**
 * Hooks for notifications: set FetchIt.Message to show the answers your
 * way. They run before the event of the same moment is dispatched (reset
 * after fetchit:reset), so cancelling the event does not undo them. An
 * exception in a hook is logged and does not stop the form.
 */
interface FetchItMessage {
  /** On submit, before fetchit:before */
  before?: (() => void) | undefined;
  /** When a FetchIt answer came, before fetchit:after */
  after?: ((message: string) => void) | undefined;
  /** When the form was accepted, before fetchit:success */
  success?: ((message: string) => void) | undefined;
  /** When the form was refused or could not be sent, before fetchit:error */
  error?: ((message: string) => void) | undefined;
  /** On every reset of the form, the one after a success included; after fetchit:reset */
  reset?: (() => void) | undefined;
}

/**
 * The built-in notifier: FetchIt.createNotifier().
 */
interface FetchItNotifier {
  success(message: string): void;
  error(message: string): void;
}

/**
 * The options of FetchIt.observe(): the bootstrap of the page passes the
 * notifier settings.
 */
interface FetchItObserveOptions {
  /** Show the answers with the built-in notifier; see FetchItStatic.Message */
  defaultNotifier?: boolean | undefined;
  /** The label of the close button of the built-in notifier */
  notifierCloseLabel?: string | undefined;
}

interface FetchItNotifierOptions {
  /** The label of the close button; "Close" by default */
  closeLabel?: string | undefined;
  /**
   * How long a message stays, in milliseconds; 6000 by default. 0 or
   * Infinity: until the visitor closes it.
   */
  duration?: number | undefined;
}

/**
 * fetchit:before, cancelable. Listeners may add to formData. Cancelling it
 * keeps the form from being sent, and no other hook or event follows.
 * FetchIt.Message.before has already run, and the errors and messages of
 * the form are already cleared.
 */
interface FetchItBeforeDetail {
  form: HTMLFormElement;
  formData: FormData;
  fetchit: FetchItInstance;
}

/**
 * fetchit:after, cancelable, dispatched only when a FetchIt answer came.
 * Cancelling it skips the rest: the field errors, the form message, the
 * success or error hook and event, and clearing the fields.
 */
interface FetchItAfterDetail extends FetchItBeforeDetail {
  response: FetchItResponse;
}

/**
 * fetchit:success, cancelable. The form message and the notification are
 * already shown. Cancelling it keeps the fields filled (no reset, so no
 * fetchit:reset), leaves a reCAPTCHA widget of the site alone and stays on
 * the page instead of going to redirect.
 */
interface FetchItSuccessDetail extends FetchItAfterDetail {
  /** The page the answer leads to (&redirectTo, the redirect hook of FormIt); see FetchItInstance.navigate() */
  redirect?: string | undefined;
}

/**
 * fetchit:error, cancelable. FetchIt.Message.error has already run.
 * Cancelling it keeps the field errors and the [data-validation-error]
 * message off the form. response is null when no usable FetchIt answer came
 * (a network error, an answer that is not FetchIt's, a captcha without an
 * answer); error then tells why.
 */
interface FetchItErrorDetail extends FetchItBeforeDetail {
  response: FetchItResponse | null;
  error?: unknown;
}

/**
 * fetchit:reset, not cancelable: the form is being reset (its button,
 * form.reset(), or after a success); the fields still hold their values.
 */
interface FetchItResetDetail {
  form: HTMLFormElement;
  fetchit: FetchItInstance;
}

interface FetchItEventMap {
  'fetchit:before': CustomEvent<FetchItBeforeDetail>;
  'fetchit:after': CustomEvent<FetchItAfterDetail>;
  'fetchit:success': CustomEvent<FetchItSuccessDetail>;
  'fetchit:error': CustomEvent<FetchItErrorDetail>;
  'fetchit:reset': CustomEvent<FetchItResetDetail>;
}

// The events are dispatched on document and do not bubble: listen on
// document, not on the form or window.
interface DocumentEventMap extends FetchItEventMap {}

/**
 * A form handled by FetchIt: FetchIt.instances.get(form).
 */
interface FetchItInstance {
  readonly form: HTMLFormElement;
  /** From data-fetchit-config of the form, or shared by the forms of one create() call */
  readonly config: Readonly<FetchItConfig>;
  /** The data of the submission in progress or of the last one; undefined before the first */
  readonly formData: FormData | undefined;
  /** Show an error at a field: its [data-error] elements, aria-invalid, the invalid classes */
  setError(name: string, message?: unknown): void;
  clearError(name: string | null): { fields: Element[]; errors: HTMLElement[]; customErrors: Element[] };
  clearErrors(): void;
  /** Show a message in [data-success] or [data-validation-error] of the form */
  setFormMessage(type: 'success' | 'validation', message?: unknown): void;
  clearFormMessages(): void;
  enableFields(): void;
  disableFields(): void;
  getFields(name: string | null): Element[];
  getErrors(name: string | null): HTMLElement[];
  getCustomErrors(name: string | null): Element[];
  readonly elements: Element[];
  /** The input, select and textarea elements of the form */
  readonly fields: Element[];
  /**
   * Go to the page of a successful answer, after fetchit:success: only an
   * http(s) address, relative ones against the page; anything else is
   * logged. True when the page is left (not for a hash of the same page): the fields then stay disabled.
   * Override it for a router of your own.
   */
  navigate(url: string): boolean;
  /**
   * Forget the form: it leaves FetchIt.instances and FetchIt.forms, its
   * listeners are removed, its proof of work stops, its captcha widget is
   * removed; put back on the page, it gets a new instance. Called when the form
   * leaves the page; calling it again does nothing.
   */
  destroy(): void;
}

interface FetchItStatic {
  new (form: HTMLFormElement, config: FetchItConfig): FetchItInstance;
  /**
   * Set it to show the answers your way. With the defaultNotifier setting,
   * FetchIt.observe() puts the built-in notifier here when it is unset, and
   * adds its success and error to one that has neither.
   */
  Message?: FetchItMessage | undefined;
  readonly forms: readonly HTMLFormElement[];
  readonly instances: ReadonlyMap<HTMLFormElement, FetchItInstance>;
  readonly events: {
    readonly before: 'fetchit:before';
    readonly success: 'fetchit:success';
    readonly error: 'fetchit:error';
    readonly after: 'fetchit:after';
    readonly reset: 'fetchit:reset';
  };
  /** Used when the config has no requestErrorMessage */
  defaultRequestErrorMessage: string;
  readonly tokenField: string;
  readonly powField: string;
  /**
   * Handle the forms of the page and the ones inserted later (a modal
   * loaded over AJAX, pdoPage, mFilter2), and destroy removed ones. The
   * bootstrap of the page calls it on DOMContentLoaded; calling it again
   * only applies the notifier options.
   */
  observe(options?: FetchItObserveOptions): void;
  /**
   * Handle the forms with data-fetchit and data-fetchit-config in root
   * (document by default), root included, right away: e.g. right after
   * inserting a form, before the observer sees it. A form that cannot start
   * is logged and sent the usual way. FetchIt.init() makes instances of the
   * class that observes the page, a class of the site included.
   */
  init(root?: ParentNode): void;
  /**
   * Handle the forms of this config, then observe() the page. Pages cached
   * by FetchIt 4.0 call it, and so does the page for a class of the site
   * with a create() of its own.
   */
  create(config: FetchItConfig): void;
  /** The built-in notifier, e.g. for FetchIt.Message without the setting */
  createNotifier(options?: FetchItNotifierOptions): FetchItNotifier;
  /** Call a FetchIt.Message hook; an exception in it is logged */
  notify(hook: keyof FetchItMessage, message?: string): void;
  isResponse(value: unknown): value is FetchItAnswer;
  sanitizeHTML(value?: string): string;
  hasErrorMessage(message?: unknown): boolean;
  escapeAttribute(value: string): string;
}

/**
 * Defined on pages with a FetchIt form or FetchItScript, once the
 * deferred fetchit.js has run: set FetchIt.Message from a deferred or module script, or on
 * DOMContentLoaded. Listeners of the events on document can be added any
 * time. With fetchit.frontend.js.classname the class has another name.
 */
declare var FetchIt: FetchItStatic;
