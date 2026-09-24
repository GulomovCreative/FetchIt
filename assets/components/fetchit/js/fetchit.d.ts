// Types of the FetchIt script (window.FetchIt) for sites written in
// TypeScript. Copy this file into the project or reference it:
//
//   /// <reference path="path/to/assets/components/fetchit/js/fetchit.d.ts" />
//
// document.addEventListener('fetchit:success', event => ...) then knows
// event.detail, and FetchIt.Message and FetchIt.create() their arguments.
// The script itself is built against this file, so they cannot drift apart.

/**
 * The config the snippet passes to FetchIt.create() for each form.
 */
interface FetchItConfig {
  /** The key of the form: its data-fetchit attribute and the X-FetchIt-Action header */
  action: string;
  actionUrl: string;
  assetsUrl?: string;
  /** Classes for an invalid field, separated by spaces */
  inputInvalidClass?: string;
  /** Classes for [data-custom] elements of an invalid field, separated by spaces */
  customInvalidClass?: string;
  clearFieldsOnSuccess?: boolean;
  /** Show the answers with the built-in notifier, unless FetchIt.Message is set */
  defaultNotifier?: boolean;
  /** The label of the close button of the built-in notifier */
  notifierCloseLabel?: string;
  /** Shown when a submission fails: a network error, an answer that is not FetchIt's */
  requestErrorMessage?: string;
  /** Bits of the proof of work, 0 to 24; 0 or none for no proof of work */
  pow?: number;
  captcha?: FetchItCaptchaConfig | null;
  /** Shown when the captcha gave no answer to send */
  captchaErrorMessage?: string;
  pageId: number | string;
}

interface FetchItCaptchaConfig {
  provider: 'turnstile' | 'recaptcha' | 'smartcaptcha';
  siteKey: string;
}

/**
 * The answer of the server: message is for the visitor, data holds the
 * errors of the fields by name (or whatever the processing snippet sent).
 */
interface FetchItResponse {
  success: boolean;
  message: string;
  data: Record<string, unknown> | unknown[];
}

/**
 * Hooks for notifications: set FetchIt.Message to show the answers your
 * way. An exception in a hook is logged and does not stop the form.
 */
interface FetchItMessage {
  before?(): void;
  after?(message: string): void;
  success?(message: string): void;
  error?(message: string): void;
  reset?(): void;
}

interface FetchItNotifierOptions {
  /** The label of the close button; "Close" by default */
  closeLabel?: string;
  /** How long a message stays, in milliseconds; 6000 by default */
  duration?: number;
}

/** fetchit:before, cancelable: cancelling it keeps the form from being sent */
interface FetchItBeforeDetail {
  form: HTMLFormElement;
  formData: FormData;
  fetchit: FetchItInstance;
}

/** fetchit:after, cancelable: cancelling it skips the handling of the answer */
interface FetchItAfterDetail extends FetchItBeforeDetail {
  response: FetchItResponse;
}

/** fetchit:success, cancelable: cancelling it keeps the fields filled */
interface FetchItSuccessDetail extends FetchItAfterDetail {}

/**
 * fetchit:error, cancelable: cancelling it keeps the errors from the form.
 * response is null when the request failed; error then tells why.
 */
interface FetchItErrorDetail extends FetchItBeforeDetail {
  response: FetchItResponse | null;
  error?: unknown;
}

/** fetchit:reset */
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

// The events are dispatched on document.
interface DocumentEventMap extends FetchItEventMap {}

/**
 * A form handled by FetchIt: FetchIt.instances.get(form).
 */
interface FetchItInstance {
  readonly form: HTMLFormElement;
  readonly config: FetchItConfig;
  /** The data of the submission in progress, or of the last one */
  readonly formData: FormData;
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
}

interface FetchItStatic {
  new (form: HTMLFormElement, config: FetchItConfig): FetchItInstance;
  /** Set it to show the answers your way; the built-in notifier sets it when on */
  Message?: FetchItMessage;
  readonly forms: HTMLFormElement[];
  readonly instances: Map<HTMLFormElement, FetchItInstance>;
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
  /** Handle the forms of this config: the snippet calls it on DOMContentLoaded */
  create(config: FetchItConfig): void;
  /** The built-in notifier, e.g. for FetchIt.Message without the setting */
  createNotifier(options?: FetchItNotifierOptions): Required<Pick<FetchItMessage, 'success' | 'error'>>;
  /** Call a FetchIt.Message hook; an exception in it is logged */
  notify(hook: keyof FetchItMessage, message?: string): void;
  isResponse(value: unknown): value is FetchItResponse;
  sanitizeHTML(value?: string): string;
  hasErrorMessage(message?: unknown): boolean;
  escapeAttribute(value: string): string;
}

interface Window {
  FetchIt: FetchItStatic;
}

declare var FetchIt: FetchItStatic;
