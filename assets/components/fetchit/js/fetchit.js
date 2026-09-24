(function() {
	//#region src/captcha.ts
	const RECAPTCHA_ACTION = "fetchit";
	/**
	* Wait until read() gives something, such as the global of the provider's
	* script, checking every 100 ms (10 s by default).
	*/
	async function loaded(read, tries = 100) {
		for (let attempt = 0; attempt < tries; attempt++) {
			const api = read();
			if (api) return api;
			await new Promise((resolve) => setTimeout(resolve, 100));
		}
	}
	function container(form) {
		const element = document.createElement("div");
		element.className = "fetchit-captcha";
		const submit = form.querySelector("[type=\"submit\"]");
		if (submit) submit.before(element);
		else form.append(element);
		return element;
	}
	/**
	* Cloudflare Turnstile: a widget in the form; its script puts the answer
	* into a hidden cf-turnstile-response field of the form.
	*/
	function turnstile(form, siteKey) {
		let widget;
		loaded(() => window.turnstile).then((api) => {
			widget = api?.render(container(form), { sitekey: siteKey });
		});
		return {
			async answer(formData) {
				const api = await loaded(() => window.turnstile);
				const response = api && await loaded(() => widget !== void 0 ? api.getResponse(widget) || void 0 : void 0, 300);
				if (response) formData.set("cf-turnstile-response", response);
			},
			reset() {
				if (widget !== void 0) window.turnstile?.reset(widget);
			}
		};
	}
	/**
	* Google reCAPTCHA v3: no widget; a fresh answer is asked for each
	* submission.
	*/
	function recaptcha(siteKey) {
		return {
			async answer(formData) {
				const api = await loaded(() => window.grecaptcha?.execute ? window.grecaptcha : void 0);
				if (!api?.execute) return;
				await new Promise((resolve) => api.ready ? api.ready(resolve) : resolve());
				formData.set("g-recaptcha-response", await api.execute(siteKey, { action: RECAPTCHA_ACTION }));
			},
			reset() {}
		};
	}
	/**
	* Yandex SmartCaptcha, invisible: executed on submission, it asks the
	* visitor only when it has doubts.
	*/
	function smartcaptcha(form, siteKey) {
		let widget;
		let resolveAnswer;
		loaded(() => window.smartCaptcha).then((api) => {
			widget = api?.render(container(form), {
				sitekey: siteKey,
				invisible: true,
				callback: (token) => resolveAnswer?.(token)
			});
		});
		return {
			async answer(formData) {
				const api = await loaded(() => window.smartCaptcha);
				if (!api || widget === void 0) return;
				const token = api.getResponse(widget) || await new Promise((resolve) => {
					resolveAnswer = resolve;
					api.execute(widget);
				});
				formData.set("smart-token", token);
			},
			reset() {
				if (widget !== void 0) window.smartCaptcha?.reset(widget);
			}
		};
	}
	function createCaptcha(form, config) {
		switch (config?.provider) {
			case "turnstile": return turnstile(form, config.siteKey);
			case "recaptcha": return recaptcha(config.siteKey);
			case "smartcaptcha": return smartcaptcha(form, config.siteKey);
			default: return null;
		}
	}
	//#endregion
	//#region src/pow.ts
	const K = new Uint32Array([
		1116352408,
		1899447441,
		3049323471,
		3921009573,
		961987163,
		1508970993,
		2453635748,
		2870763221,
		3624381080,
		310598401,
		607225278,
		1426881987,
		1925078388,
		2162078206,
		2614888103,
		3248222580,
		3835390401,
		4022224774,
		264347078,
		604807628,
		770255983,
		1249150122,
		1555081692,
		1996064986,
		2554220882,
		2821834349,
		2952996808,
		3210313671,
		3336571891,
		3584528711,
		113926993,
		338241895,
		666307205,
		773529912,
		1294757372,
		1396182291,
		1695183700,
		1986661051,
		2177026350,
		2456956037,
		2730485921,
		2820302411,
		3259730800,
		3345764771,
		3516065817,
		3600352804,
		4094571909,
		275423344,
		430227734,
		506948616,
		659060556,
		883997877,
		958139571,
		1322822218,
		1537002063,
		1747873779,
		1955562222,
		2024104815,
		2227730452,
		2361852424,
		2428436474,
		2756734187,
		3204031479,
		3329325298
	]);
	const encoder = new TextEncoder();
	const rotr = (x, n) => x >>> n | x << 32 - n;
	/**
	* The SHA-256 of a string (UTF-8) as eight 32-bit words.
	*/
	function sha256(input) {
		const bytes = encoder.encode(input);
		const blocks = Math.ceil((bytes.length + 9) / 64);
		const words = new Uint32Array(blocks * 16);
		for (let i = 0; i < bytes.length; i++) words[i >> 2] |= bytes[i] << 24 - i % 4 * 8;
		words[bytes.length >> 2] |= 128 << 24 - bytes.length % 4 * 8;
		words[words.length - 1] = bytes.length * 8;
		const hash = new Uint32Array([
			1779033703,
			3144134277,
			1013904242,
			2773480762,
			1359893119,
			2600822924,
			528734635,
			1541459225
		]);
		const w = /* @__PURE__ */ new Uint32Array(64);
		for (let block = 0; block < blocks; block++) {
			for (let t = 0; t < 16; t++) w[t] = words[block * 16 + t];
			for (let t = 16; t < 64; t++) {
				const x = w[t - 15];
				const y = w[t - 2];
				const s0 = rotr(x, 7) ^ rotr(x, 18) ^ x >>> 3;
				const s1 = rotr(y, 17) ^ rotr(y, 19) ^ y >>> 10;
				w[t] = w[t - 16] + s0 + w[t - 7] + s1 | 0;
			}
			let a = hash[0], b = hash[1], c = hash[2], d = hash[3];
			let e = hash[4], f = hash[5], g = hash[6], h = hash[7];
			for (let t = 0; t < 64; t++) {
				const t1 = h + (rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25)) + (e & f ^ ~e & g) + K[t] + w[t] | 0;
				const t2 = (rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22)) + (a & b ^ a & c ^ b & c) | 0;
				h = g;
				g = f;
				f = e;
				e = d + t1 | 0;
				d = c;
				c = b;
				b = a;
				a = t1 + t2 | 0;
			}
			hash[0] = hash[0] + a;
			hash[1] = hash[1] + b;
			hash[2] = hash[2] + c;
			hash[3] = hash[3] + d;
			hash[4] = hash[4] + e;
			hash[5] = hash[5] + f;
			hash[6] = hash[6] + g;
			hash[7] = hash[7] + h;
		}
		return hash;
	}
	/**
	* The leading zero bits of a hash.
	*/
	function zeroBits(hash) {
		let bits = 0;
		for (const word of hash) {
			if (word === 0) {
				bits += 32;
				continue;
			}
			return bits + Math.clz32(word);
		}
		return bits;
	}
	/**
	* Find the solution for a token. It yields to the page every `batch`
	* attempts, so the page stays responsive; `signal` stops it.
	*/
	async function solve(token, bits, signal, batch = 2e3) {
		for (let n = 0;; n++) {
			if (zeroBits(sha256(`${token}:${n}`)) >= bits) return String(n);
			if (n % batch === batch - 1) {
				await new Promise((resolve) => setTimeout(resolve, 0));
				if (signal?.aborted) throw new Error("FetchIt: the proof of work was stopped");
			}
		}
	}
	window.FetchIt = class FetchIt {
		static forms = [];
		static instances = /* @__PURE__ */ new Map();
		static defaultRequestErrorMessage = "Could not send the form. Please try again.";
		static tokenField = "fetchit_token";
		static powField = "fetchit_pow";
		static events = {
			before: "fetchit:before",
			success: "fetchit:success",
			error: "fetchit:error",
			after: "fetchit:after",
			reset: "fetchit:reset"
		};
		solutions = /* @__PURE__ */ new Map();
		constructor(form, config) {
			if (!(form instanceof HTMLFormElement)) throw new Error("FetchIt: the element is not a form");
			this.form = form;
			this.config = config;
			this.request = new Request(this.config.actionUrl, {
				method: "post",
				credentials: "same-origin",
				headers: {
					"Accept": "application/json",
					"X-FetchIt-Action": this.config.action
				}
			});
			this.captcha = createCaptcha(this.form, this.config.captcha);
			this.prepareEvents();
			FetchIt.forms.push(this.form);
			FetchIt.instances.set(this.form, this);
		}
		prepareEvents() {
			this.form.addEventListener("submit", async (event) => {
				event.preventDefault();
				if (this.pending) return;
				this.formData = new FormData(this.form);
				this.formData.set("pageId", String(this.config.pageId));
				this.clearErrors();
				this.clearFormMessages();
				const beforeEvent = new CustomEvent(FetchIt.events.before, {
					cancelable: true,
					detail: {
						form: this.form,
						formData: this.formData,
						fetchit: this
					}
				});
				FetchIt.notify("before");
				if (!document.dispatchEvent(beforeEvent)) return;
				this.pending = true;
				this.disableFields();
				let shown = false;
				let response;
				try {
					try {
						await this.protectSubmission();
						let query = await fetch(this.request, {
							method: "post",
							body: this.formData
						});
						let next = query.headers?.get("X-FetchIt-Token");
						this.updateToken(next);
						if (next && query.headers?.get("X-FetchIt-Refused") === "token") {
							this.formData.set(FetchIt.tokenField, next);
							if (this.config.pow) this.formData.set(FetchIt.powField, await this.solution(next));
							query = await fetch(this.request, {
								method: "post",
								body: this.formData
							});
							next = query.headers?.get("X-FetchIt-Token");
							this.updateToken(next);
						}
						const body = await query.json();
						if (!FetchIt.isResponse(body)) throw new Error(`FetchIt: unexpected answer from ${query.url || this.config.actionUrl} (HTTP ${query.status})`);
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
							fetchit: this
						}
					});
					FetchIt.notify("after", response.message);
					if (!document.dispatchEvent(afterEvent)) return;
					if (!response.success) {
						FetchIt.notify("error", response.message);
						const errorEvent = new CustomEvent(FetchIt.events.error, {
							cancelable: true,
							detail: {
								form: this.form,
								formData: this.formData,
								response,
								fetchit: this
							}
						});
						if (!document.dispatchEvent(errorEvent)) return;
						for (const [name, message] of Object.entries(response.data ?? {})) {
							if (!FetchIt.hasErrorMessage(message)) continue;
							this.setError(name, message);
						}
						this.setFormMessage("validation", response.message);
						shown = true;
						return;
					}
					this.clearErrors();
					this.setFormMessage("success", response.message);
					shown = true;
					FetchIt.notify("success", response.message);
					const successEvent = new CustomEvent(FetchIt.events.success, {
						cancelable: true,
						detail: {
							form: this.form,
							formData: this.formData,
							response,
							fetchit: this
						}
					});
					if (!document.dispatchEvent(successEvent)) return;
					if (this.config.captcha?.provider !== "recaptcha") try {
						window.grecaptcha?.reset?.();
					} catch (error) {
						console.error(error);
					}
					if (this.config.clearFieldsOnSuccess) {
						this.preserveFormMessagesOnReset = true;
						this.form.reset();
						this.preserveFormMessagesOnReset = false;
					}
				} catch (error) {
					if (shown || response?.success) console.error(error);
					else this.failRequest(error);
				} finally {
					this.captcha?.reset();
					this.enableFields();
					this.pending = false;
				}
			});
			this.form.addEventListener("reset", () => {
				const resetEvent = new CustomEvent(FetchIt.events.reset, { detail: {
					form: this.form,
					fetchit: this
				} });
				document.dispatchEvent(resetEvent);
				this.clearErrors();
				if (!this.preserveFormMessagesOnReset) this.clearFormMessages();
				FetchIt.notify("reset");
			});
			["change", "input"].forEach((eventName) => {
				this.form.addEventListener(eventName, ({ target }) => {
					this.clearError(target.getAttribute("name"));
				});
			});
			this.form.addEventListener("focusin", () => this.solveAhead(), { once: true });
		}
		/**
		* fetch() rejected (network error), the body was not a FetchIt answer
		* (a PHP error page, HTML after a redirect, JSON from a firewall), or
		* handling the answer threw: tell the visitor instead of failing silently.
		* An HTTP error status with a FetchIt answer goes the normal way.
		*/
		failRequest(error) {
			console.error(error);
			const message = this.config.requestErrorMessage || FetchIt.defaultRequestErrorMessage;
			FetchIt.notify("error", message);
			const errorEvent = new CustomEvent(FetchIt.events.error, {
				cancelable: true,
				detail: {
					form: this.form,
					formData: this.formData,
					response: null,
					error,
					fetchit: this
				}
			});
			if (!document.dispatchEvent(errorEvent)) return;
			this.setFormMessage("validation", message);
		}
		/**
		* Add the solution of the proof of work and the captcha's answer.
		*/
		async protectSubmission() {
			if (this.config.pow) {
				const token = String(this.formData.get(FetchIt.tokenField) ?? "");
				this.formData.set(FetchIt.powField, await this.solution(token));
			}
			await this.captcha?.answer(this.formData);
		}
		/**
		* The solution of the proof of work for a token, started once.
		*/
		solution(token) {
			let solution = this.solutions.get(token);
			if (!solution) {
				solution = solve(token, this.config.pow ?? 0);
				this.solutions.set(token, solution);
			}
			return solution;
		}
		/**
		* Start solving for the token of the form, so the solution is ready by the
		* time the visitor sends it.
		*/
		solveAhead() {
			const token = this.form.querySelector(`input[name="${FetchIt.tokenField}"]`)?.value;
			if (this.config.pow && token) this.solution(token).catch((error) => console.error(error));
		}
		/**
		* The protection token is single-use: every answer brings the next one.
		* The value attribute changes too, so a form reset keeps it.
		*/
		updateToken(token) {
			if (!token) return;
			this.form.querySelectorAll(`input[name="${FetchIt.tokenField}"]`).forEach((input) => {
				input.value = token;
				input.defaultValue = token;
			});
			this.solveAhead();
		}
		clearErrors() {
			this.fields.forEach((field) => this.clearError(field.getAttribute("name")));
		}
		clearError(name) {
			const fields = this.getFields(name);
			fields.forEach((field) => {
				if (this.inputInvalidClasses) field.classList.remove(...this.inputInvalidClasses);
				field.removeAttribute("aria-invalid");
			});
			const errors = this.getErrors(name);
			errors.forEach((error) => {
				error.style.display = "none";
				error.innerHTML = "";
			});
			const customErrors = this.getCustomErrors(name);
			if (this.customInvalidClasses) customErrors.forEach(({ classList }) => classList.remove(...this.customInvalidClasses));
			return {
				fields,
				errors,
				customErrors
			};
		}
		setError(name, message = "") {
			if (!FetchIt.hasErrorMessage(message)) return;
			this.getFields(name).forEach((field) => {
				if (this.inputInvalidClasses) field.classList.add(...this.inputInvalidClasses);
				field.setAttribute("aria-invalid", "true");
			});
			if (this.customInvalidClasses) this.getCustomErrors(name).forEach(({ classList }) => classList.add(...this.customInvalidClasses));
			this.getErrors(name).forEach((error) => {
				const safeMessage = FetchIt.sanitizeHTML(String(message)).trim();
				error.style.display = "";
				error.textContent = safeMessage;
			});
		}
		clearFormMessages() {
			this.form.querySelectorAll("[data-success], [data-validation-error]").forEach((element) => {
				element.style.display = "none";
				element.textContent = "";
			});
		}
		setFormMessage(type, message = "") {
			const safeMessage = FetchIt.sanitizeHTML(String(message)).trim();
			if (safeMessage === "") return;
			const showSelector = type === "success" ? "[data-success]" : "[data-validation-error]";
			const hideSelector = type === "success" ? "[data-validation-error]" : "[data-success]";
			this.form.querySelectorAll(hideSelector).forEach((element) => {
				element.style.display = "none";
				element.textContent = "";
			});
			this.form.querySelectorAll(showSelector).forEach((element) => {
				element.style.display = "";
				element.textContent = safeMessage;
			});
		}
		enableFields() {
			this.elements.filter((field) => !this.disabledBefore?.includes(field)).forEach((field) => field.removeAttribute("disabled"));
		}
		disableFields() {
			this.disabledBefore = this.elements.filter((field) => field.hasAttribute("disabled"));
			this.elements.forEach((field) => field.setAttribute("disabled", ""));
		}
		getFields(name) {
			if (!name) return [];
			const value = FetchIt.escapeAttribute(name);
			return Array.from(this.form.querySelectorAll(`[name="${value}"], [name="${value}[]"]`));
		}
		getErrors(name) {
			if (!name) return [];
			const value = FetchIt.escapeAttribute(name);
			return Array.from(this.form.querySelectorAll(`[data-error="${value}"], [data-error="${value}[]"]`));
		}
		getCustomErrors(name) {
			if (!name) return [];
			return Array.from(this.form.querySelectorAll(`[data-custom="${FetchIt.escapeAttribute(name)}"]`));
		}
		get elements() {
			return Array.from(this.form.elements);
		}
		get fields() {
			return this.elements.filter(({ tagName }) => [
				"select",
				"input",
				"textarea"
			].includes(tagName.toLowerCase()));
		}
		get inputInvalidClasses() {
			return this.config.inputInvalidClass ? this.config.inputInvalidClass.split(" ") : [];
		}
		get customInvalidClasses() {
			return this.config.customInvalidClass ? this.config.customInvalidClass.split(" ") : [];
		}
		/**
		* Escape a value for a double-quoted attribute selector: only " and \
		* need it there.
		*/
		static escapeAttribute(value) {
			return value.replace(/["\\]/g, "\\$&");
		}
		/**
		* Call a FetchIt.Message hook. A broken notifier is logged; it must not
		* keep the answer from the form.
		*/
		static notify(hook, message) {
			try {
				(FetchIt.Message?.[hook])?.(message);
			} catch (error) {
				console.error(error);
			}
		}
		static isResponse(value) {
			return typeof value === "object" && value !== null && typeof value.success === "boolean";
		}
		static sanitizeHTML(str = "") {
			return str.replace(/(<([^>]+)>)/gi, "");
		}
		static hasErrorMessage(message = "") {
			return FetchIt.sanitizeHTML(String(message)).trim() !== "";
		}
		static create(config) {
			if (config.defaultNotifier && typeof window.Notyf === "function" && typeof FetchIt.Message === "undefined") {
				const notyf = new Notyf();
				FetchIt.Message = {
					success(message) {
						notyf.success(message);
					},
					error(message) {
						notyf.error(message);
					}
				};
			}
			if (!config.action) throw new Error("FetchIt: the config has no action");
			const selector = `form[data-fetchit="${FetchIt.escapeAttribute(config.action)}"]`;
			const forms = document.querySelectorAll(selector);
			if (!forms.length) {
				console.warn(`FetchIt: no form matches ${selector}`);
				return;
			}
			forms.forEach((form) => FetchIt.instances.get(form) ?? new this(form, config));
		}
	};
	//#endregion
})();
