(function() {
	//#region \0@oxc-project+runtime@0.151.0/helpers/esm/typeof.js
	function _typeof(o) {
		"@babel/helpers - typeof";
		return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function(o) {
			return typeof o;
		} : function(o) {
			return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o;
		}, _typeof(o);
	}
	//#endregion
	//#region \0@oxc-project+runtime@0.151.0/helpers/esm/toPrimitive.js
	function toPrimitive(t, r) {
		if ("object" != _typeof(t) || !t) return t;
		var e = t[Symbol.toPrimitive];
		if (void 0 !== e) {
			var i = e.call(t, r || "default");
			if ("object" != _typeof(i)) return i;
			throw new TypeError("@@toPrimitive must return a primitive value.");
		}
		return ("string" === r ? String : Number)(t);
	}
	//#endregion
	//#region \0@oxc-project+runtime@0.151.0/helpers/esm/toPropertyKey.js
	function toPropertyKey(t) {
		var i = toPrimitive(t, "string");
		return "symbol" == _typeof(i) ? i : i + "";
	}
	//#endregion
	//#region \0@oxc-project+runtime@0.151.0/helpers/esm/defineProperty.js
	function _defineProperty(e, r, t) {
		return (r = toPropertyKey(r)) in e ? Object.defineProperty(e, r, {
			value: t,
			enumerable: !0,
			configurable: !0,
			writable: !0
		}) : e[r] = t, e;
	}
	//#endregion
	//#region src/captcha.ts
	var CaptchaError = class extends Error {
		constructor(..._args) {
			super(..._args);
			_defineProperty(this, "name", "CaptchaError");
		}
	};
	const RECAPTCHA_ACTION = "fetchit";
	const SCRIPT_WAIT = 1e4;
	const ANSWER_WAIT = 3e4;
	const CHALLENGE_WAIT = 18e4;
	/**
	* Wait until read() gives something, such as the global of the provider's
	* script, checking every 100 ms; undefined after `ms`. What read() throws
	* ends the wait.
	*/
	async function waitFor(read, ms = SCRIPT_WAIT) {
		for (let waited = 0;; waited += 100) {
			const value = read();
			if (value !== void 0 || waited >= ms) return value;
			await new Promise((resolve) => setTimeout(resolve, 100));
		}
	}
	function withTimeout(promise, ms, what) {
		let timer;
		const timeout = new Promise((_, reject) => {
			timer = setTimeout(() => reject(new CaptchaError(`FetchIt: ${what} gave no answer in ${ms / 1e3} s`)), ms);
		});
		return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
	}
	function notLoaded(provider, host) {
		return new CaptchaError(`FetchIt: the script of ${provider} did not load; is ${host} blocked?`);
	}
	function safely(action) {
		try {
			action();
		} catch (error) {
			console.error(error);
		}
	}
	/**
	* The block for the widget: before the first submit button, or at the end
	* of the form.
	*/
	function container(form) {
		const element = document.createElement("div");
		element.className = "fetchit-captcha";
		const submit = form.querySelector("[type=\"submit\"]");
		if (submit) submit.before(element);
		else form.append(element);
		return element;
	}
	/**
	* Cloudflare Turnstile: a widget in the form, rendered as soon as its script
	* is there (or at the first submission, if the script came late).
	*/
	function turnstile(form, siteKey) {
		let widget;
		let failure;
		const render = (api) => {
			var _widget;
			(_widget = widget) !== null && _widget !== void 0 || (widget = api.render(container(form), {
				sitekey: siteKey,
				callback: () => {
					failure = void 0;
				},
				"error-callback": (code) => {
					failure = String(code);
					console.error(`FetchIt: Turnstile error ${code}`);
				}
			}));
			return widget;
		};
		waitFor(() => window.turnstile).then((api) => api && render(api)).catch((error) => console.error(error));
		return {
			async answer(formData) {
				const api = await waitFor(() => window.turnstile);
				if (!api) throw notLoaded("Turnstile", "challenges.cloudflare.com");
				const id = render(api);
				const response = await waitFor(() => {
					const current = api.getResponse(id);
					if (current) return current;
					if (failure !== void 0) throw new CaptchaError(`FetchIt: Turnstile failed with error ${failure}`);
				}, ANSWER_WAIT);
				if (!response) throw new CaptchaError(`FetchIt: Turnstile gave no answer in ${ANSWER_WAIT / 1e3} s`);
				formData.set("cf-turnstile-response", response);
			},
			reset() {
				if (widget !== void 0) {
					const id = widget;
					safely(() => {
						var _window$turnstile;
						return (_window$turnstile = window.turnstile) === null || _window$turnstile === void 0 ? void 0 : _window$turnstile.reset(id);
					});
				}
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
				const api = await waitFor(() => {
					var _window$grecaptcha;
					return ((_window$grecaptcha = window.grecaptcha) === null || _window$grecaptcha === void 0 ? void 0 : _window$grecaptcha.execute) ? window.grecaptcha : void 0;
				});
				const execute = api === null || api === void 0 ? void 0 : api.execute;
				if (!api || !execute) throw notLoaded("reCAPTCHA", "www.google.com");
				await withTimeout(new Promise((resolve) => api.ready ? api.ready(resolve) : resolve()), SCRIPT_WAIT, "reCAPTCHA");
				const token = await withTimeout(execute(siteKey, { action: RECAPTCHA_ACTION }), ANSWER_WAIT, "reCAPTCHA").catch((error) => {
					throw error instanceof CaptchaError ? error : new CaptchaError(`FetchIt: reCAPTCHA failed: ${String(error)}`);
				});
				formData.set("g-recaptcha-response", token);
			},
			reset() {}
		};
	}
	/**
	* Yandex SmartCaptcha, invisible: executed on submission, it shows a puzzle
	* only when it has doubts.
	*/
	function smartcaptcha(form, siteKey) {
		let widget;
		let pending;
		const settle = (token, error) => {
			const waiting = pending;
			pending = void 0;
			if (error) waiting === null || waiting === void 0 || waiting.reject(error);
			else if (token) waiting === null || waiting === void 0 || waiting.resolve(token);
		};
		const render = (api) => {
			if (widget === void 0) {
				var _api$subscribe, _api$subscribe2, _api$subscribe3;
				const id = api.render(container(form), {
					sitekey: siteKey,
					invisible: true,
					callback: (token) => settle(token)
				});
				widget = id;
				(_api$subscribe = api.subscribe) === null || _api$subscribe === void 0 || _api$subscribe.call(api, id, "challenge-hidden", () => setTimeout(() => {
					if (!api.getResponse(id)) settle(void 0, new CaptchaError("FetchIt: the check of SmartCaptcha was closed"));
				}, 1e3));
				(_api$subscribe2 = api.subscribe) === null || _api$subscribe2 === void 0 || _api$subscribe2.call(api, id, "network-error", () => settle(void 0, new CaptchaError("FetchIt: SmartCaptcha could not reach its server")));
				(_api$subscribe3 = api.subscribe) === null || _api$subscribe3 === void 0 || _api$subscribe3.call(api, id, "javascript-error", (error) => settle(void 0, new CaptchaError(`FetchIt: SmartCaptcha failed: ${JSON.stringify(error)}`)));
			}
			return widget;
		};
		waitFor(() => window.smartCaptcha).then((api) => api && render(api)).catch((error) => console.error(error));
		return {
			async answer(formData) {
				const api = await waitFor(() => window.smartCaptcha);
				if (!api) throw notLoaded("SmartCaptcha", "smartcaptcha.yandexcloud.net");
				const id = render(api);
				const token = api.getResponse(id) || await withTimeout(new Promise((resolve, reject) => {
					pending = {
						resolve,
						reject
					};
					api.execute(id);
				}), CHALLENGE_WAIT, "SmartCaptcha");
				formData.set("smart-token", token);
			},
			reset() {
				pending = void 0;
				if (widget !== void 0) {
					const id = widget;
					safely(() => {
						var _window$smartCaptcha;
						return (_window$smartCaptcha = window.smartCaptcha) === null || _window$smartCaptcha === void 0 ? void 0 : _window$smartCaptcha.reset(id);
					});
				}
			}
		};
	}
	function createCaptcha(form, config) {
		switch (config === null || config === void 0 ? void 0 : config.provider) {
			case "turnstile": return turnstile(form, config.siteKey);
			case "recaptcha": return recaptcha(config.siteKey);
			case "smartcaptcha": return smartcaptcha(form, config.siteKey);
			default: return null;
		}
	}
	//#endregion
	//#region src/text.ts
	/**
	* Drop anything tag-like between < and >: messages are shown as text.
	* Entities such as &amp; are left as they are.
	*/
	function stripTags(value = "") {
		return value.replace(/(<([^>]+)>)/gi, "");
	}
	//#endregion
	//#region src/notifier.ts
	var _document$currentScri;
	const STYLE_ID = "fetchit-toasts-style";
	const MAX_TOASTS = 3;
	const DEFAULT_DURATION = 6e3;
	const MAX_DELAY = 2 ** 31 - 1;
	const nonce = ((_document$currentScri = document.currentScript) === null || _document$currentScri === void 0 ? void 0 : _document$currentScri.nonce) || void 0;
	const CSS = `
.fetchit-toasts {
  position: fixed; z-index: 2147483000; inset-block-end: 1rem; inset-inline-end: 1rem;
  display: flex; flex-direction: column; gap: .5rem;
  width: max-content; max-width: min(24rem, calc(100vw - 2rem)); pointer-events: none;
}
.fetchit-toast {
  display: flex; align-items: flex-start; gap: .75rem; margin: 0; padding: .75rem 1rem; border-radius: .5rem;
  color: var(--fetchit-toast-success-text, #016630); background: var(--fetchit-toast-success-bg, #dcfce7);
  border: 1px solid var(--fetchit-toast-success-border, #b9f8cf);
  box-shadow: 0 .25rem .75rem rgb(0 0 0 / .08); line-height: 1.4; pointer-events: auto;
  animation: fetchit-toast-in .2s ease-out;
}
.fetchit-toast:where([data-type="error"]) {
  color: var(--fetchit-toast-error-text, #9f0712); background: var(--fetchit-toast-error-bg, #ffe2e2);
  border-color: var(--fetchit-toast-error-border, #ffc9c9);
}
@supports (color: oklch(0% 0 0)) {
  .fetchit-toast {
    color: var(--fetchit-toast-success-text, oklch(44.8% 0.119 151.328));
    background: var(--fetchit-toast-success-bg, oklch(96.2% 0.044 156.743));
    border-color: var(--fetchit-toast-success-border, oklch(92.5% 0.084 155.995));
  }
  .fetchit-toast:where([data-type="error"]) {
    color: var(--fetchit-toast-error-text, oklch(44.4% 0.177 26.899));
    background: var(--fetchit-toast-error-bg, oklch(93.6% 0.032 17.717));
    border-color: var(--fetchit-toast-error-border, oklch(88.5% 0.062 18.334));
  }
}
.fetchit-toast__text { flex: 1; overflow-wrap: anywhere; }
.fetchit-toast__close {
  flex: none; margin: 0; padding: 0 .25rem; border: 0; border-radius: .25rem; background: none; box-shadow: none;
  color: inherit; font: inherit; font-size: 1.25rem; line-height: 1; cursor: pointer; opacity: .85;
}
.fetchit-toast__close:where(:hover, :focus-visible) { opacity: 1; outline: 2px solid currentColor; outline-offset: 2px; }
@keyframes fetchit-toast-in { from { opacity: 0; transform: translateY(.5rem); } }
@media (prefers-reduced-motion: reduce) { .fetchit-toast { animation: none; } }
@media (max-width: 30rem) { .fetchit-toasts { inset-inline: 1rem; width: auto; max-width: none; } }
`;
	let warned = false;
	let origin = null;
	function addStyles() {
		var _document$head;
		if (document.getElementById(STYLE_ID)) return;
		const style = document.createElement("style");
		style.id = STYLE_ID;
		if (nonce) style.nonce = nonce;
		style.textContent = CSS;
		((_document$head = document.head) !== null && _document$head !== void 0 ? _document$head : document.documentElement).prepend(style);
		if (!style.sheet && !warned) {
			warned = true;
			console.warn("FetchIt: the styles of the notifier were blocked, probably by a Content-Security-Policy (style-src). Give the FetchIt script a nonce, or style .fetchit-toast yourself.");
		}
	}
	/**
	* An element the stylesheet cannot hide from sight but keeps for screen
	* readers; styled through the DOM, which a Content-Security-Policy allows.
	*/
	function visuallyHidden(element) {
		Object.assign(element.style, {
			position: "absolute",
			width: "1px",
			height: "1px",
			margin: "-1px",
			padding: "0",
			overflow: "hidden",
			clip: "rect(0 0 0 0)",
			whiteSpace: "nowrap",
			border: "0"
		});
	}
	function regions() {
		var _document$body;
		const parent = (_document$body = document.body) !== null && _document$body !== void 0 ? _document$body : document.documentElement;
		let toasts = document.querySelector(".fetchit-toasts");
		if (!toasts) {
			toasts = document.createElement("div");
			toasts.className = "fetchit-toasts";
			parent.append(toasts);
		}
		const live = (role) => {
			let region = document.querySelector(`.fetchit-toasts-live[role="${role}"]`);
			if (!region) {
				region = document.createElement("div");
				region.className = "fetchit-toasts-live";
				region.setAttribute("role", role);
				visuallyHidden(region);
				parent.append(region);
			}
			return region;
		};
		return {
			toasts,
			polite: live("status"),
			assertive: live("alert")
		};
	}
	/**
	* Say a message in a live region. The region is emptied first and filled a
	* moment later, so a region added just now, or the same message twice, is
	* still announced.
	*/
	function announce(region, message) {
		region.textContent = "";
		setTimeout(() => {
			region.textContent = message;
		}, 100);
	}
	function duration(value) {
		if (value === void 0) return DEFAULT_DURATION;
		if (value === 0 || value === Infinity) return 0;
		if (Number.isFinite(value) && value > 0) return Math.min(value, MAX_DELAY);
		console.warn(`FetchIt: createNotifier() got duration ${value}; using ${DEFAULT_DURATION}`);
		return DEFAULT_DURATION;
	}
	function focusable(element) {
		return element instanceof HTMLElement && element.isConnected && !element.hasAttribute("disabled");
	}
	/**
	* A toast that closes by itself after `delay` (0: never), or with its
	* button. The countdown stops while the pointer or the focus is on it and
	* starts over when both have left. When a toast with the focus goes, the
	* focus moves to the next toast, or back to where it came from.
	*/
	function show(type, message, closeLabel, delay) {
		const content = stripTags(message == null ? "" : String(message)).trim();
		if (content === "") return;
		addStyles();
		const { toasts, polite, assertive } = regions();
		const toast = document.createElement("div");
		toast.className = "fetchit-toast";
		toast.dataset.type = type;
		const text = document.createElement("div");
		text.className = "fetchit-toast__text";
		text.textContent = content;
		const close = document.createElement("button");
		close.type = "button";
		close.className = "fetchit-toast__close";
		close.setAttribute("aria-label", closeLabel);
		close.textContent = "×";
		let timer;
		let hovered = false;
		let focused = false;
		const remove = () => {
			clearTimeout(timer);
			if (toast.contains(document.activeElement)) {
				var _others$find;
				const others = Array.from(toasts.querySelectorAll(".fetchit-toast__close")).filter((button) => !toast.contains(button));
				const next = (_others$find = others.find((button) => toast.compareDocumentPosition(button) & Node.DOCUMENT_POSITION_FOLLOWING)) !== null && _others$find !== void 0 ? _others$find : others.at(-1);
				if (next) next.focus();
				else if (focusable(origin)) origin.focus();
				else {
					var _document$activeEleme;
					(_document$activeEleme = document.activeElement) === null || _document$activeEleme === void 0 || _document$activeEleme.blur();
				}
			}
			toast.remove();
		};
		const start = () => {
			clearTimeout(timer);
			if (delay > 0 && !hovered && !focused) timer = setTimeout(remove, delay);
		};
		const stop = () => clearTimeout(timer);
		close.addEventListener("click", remove);
		toast.addEventListener("mouseenter", () => {
			hovered = true;
			stop();
		});
		toast.addEventListener("mouseleave", () => {
			hovered = false;
			start();
		});
		toast.addEventListener("focusin", (event) => {
			const from = event.relatedTarget;
			if (!from || !toasts.contains(from)) origin = from;
			focused = true;
			stop();
		});
		toast.addEventListener("focusout", (event) => {
			if (!toast.contains(event.relatedTarget)) {
				focused = false;
				start();
			}
		});
		toast.append(text, close);
		toasts.append(toast);
		for (const old of Array.from(toasts.children)) {
			if (toasts.children.length <= MAX_TOASTS) break;
			if (!old.contains(document.activeElement)) old.remove();
		}
		announce(type === "error" ? assertive : polite, content);
		start();
	}
	function createNotifier(options = {}) {
		const closeLabel = options.closeLabel || "Close";
		const delay = duration(options.duration);
		if (document.body) regions();
		return {
			success(message) {
				show("success", message, closeLabel, delay);
			},
			error(message) {
				show("error", message, closeLabel, delay);
			}
		};
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
	* Find the solution for a token: `bits` comes from the server, 0 to 24. It
	* yields to the page every `batch` attempts, so the page stays responsive;
	* `signal` stops it.
	*/
	async function solve(token, bits, signal, batch = 2e3) {
		for (let n = 0;; n++) {
			if (zeroBits(sha256(`${token}:${n}`)) >= bits) return String(n);
			if (n % batch === batch - 1) {
				await new Promise((resolve) => setTimeout(resolve, 0));
				if (signal === null || signal === void 0 ? void 0 : signal.aborted) throw new Error("FetchIt: the proof of work was stopped");
			}
		}
	}
	//#endregion
	//#region src/index.ts
	/**
	* Dispatch an event of FetchIt on document, its detail checked against the
	* public types (FetchItEventMap). False when a listener cancelled it.
	*/
	function dispatch(type, detail, cancelable = true) {
		return document.dispatchEvent(new CustomEvent(type, {
			cancelable,
			detail
		}));
	}
	/**
	* A FetchIt answer as the hooks and events get it: a processing snippet may
	* leave out the message or the data, or send a message that is not a string.
	*/
	function toResponse(answer) {
		const message = answer.message;
		const data = answer.data;
		return {
			success: answer.success,
			message: typeof message === "string" ? message : message == null ? "" : String(message),
			data: data !== null && typeof data === "object" ? data : {}
		};
	}
	var FetchIt = class FetchIt {
		constructor(form, config) {
			_defineProperty(this, "formData", void 0);
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
				const formData = new FormData(this.form);
				formData.set("pageId", String(this.config.pageId));
				this.formData = formData;
				this.clearErrors();
				this.clearFormMessages();
				FetchIt.notify("before");
				if (!dispatch("fetchit:before", {
					form: this.form,
					formData,
					fetchit: this
				})) return;
				this.pending = true;
				this.disableFields();
				let shown = false;
				let response;
				try {
					var _this$config$captcha;
					try {
						var _query$headers, _query$headers2, _query$headers$get, _query$headers3, _this$config$pow;
						await this.protectSubmission(formData);
						let query = await fetch(this.request, {
							method: "post",
							body: formData
						});
						let next = (_query$headers = query.headers) === null || _query$headers === void 0 ? void 0 : _query$headers.get("X-FetchIt-Token");
						this.updateToken(next);
						const refused = (_query$headers2 = query.headers) === null || _query$headers2 === void 0 ? void 0 : _query$headers2.get("X-FetchIt-Refused");
						const bits = Number((_query$headers$get = (_query$headers3 = query.headers) === null || _query$headers3 === void 0 ? void 0 : _query$headers3.get("X-FetchIt-Pow")) !== null && _query$headers$get !== void 0 ? _query$headers$get : 0);
						if (next && (refused === "token" || refused === "pow" && bits > ((_this$config$pow = this.config.pow) !== null && _this$config$pow !== void 0 ? _this$config$pow : 0))) {
							var _query$headers4;
							if (refused === "pow") this.config.pow = bits;
							formData.set(FetchIt.tokenField, next);
							if (this.config.pow) formData.set(FetchIt.powField, await this.solution(next));
							query = await fetch(this.request, {
								method: "post",
								body: formData
							});
							next = (_query$headers4 = query.headers) === null || _query$headers4 === void 0 ? void 0 : _query$headers4.get("X-FetchIt-Token");
							this.updateToken(next);
						}
						if (refused === "captcha" && !this.config.captcha) console.warn("FetchIt: the server asks for a captcha this page does not have; the page may come from a cache made before the captcha was turned on");
						const body = await query.json();
						if (!FetchIt.isResponse(body)) throw new Error(`FetchIt: unexpected answer from ${query.url || this.config.actionUrl} (HTTP ${query.status})`);
						response = toResponse(body);
					} catch (error) {
						this.failRequest(error, formData);
						return;
					}
					FetchIt.notify("after", response.message);
					if (!dispatch("fetchit:after", {
						form: this.form,
						formData,
						response,
						fetchit: this
					})) return;
					if (!response.success) {
						FetchIt.notify("error", response.message);
						if (!dispatch("fetchit:error", {
							form: this.form,
							formData,
							response,
							fetchit: this
						})) return;
						for (const [name, message] of Object.entries(response.data)) {
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
					if (!dispatch("fetchit:success", {
						form: this.form,
						formData,
						response,
						fetchit: this
					})) return;
					if (((_this$config$captcha = this.config.captcha) === null || _this$config$captcha === void 0 ? void 0 : _this$config$captcha.provider) !== "recaptcha") try {
						var _window$grecaptcha, _window$grecaptcha$re;
						(_window$grecaptcha = window.grecaptcha) === null || _window$grecaptcha === void 0 || (_window$grecaptcha$re = _window$grecaptcha.reset) === null || _window$grecaptcha$re === void 0 || _window$grecaptcha$re.call(_window$grecaptcha);
					} catch (error) {
						console.error(error);
					}
					if (this.config.clearFieldsOnSuccess) {
						this.preserveFormMessagesOnReset = true;
						this.form.reset();
						this.preserveFormMessagesOnReset = false;
					}
				} catch (error) {
					if (shown || (response === null || response === void 0 ? void 0 : response.success)) console.error(error);
					else this.failRequest(error, formData);
				} finally {
					var _this$captcha;
					this.enableFields();
					this.pending = false;
					(_this$captcha = this.captcha) === null || _this$captcha === void 0 || _this$captcha.reset();
				}
			});
			this.form.addEventListener("reset", () => {
				dispatch("fetchit:reset", {
					form: this.form,
					fetchit: this
				}, false);
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
		* (a PHP error page, HTML after a redirect, JSON from a firewall),
		* handling the answer threw, or the captcha gave no answer to send: tell
		* the visitor instead of failing silently. An HTTP error status with a
		* FetchIt answer goes the normal way.
		*/
		failRequest(error, formData) {
			console.error(error);
			const message = error instanceof CaptchaError && this.config.captchaErrorMessage || this.config.requestErrorMessage || FetchIt.defaultRequestErrorMessage;
			FetchIt.notify("error", message);
			if (!dispatch("fetchit:error", {
				form: this.form,
				formData,
				response: null,
				error,
				fetchit: this
			})) return;
			this.setFormMessage("validation", message);
		}
		/**
		* Add the solution of the proof of work and the captcha's answer.
		*/
		async protectSubmission(formData) {
			var _this$captcha2;
			if (this.config.pow) {
				var _formData$get;
				const token = String((_formData$get = formData.get(FetchIt.tokenField)) !== null && _formData$get !== void 0 ? _formData$get : "");
				formData.set(FetchIt.powField, await this.solution(token));
			}
			await ((_this$captcha2 = this.captcha) === null || _this$captcha2 === void 0 ? void 0 : _this$captcha2.answer(formData));
		}
		/**
		* The solution of the proof of work for a token, started once; solving
		* for another token stops it.
		*/
		solution(token) {
			var _this$work;
			if (((_this$work = this.work) === null || _this$work === void 0 ? void 0 : _this$work.token) !== token) {
				var _this$work2, _this$config$pow2;
				(_this$work2 = this.work) === null || _this$work2 === void 0 || _this$work2.stop.abort();
				const stop = new AbortController();
				const solution = solve(token, (_this$config$pow2 = this.config.pow) !== null && _this$config$pow2 !== void 0 ? _this$config$pow2 : 0, stop.signal);
				const work = {
					token,
					solution,
					stop
				};
				this.work = work;
				solution.catch(() => {
					if (this.work === work) this.work = void 0;
				});
			}
			return this.work.solution;
		}
		/**
		* Start solving for the token of the form, so the solution is ready by the
		* time the visitor sends it.
		*/
		solveAhead() {
			var _this$form$querySelec;
			const token = (_this$form$querySelec = this.form.querySelector(`input[name="${FetchIt.tokenField}"]`)) === null || _this$form$querySelec === void 0 ? void 0 : _this$form$querySelec.value;
			if (this.config.pow && token) this.solution(token).catch((error) => {
				var _this$work3;
				if (((_this$work3 = this.work) === null || _this$work3 === void 0 ? void 0 : _this$work3.token) === token) console.error(error);
			});
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
			this.elements.filter((field) => {
				var _this$disabledBefore;
				return !((_this$disabledBefore = this.disabledBefore) === null || _this$disabledBefore === void 0 ? void 0 : _this$disabledBefore.includes(field));
			}).forEach((field) => field.removeAttribute("disabled"));
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
				var _FetchIt$Message, _FetchIt$Message$hook;
				(_FetchIt$Message = FetchIt.Message) === null || _FetchIt$Message === void 0 || (_FetchIt$Message$hook = _FetchIt$Message[hook]) === null || _FetchIt$Message$hook === void 0 || _FetchIt$Message$hook.call(_FetchIt$Message, message);
			} catch (error) {
				console.error(`FetchIt: FetchIt.Message.${hook}() threw; the visitor did not get this notification`, error);
			}
		}
		/**
		* Whether a value looks like a FetchIt answer: an object with a boolean
		* success. The message and the data are not checked.
		*/
		static isResponse(value) {
			return typeof value === "object" && value !== null && typeof value.success === "boolean";
		}
		static sanitizeHTML(str = "") {
			return stripTags(str);
		}
		static hasErrorMessage(message = "") {
			return FetchIt.sanitizeHTML(String(message)).trim() !== "";
		}
		static createNotifier(options) {
			return createNotifier(options);
		}
		static create(config) {
			if (config.defaultNotifier) {
				const message = FetchIt.Message;
				if (message === void 0) FetchIt.Message = createNotifier({ closeLabel: config.notifierCloseLabel });
				else if (!message.success && !message.error) Object.assign(message, createNotifier({ closeLabel: config.notifierCloseLabel }));
			}
			if (!config.action) throw new Error("FetchIt: the config has no action");
			const selector = `form[data-fetchit="${FetchIt.escapeAttribute(config.action)}"]`;
			const forms = document.querySelectorAll(selector);
			if (!forms.length) {
				console.warn(`FetchIt: no form matches ${selector}`);
				return;
			}
			forms.forEach((form) => {
				var _FetchIt$instances$ge;
				return (_FetchIt$instances$ge = FetchIt.instances.get(form)) !== null && _FetchIt$instances$ge !== void 0 ? _FetchIt$instances$ge : new this(form, config);
			});
		}
	};
	_defineProperty(FetchIt, "forms", []);
	_defineProperty(FetchIt, "instances", /* @__PURE__ */ new Map());
	_defineProperty(FetchIt, "defaultRequestErrorMessage", "Could not send the form. Please try again.");
	_defineProperty(FetchIt, "tokenField", "fetchit_token");
	_defineProperty(FetchIt, "powField", "fetchit_pow");
	_defineProperty(FetchIt, "events", {
		before: "fetchit:before",
		success: "fetchit:success",
		error: "fetchit:error",
		after: "fetchit:after",
		reset: "fetchit:reset"
	});
	window.FetchIt = FetchIt;
	//#endregion
})();
