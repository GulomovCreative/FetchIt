interface FetchItConfig {
  action: string;
  actionUrl: string;
  assetsUrl?: string;
  inputInvalidClass?: string;
  customInvalidClass?: string;
  clearFieldsOnSuccess?: boolean;
  defaultNotifier?: boolean;
  requestErrorMessage?: string;
  pow?: number;
  captcha?: { provider: 'turnstile' | 'recaptcha' | 'smartcaptcha'; siteKey: string } | null;
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
    render(element: HTMLElement, options: { sitekey: string }): string;
    getResponse(widget: string): string | undefined;
    reset(widget: string): void;
  };
  smartCaptcha?: {
    render(element: HTMLElement, options: { sitekey: string; invisible?: boolean; callback?: (token: string) => void }): number;
    execute(widget: number): void;
    getResponse(widget: number): string;
    reset(widget: number): void;
  };
  Notyf?: unknown;
}

declare const Notyf: new () => {
  success(message: string): void;
  error(message: string): void;
};
