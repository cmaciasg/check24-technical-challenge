import { expect, test, type Page } from '@playwright/test'

async function fillForm(page: Page) {
  await page.getByTestId('field-birth-date').fill('1990-05-14')
  await page.getByTestId('field-vehicle-type').selectOption('SEDAN')
  await page.getByTestId('field-usage-type').getByLabel('Private').check()
}

// Escenario 1: flujo completo con un provider caído (C está forzado a HTTP 500 en este stack).
// Se ejecuta en escritorio y en un móvil emulado: es la única comprobación automática del requisito
// mobile/desktop (product.md §5) y de que todo el sistema está bien cableado.
test('full flow with a provider down', async ({ page }) => {
  await page.goto('/')
  await fillForm(page)

  await page.getByTestId('submit').click()

  const cards = page.getByTestId('offer-card')
  await expect(cards).toHaveCount(2)
  await expect(cards.nth(0)).toHaveAttribute('data-provider-id', /provider-[ab]/)
  const providers = await cards.evaluateAll((els) => els.map((el) => el.getAttribute('data-provider-id')).sort())
  expect(providers).toEqual(['provider-a', 'provider-b'])

  await expect(page.getByTestId('provider-notice')).toHaveText('Provider C is not available right now.')
  await expect(page.getByTestId('offer-price-final').first()).toContainText('€')

  // La página no debe desbordar horizontalmente en ningún viewport.
  const overflows = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)
  expect(overflows).toBe(false)
})

// Escenario 2: recarga real de la pestaña. Solo en escritorio (testing.md §8.2).
test('a half-filled form survives a page reload', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'Only run on desktop')

  await page.goto('/')
  await page.getByTestId('field-birth-date').fill('1990-05-14')
  await page.getByTestId('field-vehicle-type').selectOption('VAN')

  await page.reload()

  await expect(page.getByTestId('field-birth-date')).toHaveValue('1990-05-14')
  await expect(page.getByTestId('field-vehicle-type')).toHaveValue('VAN')
  await expect(page.getByTestId('field-usage-type').getByLabel('Private')).not.toBeChecked()
})
