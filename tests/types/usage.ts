// How a site written in TypeScript uses the public types of FetchIt
// (assets/components/fetchit/js/fetchit.d.ts). Only compiled, never run:
// npm run typecheck fails when the types stop allowing this, or start
// allowing the lines marked @ts-expect-error.

document.addEventListener('fetchit:success', event => {
  const response: FetchItResponse = event.detail.response
  const form: HTMLFormElement = event.detail.form
  event.detail.fetchit.clearErrors()
  console.log(response.message, form.id)
})

document.addEventListener('fetchit:error', event => {
  // @ts-expect-error The response is null when the request failed.
  console.log(event.detail.response.message)
  if (event.detail.response === null) {
    console.error(event.detail.error)
  }
})

document.addEventListener(FetchIt.events.before, event => {
  event.detail.formData.append('source', 'landing')
  if (!event.detail.form.checkValidity()) {
    event.preventDefault()
  }
})

document.addEventListener('fetchit:reset', event => {
  // @ts-expect-error There is no form data on reset.
  console.log(event.detail.formData)
})

window.FetchIt.Message = {
  success (message) {
    const text: string = message
    console.log(text)
  },
}

FetchIt.Message = FetchIt.createNotifier({ closeLabel: 'Закрыть', duration: 4000 })

const instance = FetchIt.instances.get(document.forms[0]!)
instance?.setError('email', 'Required')
instance?.setFormMessage('validation', 'Check the form')
// @ts-expect-error Only success and validation messages.
instance?.setFormMessage('warning', 'Check the form')

FetchIt.create({ action: 'abc', actionUrl: '/assets/components/fetchit/action.php', pageId: 1 })
// @ts-expect-error The action is required.
FetchIt.create({ actionUrl: '/assets/components/fetchit/action.php', pageId: 1 })
