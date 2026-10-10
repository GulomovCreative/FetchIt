import { CaptchaError, createCaptcha, type Captcha } from './captcha'
import { createNotifier } from './notifier'
import { solve } from './pow'
import { stripTags } from './text'

/**
 * Dispatch an event of FetchIt on document, its detail checked against the
 * public types (FetchItEventMap). False when a listener cancelled it.
 */
function dispatch<K extends keyof FetchItEventMap> (type: K, detail: FetchItEventMap[K]['detail'], cancelable = true): boolean {
  return document.dispatchEvent(new CustomEvent(type, { cancelable, detail }));
}

/**
 * A FetchIt answer as the hooks and events get it: a processing snippet may
 * leave out the message or the data, or send a message that is not a string.
 */
function toResponse (answer: FetchItAnswer): FetchItResponse {
  const message = answer.message;
  const data = answer.data;
  return {
    success: answer.success,
    message: typeof message === 'string' ? message : (message == null ? '' : String(message)),
    data: data !== null && typeof data === 'object' ? data as FetchItResponse['data'] : {},
  };
}

/**
 * Date.now() from which a token of now is taken as filled in, given min_time
 * in seconds (X-FetchIt-Min-Time); 0 when there is none to wait for.
 */
function readyAt (minTime: number): number {
  // The server counts whole seconds; a little more keeps clear of it.
  return minTime > 0 ? Date.now() + minTime * 1000 + 250 : 0;
}

/**
 * Wait until Date.now() reaches the time.
 */
async function until (time: number): Promise<void> {
  const wait = time - Date.now();
  if (wait > 0) {
    await new Promise(resolve => setTimeout(resolve, wait));
  }
}

class FetchIt implements FetchItInstance {
  declare static Message?: FetchItMessage;
  static forms: HTMLFormElement[] = [];
  static instances = new Map<HTMLFormElement, FetchIt>();
  // Started once per page by observe(): it initialises inserted forms and
  // destroys removed ones.
  static observer: MutationObserver | undefined = undefined;
  // The class that called observe(): a class of the site, or FetchIt.
  static started: typeof FetchIt | undefined = undefined;
  // Where the snippet puts the config of a form.
  static readonly configAttribute = 'data-fetchit-config';
  // The configs create() got on a page cached by FetchIt 4.0, by action: its
  // forms have no config of their own, and one put back is started again.
  static legacy = new Map<string, FetchItConfig>();
  // Used when the page config has no requestErrorMessage (a page cached by 1.1.3).
  static defaultRequestErrorMessage = 'Could not send the form. Please try again.';
  // The hidden field of the spam protection (FetchItGuard::TOKEN).
  static tokenField = 'fetchit_token';
  // The field with the solution of the proof of work (FetchItGuard::POW).
  static powField = 'fetchit_pow';
  static events = {
    before: 'fetchit:before',
    success: 'fetchit:success',
    error: 'fetchit:error',
    after: 'fetchit:after',
    reset: 'fetchit:reset',
  } as const

  declare form: HTMLFormElement;
  declare config: FetchItConfig;
  declare request: Request;
  // The data of the submission in progress or of the last one.
  formData: FormData | undefined = undefined;
  declare preserveFormMessagesOnReset: boolean;
  declare disabledBefore: Element[];
  declare pending: boolean;
  declare captcha: Captcha | null;
  // The proof of work for the current token, started ahead; a new token stops it.
  declare work?: { token: string; solution: Promise<string>; stop: AbortController };
  // Removes the listeners of the form when the instance is destroyed: a form
  // put back on the page later gets a new instance and must not be sent twice.
  readonly listening = new AbortController();
  // Set by destroy(): what the instance still has running ends quietly.
  destroyed = false;
  // The exchange of the token of the page for a fresh one (config.freshToken),
  // started on the first focus or submission.
  tokenExchange?: Promise<void> = undefined;
  // Date.now() from which the server takes the fresh token as filled in:
  // min_time counts from the exchange.
  freshTokenReadyAt = 0;

  constructor (form: unknown, config: FetchItConfig) {
    if (!(form instanceof HTMLFormElement)) {
      throw new Error('FetchIt: the element is not a form');
    }

    // One instance per form: a new one replaces the old.
    FetchIt.instances.get(form)?.destroy();
    this.form = form;
    this.config = config;

    this.request = new Request(this.config.actionUrl, {
      method: 'post',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-FetchIt-Action': this.config.action,
      },
    });

