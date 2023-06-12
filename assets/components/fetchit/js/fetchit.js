(function () {
  'use strict';

  class FetchIt {
    static forms = [];
    static instances = new Map();
    static events = {
      before: 'fetchit:before',
      success: 'fetchit:success',
      error: 'fetchit:error',
      after: 'fetchit:after',
      reset: 'fetchit:reset',
    }

    constructor (form, config) {
      if (!(form instanceof HTMLFormElement)) {
        throw new Error('Не форма');
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
      this.form.addEventListener('submit', async e => {
        e.preventDefault();

        this.formData = new FormData(this.form);
        this.formData.set('pageId', this.config.pageId);

        this.clearErrors();

        const beforeEvent = new CustomEvent(FetchIt.events.before, {
          cancelable: true,
          detail: {
            form: this.form,
            formData: this.formData,
            fetchit: this,
          },
        });

        FetchIt?.Message?.before?.();

        if (!document.dispatchEvent(beforeEvent)) {
          return;
        }

        this.disableFields();

        try {
          const query = await fetch(this.request, { body: this.formData });
          const response = await query.json();

          const afterEvent = new CustomEvent(FetchIt.events.after, {
            cancelable: true,
            detail: {
              form: this.form,
              formData: this.formData,
              response,
              fetchit: this,
            },
          });

          FetchIt?.Message?.after?.(response.message);

          if (!document.dispatchEvent(afterEvent)) {
            return;
          }

          if (!response.success) {
            FetchIt?.Message?.error?.(response.message);

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

            for (const [ name, message ] of Object.entries(response.data)) {
              this.setError(name, message);
            }

            return;
          }

          this.clearErrors();
          FetchIt?.Message?.success?.(response.message);

          const successEvent = new CustomEvent(FetchIt.events.success, {
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
            this.form.reset();
          }
        } catch (e) {
          console.error(e);
        } finally {
          this.enableFields();
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
        FetchIt?.Message?.reset?.();
      });

      ['change', 'input'].forEach(eventName => {
        this.form.addEventListener(eventName, ({ target }) => {
          this.clearError(target.getAttribute('name'));
        });
      });
    }

    clearErrors () {
      this.fields.forEach(field => this.clearError(field.getAttribute('name')));
    }

    clearError (name) {
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
        error.style.display = 'none';
        error.innerHTML = '';
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

    setError (name, message = '') {
      this.getFields(name).forEach(field => {
        if (this.inputInvalidClasses) {
          field.classList.add(...this.inputInvalidClasses);
        }
        field.setAttribute('aria-invalid', true);
        // if (!this.form.noValidate) {
        //   field.setCustomValidity(FetchIt.sanitizeHTML(message));
        //   field.reportValidity();
        // }
      });

      if (this.customInvalidClasses) {
        this.getCustomErrors(name).forEach(({ classList }) => classList.add(...this.customInvalidClasses));
      }

      this.getErrors(name).forEach(error => {
        error.style.display = '';
        error.innerHTML = message;
      });
    }

    enableFields () {
      this.elements.forEach(field => field.removeAttribute('disabled'));
    }

    disableFields () {
      this.elements.forEach(field => field.setAttribute('disabled', ''));
    }

    getFields (name) {
      if (!name) {
        return [];
      }

      return Array.from(this.form.querySelectorAll(`[name="${name}"], [name="${name}[]"]`));
    }

    getErrors (name) {
      if (!name) {
        return [];
      }

      return Array.from(this.form.querySelectorAll(`[data-error="${name}"], [data-error="${name}[]"]`));
    }

    getCustomErrors (name) {
      if (!name) {
        return [];
      }

      return Array.from(this.form.querySelectorAll(`[data-custom="${name}"]`));
    }

    get elements () {
      return Array.from(this.form.elements);
    }

    get fields () {
      return this.elements.filter(({ tagName }) => ['select', 'input', 'textarea'].includes(tagName.toLowerCase()));
    }

    get inputInvalidClasses() {
      return this.config.inputInvalidClass ? this.config.inputInvalidClass.split(' ') : [];
    }

    get customInvalidClasses() {
      return this.config.customInvalidClass ? this.config.customInvalidClass.split(' ') : [];
    }

    static sanitizeHTML (str = '') {
      return str.replace(/(<([^>]+)>)/gi, '');
    }

    static create(config) {
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
        };
      }

      if (!config.action) {
        throw new Error('Нет идентификатора формы FetchIt');
      }

      const forms = document.querySelectorAll(`form[data-fetchit="${config.action}"]`);
      if (!forms) {
        throw new Error(`В документе не найдено форм по селектору: form[data-fetchit="${config.action}"]`);
      }

      forms.forEach(form => {
        new this(form, config);
      });
    }
  }

  window.FetchIt = FetchIt;

})();
