import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '@/store/auth.store'
import { useDashboardDataServices } from './useDashboardDataServices'

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

export function useAuditModule() {
  const auth = useAuthStore()
  const services = useDashboardDataServices()

  const normalizedRole = computed(() => String(auth.user?.role ?? 'student').toLowerCase())
  const isAdminRole = computed(() => normalizedRole.value === 'admin')

  const auditLogs = ref([])
  const auditLoading = ref(false)
  const auditError = ref('')

  async function loadAuditLogs() {
    if (!isAdminRole.value) return

    auditLoading.value = true
    auditError.value = ''

    try {
      const { data } = await services.getAuditLogs()
      auditLogs.value = data.logs ?? []
    } catch (error) {
      auditError.value = firstApiError(error, 'Unable to load audit logs.')
    } finally {
      auditLoading.value = false
    }
  }

  onMounted(async () => {
    await loadAuditLogs()
  })

  return {
    auditLogs,
    auditLoading,
    auditError,
    loadAuditLogs,
  }
}
