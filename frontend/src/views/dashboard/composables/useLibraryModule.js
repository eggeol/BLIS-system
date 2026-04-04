import { computed, onMounted } from 'vue'
import { useAuthStore } from '@/store/auth.store'
import { useLibraryManager } from './useLibraryManager'

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

export function useLibraryModule() {
  const auth = useAuthStore()

  const canManageLibraries = computed(() => (
    ['staff_master_examiner'].includes(String(auth.user?.role ?? 'student').toLowerCase())
  ))

  const libraryModule = useLibraryManager({
    canManageLibraries,
    parseApiError: firstApiError,
  })

  onMounted(() => {
    libraryModule.loadLibraryBanks()
  })

  return libraryModule
}
