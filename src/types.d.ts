interface FetchItConfig {
  action: string;
  actionUrl: string;
  assetsUrl?: string;
  inputInvalidClass?: string;
  customInvalidClass?: string;
  clearFieldsOnSuccess?: boolean;
  defaultNotifier?: boolean;
  requestErrorMessage?: string;
  // Bits of the proof of work, 0 to FetchItGuard::MAX_POW (24); 0 or none for no proof of work.
  pow?: number;
  captcha?: import('./captcha').CaptchaConfig | null;
  // Shown when the captcha gave no answer to send (see CaptchaError).
  captchaErrorMessage?: string;
  pageId: number | string;
}

interface FetchItResponse {
  success: boolean;
  message: string;
  data: Record<string, unknown> | unknown[];
}

interface FetchItMessage {
  before?(): void;
  after?(message: string): void;
  success?(message: string): void;
  error?(message: string): void;
  reset?(): void;
}

interface Window {
  FetchIt: typeof FetchIt;
  grecaptcha?: {
    reset?(): void;
    ready?(callback: () => void): void;
    execute?(siteKey: string, options: { action: string }): Promise<string>;
  };
  turnstile?: {
    render(element: HTMLElement, options: {
      sitekey: string;
      callback?: (token: string) => void;
      'error-callback'?: (code: string | number) => void;
    }): string;
    getResponse(widget: string): string | undefined;
    reset(widget: string): void;
  };
  smartCaptcha?: {
    render(element: HTMLElement, options: { sitekey: string; invisible?: boolean; callback?: (token: string) => void }): number;
    execute(widget: number): void;
    getResponse(widget: number): string;
    reset(widget: number): void;
    subscribe?(widget: number, event: string, callback: (detail?: unknown) => void): () => void;
  };
  Notyf?: unknown;
}

declare const Notyf: new () => {
  success(message: string): void;
  error(message: string): void;
};
