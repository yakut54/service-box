import { ref } from 'vue'

export function useFormValidation() {
  const formErrors  = ref<Record<string, string>>({})
  const formTouched = ref<Record<string, boolean>>({})

  function touch(field: string, validateFn: (f: string) => void) {
    formTouched.value[field] = true
    validateFn(field)
  }

  function clearError(field: string) {
    const e = { ...formErrors.value }
    delete e[field]
    formErrors.value = e
  }

  function isFieldValid(field: string, check: () => boolean): boolean {
    return !!formTouched.value[field] && !formErrors.value[field] && check()
  }

  function touchAll(fields: string[], validateFn: (f: string) => void) {
    fields.forEach(f => {
      formTouched.value[f] = true
      validateFn(f)
    })
  }

  return { formErrors, formTouched, touch, clearError, isFieldValid, touchAll }
}
