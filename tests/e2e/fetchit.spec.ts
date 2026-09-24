import { expect, test } from '@playwright/test'

const fixtures: { custom: number, formit: number | null } = JSON.parse(process.env.FIXTURES ?? '{}')

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

test.describe('default notifier @notifier', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`/index.php?id=${fixtures.custom}`)
  })

  test('links Notyf and shows the answers as toasts', async ({ page }) => {
    await expect(page.locator('link[href*="lib/notyf.min.css"]')).toHaveCount(1)

    await page.getByRole('button', { name: 'Send' }).click()
    await expect(page.locator('.notyf__toast', { hasText: 'Check the form' })).toBeVisible()

    await page.locator('input[name="email"]').fill('ann@example.com')
    await page.getByRole('button', { name: 'Send' }).click()
    await expect(page.locator('.notyf__toast', { hasText: 'Thanks, ann@example.com' })).toBeVisible()
  })
})
