# Модальные окна tingle.js

В данном разделе разберём пример работы с модальными окнами [tingle.js](https://tingle.robinparisi.com/).

## Открытие модального окна

Если у вас есть задача окрыть модальное окно после успешной отправки формы, то её можно решить двумя способами:

1. С помощью события [`fetchit:success`](/guide/frontend/events#fetchit-success).

```js
const successModal = new tingle.modal();

document.addEventListener('fetchit:success', ({ detail: { response: { message } } }) => {
  successModal.setContent(message);
  successModal.open();
});
```

2. С помощью [`FetchIt.Message`](/guide/frontend/class#fetchit-message-object).

```js
const successModal = new tingle.modal();

FetchIt.Message = {
  // ...
  success (message) {
    successModal.setContent(message);
    successModal.open();
  },
}
```
