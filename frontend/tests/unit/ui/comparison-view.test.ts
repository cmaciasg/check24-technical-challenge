import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ComparisonView from '../../../src/comparator/car-insurance/ui/ComparisonView.vue'
import { offer, response, status, validInput } from '../support/fixtures'

// El store real usa `postComparison`: se sustituye por un doble sin tocar la red.
const api = vi.hoisted(() => vi.fn())
vi.mock('../../../src/comparator/car-insurance/infra/comparison-api', () => ({ postComparison: api }))

let wrapper: VueWrapper

function mountView(): VueWrapper {
  wrapper = mount(ComparisonView, { global: { plugins: [createPinia()] }, attachTo: document.body })
  return wrapper
}

const byId = (id: string) => wrapper.find(`[data-testid="${id}"]`)
const allById = (id: string) => wrapper.findAll(`[data-testid="${id}"]`)

async function fillForm(input = validInput): Promise<void> {
  await byId('field-birth-date').setValue(input.birthDate)
  await byId('field-vehicle-type').setValue(input.vehicleType)
  await wrapper.find(`input[type="radio"][value="${input.usageType}"]`).setValue(true)
}

async function submit(): Promise<void> {
  await wrapper.find('form').trigger('submit')
  await flushPromises()
}

describe('ComparisonView', () => {
  beforeEach(() => {
    api.mockReset()
    window.sessionStorage.clear()
  })

  afterEach(() => {
    wrapper.unmount()
  })

  it('shows only the form before the first submit', () => {
    mountView()

    expect(byId('field-birth-date').exists()).toBe(true)
    expect(byId('field-vehicle-type').exists()).toBe(true)
    expect(byId('field-usage-type').exists()).toBe(true)
    expect(byId('submit').exists()).toBe(true)
    for (const id of ['state-loading', 'state-error', 'state-empty', 'offer-card']) {
      expect(byId(id).exists()).toBe(false)
    }
  })

  it('offers the four vehicle types and the two usage types', () => {
    mountView()

    const options = byId('field-vehicle-type').findAll('option').map((o) => o.attributes('value'))
    expect(options).toEqual(['', 'SEDAN', 'HATCHBACK', 'SUV', 'VAN'])
    expect(wrapper.findAll('input[type="radio"]').map((r) => r.attributes('value'))).toEqual(['PRIVATE', 'COMMERCIAL'])
  })

  it('limits the date picker to today at most', () => {
    mountView()

    expect(byId('field-birth-date').attributes('max')).toBe(new Date().toISOString().slice(0, 10))
  })

  describe('validation', () => {
    it('sends nothing, shows an error under each invalid field and focuses the first one', async () => {
      mountView()

      await submit()

      expect(api).not.toHaveBeenCalled()
      expect(wrapper.findAll('.field__error')).toHaveLength(3)
      expect(document.activeElement).toBe(byId('field-birth-date').element)
    })

    it('links each error to its field for assistive technology', async () => {
      mountView()

      await submit()

      expect(byId('field-birth-date').attributes('aria-describedby')).toBe('birth-date-error')
      expect(wrapper.find('#birth-date-error').exists()).toBe(true)
      expect(byId('field-birth-date').attributes('aria-invalid')).toBe('true')
    })

    it('focuses the first invalid field when only a later one is wrong', async () => {
      mountView()
      await byId('field-birth-date').setValue('1990-05-14')
      await byId('field-vehicle-type').setValue('SUV')

      await submit()

      expect(document.activeElement).toBe(wrapper.find('input[type="radio"]').element)
    })

    it('validates a field when it loses focus', async () => {
      mountView()

      await byId('field-birth-date').setValue('2999-01-01')
      await byId('field-birth-date').trigger('blur')

      expect(wrapper.find('#birth-date-error').text()).toBe('The date of birth must be in the past.')
    })

    it('never disables the button because of validity', () => {
      mountView()

      expect(byId('submit').attributes('disabled')).toBeUndefined()
    })
  })

  describe('loading', () => {
    it('disables the button, shows progress and hides the previous results while waiting', async () => {
      api.mockResolvedValueOnce({ status: 200, data: response() })
      mountView()
      await fillForm()
      await submit()
      expect(byId('offer-card').exists()).toBe(true)

      api.mockReturnValueOnce(new Promise(() => undefined))
      await submit()

      expect(byId('state-loading').exists()).toBe(true)
      expect(byId('submit').attributes('disabled')).toBeDefined()
      expect(byId('submit').text()).toBe('Comparing…')
      expect(byId('offer-card').exists()).toBe(false)
    })
  })

  describe('offers', () => {
    it('shows one card per offer ordered by price, with a notice per failed provider', async () => {
      api.mockResolvedValue({
        status: 200,
        data: response({
          offers: [
            offer({ providerId: 'provider-b', providerName: 'Provider B', priceAmountCents: 4890, providerQuoteReference: 'B-9182' }),
            offer({ providerId: 'provider-a', providerName: 'Provider A', priceAmountCents: 4250 }),
          ],
          providerStatuses: [
            status({ providerId: 'provider-a' }),
            status({ providerId: 'provider-b', providerName: 'Provider B' }),
            status({ providerId: 'provider-c', providerName: 'Provider C', status: 'failed', reason: 'timeout', elapsedMs: 2001 }),
          ],
        }),
      })
      mountView()
      await fillForm()

      await submit()

      const cards = allById('offer-card')
      expect(cards.map((c) => c.attributes('data-provider-id'))).toEqual(['provider-a', 'provider-b'])
      expect(cards[0]?.text()).toContain('Provider A')
      expect(cards[0]?.text()).toContain('Standard')
      expect(cards[0]?.text()).toContain('A-7f3c1e')
      expect(cards[0]?.find('[data-testid="offer-price-final"]').text()).toBe('€42.50')

      const notices = allById('provider-notice')
      expect(notices).toHaveLength(1)
      expect(notices[0]?.text()).toBe('Provider C is not available right now.')
    })

    it('never explains a failed provider with its reason or its elapsed time', async () => {
      api.mockResolvedValue({
        status: 200,
        data: response({ providerStatuses: [status({ providerId: 'provider-c', providerName: 'Provider C', status: 'failed', reason: 'malformed_response', elapsedMs: 2001 })] }),
      })
      mountView()
      await fillForm()

      await submit()

      const text = byId('results-panel').text()
      expect(text).not.toContain('malformed')
      expect(text).not.toContain('2001')
    })

    it('uses the provider name from the backend, never one derived from the provider id', async () => {
      api.mockResolvedValue({
        status: 200,
        data: response({ providerStatuses: [status({ providerId: 'provider-c', providerName: 'Acme Insurance', status: 'failed', reason: 'timeout' })] }),
      })
      mountView()
      await fillForm()

      await submit()

      expect(byId('provider-notice').text()).toBe('Acme Insurance is not available right now.')
    })

    it('renders the results panel as a polite live region', () => {
      mountView()

      expect(byId('results-panel').attributes('aria-live')).toBe('polite')
    })
  })

  // TC-P4 / TC-P5: campaña
  describe('campaign', () => {
    it('TC-P5 shows the original price struck through next to the final price', async () => {
      api.mockResolvedValue({
        status: 200,
        data: response({ offers: [offer({ priceAmountCents: 4250, discountedPriceAmountCents: 3825, campaignApplied: true })] }),
      })
      mountView()
      await fillForm()

      await submit()

      const original = byId('offer-price-original')
      expect(original.text()).toBe('€42.50')
      expect(original.element.tagName).toBe('S')
      expect(byId('offer-price-final').text()).toBe('€38.25')
    })

    it('TC-P4 shows only the price when there is no campaign', async () => {
      api.mockResolvedValue({ status: 200, data: response() })
      mountView()
      await fillForm()

      await submit()

      expect(byId('offer-price-original').exists()).toBe(false)
      expect(byId('offer-price-final').text()).toBe('€42.50')
    })

    it('shows a zero final price when the discount covers everything', async () => {
      api.mockResolvedValue({
        status: 200,
        data: response({ offers: [offer({ priceAmountCents: 4250, discountedPriceAmountCents: 0, campaignApplied: true })] }),
      })
      mountView()
      await fillForm()

      await submit()

      expect(byId('offer-price-final').text()).toBe('€0.00')
    })

    it('sorts by the discounted price when there is a campaign', async () => {
      api.mockResolvedValue({
        status: 200,
        data: response({
          offers: [
            offer({ providerId: 'a', providerName: 'A', priceAmountCents: 3000, discountedPriceAmountCents: 2900, campaignApplied: true }),
            offer({ providerId: 'b', providerName: 'B', priceAmountCents: 5000, discountedPriceAmountCents: 2000, campaignApplied: true }),
          ],
        }),
      })
      mountView()
      await fillForm()

      await submit()

      expect(allById('offer-card').map((c) => c.attributes('data-provider-id'))).toEqual(['b', 'a'])
    })
  })

  describe('empty result', () => {
    const emptyResponse = () =>
      response({
        offers: [],
        providerStatuses: [
          status({ providerId: 'provider-a', providerName: 'Provider A', status: 'failed', reason: 'timeout' }),
          status({ providerId: 'provider-b', providerName: 'Provider B', status: 'failed', reason: 'http_error' }),
          status({ providerId: 'provider-c', providerName: 'Provider C', status: 'failed', reason: 'unknown_error' }),
        ],
      })

    it('shows a clear empty state with a notice per provider, not a blank screen or an error', async () => {
      api.mockResolvedValue({ status: 200, data: emptyResponse() })
      mountView()
      await fillForm()

      await submit()

      expect(byId('state-empty').exists()).toBe(true)
      expect(byId('state-error').exists()).toBe(false)
      expect(allById('provider-notice').map((n) => n.text())).toEqual([
        'Provider A is not available right now.',
        'Provider B is not available right now.',
        'Provider C is not available right now.',
      ])
    })

    it('"Compare again" resubmits the current form', async () => {
      api.mockResolvedValue({ status: 200, data: emptyResponse() })
      mountView()
      await fillForm()
      await submit()
      api.mockResolvedValue({ status: 200, data: response() })

      await byId('state-empty').find('button').trigger('click')
      await flushPromises()

      expect(api).toHaveBeenCalledTimes(2)
      expect(api).toHaveBeenLastCalledWith(validInput)
      expect(byId('offer-card').exists()).toBe(true)
    })
  })

  describe('errors', () => {
    it('a 400 asks to review the data, without a retry button and without the server body', async () => {
      api.mockResolvedValue({ status: 400 })
      mountView()
      await fillForm()

      await submit()

      const error = byId('state-error')
      expect(error.text()).toContain('Please check your details')
      expect(error.find('button').exists()).toBe(false)
    })

    it.each([
      ['a 500', () => api.mockResolvedValue({ status: 500 })],
      ['a network failure', () => api.mockRejectedValue(new TypeError('Failed to fetch'))],
    ])('%s shows a temporary failure with a retry button', async (_name, arrange) => {
      arrange()
      mountView()
      await fillForm()

      await submit()

      const error = byId('state-error')
      expect(error.text()).toContain('Something went wrong')
      expect(error.find('button').text()).toBe('Retry')
    })

    it('the retry button resubmits and recovers', async () => {
      api.mockResolvedValueOnce({ status: 500 })
      mountView()
      await fillForm()
      await submit()
      api.mockResolvedValueOnce({ status: 200, data: response() })

      await byId('state-error').find('button').trigger('click')
      await flushPromises()

      expect(byId('state-error').exists()).toBe(false)
      expect(byId('offer-card').exists()).toBe(true)
    })

    it('the two error kinds show different messages', async () => {
      api.mockResolvedValueOnce({ status: 400 })
      mountView()
      await fillForm()
      await submit()
      const invalidText = byId('state-error').text()

      api.mockResolvedValueOnce({ status: 500 })
      await submit()

      expect(byId('state-error').text()).not.toBe(invalidText)
    })
  })

  describe('form persistence in the view', () => {
    it('keeps what was typed after a successful comparison so it can be compared again', async () => {
      api.mockResolvedValue({ status: 200, data: response() })
      mountView()
      await fillForm()

      await submit()

      expect((byId('field-birth-date').element as HTMLInputElement).value).toBe('1990-05-14')
      expect((byId('field-vehicle-type').element as HTMLSelectElement).value).toBe('SEDAN')
      expect((wrapper.find('input[value="PRIVATE"]').element as HTMLInputElement).checked).toBe(true)
    })

    it('restores a half-typed form after a reload', async () => {
      mountView()
      await byId('field-birth-date').setValue('1990-05-14')
      await byId('field-vehicle-type').setValue('VAN')
      wrapper.unmount()

      // Un montaje nuevo con un Pinia nuevo es lo que ocurre tras recargar la pestaña.
      mountView()

      expect((byId('field-birth-date').element as HTMLInputElement).value).toBe('1990-05-14')
      expect((byId('field-vehicle-type').element as HTMLSelectElement).value).toBe('VAN')
    })
  })
})
