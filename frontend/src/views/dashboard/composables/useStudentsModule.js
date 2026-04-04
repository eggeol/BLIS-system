import { computed, onMounted, reactive, ref } from 'vue'
import { useAuthStore } from '@/store/auth.store'
import { formatDateTime } from './useDashboardFormatters'
import { useDashboardDataServices } from './useDashboardDataServices'

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function yearLevelLabel(value) {
  const numeric = Number(value)
  if (numeric === 1) return '1st Year'
  if (numeric === 2) return '2nd Year'
  if (numeric === 3) return '3rd Year'
  if (numeric === 4) return '4th Year'
  return 'Year level not set'
}

function formatPercent(value, fallback = 'N/A') {
  const numeric = Number(value)
  if (!Number.isFinite(numeric)) return fallback

  return `${new Intl.NumberFormat(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(numeric)}%`
}

function compareStudents(left, right) {
  const nameCompare = String(left?.name ?? '').localeCompare(String(right?.name ?? ''), undefined, {
    sensitivity: 'base',
  })

  if (nameCompare !== 0) return nameCompare

  return String(left?.student_id ?? '').localeCompare(String(right?.student_id ?? ''), undefined, {
    numeric: true,
    sensitivity: 'base',
  })
}

export function useStudentsModule() {
  const auth = useAuthStore()
  const services = useDashboardDataServices()

  const normalizedRole = computed(() => String(auth.user?.role ?? 'student').toLowerCase())
  const isStaffDirectoryRole = computed(() => ['admin'].includes(normalizedRole.value))

  const directoryLoading = ref(false)
  const directoryError = ref('')
  const directorySummary = ref({})
  const directoryStudents = ref([])
  const studentFilters = reactive({
    search: '',
    year_level: '',
    archive_state: 'current',
  })

  const yearLevelOptions = [
    { value: '1', label: '1st Year' },
    { value: '2', label: '2nd Year' },
    { value: '3', label: '3rd Year' },
    { value: '4', label: '4th Year' },
  ]

  function isArchivedStudent(student) {
    return Boolean(student?.archived_at)
  }

  async function loadStudentsDirectory() {
    if (!isStaffDirectoryRole.value) return

    directoryLoading.value = true
    directoryError.value = ''

    try {
      const { data } = await services.getStudentsDirectory()
      directorySummary.value = data?.summary ?? {}
      directoryStudents.value = Array.isArray(data?.students) ? data.students : []
    } catch (error) {
      directoryError.value = firstApiError(error, 'Unable to load the student directory right now.')
    } finally {
      directoryLoading.value = false
    }
  }

  const filteredStudents = computed(() => {
    const search = studentFilters.search.trim().toLowerCase()

    return [...directoryStudents.value]
      .filter((student) => {
        if (studentFilters.archive_state === 'current' && isArchivedStudent(student)) return false
        if (studentFilters.archive_state === 'archived' && !isArchivedStudent(student)) return false
        if (studentFilters.year_level && String(student?.year_level ?? '') !== studentFilters.year_level) return false

        if (!search) return true

        const haystack = [
          student?.name,
          student?.email,
          student?.student_id,
          ...(Array.isArray(student?.room_names) ? student.room_names : []),
          student?.latest_exam_title,
          student?.strongest_subject,
        ]
          .filter(Boolean)
          .join(' ')
          .toLowerCase()

        return haystack.includes(search)
      })
      .sort(compareStudents)
  })

  function buildYearGroups(students) {
    const groups = yearLevelOptions
      .map((option) => {
        const matchingStudents = students.filter((student) => String(student?.year_level ?? '') === option.value)

        return {
          key: `year-${option.value}`,
          label: option.label,
          count: matchingStudents.length,
          students: matchingStudents,
        }
      })
      .filter((group) => group.count > 0)

    const unassignedStudents = students.filter((student) => !['1', '2', '3', '4'].includes(String(student?.year_level ?? '')))

    if (unassignedStudents.length > 0) {
      groups.push({
        key: 'year-unassigned',
        label: 'Year Level Not Set',
        count: unassignedStudents.length,
        students: unassignedStudents,
      })
    }

    return groups
  }

  const currentStudentGroups = computed(() => (
    buildYearGroups(filteredStudents.value.filter((student) => !isArchivedStudent(student)))
  ))

  const archivedStudentGroups = computed(() => (
    buildYearGroups(filteredStudents.value.filter((student) => isArchivedStudent(student)))
  ))

  const studentsWithResults = computed(() => (
    filteredStudents.value.filter((student) => Number(student?.attempts_submitted ?? 0) > 0)
  ))

  const studentDirectoryCards = computed(() => {
    const scoreValues = studentsWithResults.value
      .map((student) => Number(student?.average_score_percent))
      .filter((score) => Number.isFinite(score))
    const passRateValues = studentsWithResults.value
      .map((student) => Number(student?.pass_rate_percent))
      .filter((score) => Number.isFinite(score))

    return [
      {
        key: 'displayed',
        label: 'Displayed Students',
        value: filteredStudents.value.length,
        note: `${studentsWithResults.value.length} with submitted results`,
        tone: 'navy',
      },
      {
        key: 'current',
        label: 'Current Students',
        value: filteredStudents.value.filter((student) => !isArchivedStudent(student)).length,
        note: `${directorySummary.value?.current_students ?? 0} in the full directory`,
        tone: 'success',
      },
      {
        key: 'average-score',
        label: 'Average Score',
        value: formatPercent(scoreValues.length > 0 ? scoreValues.reduce((sum, score) => sum + score, 0) / scoreValues.length : null),
        note: 'Across students with graded attempts',
        tone: 'gold',
      },
      {
        key: 'average-pass-rate',
        label: 'Average Pass Rate',
        value: formatPercent(passRateValues.length > 0 ? passRateValues.reduce((sum, score) => sum + score, 0) / passRateValues.length : null),
        note: `${directorySummary.value?.archived_students ?? 0} archived records available`,
        tone: 'neutral',
      },
    ]
  })

  function latestPerformanceLabel(student) {
    const latestExam = String(student?.latest_exam_title ?? '').trim()
    const latestScore = formatPercent(student?.latest_score_percent, '')

    if (latestExam && latestScore) {
      return `${latestExam} • ${latestScore}`
    }

    if (latestExam) return latestExam
    if (latestScore) return latestScore
    return 'No graded result yet'
  }

  function studentActivityLabel(student) {
    const activityAt = student?.last_activity_at ? formatDateTime(student.last_activity_at) : 'No activity yet'
    const strongestSubject = String(student?.strongest_subject ?? '').trim()

    if (!strongestSubject) return activityAt

    return `${activityAt} • Best in ${strongestSubject}`
  }

  onMounted(async () => {
    await loadStudentsDirectory()
  })

  return {
    directoryLoading,
    directoryError,
    studentFilters,
    yearLevelOptions,
    filteredStudents,
    currentStudentGroups,
    archivedStudentGroups,
    studentDirectoryCards,
    yearLevelLabel,
    formatPercent,
    isArchivedStudent,
    latestPerformanceLabel,
    studentActivityLabel,
    loadStudentsDirectory,
  }
}
