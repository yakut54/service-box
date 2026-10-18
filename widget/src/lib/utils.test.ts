import { describe, expect, test } from 'vitest'
import { formatPhone, isPhoneValid } from './utils'

describe('formatPhone', () => {
  test('8 заменяется на 7', () => {
    expect(formatPhone('89991234567')).toBe('+7 (999) 123-45-67')
  })

  test('без кода страны — 7 подставляется сама', () => {
    expect(formatPhone('9991234567')).toBe('+7 (999) 123-45-67')
  })

  test('пусто остаётся пусто', () => {
    expect(formatPhone('')).toBe('')
  })

  test('лишние цифры после 11-й обрезаются', () => {
    expect(formatPhone('799912345678888')).toBe('+7 (999) 123-45-67')
  })
})

describe('isPhoneValid', () => {
  test('ровно 11 цифр — валиден', () => {
    expect(isPhoneValid('+7 (999) 123-45-67')).toBe(true)
  })

  test('меньше 11 цифр — невалиден', () => {
    expect(isPhoneValid('+7 (999) 123-45')).toBe(false)
  })
})
