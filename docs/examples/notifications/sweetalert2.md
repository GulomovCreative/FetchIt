# SweetAlert2

[SweetAlert2](https://sweetalert2.github.io/) это одна из самых популярных библиотек уведомлений у которой нет зависимостей. Для её подключения нам необходимо проделать следующие действия.

- Подключим скрипты и стили библиотеки. Для простоты примера сделаем это через CDN, в котором они идут одним комплектом.

```html
<!-- All in One -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>
```

- И определим свойство [`FetchIt.Message`](/guide/frontend/class#fetchit-message-object) следующим образом:

```js
document.addEventListener('DOMContentLoaded', () => {
  FetchIt.Message = {
    success(message) {
      Swal.fire({
        icon: 'success',
        title: message,
        showConfirmButton: false,
      });
    },
    error(message) {
      Swal.fire({
        icon: 'error',
        title: message,
        showConfirmButton: false,
      });
    },
  }
});
```

- Либо в своём файловом скрипте с атрибутом подключения `defer`, тогда вам не нужно накладывать обработчик на событие `DOMContentLoaded` и получить прямой доступ к классу FetchIt:

```js
FetchIt.Message = {
  success(message) {
    Swal.fire({
      icon: 'success',
      title: message,
      showConfirmButton: false,
    });
  },
  error(message) {
    Swal.fire({
      icon: 'error',
      title: message,
      showConfirmButton: false,
    });
  },
}
```

Отлично! Теперь у нас будут отображаться красивые уведомления **SweetAlert2**.
