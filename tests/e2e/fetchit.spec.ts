import { expect, test } from '@playwright/test'

const fixtures: { custom: number, formit: number | null, mixed?: number | null } = JSON.parse(process.env.FIXTURES ?? '{}')

test.describe('form with its own handler', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`/index.php?id=${fixtures.custom}`)
  })

  test('shows the field error and the form message', async ({ page }) => {
    await page.getByRole('button', { name: 'Send' }).click()

    await expect(page.locator('[data-error="email"]')).toHaveText('Email is required')
    await expect(page.locator('input[name="email"]')).toHaveAttribute('aria-invalid', 'true')
    await expect(page.locator('input[name="email"]')).toHaveClass(/is-invalid/)
    await expect(page.locator('[data-validation-error]')).toHaveText('Check the form')
    await expect(page.locator('[data-error="topics"]')).toHaveText('Pick a topic')
    await expect(page.locator('input[name="topics[]"]').first()).toHaveAttribute('aria-invalid', 'true')
  })

  test('sends checkboxes as an array and uploads a file', async ({ page }) => {
    await page.locator('input[name="email"]').fill('ann@example.com')
    await page.getByLabel('News').check()
    await page.getByLabel('Events').check()
    await page.locator('input[name="attachment"]').setInputFiles({
      name: 'hello.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('hello'),
    })

    const answer = page.waitForResponse('**/action.php')
    await page.getByRole('button', { name: 'Send' }).click()
    const body = await (await answer).json()

    expect(body.data.topics).toEqual(['news', 'events'])
    expect(body.data.file).toBe('hello.txt:5')
    await expect(page.locator('[data-success]')).toHaveText('Thanks, ann@example.com')
  })

  test('clears the error once the field changes', async ({ page }) => {
    await page.getByRole('button', { name: 'Send' }).click()
    await expect(page.locator('[data-error="email"]')).toHaveText('Email is required')

    await page.locator('input[name="email"]').fill('a')

    await expect(page.locator('[data-error="email"]')).toBeHidden()
    await expect(page.locator('input[name="email"]')).not.toHaveAttribute('aria-invalid')
  })

  test('submits without a page reload and resets the form', async ({ page }) => {
    let reloaded = false
    page.on('framenavigated', () => { reloaded = true })

    await page.locator('input[name="email"]').fill('ann@example.com')
    await page.getByRole('button', { name: 'Send' }).click()

    await expect(page.locator('[data-success]')).toHaveText('Thanks, ann@example.com')
    await expect(page.locator('input[name="email"]')).toHaveValue('')
    expect(reloaded).toBe(false)
  })

  test('shows no toasts without the setting', async ({ page }) => {
    await page.locator('input[name="email"]').fill('ann@example.com')
    await page.getByRole('button', { name: 'Send' }).click()

    await expect(page.locator('[data-success]')).toHaveText('Thanks, ann@example.com')
    await expect(page.locator('.fetchit-toast')).toHaveCount(0)
  })
})

test.describe('spam protection', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`/index.php?id=${fixtures.custom}`)
  })

  test('hides the trap from people', async ({ page }) => {
    // Its name is random per installation.
    const trap = page.locator('input[name^="fetchit_"]:not([name="fetchit_token"])')

    await expect(trap).toHaveCount(1)
    await expect(trap).not.toBeInViewport()
    // aria-hidden keeps it from screen readers too.
    await expect(page.getByRole('textbox', { name: 'Leave this field empty' })).toHaveCount(0)
  })

  test('sends the form again without a reload', async ({ page }) => {
    // Every token is single-use: the second send needs the one from the answer.
    const email = page.locator('input[name="email"]')
    const send = page.getByRole('button', { name: 'Send' })

    await email.fill('first@example.com')
    await send.click()
    await expect(page.locator('[data-success]')).toHaveText('Thanks, first@example.com')

    await email.fill('second@example.com')
    await send.click()
    await expect(page.locator('[data-success]')).toHaveText('Thanks, second@example.com')
  })
})

test.describe('stale token', () => {
  test('a used token is replaced without the visitor noticing', async ({ page }) => {
    // As on a page from a full-page cache: its token was used by someone else.
    await page.goto(`/index.php?id=${fixtures.custom}`)
    const token = page.locator('input[name="fetchit_token"]')
    const stale = await token.inputValue()
    await page.locator('input[name="email"]').fill('first@example.com')
    await page.getByRole('button', { name: 'Send' }).click()
    await expect(page.locator('[data-success]')).toHaveText('Thanks, first@example.com')

    await token.evaluate((input, value) => { (input as HTMLInputElement).value = value }, stale)
    await page.locator('input[name="email"]').fill('second@example.com')
    await page.getByRole('button', { name: 'Send' }).click()

    await expect(page.locator('[data-success]')).toHaveText('Thanks, second@example.com')
  })
})

test.describe('fill time @timing', () => {
  test('a visitor fixing a field right after an error is not refused', async ({ page }) => {
    await page.goto(`/index.php?id=${fixtures.custom}`)
    await page.waitForTimeout(3500)

    await page.getByRole('button', { name: 'Send' }).click()
    await expect(page.locator('[data-error="email"]')).toHaveText('Email is required')

    // Right away: the next token keeps the time the page was loaded.
    await page.locator('input[name="email"]').fill('ann@example.com')
    await page.getByRole('button', { name: 'Send' }).click()

    await expect(page.locator('[data-success]')).toHaveText('Thanks, ann@example.com')
  })
})

