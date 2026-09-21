import { beforeEach, describe, expect, it } from 'vitest'
import { createSessionFormStorage, STORAGE_KEY } from '../../../src/comparator/car-insurance/infra/form-storage'

describe('createSessionFormStorage', () => {
  beforeEach(() => {
    window.sessionStorage.clear()
  })

  it('loads nothing when nothing was saved', () => {
    expect(createSessionFormStorage().load()).toBeNull()
  })

  it('saves and loads the raw input, valid or not', () => {
    const storage = createSessionFormStorage()
    const half = { birthDate: '1990-0', vehicleType: '', usageType: '' }

    storage.save(half)

    expect(storage.load()).toEqual(half)
  })

  it('uses sessionStorage, never localStorage', () => {
    createSessionFormStorage().save({ birthDate: '1990-05-14', vehicleType: '', usageType: '' })

    expect(window.sessionStorage.getItem(STORAGE_KEY)).not.toBeNull()
    expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull()
  })

  it('clears what was saved', () => {
    const storage = createSessionFormStorage()
    storage.save({ birthDate: 'x', vehicleType: '', usageType: '' })

    storage.clear()

    expect(storage.load()).toBeNull()
  })

  it.each([
    ['not json', '{oops'],
    ['not an object', '"text"'],
    ['missing a field', '{"birthDate":"1990-05-14"}'],
    ['wrong field type', '{"birthDate":1,"vehicleType":"","usageType":""}'],
  ])('ignores corrupt stored data (%s)', (_name, raw) => {
    window.sessionStorage.setItem(STORAGE_KEY, raw)

    expect(createSessionFormStorage().load()).toBeNull()
  })

  it('keeps working when the browser blocks storage', () => {
    const blocked = {
      getItem: () => {
        throw new Error('blocked')
      },
      setItem: () => {
        throw new Error('blocked')
      },
      removeItem: () => {
        throw new Error('blocked')
      },
    } as unknown as Storage
    const storage = createSessionFormStorage(blocked)

    expect(storage.load()).toBeNull()
    expect(() => storage.save({ birthDate: 'x', vehicleType: '', usageType: '' })).not.toThrow()
    expect(() => storage.clear()).not.toThrow()
  })
})