    this.captcha = createCaptcha(this.form, this.config.captcha);
    this.prepareEvents();

    FetchIt.forms.push(this.form);
    FetchIt.instances.set(this.form, this);
  }

  prepareEvents() {
    const signal = this.listening.signal;

    this.form.addEventListener('submit', async event => {
      // A browser that does not know the signal option keeps the listeners
      // of a destroyed instance; the one that sends is guarded, the others
      // only clear errors and messages.
      if (FetchIt.instances.get(this.form) !== this) {
        return;
      }
      event.preventDefault();

      // requestSubmit() works on disabled buttons too: one request at a time.
      if (this.pending) {
        return;
      }

      const formData = new FormData(this.form);
      formData.set('pageId', String(this.config.pageId));
      this.formData = formData;

      this.clearErrors();
      this.clearFormMessages();

      FetchIt.notify('before');

      if (!dispatch('fetchit:before', { form: this.form, formData, fetchit: this })) {
        return;
      }

      this.pending = true;
      this.disableFields();

      // Set once the visitor has seen the outcome; a later error is only logged.
      let shown = false;
      let response: FetchItResponse | undefined;

      try {
        try {
          if (this.config.freshToken) {
            await this.awaitFreshToken();
            const token = this.currentToken();
            if (token) {
              formData.set(FetchIt.tokenField, token);
            }
            await this.untilReady();
            if (this.destroyed) {
              return;
            }
          }
          await this.protectSubmission(formData);
          let query = await fetch(this.request, { method: 'post', body: formData });
          let next = query.headers?.get('X-FetchIt-Token');
          this.updateToken(next);
          const refused = query.headers?.get('X-FetchIt-Refused');
          const bits = Number(query.headers?.get('X-FetchIt-Pow') ?? 0);
          // A page from a cache, or open for long, holds a used or expired
          // token, or asks for less proof of work than the server now does:
          // send once more with the new token instead of showing it. The
          // captcha answer was not checked yet (FetchItGuard checks it last).
          if (next && (refused === 'token' || (refused === 'pow' && bits > (this.config.pow ?? 0)))) {
            if (refused === 'pow') {
              this.config.pow = bits;
            }
            // With a full-page cache the next token of a used or expired
            // one is timed now: wait the fill time the server asks for.
            if (refused === 'token') {
              await until(readyAt(Number(query.headers?.get('X-FetchIt-Min-Time') ?? 0)));
              if (this.destroyed) {
                return;
              }
            }
            formData.set(FetchIt.tokenField, next);
            if (this.config.pow) {
              formData.set(FetchIt.powField, await this.solution(next));
            }
            query = await fetch(this.request, { method: 'post', body: formData });
            next = query.headers?.get('X-FetchIt-Token');
            this.updateToken(next);
          }
          if (refused === 'captcha' && !this.config.captcha) {
            console.warn('FetchIt: the server asks for a captcha this page does not have; the page may come from a cache made before the captcha was turned on');
          }
          const body: unknown = await query.json();
          if (!FetchIt.isResponse(body)) {
            throw new Error(`FetchIt: unexpected answer from ${query.url || this.config.actionUrl} (HTTP ${query.status})`);
          }
          response = toResponse(body);
        } catch (error) {
          this.failRequest(error, formData);
          return;
        }

        FetchIt.notify('after', response.message);

        if (!dispatch('fetchit:after', { form: this.form, formData, response, fetchit: this })) {
          return;
        }

        if (!response.success) {
          FetchIt.notify('error', response.message);

          if (!dispatch('fetchit:error', { form: this.form, formData, response, fetchit: this })) {
            return;
          }

          for (const [ name, message ] of Object.entries(response.data)) {
            if (!FetchIt.hasErrorMessage(message)) {
              continue;
            }

            this.setError(name, message);
          }

          this.setFormMessage('validation', response.message);
          shown = true;

          return;
        }

        this.clearErrors();
        this.setFormMessage('success', response.message);
        shown = true;
        FetchIt.notify('success', response.message);

        // Cancelling it keeps the fields and skips the reCAPTCHA reset.
        if (!dispatch('fetchit:success', { form: this.form, formData, response, fetchit: this })) {
          return;
        }

        // A reCAPTCHA v2 widget the site added itself; FetchIt's own captcha
        // resets below.
        if (this.config.captcha?.provider !== 'recaptcha') {
          try {
            window.grecaptcha?.reset?.();
          } catch (error) {
            console.error(error);
          }
        }

        if (this.config.clearFieldsOnSuccess) {
          this.preserveFormMessagesOnReset = true;
          this.form.reset();
          this.preserveFormMessagesOnReset = false;
        }
      } catch (error) {
        // After the server accepted the form, "could not send" would make
        // the visitor send it again.
        if (shown || response?.success) {
          console.error(error);
        } else {
          this.failRequest(error, formData);
        }
      } finally {
        this.enableFields();
        this.pending = false;
        // A captcha answer is good for one check.
        this.captcha?.reset();
      }
    }, { signal });

    this.form.addEventListener('reset', () => {
      dispatch('fetchit:reset', { form: this.form, fetchit: this }, false);
      this.clearErrors();
      if (!this.preserveFormMessagesOnReset) {
        this.clearFormMessages();
      }
      FetchIt.notify('reset');
    }, { signal });

    ['change', 'input'].forEach(eventName => {
      this.form.addEventListener(eventName, ({ target }) => {
        this.clearError((target as Element).getAttribute('name'));
      }, { signal });
    });

    // The visitor started on the form: get a fresh token when the page comes
    // from a full-page cache (the proof of work is then solved for it),
    // otherwise solve the proof of work ahead.
    this.form.addEventListener('focusin', () => {
      if (this.config.freshToken) {
        this.awaitFreshToken().catch(error => console.error(error));
      } else {
        this.solveAhead();
      }
    }, { once: true, signal });
  }

  /**
   * The form is gone: forget it, remove its listeners, stop the proof of
   * work, remove the captcha widget. A submission waiting for the proof of
   * work or the captcha ends without a message; an answer already on its
   * way is handled as usual, but starts no new proof of work.
   */
  destroy () {
    if (FetchIt.instances.get(this.form) !== this) {
      return;
    }
    this.destroyed = true;
    FetchIt.instances.delete(this.form);
    this.listening.abort();
    const index = FetchIt.forms.indexOf(this.form);
    if (index !== -1) {
      FetchIt.forms.splice(index, 1);
    }
    this.work?.stop.abort();
    this.work = undefined;
    this.captcha?.destroy();
  }

  /**
   * fetch() rejected (network error), the body was not a FetchIt answer
   * (a PHP error page, HTML after a redirect, JSON from a firewall),
   * handling the answer threw, or the captcha gave no answer to send: tell
   * the visitor instead of failing silently. An HTTP error status with a
   * FetchIt answer goes the normal way.
   */
  failRequest (error: unknown, formData: FormData) {
    // A form taken off the page while it was being sent (a modal closed)
    // tells nobody: destroy() stopped its proof of work or captcha.
    if (this.destroyed) {
      return;
    }
    console.error(error);

    const message = (error instanceof CaptchaError && this.config.captchaErrorMessage)
      || this.config.requestErrorMessage
      || FetchIt.defaultRequestErrorMessage;
    FetchIt.notify('error', message);

    if (!dispatch('fetchit:error', { form: this.form, formData, response: null, error, fetchit: this })) {
      return;
    }

    this.setFormMessage('validation', message);
  }

  /**
   * Add the solution of the proof of work and the captcha's answer.
   */
  async protectSubmission (formData: FormData) {
    if (this.config.pow) {
      const token = String(formData.get(FetchIt.tokenField) ?? '');
      formData.set(FetchIt.powField, await this.solution(token));
    }
    await this.captcha?.answer(formData);
  }

  /**
   * The solution of the proof of work for a token, started once; solving
   * for another token stops it.
   */
  solution (token: string): Promise<string> {
    if (this.work?.token !== token) {
      this.work?.stop.abort();
      const stop = new AbortController();
      const solution = solve(token, this.config.pow ?? 0, stop.signal);
      const work = { token, solution, stop };
      this.work = work;
      // A failed solution is not kept: the next submission tries again.
      solution.catch(() => {
        if (this.work === work) {
          this.work = undefined;
        }
      });
    }
    return this.work.solution;
  }

  /**
   * Start solving for the token of the form, so the solution is ready by the
   * time the visitor sends it.
   */
  solveAhead () {
    if (this.destroyed) {
      return;
    }
    const token = this.currentToken();
    if (this.config.pow && token) {
      this.solution(token).catch(error => {
        // Stopped for a newer token: nothing went wrong.
        if (this.work?.token === token) {
          console.error(error);
        }
      });
    }
  }

  /**
   * Exchange the token of the page for a fresh one, once: a page from a
   * full-page cache holds a token many visitors got.
   */
  awaitFreshToken (): Promise<void> {
    this.tokenExchange ??= this.exchangeToken();
    return this.tokenExchange;
  }

  /**
   * Ask the server for a fresh token (FetchItGuard::exchange()). A failed
   * exchange keeps the token of the page: the retry after a refusal still
   * covers it. destroy() stops it quietly.
   */
  async exchangeToken (): Promise<void> {
    const token = this.currentToken();
    let fresh: string | null | undefined;
    if (token) {
      const body = new FormData();
      body.set(FetchIt.tokenField, token);
      // action.php reads the settings of the context of the page.
      body.set('pageId', String(this.config.pageId));
      try {
        const answer = await fetch(this.config.actionUrl, {
          method: 'post',
          credentials: 'same-origin',
          headers: { 'X-FetchIt-Action': this.config.action, 'X-FetchIt-Exchange': '1' },
          body,
          signal: this.listening.signal,
        });
        fresh = answer.headers?.get('X-FetchIt-Token');
        if (fresh) {
          const bits = Number(answer.headers.get('X-FetchIt-Pow') ?? 0);
          if (bits > (this.config.pow ?? 0)) {
            this.config.pow = bits;
          }
          const minTime = Number(answer.headers.get('X-FetchIt-Min-Time') ?? 0);
          this.freshTokenReadyAt = readyAt(minTime);
        } else if (!this.destroyed) {
          console.warn(`FetchIt: no fresh token (${answer.headers?.get('X-FetchIt-Refused') ?? `HTTP ${answer.status}`}); the form keeps the token of the page`);
        }
      } catch (error) {
        if (!this.destroyed) {
          console.warn('FetchIt: could not get a fresh token; the form keeps the token of the page', error);
        }
      }
    }
    if (this.destroyed) {
      return;
    }
    if (fresh) {
      this.updateToken(fresh);
    } else {
      this.solveAhead();
    }
  }

  /**
   * Wait until the fill time of the server has passed since the exchange.
   */
  async untilReady (): Promise<void> {
    await until(this.freshTokenReadyAt);
  }

  /**
   * The token in the form now.
   */
  currentToken (): string | undefined {
    return this.form.querySelector<HTMLInputElement>(`input[name="${FetchIt.tokenField}"]`)?.value;
  }

  /**
   * The protection token is single-use: every answer brings the next one.
   * The value attribute changes too, so a form reset keeps it.
   */
  updateToken (token: string | null | undefined) {
    if (!token) {
      return;
    }
    this.form.querySelectorAll<HTMLInputElement>(`input[name="${FetchIt.tokenField}"]`).forEach(input => {
      input.value = token;
      input.defaultValue = token;
    });
    this.solveAhead();
  }

  clearErrors () {
    this.fields.forEach(field => this.clearError(field.getAttribute('name')));
  }

  clearError (name: string | null) {
    const fields = this.getFields(name);
    fields.forEach(field => {
      if (this.inputInvalidClasses) {
        field.classList.remove(...this.inputInvalidClasses);
      }
      field.removeAttribute('aria-invalid');
      // field.setCustomValidity('');
    });

    const errors = this.getErrors(name);
    errors.forEach(error => {
      error.style.display = 'none'
      error.innerHTML = ''
    });

    const customErrors = this.getCustomErrors(name);
    if (this.customInvalidClasses) {
      customErrors.forEach(({ classList }) => classList.remove(...this.customInvalidClasses));
    }

    return {
      fields,
      errors,
      customErrors,
    };
  }

  setError (name: string, message: unknown = '') {
    if (!FetchIt.hasErrorMessage(message)) {
      return;
    }

    this.getFields(name).forEach(field => {
      if (this.inputInvalidClasses) {
        field.classList.add(...this.inputInvalidClasses);
      }
      field.setAttribute('aria-invalid', 'true');
      // if (!this.form.noValidate) {
      //   field.setCustomValidity(FetchIt.sanitizeHTML(message));
      //   field.reportValidity();
      // }
    });

    if (this.customInvalidClasses) {
      this.getCustomErrors(name).forEach(({ classList }) => classList.add(...this.customInvalidClasses));
    }

    this.getErrors(name).forEach(error => {
      const safeMessage = FetchIt.sanitizeHTML(String(message)).trim();
      error.style.display = '';
      error.textContent = safeMessage;
    });
  }

  clearFormMessages () {
    this.form.querySelectorAll<HTMLElement>('[data-success], [data-validation-error]').forEach(element => {
      element.style.display = 'none';
      element.textContent = '';
    });
  }

  setFormMessage (type: 'success' | 'validation', message: unknown = '') {
    const safeMessage = FetchIt.sanitizeHTML(String(message)).trim();
    if (safeMessage === '') {
      return;
    }

    const showSelector = type === 'success' ? '[data-success]' : '[data-validation-error]';
    const hideSelector = type === 'success' ? '[data-validation-error]' : '[data-success]';

    this.form.querySelectorAll<HTMLElement>(hideSelector).forEach(element => {
      element.style.display = 'none';
      element.textContent = '';
    });

    this.form.querySelectorAll<HTMLElement>(showSelector).forEach(element => {
      element.style.display = '';
      element.textContent = safeMessage;
    });
  }

  enableFields () {
    this.elements
      .filter(field => !this.disabledBefore?.includes(field))
      .forEach(field => field.removeAttribute('disabled'));
  }

  disableFields () {
    this.disabledBefore = this.elements.filter(field => field.hasAttribute('disabled'));
    this.elements.forEach(field => field.setAttribute('disabled', ''));
  }

  getFields (name: string | null): Element[] {
    if (!name) {
      return [];
    }

    const value = FetchIt.escapeAttribute(name);
    return Array.from(this.form.querySelectorAll(`[name="${value}"], [name="${value}[]"]`));
  }

  getErrors (name: string | null): HTMLElement[] {
    if (!name) {
      return [];
    }

    const value = FetchIt.escapeAttribute(name);
    return Array.from(this.form.querySelectorAll<HTMLElement>(`[data-error="${value}"], [data-error="${value}[]"]`));
  }

  getCustomErrors (name: string | null): Element[] {
    if (!name) {
      return [];
    }

    return Array.from(this.form.querySelectorAll(`[data-custom="${FetchIt.escapeAttribute(name)}"]`));
  }

  get elements (): Element[] {
    return Array.from(this.form.elements);
  }

  get fields (): Element[] {
    return this.elements.filter(({ tagName }) => ['select', 'input', 'textarea'].includes(tagName.toLowerCase()));
  }

  get inputInvalidClasses(): string[] {
    return this.config.inputInvalidClass ? this.config.inputInvalidClass.split(' ') : [];
  }

  get customInvalidClasses(): string[] {
    return this.config.customInvalidClass ? this.config.customInvalidClass.split(' ') : [];
  }

  /**
   * Escape a value for a double-quoted attribute selector: only " and \
   * need it there.
   */
  static escapeAttribute (value: string): string {
    return value.replace(/["\\]/g, '\\$&');
  }

  /**
   * Call a FetchIt.Message hook. A broken notifier is logged; it must not
   * keep the answer from the form.
   */
  static notify (hook: keyof FetchItMessage, message?: string) {
    try {
      (FetchIt.Message?.[hook] as ((message?: string) => void) | undefined)?.(message);
    } catch (error) {
      console.error(`FetchIt: FetchIt.Message.${hook}() threw; the visitor did not get this notification`, error);
    }
  }

  /**
   * Whether a value looks like a FetchIt answer: an object with a boolean
   * success. The message and the data are not checked.
   */
  static isResponse (value: unknown): value is FetchItAnswer {
    return typeof value === 'object' && value !== null && typeof (value as FetchItAnswer).success === 'boolean';
  }

  static sanitizeHTML (str: string = ''): string {
    return stripTags(str);
  }

  static hasErrorMessage (message: unknown = ''): boolean {
    return FetchIt.sanitizeHTML(String(message)).trim() !== '';
  }

  static createNotifier (options?: FetchItNotifierOptions) {
    return createNotifier(options);
  }

  /**
   * The built-in notifier, when asked for. A FetchIt.Message of the site
   * wins over it; one with neither success nor error (a spinner in before
   * and after) gets the built-in ones.
   */
  static useNotifier (options: FetchItObserveOptions) {
    if (!options.defaultNotifier) {
      return;
    }
    const message = FetchIt.Message;
    if (message === undefined) {
      FetchIt.Message = createNotifier({ closeLabel: options.notifierCloseLabel });
    } else if (!message.success && !message.error) {
      Object.assign(message, createNotifier({ closeLabel: options.notifierCloseLabel }));
    }
  }

  /**
   * Handle the forms of the page and those inserted later, and forget the
   * removed ones: the bootstrap of the page calls it on DOMContentLoaded.
   */
  static observe (options: FetchItObserveOptions = {}) {
    // Applied on every call (it changes nothing the second time), so a
    // later call with the notifier still turns it on.
    FetchIt.useNotifier(options);
    if (FetchIt.observer) {
      return;
    }
    FetchIt.started = this;
    // Before the forms of the page: one that cannot start must not keep the
    // forms loaded later from being picked up.
    FetchIt.observer = new MutationObserver(records => this.mutated(records));
    FetchIt.observer.observe(document.documentElement, { childList: true, subtree: true });
    this.init(document);
  }

  /**
   * Handle the forms in root, root itself included, that carry their
   * config (or were started by create() on a page cached by FetchIt 4.0)
   * and have no instance yet. A form that cannot start is logged and sent
   * the usual way; the others start all the same.
   */
  static init (root: ParentNode = document) {
    // FetchIt.init() on a site with a class of its own makes instances of
    // that class.
    const Class = this === FetchIt && FetchIt.started ? FetchIt.started : this;
    const selector = 'form[data-fetchit]';
    const forms = Array.from(root.querySelectorAll<HTMLFormElement>(selector));
    if (root instanceof HTMLFormElement && root.matches(selector)) {
      forms.unshift(root);
    }
    forms.forEach(form => {
      if (FetchIt.instances.has(form)) {
        return;
      }
      const config = form.hasAttribute(FetchIt.configAttribute)
        ? FetchIt.readConfig(form)
        : FetchIt.legacy.get(form.getAttribute('data-fetchit') ?? '');
      if (!config) {
        return;
      }
      try {
        FetchIt.instances.set(form, new Class(form, config));
      } catch (error) {
        console.error(`FetchIt: could not start the form ${config.action}; it is sent without FetchIt`, form, error);
      }
    });
  }

  /**
   * The config of a form, or null (logged) when it is not valid: the form
   * is then sent the usual way.
   */
  static readConfig (form: HTMLFormElement): FetchItConfig | null {
    let reason = 'it has no action, actionUrl or pageId';
    try {
      const config: unknown = JSON.parse(form.getAttribute(FetchIt.configAttribute) ?? '');
      if (FetchIt.isConfig(config)) {
        if (config.action === form.getAttribute('data-fetchit')) {
          return config;
        }
        reason = 'its action is not the data-fetchit of the form';
      }
    } catch (error) {
      reason = error instanceof Error ? error.message : String(error);
    }
    console.error(`FetchIt: the form has no valid ${FetchIt.configAttribute} (${reason}); it is sent without FetchIt`, form);
    return null;
  }

  /**
   * What the script cannot do without: the key of the form, where to send
   * it and the page it belongs to.
   */
  static isConfig (value: unknown): value is FetchItConfig {
    if (value === null || typeof value !== 'object') {
      return false;
    }
    const config = value as Record<string, unknown>;
    return typeof config.action === 'string' && config.action !== ''
      && typeof config.actionUrl === 'string'
      && (typeof config.pageId === 'string' || typeof config.pageId === 'number');
  }

  /**
   * Inserted forms get an instance; removed ones are destroyed. The
   * records come after the whole batch of changes, so a form moved within
   * the page is connected again by then and is kept; one put back in a
   * later task (a modal opened again) gets a new instance.
   */
  static mutated (records: MutationRecord[]) {
    const removed: Node[] = [];
    records.forEach(record => {
      record.addedNodes.forEach(node => {
        if (node instanceof Element) {
          this.init(node);
        }
      });
      removed.push(...Array.from(record.removedNodes));
    });
    if (!removed.length) {
      return;
    }
    FetchIt.instances.forEach(instance => {
      const form = instance.form;
      if (!form.isConnected && removed.some(node => node.contains(form))) {
        instance.destroy();
      }
    });
  }

  static create(config: FetchItConfig) {
    FetchIt.useNotifier(config);

    if (!config.action) {
      throw new Error('FetchIt: the config has no action');
    }

    // A page cached by FetchIt 4.0: its forms have no config of their own.
    const selector = `form[data-fetchit="${FetchIt.escapeAttribute(config.action)}"]`;
    const forms = document.querySelectorAll<HTMLFormElement>(selector);
    if (!forms.length) {
      console.warn(`FetchIt: no form matches ${selector}`);
    }

    // Pages cached by 4.0 call create() once per snippet call; identical
    // calls share an action.
    FetchIt.legacy.set(config.action, config);
    forms.forEach(form => FetchIt.instances.get(form) ?? new this(form, config));
    this.observe();
  }
}

window.FetchIt = FetchIt
