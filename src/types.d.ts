interface FetchItConfig {
  action: string;
  actionUrl: string;
  assetsUrl?: string;
  inputInvalidClass?: string;
  customInvalidClass?: string;
  clearFieldsOnSuccess?: boolean;
  defaultNotifier?: boolean;
  requestErrorMessage?: string;
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
  grecaptcha?: { reset(): void };
  Notyf?: unknown;
}

declare const Notyf: new () => {
  success(message: string): void;
  error(message: string): void;
};
