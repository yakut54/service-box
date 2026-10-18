import { describe, expect, test } from 'vitest'
import { orderStatusSteps, orderStepIndex } from './labels'

describe('orderStatusSteps', () => {
  test('самовывоз/почта — 4 шага, последний "Завершён"', () => {
    const steps = orderStatusSteps('pickup')
    expect(steps.map(s => s.key)).toEqual(['pending', 'processing', 'collected', 'completed'])
    expect(steps.at(-1)?.label).toBe('Завершён')
  })

  test('курьер — добавляется "В доставке", последний шаг "Доставлен"', () => {
    const steps = orderStatusSteps('courier')
    expect(steps.map(s => s.key)).toEqual(['pending', 'processing', 'collected', 'in_delivery', 'completed'])
    expect(steps.at(-1)?.label).toBe('Доставлен')
  })
})

describe('orderStepIndex', () => {
  test('paid мапится на тот же шаг, что pending', () => {
    expect(orderStepIndex('paid')).toBe(orderStepIndex('pending'))
  })

  test('needs_attention мапится на processing ("застрял на сборке")', () => {
    expect(orderStepIndex('needs_attention')).toBe(orderStepIndex('processing'))
  })

  test('неизвестный статус не падает, возвращает 0', () => {
    expect(orderStepIndex('something-unexpected')).toBe(0)
  })
})
