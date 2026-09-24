class FetchIt {
  declare static Message?: FetchItMessage;
  static forms: HTMLFormElement[] = [];
  static instances = new Map<HTMLFormElement, FetchIt>();
  // Used when the page config has no requestErrorMessage (a page cached by 1.1.3).
  static defaultRequestErrorMessage = 'Could not send the form. Please try again.';
  // The hidden field of the spam protection (FetchItGuard::TOKEN).
  static tokenField = 'fetchit_token';
  static events = {
    before: 'fetchit:before',
    success: 'fetchit:success',
    error: 'fetchit:error',
    after: 'fetchit:after',
    reset: 'fetchit:reset',
  }

  declare form: HTMLFormElement;
  declare config: FetchItConfig;
  declare request: Request;
  declare formData: FormData;
  declare preserveFormMessagesOnReset: boolean;
  declare disabledBefore: Element[];
  declare pending: boolean;

  constructor (form: unknown, config: FetchItConfig) {
    if (!(form instanceof HTMLFormElement)) {
      throw new Error('FetchIt: the element is not a form');
    }

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

    this.prepareEvents();

    FetchIt.forms.push(this.form);
    FetchIt.instances.set(this.form, this);
  }

  prepareEvents() {
    this.form.addEventListener('submit', async event => {
      event.preventDefault();

      // requestSubmit() works on disabled buttons too: one request at a time.
      if (this.pending) {
        return;
      }

      this.formData = new FormData(this.form);
      this.formData.set('pageId', String(this.config.pageId));

      this.clearErrors();
      this.clearFormMessages();

      const beforeEvent = new CustomEvent(FetchIt.events.before, {
        cancelable: true,
        detail: {
          form: this.form,
          formData: this.formData,
          fetchit: this,
        },
      });

      FetchIt.notify('before');

      if (!document.dispatchEvent(beforeEvent)) {
        return;
      }

      this.pending = true;
      this.disableFields();

      // Set once the visitor has seen the outcome; a later error is only logged.
      let shown = false;
      let response: FetchItResponse | undefined;

      try {
        try {
          const query = await fetch(this.request, { method: 'post', body: this.formData });
          this.updateToken(query.headers?.get('X-FetchIt-Token'));
          const body: unknown = await query.json();
          if (!FetchIt.isResponse(body)) {
            throw new Error(`FetchIt: unexpected answer from ${query.url || this.config.actionUrl} (HTTP ${query.status})`);
          }
          response = body;
        } catch (error) {
          this.failRequest(error);
          return;
        }

        const afterEvent = new CustomEvent(FetchIt.events.after, {
          cancelable: true,
          detail: {
            form: this.form,
            formData: this.formData,
            response,
            fetchit: this,
          },
        });

        FetchIt.notify('after', response.message);

        if (!document.dispatchEvent(afterEvent)) {
          return;
        }

        if (!response.success) {
          FetchIt.notify('error', response.message);

          const errorEvent = new CustomEvent(FetchIt.events.error, {
            cancelable: true,
            detail: {
              form: this.form,
              formData: this.formData,
              response,
              fetchit: this,
            },
          });

          if (!document.dispatchEvent(errorEvent)) {
            return;
          }

          for (const [ name, message ] of Object.entries(response.data ?? {})) {
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
        const successEvent = new CustomEvent(FetchIt.events.success, {
          cancelable: true,
          detail: {
            form: this.form,
            formData: this.formData,
            response,
            fetchit: this,
          },
        });

        if (!document.dispatchEvent(successEvent)) {
          return;
        }

        if (typeof window.grecaptcha !== 'undefined') {
          window.grecaptcha.reset();
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
          this.failRequest(error);
        }
      } finally {
        this.enableFields();
        this.pending = false;
      }
    });

    this.form.addEventListener('reset', () => {
      const resetEvent = new CustomEvent(FetchIt.events.reset, {
        detail: {
          form: this.form,
          fetchit: this,
        },
      });

      document.dispatchEvent(resetEvent);
      this.clearErrors();
      if (!this.preserveFormMessagesOnReset) {
        this.clearFormMessages();
      }
      FetchIt.notify('reset');
    });

    ['change', 'input'].forEach(eventName => {
      this.form.addEventListener(eventName, ({ target }) => {
        this.clearError((target as Element).getAttribute('name'));
      });
    });
  }

  /**
   * fetch() rejected (network error), the body was not a FetchIt answer
   * (a PHP error page, HTML after a redirect, JSON from a firewall), or
   * handling the answer threw: tell the visitor instead of failing silently.
   * An HTTP error status with a FetchIt answer goes the normal way.
   */
  failRequest (error: unknown) {
    console.error(error);

    const message = this.config.requestErrorMessage || FetchIt.defaultRequestErrorMessage;
    FetchIt.notify('error', message);

    const errorEvent = new CustomEvent(FetchIt.events.error, {
      cancelable: true,
      detail: {
        form: this.form,
        formData: this.formData,
        response: null,
        error,
        fetchit: this,
      },
    });

    if (!document.dispatchEvent(errorEvent)) {
      return;
    }

    this.setFormMessage('validation', message);
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
      console.error(error);
    }
  }

  static isResponse (value: unknown): value is FetchItResponse {
    return typeof value === 'object' && value !== null && typeof (value as FetchItResponse).success === 'boolean';
  }

  static sanitizeHTML (str: string = ''): string {
    return str.replace(/(<([^>]+)>)/gi, '');
  }

  static hasErrorMessage (message: unknown = ''): boolean {
    return FetchIt.sanitizeHTML(String(message)).trim() !== '';
  }

  static create(config: FetchItConfig) {
    if (
      config.defaultNotifier
      && typeof window.Notyf === 'function'
      && typeof FetchIt.Message === 'undefined'
    ) {
      const notyf = new Notyf();

      FetchIt.Message = {
        success(message) {
          notyf.success(message);
        },
        error(message) {
          notyf.error(message);
        },
      }
    }

    if (!config.action) {
      throw new Error('FetchIt: the config has no action');
    }

    const selector = `form[data-fetchit="${FetchIt.escapeAttribute(config.action)}"]`;
    const forms = document.querySelectorAll<HTMLFormElement>(selector);
    if (!forms.length) {
      console.warn(`FetchIt: no form matches ${selector}`);
      return;
    }

    // Identical snippet calls share an action and call create() twice.
    forms.forEach(form => FetchIt.instances.get(form) ?? new this(form, config));
  }
}

window.FetchIt = FetchIt
