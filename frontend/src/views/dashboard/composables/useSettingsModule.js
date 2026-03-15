import { onMounted, reactive, ref } from 'vue'
import { useDashboardDataServices } from './useDashboardDataServices'

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

export function useSettingsModule() {
  const services = useDashboardDataServices()

  const settingsLoading = ref(false)
  const settingsSaving = ref(false)
  const settingsError = ref('')
  const settingsMessage = ref('')
  const settingsCanEdit = ref(false)
  const settingsForm = reactive({
    platform_name: '',
    academic_term: '',
    allow_public_registration: true,
    maintenance_mode: false,
    announcement_banner: '',
    terms_of_use_text: '',
    privacy_policy_text: '',
  })

  async function loadSystemSettings() {
    settingsLoading.value = true
    settingsError.value = ''
    settingsMessage.value = ''

    try {
      const { data } = await services.getSystemSettings()
      const settings = data.settings ?? {}
      settingsCanEdit.value = Boolean(data.can_edit)
      settingsForm.platform_name = settings.platform_name ?? ''
      settingsForm.academic_term = settings.academic_term ?? ''
      settingsForm.allow_public_registration = Boolean(settings.allow_public_registration)
      settingsForm.maintenance_mode = Boolean(settings.maintenance_mode)
      settingsForm.announcement_banner = settings.announcement_banner ?? ''
      settingsForm.terms_of_use_text = settings.terms_of_use_text ?? ''
      settingsForm.privacy_policy_text = settings.privacy_policy_text ?? ''
    } catch (error) {
      settingsError.value = firstApiError(error, 'Unable to load system settings.')
    } finally {
      settingsLoading.value = false
    }
  }

  async function saveSystemSettings() {
    if (!settingsCanEdit.value) return

    const termsOfUse = settingsForm.terms_of_use_text.trim()
    const privacyPolicy = settingsForm.privacy_policy_text.trim()

    settingsError.value = ''
    settingsMessage.value = ''

    if (!termsOfUse || !privacyPolicy) {
      settingsError.value = 'Terms of Use and Privacy Policy are required.'
      return
    }

    settingsSaving.value = true

    try {
      await services.saveSystemSettings({
        platform_name: settingsForm.platform_name.trim(),
        academic_term: settingsForm.academic_term.trim(),
        allow_public_registration: settingsForm.allow_public_registration,
        maintenance_mode: settingsForm.maintenance_mode,
        announcement_banner: settingsForm.announcement_banner.trim(),
        terms_of_use_text: termsOfUse,
        privacy_policy_text: privacyPolicy,
      })
      settingsMessage.value = 'System settings updated.'
    } catch (error) {
      settingsError.value = firstApiError(error, 'Unable to save settings.')
    } finally {
      settingsSaving.value = false
    }
  }

  onMounted(async () => {
    await loadSystemSettings()
  })

  return {
    settingsLoading,
    settingsSaving,
    settingsError,
    settingsMessage,
    settingsCanEdit,
    settingsForm,
    loadSystemSettings,
    saveSystemSettings,
  }
}
