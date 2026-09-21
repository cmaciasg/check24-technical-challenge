import { describe, expect, it } from 'vitest'
import { effectivePriceCents, sortOffers } from '../../../src/comparator/car-insurance/domain/sort-offers'
import { offer } from '../support/fixtures'

describe('sortOffers', () => {
  it('orders by ascending price', () => {
    const sorted = sortOffers([
      offer({ providerId: 'b', providerName: 'B', priceAmountCents: 5000 }),
      offer({ providerId: 'a', providerName: 'A', priceAmountCents: 3000 }),
      offer({ providerId: 'c', providerName: 'C', priceAmountCents: 4000 }),
    ])

    expect(sorted.map((o) => o.providerId)).toEqual(['a', 'c', 'b'])
  })

  it('breaks ties by provider name', () => {
    const sorted = sortOffers([
      offer({ providerId: 'z', providerName: 'Zeta', priceAmountCents: 3000 }),
      offer({ providerId: 'a', providerName: 'Alfa', priceAmountCents: 3000 }),
    ])

    expect(sorted.map((o) => o.providerName)).toEqual(['Alfa', 'Zeta'])
  })

  it('uses the discounted price when there is a campaign', () => {
    const sorted = sortOffers([
      offer({ providerId: 'a', providerName: 'A', priceAmountCents: 3000 }),
      offer({ providerId: 'b', providerName: 'B', priceAmountCents: 5000, discountedPriceAmountCents: 2000, campaignApplied: true }),
    ])

    expect(sorted.map((o) => o.providerId)).toEqual(['b', 'a'])
  })

  it('exposes the effective price', () => {
    expect(effectivePriceCents(offer({ priceAmountCents: 4250 }))).toBe(4250)
    expect(effectivePriceCents(offer({ priceAmountCents: 4250, discountedPriceAmountCents: 3825 }))).toBe(3825)
    expect(effectivePriceCents(offer({ priceAmountCents: 4250, discountedPriceAmountCents: 0 }))).toBe(0)
  })

  it('does not mutate its input and copes with an empty list', () => {
    const input = [offer({ priceAmountCents: 2 }), offer({ priceAmountCents: 1 })]

    sortOffers(input)

    expect(input.map((o) => o.priceAmountCents)).toEqual([2, 1])
    expect(sortOffers([])).toEqual([])
  })
})
