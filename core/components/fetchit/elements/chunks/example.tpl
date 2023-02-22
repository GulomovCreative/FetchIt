<form action="[[~[[*id]]]]" method="post">
  <fieldset>

    <div>
      <label>[[%fetchit_label_name]]
        <input type="text" name="name" value="[[+fi.name]]"/>
        <span data-error="name">[[+fi.error.name]]</span>
      </label>
    </div>

    <div>
      <label>[[%fetchit_label_email]]
        <input type="text" name="email" value="[[+fi.email]]"/>
        <span data-error="email">[[+fi.error.email]]</span>
      </label>
    </div>

    <div>
      <label>[[%fetchit_label_message]]
        <textarea name="message" rows="5">[[+fi.message]]</textarea>
        <span data-error="message">[[+fi.error.message]]</span>
      </label>
    </div>

    <div>
      <button type="reset">[[%fetchit_reset]]</button>
      <button type="submit">[[%fetchit_submit]]</button>
    </div>

    [[+fi.success:is=`1`:then=`
    <div role="alert">[[+fi.successMessage]]</div>
    `]]
    [[+fi.validation_error:is=`1`:then=`
    <div role="alert">[[+fi.validation_error_message]]</div>
    `]]
  </fieldset>
</form>