test.describe('proof of work @pow', () => {
  test('the browser solves it and the form is sent', async ({ page }) => {
    await page.goto(`/index.php?id=${fixtures.custom}`)
    const request = page.waitForRequest('**/action.php')

    await page.locator('input[name="email"]').fill('ann@example.com')
    await page.getByRole('button', { name: 'Send' }).click()

    await expect(page.locator('[data-success]')).toHaveText('Thanks, ann@example.com')
    expect((await request).postData()).toContain('name="fetchit_pow"')
  })
})

test.describe('Turnstile @captcha', () => {
  test('the widget answers and the form is sent', async ({ page }) => {
    await page.goto(`/index.php?id=${fixtures.custom}`)
    // The test site key of Cloudflare: a widget that always passes. Its
    // iframe is in a closed shadow root; its answer lands in the form.
    await expect(page.locator('form .fetchit-captcha input[name="cf-turnstile-response"]'))
      .not.toHaveValue('', { timeout: 15_000 })

    await page.locator('input[name="email"]').fill('ann@example.com')
    await page.getByRole('button', { name: 'Send' }).click()

    await expect(page.locator('[data-success]')).toHaveText('Thanks, ann@example.com', { timeout: 30_000 })
  })
})

test.describe('broken server', () => {
  test('tells the visitor when the answer is not JSON', async ({ page }) => {
    await page.route('**/action.php', route => route.fulfill({
      status: 500,
      contentType: 'text/html',
      body: '<b>Fatal error</b>',
    }))
    await page.goto(`/index.php?id=${fixtures.custom}`)

    await page.locator('input[name="email"]').fill('ann@example.com')
    await page.getByRole('button', { name: 'Send' }).click()

    await expect(page.locator('[data-validation-error]')).toHaveText('Could not send the form. Please try again.')
    await expect(page.locator('input[name="email"]')).toBeEnabled()
  })
})

test.describe('form processed by FormIt', () => {
  test.skip(!fixtures.formit, 'FormIt is not installed')

  test.beforeEach(async ({ page }) => {
    await page.goto(`/index.php?id=${fixtures.formit}`)
  })

  test('reports the FormIt validation error', async ({ page }) => {
    await page.locator('input[name="email"]').fill('not-an-email')
    await page.getByRole('button', { name: 'Send' }).click()

    await expect(page.locator('[data-error="email"]')).not.toBeEmpty()
    await expect(page.locator('input[name="email"]')).toHaveAttribute('aria-invalid', 'true')
  })

  test('shows the success message from the snippet call', async ({ page }) => {
    await page.locator('input[name="email"]').fill('ann@example.com')
    await page.getByRole('button', { name: 'Send' }).click()

    await expect(page.locator('[data-success]')).toHaveText('Sent')
  })
})

test.describe('a form of FormIt in its AJAX mode next to a FetchIt form', () => {
  // FormIt 5.2 and later; FetchIt turns the AJAX mode off for its own forms only.
  test.skip(!fixtures.mixed, 'FormIt has no AJAX mode')

  test('both are sent, each its own way', async ({ page }) => {
    await page.goto(`/index.php?id=${fixtures.mixed}`)
    const own = page.locator('#own')

    await own.getByRole('textbox', { name: 'Own' }).fill('hello')
    await own.getByRole('button', { name: 'Own' }).click()
    await expect(own.locator('[data-formit-success-message]')).toHaveText('Own sent')

    const fetchit = page.locator('form[data-fetchit]')
    await fetchit.locator('input[name="email"]').fill('ann@example.com')
    await fetchit.getByRole('button', { name: 'Send' }).click()
    await expect(fetchit.locator('[data-success]')).toHaveText('Sent')
  })
})

test.describe('default notifier @notifier', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`/index.php?id=${fixtures.custom}`)
  })

  test('shows the answers as toasts, with no other files', async ({ page }) => {
    await expect(page.locator('link[rel="stylesheet"][href*="components/fetchit"]')).toHaveCount(0)
    const toasts = page.locator('.fetchit-toasts')

    await page.getByRole('button', { name: 'Send' }).click()
    const error = toasts.locator('.fetchit-toast', { hasText: 'Check the form' })
    await expect(error).toBeVisible()
    // Screen readers hear it through the live region, not the toast.
    await expect(page.locator('.fetchit-toasts-live[role="alert"]')).toHaveText('Check the form')
    await error.getByRole('button', { name: 'Close' }).click()
    await expect(error).toHaveCount(0)

    await page.locator('input[name="email"]').fill('ann@example.com')
    await page.getByRole('button', { name: 'Send' }).click()
    await expect(toasts.locator('.fetchit-toast', { hasText: 'Thanks, ann@example.com' })).toBeVisible()
    await expect(page.locator('.fetchit-toasts-live[role="status"]')).toHaveText('Thanks, ann@example.com')
  })
})
