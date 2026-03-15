import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/store/auth.store'
import { useDashboardDataServices } from './useDashboardDataServices'

const USERS_DEFAULT_PAGE_SIZE = 50

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function displayMemberRole(role) {
  const normalized = String(role ?? '').toLowerCase()
  if (normalized === 'admin') return 'Administrator'
  if (normalized === 'staff_master_examiner') return 'Staff / Master Examiner'
  return 'Student'
}

export function useUsersModule() {
  const auth = useAuthStore()
  const services = useDashboardDataServices()

  const normalizedRole = computed(() => String(auth.user?.role ?? 'student').toLowerCase())
  const isAdminRole = computed(() => normalizedRole.value === 'admin')

  const adminUsers = ref([])
  const usersLoading = ref(false)
  const usersSaving = ref(false)
  const usersRecovering = ref(false)
  const usersError = ref('')
  const usersMessage = ref('')
  const showUserModal = ref(false)
  const showRecoverUserModal = ref(false)
  const recoverTargetUser = ref(null)
  const userFilters = reactive({
    search: '',
    role: '',
    status: '',
  })
  const usersPagination = reactive({
    current_page: 1,
    last_page: 1,
    per_page: USERS_DEFAULT_PAGE_SIZE,
    total: 0,
    from: 0,
    to: 0,
  })
  const recoverForm = reactive({
    student_id: '',
    email: '',
    password: '',
  })
  const userForm = reactive({
    id: null,
    name: '',
    email: '',
    student_id: '',
    role: 'student',
    is_active: true,
    password: '',
  })

  let usersFilterSearchDebounce = null

  const canGoToPreviousUsersPage = computed(() => usersPagination.current_page > 1)
  const canGoToNextUsersPage = computed(() => usersPagination.current_page < usersPagination.last_page)
  const usersRangeLabel = computed(() => {
    if (!Number.isFinite(usersPagination.total) || usersPagination.total < 1) {
      return 'No users found'
    }

    const from = Number(usersPagination.from ?? 0)
    const to = Number(usersPagination.to ?? 0)
    const total = Number(usersPagination.total ?? 0)

    return `Showing ${from}-${to} of ${total} users`
  })

  function resetUserForm() {
    userForm.id = null
    userForm.name = ''
    userForm.email = ''
    userForm.student_id = ''
    userForm.role = 'student'
    userForm.is_active = true
    userForm.password = ''
  }

  function openCreateUserModal() {
    resetUserForm()
    usersError.value = ''
    usersMessage.value = ''
    showUserModal.value = true
  }

  function openEditUserModal(user) {
    userForm.id = user.id
    userForm.name = user.name ?? ''
    userForm.email = user.email ?? ''
    userForm.student_id = user.student_id ?? ''
    userForm.role = user.role ?? 'student'
    userForm.is_active = Boolean(user.is_active)
    userForm.password = ''
    usersError.value = ''
    usersMessage.value = ''
    showUserModal.value = true
  }

  function closeUserModal() {
    showUserModal.value = false
    resetUserForm()
  }

  function resetRecoverForm() {
    recoverForm.student_id = ''
    recoverForm.email = ''
    recoverForm.password = ''
  }

  function openRecoverUserModal(user) {
    if (String(user?.role ?? '').toLowerCase() !== 'student') return

    recoverTargetUser.value = user
    resetRecoverForm()
    usersError.value = ''
    usersMessage.value = ''
    showRecoverUserModal.value = true
  }

  function closeRecoverUserModal() {
    showRecoverUserModal.value = false
    recoverTargetUser.value = null
    resetRecoverForm()
  }

  async function loadAdminUsers(options = {}) {
    if (!isAdminRole.value) return

    usersLoading.value = true
    usersError.value = ''
    usersMessage.value = ''

    try {
      const requestedPage = Number(options.page ?? usersPagination.current_page ?? 1)
      const normalizedPage = Number.isFinite(requestedPage) && requestedPage > 0
        ? Math.trunc(requestedPage)
        : 1

      const params = {
        page: normalizedPage,
        per_page: usersPagination.per_page || USERS_DEFAULT_PAGE_SIZE,
      }

      if (userFilters.search.trim()) params.search = userFilters.search.trim()
      if (userFilters.role) params.role = userFilters.role
      if (userFilters.status) params.status = userFilters.status

      const { data } = await services.getUsers(params)
      adminUsers.value = data.users ?? []

      const meta = data?.meta ?? {}
      const currentPage = Number(meta.current_page ?? normalizedPage)
      const lastPage = Number(meta.last_page ?? 1)
      const perPage = Number(meta.per_page ?? params.per_page)
      const total = Number(meta.total ?? adminUsers.value.length)
      const fallbackFrom = total > 0 ? ((currentPage - 1) * perPage) + 1 : 0
      const fallbackTo = total > 0 ? Math.min(currentPage * perPage, total) : 0

      usersPagination.current_page = currentPage > 0 ? currentPage : normalizedPage
      usersPagination.last_page = lastPage > 0 ? lastPage : 1
      usersPagination.per_page = perPage > 0 ? perPage : USERS_DEFAULT_PAGE_SIZE
      usersPagination.total = Number.isFinite(total) && total >= 0 ? total : adminUsers.value.length
      usersPagination.from = Number(meta.from ?? fallbackFrom)
      usersPagination.to = Number(meta.to ?? fallbackTo)
    } catch (error) {
      usersError.value = firstApiError(error, 'Unable to load users.')
    } finally {
      usersLoading.value = false
    }
  }

  async function handleSaveUser() {
    if (!isAdminRole.value) return
    if (!userForm.name.trim() || !userForm.email.trim()) return

    if (userForm.role === 'student' && !/^\d{7,20}$/.test(userForm.student_id.trim())) {
      usersError.value = 'Student ID must be 7 to 20 digits for student accounts.'
      return
    }

    usersSaving.value = true
    usersError.value = ''
    usersMessage.value = ''

    const payload = {
      name: userForm.name.trim(),
      email: userForm.email.trim(),
      student_id: userForm.role === 'student' ? (userForm.student_id.trim() || null) : null,
      role: userForm.role,
      is_active: Boolean(userForm.is_active),
    }

    if (userForm.password.trim()) {
      payload.password = userForm.password
    }

    try {
      if (userForm.id) {
        await services.updateUser(userForm.id, payload)
        usersMessage.value = 'User updated successfully.'
      } else {
        if (!payload.password || payload.password.length < 8) {
          usersError.value = 'Password must be at least 8 characters for new users.'
          usersSaving.value = false
          return
        }

        await services.createUser(payload)
        usersMessage.value = 'User created successfully.'
      }

      closeUserModal()
      await loadAdminUsers({ page: usersPagination.current_page })
    } catch (error) {
      usersError.value = firstApiError(error, 'Unable to save user.')
    } finally {
      usersSaving.value = false
    }
  }

  async function handleRecoverUserAccount() {
    if (!isAdminRole.value) return

    const targetUserId = Number(recoverTargetUser.value?.id ?? 0)
    if (!Number.isFinite(targetUserId) || targetUserId < 1) return

    if (!/^\d{7,20}$/.test(recoverForm.student_id.trim())) {
      usersError.value = 'Student ID must be 7 to 20 digits.'
      return
    }

    if (!recoverForm.email.trim() || recoverForm.password.length < 8) return

    usersRecovering.value = true
    usersError.value = ''
    usersMessage.value = ''

    try {
      const { data } = await services.recoverUserAccount(targetUserId, {
        student_id: recoverForm.student_id.trim(),
        email: recoverForm.email.trim(),
        password: recoverForm.password,
      })

      usersMessage.value = data?.message ?? 'Student account recovered successfully.'
      closeRecoverUserModal()
      await loadAdminUsers({ page: usersPagination.current_page })
    } catch (error) {
      usersError.value = firstApiError(error, 'Unable to recover the student account.')
    } finally {
      usersRecovering.value = false
    }
  }

  watch(
    () => [userFilters.role, userFilters.status],
    async () => {
      if (!isAdminRole.value) return
      await loadAdminUsers({ page: 1 })
    },
  )

  watch(
    () => userFilters.search,
    (value, previousValue) => {
      if (!isAdminRole.value) return
      if (String(value ?? '') === String(previousValue ?? '')) return

      if (usersFilterSearchDebounce) {
        clearTimeout(usersFilterSearchDebounce)
        usersFilterSearchDebounce = null
      }

      usersFilterSearchDebounce = setTimeout(() => {
        if (!isAdminRole.value) return
        loadAdminUsers({ page: 1 })
      }, 300)
    },
  )

  onMounted(async () => {
    await loadAdminUsers({ page: 1 })
  })

  onBeforeUnmount(() => {
    if (!usersFilterSearchDebounce) return
    clearTimeout(usersFilterSearchDebounce)
    usersFilterSearchDebounce = null
  })

  return {
    adminUsers,
    usersLoading,
    usersSaving,
    usersRecovering,
    usersError,
    usersMessage,
    showUserModal,
    showRecoverUserModal,
    recoverTargetUser,
    userFilters,
    usersPagination,
    recoverForm,
    userForm,
    canGoToPreviousUsersPage,
    canGoToNextUsersPage,
    usersRangeLabel,
    displayMemberRole,
    openCreateUserModal,
    openEditUserModal,
    closeUserModal,
    openRecoverUserModal,
    closeRecoverUserModal,
    loadAdminUsers,
    handleSaveUser,
    handleRecoverUserAccount,
  }
}
