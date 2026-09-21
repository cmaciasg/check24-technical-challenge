import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ComparisonApi } from '../../../src/comparator/car-insurance/infra/comparison-api'
import {
  createSessionFormStorage,
  STORAGE_KEY,
} from '../../../src/comparator/car-insurance/infra/form-storage'
import { defineComparisonStore } from '../../../src/comparator/car-insurance/store/comparison-store'
import { offer, response, status, TODAY, validInput } from '../support/fixtures'

function createStore(api: ComparisonApi) {
  setActivePinia(createPinia())
  return defineComparisonStore({ api, storage: createSessionFormStorage(), today: () => TODAY })()
}

function fill(store: ReturnType<typeof createStore>, input = validInput) {
  store.setField('birthDate', input.birthDate)
  store.setField('vehicleType', input.vehicleType)
  store.setField('usageType', input.usageType)
}

describe('comparison store', () => {
  beforeEach(() => {
    window.sessionStorage.clear()
  })

  // TC-P2: la persistencia sobrevive a una recarga
  describe('TC-P2 persistence survives a reload', () => {
    it('recovers exactly what was typed, invalid and incomplete included', () => {
      const first = createStore(vi.fn())
      first.setField('birthDate', '1990-0')
      first.setField('vehicleType', 'SUV')

      // Crear un store nuevo desde cero es lo que ocurre al recargar la pestaña.
      const afterReload = createStore(vi.fn())

      expect(afterReload.form).toEqual({ birthDate: '1990-0', vehicleType: 'SUV', usageType: '' })
    })

    it('starts empty when nothing was stored', () => {
      expect(createStore(vi.fn()).form).toEqual({ birthDate: '', vehicleType: '', usageType: '' })
    })

    it('persists on every change', () => {
      const store = createStore(vi.fn())

      store.setField('usageType', 'COMMERCIAL')

      expect(JSON.parse(window.sessionStorage.getItem(STORAGE_KEY) ?? '{}')).toMatchObject({ usageType: 'COMMERCIAL' })
    })
  })

  // TC-P3: la persistencia se limpia solo en éxito
  describe('TC-P3 persistence is cleared only on success', () => {
    it('clears sessionStorage after a 200 but keeps the values in memory', async () => {
      const store = createStore(vi.fn().mockResolvedValue({ status: 200, data: response() }))
      fill(store)
      expect(window.sessionStorage.getItem(STORAGE_KEY)).not.toBeNull()

      await store.submit()

      expect(window.sessionStorage.getItem(STORAGE_KEY)).toBeNull()
      expect(store.form).toEqual(validInput)
      expect(store.viewState).toBe('success')
    })

    it.each([
      ['400', { status: 400 }],
      ['500', { status: 500 }],
    ])('keeps the stored data after a %s', async (_name, apiResponse) => {
      const store = createStore(vi.fn().mockResolvedValue(apiResponse))
      fill(store)

      await store.submit()

      expect(JSON.parse(window.sessionStorage.getItem(STORAGE_KEY) ?? 'null')).toEqual(validInput)
    })

    it('keeps the stored data after a network failure', async () => {
      const store = createStore(vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
      fill(store)

      await store.submit()

      expect(window.sessionStorage.getItem(STORAGE_KEY)).not.toBeNull()
      expect(store.viewState).toBe('unavailable')
    })

    it('does not clear anything when the form was invalid and nothing was sent', async () => {
      const api = vi.fn()
      const store = createStore(api)
      store.setField('birthDate', '1990-0')

      await store.submit()

      expect(api).not.toHaveBeenCalled()
      expect(window.sessionStorage.getItem(STORAGE_KEY)).not.toBeNull()
    })
  })

  describe('view state', () => {
    it('starts idle', () => {
      expect(createStore(vi.fn()).viewState).toBe('idle')
    })

    it('is loading while the request is in flight, and clears the previous results', async () => {
      let resolveApi: (value: { status: number }) => void = () => undefined
      const api = vi
        .fn()
        .mockResolvedValueOnce({ status: 200, data: response() })
        .mockReturnValueOnce(new Promise((resolve) => (resolveApi = resolve)))
      const store = createStore(api)
      fill(store)
      await store.submit()
      expect(store.offers).toHaveLength(1)

      const second = store.submit()

      expect(store.viewState).toBe('loading')
      expect(store.isLoading).toBe(true)
      expect(store.offers).toEqual([])
      expect(store.providerStatuses).toEqual([])

      resolveApi({ status: 500 })
      await second
      expect(store.viewState).toBe('unavailable')
    })

    it('sends the validated payload to the API', async () => {
      const api = vi.fn().mockResolvedValue({ status: 200, data: response() })
      const store = createStore(api)
      fill(store)

      await store.submit()

      expect(api).toHaveBeenCalledExactlyOnceWith(validInput)
    })

    it('distinguishes a success with offers from an empty success', async () => {
      const store = createStore(
        vi.fn().mockResolvedValue({ status: 200, data: response({ offers: [], providerStatuses: [status({ status: 'failed', reason: 'timeout' })] }) }),
      )
      fill(store)

      await store.submit()

      expect(store.isEmptyResult).toBe(true)
      expect(store.hasResults).toBe(false)
      expect(store.failedProviders).toHaveLength(1)
    })

    it('maps a 400 to invalid-data and any other error to unavailable', async () => {
      const bad = createStore(vi.fn().mockResolvedValue({ status: 400 }))
      fill(bad)
      await bad.submit()
      expect(bad.viewState).toBe('invalid-data')

      const broken = createStore(vi.fn().mockResolvedValue({ status: 503 }))
      fill(broken)
      await broken.submit()
      expect(broken.viewState).toBe('unavailable')
    })

    it('treats a 200 without a body as unavailable', async () => {
      const store = createStore(vi.fn().mockResolvedValue({ status: 200 }))
      fill(store)

      await store.submit()

      expect(store.viewState).toBe('unavailable')
    })

    it('exposes the offers sorted by effective price', async () => {
      const store = createStore(
        vi.fn().mockResolvedValue({
          status: 200,
          data: response({
            offers: [
              offer({ providerId: 'b', providerName: 'B', priceAmountCents: 5000 }),
              offer({ providerId: 'a', providerName: 'A', priceAmountCents: 3000 }),
            ],
          }),
        }),
      )
      fill(store)

      await store.submit()

      expect(store.sortedOffers.map((o) => o.providerId)).toEqual(['a', 'b'])
    })
  })

  describe('validation', () => {
    it('does not send anything and reports every invalid field on submit', async () => {
      const api = vi.fn()
      const store = createStore(api)

      await store.submit()

      expect(api).not.toHaveBeenCalled()
      expect(store.errors).toEqual({ birthDate: 'required', vehicleType: 'required', usageType: 'required' })
      expect(store.viewState).toBe('idle')
    })

    it('asks to focus the first invalid field', async () => {
      const store = createStore(vi.fn())
      store.setField('birthDate', '1990-05-14')

      await store.submit()
      expect(store.focusRequest?.field).toBe('vehicleType')
      const firstToken = store.focusRequest?.token ?? 0

      await store.submit()
      expect(store.focusRequest?.token).toBeGreaterThan(firstToken)
    })

    it('validates a field when it loses focus', () => {
      const store = createStore(vi.fn())
      store.setField('birthDate', '2027-01-01')
      expect(store.errors.birthDate).toBeUndefined()

      store.blurField('birthDate')

      expect(store.errors.birthDate).toBe('not_in_the_past')
    })

    it('revalidates a touched field as it changes, so the error disappears once fixed', () => {
      const store = createStore(vi.fn())
      store.setField('birthDate', '2027-01-01')
      store.blurField('birthDate')

      store.setField('birthDate', '1990-05-14')

      expect(store.errors.birthDate).toBeUndefined()
    })
  })
})
