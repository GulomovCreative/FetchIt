// The public types are in fetchit.d.ts; these are the globals of the
// captcha scripts, for FetchIt's own use.

interface Window {
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
}

