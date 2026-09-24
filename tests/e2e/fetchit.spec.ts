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
