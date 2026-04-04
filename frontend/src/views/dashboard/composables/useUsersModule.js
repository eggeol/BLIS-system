import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/store/auth.store'
import { useDashboardDataServices } from './useDashboardDataServices'

const USERS_DEFAULT_PAGE_SIZE = 200

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

function yearLevelLabel(value) {
  const numeric = Number(value)
  if (numeric === 1) return '1st Year'
  if (numeric === 2) return '2nd Year'
  if (numeric === 3) return '3rd Year'
  if (numeric === 4) return '4th Year'
  return 'Year level not set'
}

function compareUsersForDirectory(left, right) {
  const nameCompare = String(left?.name ?? '').localeCompare(String(right?.name ?? ''), undefined, {
    sensitivity: 'base',
  })

  if (nameCompare !== 0) {
    return nameCompare
  }

  const studentIdCompare = String(left?.student_id ?? '').localeCompare(String(right?.student_id ?? ''), undefined, {
    numeric: true,
    sensitivity: 'base',
  })

  if (studentIdCompare !== 0) {
    return studentIdCompare
  }

  return Number(left?.id ?? 0) - Number(right?.id ?? 0)
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
    year_level: '',
    archive_state: 'current',
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
    year_level: '1',
    is_active: true,
    archived: false,
    password: '',
  })

  const yearLevelOptions = [
    { value: '1', label: '1st Year' },
    { value: '2', label: '2nd Year' },
    { value: '3', label: '3rd Year' },
    { value: '4', label: '4th Year' },
  ]

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
  const sortedAdminUsers = computed(() => (
    [...adminUsers.value].sort(compareUsersForDirectory)
  ))
  const currentStudentUsers = computed(() => (
    sortedAdminUsers.value.filter((user) => String(user?.role ?? '').toLowerCase() === 'student' && !isUserArchived(user))
  ))
  const archivedStudentUsers = computed(() => (
    sortedAdminUsers.value.filter((user) => String(user?.role ?? '').toLowerCase() === 'student' && isUserArchived(user))
  ))
  const managementUsers = computed(() => (
    sortedAdminUsers.value.filter((user) => String(user?.role ?? '').toLowerCase() !== 'student')
  ))
  const currentStudentYearGroups = computed(() => {
    const groups = yearLevelOptions
      .map((option) => {
        const users = currentStudentUsers.value.filter((user) => String(user?.year_level ?? '') === option.value)

        return {
          key: `year-${option.value}`,
          label: option.label,
          count: users.length,
          users,
        }
      })
      .filter((group) => group.count > 0)

    const unassignedUsers = currentStudentUsers.value.filter((user) => !['1', '2', '3', '4'].includes(String(user?.year_level ?? '')))

    if (unassignedUsers.length > 0) {
      groups.push({
        key: 'year-unassigned',
        label: 'Year Level Not Set',
        count: unassignedUsers.length,
        users: unassignedUsers,
      })
    }

    return groups
  })
  const archivedStudentYearGroups = computed(() => {
    const groups = yearLevelOptions
      .map((option) => {
        const users = archivedStudentUsers.value.filter((user) => String(user?.year_level ?? '') === option.value)

        return {
          key: `archived-year-${option.value}`,
          label: option.label,
          count: users.length,
          users,
        }
      })
      .filter((group) => group.count > 0)

    const unassignedUsers = archivedStudentUsers.value.filter((user) => !['1', '2', '3', '4'].includes(String(user?.year_level ?? '')))

    if (unassignedUsers.length > 0) {
      groups.push({
        key: 'archived-year-unassigned',
        label: 'Year Level Not Set',
        count: unassignedUsers.length,
        users: unassignedUsers,
      })
    }

    return groups
  })
  const managementAccountGroups = computed(() => ([
    {
      key: 'staff-accounts',
      label: 'Staff / Master Examiners',
      users: managementUsers.value.filter((user) => String(user?.role ?? '').toLowerCase() === 'staff_master_examiner'),
    },
    {
      key: 'admin-accounts',
      label: 'Administrators',
      users: managementUsers.value.filter((user) => String(user?.role ?? '').toLowerCase() === 'admin'),
    },
  ].filter((group) => group.users.length > 0)))
  const userDirectorySummary = computed(() => ([
    {
      key: 'current-students',
      label: 'Current Students',
      value: currentStudentUsers.value.length,
      tone: 'success',
    },
    {
      key: 'archived-students',
      label: 'Archived Records',
      value: archivedStudentUsers.value.length,
      tone: 'neutral',
    },
    {
      key: 'staff-admin',
      label: 'Staff and Admin',
      value: managementUsers.value.length,
      tone: 'navy',
    },
    {
      key: 'inactive',
      label: 'Inactive Accounts',
      value: sortedAdminUsers.value.filter((user) => !Boolean(user?.is_active)).length,
      tone: 'danger',
    },
  ]))

  function resetUserForm() {
    userForm.id = null
    userForm.name = ''
    userForm.email = ''
    userForm.student_id = ''
    userForm.role = 'student'
    userForm.year_level = '1'
    userForm.is_active = true
    userForm.archived = false
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
    userForm.year_level = user.year_level ? String(user.year_level) : '1'
    userForm.is_active = Boolean(user.is_active)
    userForm.archived = Boolean(user.archived_at)
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
      if (userFilters.year_level) params.year_level = userFilters.year_level
      if (userFilters.archive_state) params.archive_state = userFilters.archive_state

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

    if (userForm.role === 'student' && !['1', '2', '3', '4'].includes(String(userForm.year_level))) {
      usersError.value = 'Select a valid year level for student accounts.'
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
      year_level: userForm.role === 'student' ? Number(userForm.year_level) : null,
      is_active: Boolean(userForm.is_active),
      archived: userForm.role === 'student' ? Boolean(userForm.archived) : false,
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

  function isUserArchived(user) {
    return Boolean(user?.archived_at)
  }

  async function handleToggleUserArchive(user, shouldArchive) {
    if (!isAdminRole.value) return
    if (String(user?.role ?? '').toLowerCase() !== 'student') return

    const userId = Number(user?.id ?? 0)
    if (!Number.isFinite(userId) || userId < 1) return

    const userName = String(user?.name ?? 'this student').trim() || 'this student'
    const actionLabel = shouldArchive ? 'archive' : 'restore'

    if (typeof window !== 'undefined') {
      const confirmed = window.confirm(`${shouldArchive ? 'Archive' : 'Restore'} ${userName}?`)
      if (!confirmed) return
    }

    usersSaving.value = true
    usersError.value = ''
    usersMessage.value = ''

    try {
      if (shouldArchive) {
        await services.archiveUser(userId)
        usersMessage.value = `${userName} moved to archived student records.`
      } else {
        await services.restoreUser(userId)
        usersMessage.value = `${userName} restored to current student records.`
      }

      await loadAdminUsers({ page: usersPagination.current_page })
    } catch (error) {
      usersError.value = firstApiError(error, `Unable to ${actionLabel} the student record.`)
    } finally {
      usersSaving.value = false
    }
  }

  watch(
    () => [userFilters.role, userFilters.status, userFilters.year_level, userFilters.archive_state],
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
    currentStudentYearGroups,
    archivedStudentYearGroups,
    managementAccountGroups,
    userDirectorySummary,
    displayMemberRole,
    yearLevelLabel,
    yearLevelOptions,
    isUserArchived,
    openCreateUserModal,
    openEditUserModal,
    closeUserModal,
    openRecoverUserModal,
    closeRecoverUserModal,
    loadAdminUsers,
    handleSaveUser,
    handleToggleUserArchive,
    handleRecoverUserAccount,
  }
}
