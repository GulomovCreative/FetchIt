# Миграция с AjaxForm

В данном разделе представлена информация о миграции с **AjaxForm** на **FetchIt**.

## Стили

**FetchIt** не регистрирует никакие стили. Поэтому в вашей вёрстке уже должны быть предусмотрены стили отвечающие за невалидное состояние полей и должны быть добавлены в системную настройки [`fetchit.frontend.input.invalid.class`](/guide/settings) и [`fetchit.frontend.custom.invalid.class`](/guide/settings).

## Всплывающие сообщения

В отличии от **AjaxForm** у которого в коробке идёт зависимость ввиде **jGrowl**, мы предоставляем возможность добавить любую готовую библиотеку или вашу собственную несколькими строчками кода.

В этой документации есть целый раздел с подключением всех популярных и не очень библиотек.

[Примеры подключения](/examples/#всплывающие-сообщения).

## Вызов сниппета

Сниппет **FetchIt** сохранил основные параметры нетронутыми, но мы перенесли некоторые из них в [системные настройки](/guide/settings).

:::code-group
```html [Шаблонизатор MODX]
[[!FetchIt?
  &form=`название чанка`
  &snippet=`FormIt`
  &actionUrl=`[[+assetsUrl]]action.php`
  &clearFieldsOnSuccess=`1`
  &frontend_js=`` // [!code warning] Системная настройка: fetchit.frontend.js
  &objectName=`` // [!code warning] Системная настройка: fetchit.frontend.js.classname
  &frontend_css=`` // [!code --]
  &formSelector=`` // [!code --]
]]
```
```html [fenom]
{'!FetchIt' | snippet : [
  'form' => 'название чанка',
  'snippet' => 'FormIt',
  'actionUrl' => '[[+assetsUrl]]action.php',
  'clearFieldsOnSuccess' => true,
  'frontend_js' => '', // [!code warning] Системная настройка: fetchit.frontend.js
  'objectName' => '', // [!code warning] Системная настройка: fetchit.frontend.js.classname
  'frontend_css' => '', // [!code --]
  'formSelector' => '', // [!code --]
]}
```
:::

## Разметка формы

Сама разметка формы не изменилась, а лишь изменились [селекторы](/guide/selectors). Посмотрим на чанк с формой для примера **AjaxForm**:

```html
<form action="[[~[[*id]]]]" method="post" class="ajax_form"> // [!code --]
<form action="[[~[[*id]]]]" method="post"> // [!code ++]

  <div class="form-group">
    <label class="control-label">Имя</label>
    <div class="controls">
      <input type="text" name="name" value="[[+fi.name]]" class="form-control"/>
      <span class="error_name">[[+fi.error.name]]</span> // [!code --]
      <span data-error="name">[[+fi.error.name]]</span> // [!code ++]
    </div>
  </div>

  <div class="form-group">
    <label class="control-label">Email</label>
    <div class="controls">
      <input type="email" name="email" value="[[+fi.email]]" class="form-control"/>
      <span class="error_email">[[+fi.error.email]]</span> // [!code --]
      <span data-error="email">[[+fi.error.email]]</span> // [!code ++]
    </div>
  </div>

  <div class="form-group">
    <label class="control-label">Сообщение</label>
    <div class="controls">
      <textarea name="message" class="form-control" rows="5">[[+fi.message]]</textarea>
      <span class="error_message">[[+fi.error.message]]</span> // [!code --]
      <span data-error="message">[[+fi.error.message]]</span> // [!code ++]
    </div>
  </div>

  <div class="form-group">
    <div class="controls">
      <button type="reset" class="btn btn-default">Сбросить</button>
      <button type="submit" class="btn btn-primary">Отправить</button>
    </div>
  </div>
</form>
```

## Валидация на стороне клиента

Если у вас была валидация на стороне клиента в таком виде:

```js
$(document).on('submit', '.ajax_form', function() {
  // Код валидации
  afValidated = false;
});
```

То вам нужно переписать ваш код так:

```js
document.addEventListener('fetchit:before', (e) => {
  const { form, fetchit } = e.detail; // Получаем форму и экземпляр класса FetchIt

  // Код валидации

  // Если не прошла валидация
  fetchit.setError('название_поля', 'Выводимое сообщение'); // Необязательно
  e.preventDefault();

  // Если прошла, то можем ничего не делать
});
```

:::danger Важно!
Валидация на стороне клиента небезопасна и должна быть реализована только для удобства пользователя.
:::

## Событие `af_complete`

У **AjaxForm** есть одно событие, и оно срабатывает после успешной отправки формы. Аналогичным событием является [fetchit:after](/guide/frontend/events#fetchit-after).

Было:
```js
$(document).on('af_complete', function(event, response) {
  var form = response.form;
  // Если у формы определённый id
  if (form.attr('id') == 'my_form_3') {
    // Скрываем её!
    form.hide();
  }
  // Иначе печатаем в консоль весь ответ
  else {
    console.log(response)
  }
});
```

Стало:
```js
document.addEventListener('fetchit:after', (e) => {
  const { form, response } = e.detail;
  // Если у формы определённый id
  if (form.getAttribute('id') === 'my_form_3') {
    // Скрываем её!
    form.style.display = 'none';
  }
  // Иначе печатаем в консоль весь ответ
  else {
    console.log(response);
  }
});
```

:::warning Обратите внимание
События компонента **FetchIt** не возвращают **jQuery Object** и не имеют ничего общего с **jQuery** и поэтому у **form** естественно не будет методов **attr()** или **hide()**.
:::
