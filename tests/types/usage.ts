// How a site written in TypeScript uses the public types of FetchIt
// (assets/components/fetchit/js/fetchit.d.ts). Only compiled, never run:
// npm run typecheck fails when the types stop allowing this, or start
// allowing the lines marked @ts-expect-error. Every @ts-expect-error has a
// line before it that must compile, so a type that went missing (and makes
// everything an error) cannot pass for a check.

// Exact type equality: unlike assignability, it also catches `any`.
type Equals<A, B> = (<T>() => T extends A ? 1 : 2) extends (<T>() => T extends B ? 1 : 2) ? true : false
type Assert<T extends true> = T

// ---------------------------------------------------------------- events

type EventNames = typeof FetchIt.events[keyof typeof FetchIt.events]
export type Checks = [
  Assert<Equals<EventNames, keyof FetchItEventMap>>,
  Assert<Equals<DocumentEventMap['fetchit:before'], CustomEvent<FetchItBeforeDetail>>>,
  Assert<Equals<DocumentEventMap['fetchit:after'], CustomEvent<FetchItAfterDetail>>>,
  Assert<Equals<DocumentEventMap['fetchit:success'], CustomEvent<FetchItSuccessDetail>>>,
  Assert<Equals<DocumentEventMap['fetchit:error'], CustomEvent<FetchItErrorDetail>>>,
  Assert<Equals<DocumentEventMap['fetchit:reset'], CustomEvent<FetchItResetDetail>>>,
  Assert<Equals<FetchItBeforeDetail, { form: HTMLFormElement, formData: FormData, fetchit: FetchItInstance }>>,
  Assert<Equals<FetchItAfterDetail['response'], FetchItResponse>>,
  Assert<Equals<FetchItErrorDetail['response'], FetchItResponse | null>>,
  Assert<Equals<FetchItResetDetail, { form: HTMLFormElement, fetchit: FetchItInstance }>>,
  Assert<Equals<FetchItResponse, { success: boolean, message: string, data: Record<string, unknown> | unknown[] }>>,
  Assert<Equals<ReturnType<FetchItStatic['createNotifier']>, FetchItNotifier>>,
  Assert<Equals<ConstructorParameters<FetchItStatic>, [form: HTMLFormElement, config: FetchItConfig]>>,
  Assert<Equals<InstanceType<FetchItStatic>, FetchItInstance>>,
]

document.addEventListener('fetchit:before', event => {
  event.detail.formData.append('source', 'landing')
  if (!event.detail.form.checkValidity()) {
    event.preventDefault()
  }
})

document.addEventListener(FetchIt.events.after, event => {
  const response: FetchItResponse = event.detail.response
  console.log(response.success, response.message, response.data)
})

document.addEventListener('fetchit:success', event => {
  const form: HTMLFormElement = event.detail.form
  event.detail.fetchit.clearErrors()
  console.log(event.detail.response.message, form.id)
})

document.addEventListener('fetchit:error', event => {
  if (event.detail.response === null) {
    console.error(event.detail.error)
  }
  // @ts-expect-error The response is null when no FetchIt answer came.
  console.log(event.detail.response.message)
})

document.addEventListener('fetchit:reset', event => {
  const form: HTMLFormElement = event.detail.form
  event.detail.fetchit.clearFormMessages()
  console.log(form.id)
  // @ts-expect-error There is no form data on reset.
  console.log(event.detail.formData)
})

// ------------------------------------------------------ FetchIt.Message

window.FetchIt.Message = {
  success (message) {
    const text: string = message
    console.log(text)
  },
}

const label: string | undefined = document.documentElement.lang === 'ru' ? 'Закрыть' : undefined
FetchIt.Message = FetchIt.createNotifier({ closeLabel: label, duration: 0 })
FetchIt.Message = { ...FetchIt.createNotifier(), before () {}, after: undefined }

// ------------------------------------------------------------- instances

const form = document.querySelector('form')
const instance = form ? FetchIt.instances.get(form) : undefined
if (form && instance) {
  instance.setError('email', 'Required')
  instance.setFormMessage('validation', 'Check the form')
  instance.formData?.get('email')
  console.log(instance.config.action, FetchIt.forms.length)

  // @ts-expect-error Only success and validation messages.
  instance.setFormMessage('warning', 'Check the form')
  // @ts-expect-error No form data before the first submission.
  instance.formData.get('email')
  // @ts-expect-error The config is shared by the forms of one snippet call.
  instance.config.pow = 16
  // @ts-expect-error FetchIt keeps track of its forms itself.
  FetchIt.instances.delete(form)
}

// Every member of the public API, both ways: a member removed from the
// types is an unknown property here, a new one a missing property.
export const instanceApi: Record<keyof FetchItInstance, true> = {
  form: true, config: true, formData: true, setError: true, clearError: true, clearErrors: true,
  setFormMessage: true, clearFormMessages: true, enableFields: true, disableFields: true,
  getFields: true, getErrors: true, getCustomErrors: true, elements: true, fields: true,
}
export const staticApi: Record<keyof FetchItStatic, true> = {
  Message: true, forms: true, instances: true, events: true, defaultRequestErrorMessage: true,
  tokenField: true, powField: true, create: true, createNotifier: true, notify: true,
  isResponse: true, sanitizeHTML: true, hasErrorMessage: true, escapeAttribute: true,
}

// A class of the site on top of FetchIt (fetchit.frontend.js.classname).
export class MyFetchIt extends FetchIt {
  override setError (name: string, message?: unknown) {
    super.setError(name, message)
    this.form.classList.add('has-errors')
  }
}

// ---------------------------------------------------------------- config

FetchIt.create({ action: 'abc', actionUrl: '/assets/components/fetchit/action.php', pageId: 1, pow: undefined })
FetchIt.create({ action: 'abc', actionUrl: '/a', pageId: '1', captcha: { provider: 'turnstile', siteKey: 'key' } })
// @ts-expect-error The action is required.
FetchIt.create({ actionUrl: '/assets/components/fetchit/action.php', pageId: 1 })
// @ts-expect-error The URL of action.php is required.
FetchIt.create({ action: 'abc', pageId: 1 })
// @ts-expect-error The page is required.
FetchIt.create({ action: 'abc', actionUrl: '/a' })
// @ts-expect-error One of the three providers.
FetchIt.create({ action: 'abc', actionUrl: '/a', pageId: 1, captcha: { provider: 'hcaptcha', siteKey: 'key' } })

const answer: unknown = JSON.parse('{"success":true}')
if (FetchIt.isResponse(answer)) {
  const success: boolean = answer.success
  console.log(success)
  // @ts-expect-error The message of a raw answer is not checked.
  const message: string = answer.message
  console.log(message)
}
