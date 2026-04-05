import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '@/store/auth.store'
import { studentDashboardApi } from '@/api/studentDashboard.api'
import { formatDateTime } from './useDashboardFormatters'
import {
  canStudentOpenExam,
  examHasQuestionSet,
  examScheduleStart,
  isStudentExamCompleted,
  isStudentExamEnded,
  isStudentExamInProgress,
  isStudentExamRetakeLimitReached,
  isStudentExamUpcoming,
  studentExamActionLabel,
  studentExamAvailabilityText,
} from './studentExamAvailability'

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function asList(value) {
  return Array.isArray(value) ? value : []
}

function examNotificationState(exam) {
  if (!examHasQuestionSet(exam)) return 'unavailable'
  if (isStudentExamInProgress(exam)) return 'in_progress'
  if (isStudentExamUpcoming(exam)) return 'upcoming'
  if (isStudentExamEnded(exam)) return 'closed'
  if (isStudentExamCompleted(exam) && isStudentExamRetakeLimitReached(exam)) return 'review_only'
  if (isStudentExamCompleted(exam)) return 'retake'
  if (canStudentOpenExam(exam)) return 'available'
  return 'info'
}

function notificationPriority(state) {
  switch (state) {
    case 'in_progress':
      return 0
    case 'available':
      return 1
    case 'retake':
      return 2
    case 'upcoming':
      return 3
    case 'review_only':
      return 4
    case 'unavailable':
      return 5
    case 'closed':
      return 6
    default:
      return 7
  }
}

function notificationTone(state) {
  switch (state) {
    case 'in_progress':
      return 'navy'
    case 'available':
      return 'success'
    case 'retake':
      return 'gold'
    case 'upcoming':
      return 'neutral'
    case 'review_only':
      return 'success'
    case 'closed':
      return 'danger'
    default:
      return 'neutral'
  }
}

function notificationLabel(state) {
  switch (state) {
    case 'in_progress':
      return 'Resume ready'
    case 'available':
      return 'Available now'
    case 'retake':
      return 'Retake open'
    case 'upcoming':
      return 'Upcoming'
    case 'review_only':
      return 'Review only'
    case 'closed':
      return 'Window closed'
    case 'unavailable':
      return 'Needs setup'
    default:
      return 'Notice'
  }
}

function examTimeValue(exam) {
  const parsed = new Date(examScheduleStart(exam) ?? exam?.schedule_end_at ?? exam?.assigned_at ?? 0)
  return Number.isNaN(parsed.getTime()) ? Number.MAX_SAFE_INTEGER : parsed.getTime()
}

function scoreTone(scorePercent) {
  const score = Number(scorePercent ?? 0)
  if (score >= 75) return 'success'
  if (score >= 50) return 'gold'
  return 'danger'
}

export function useStudentDashboardModule() {
  const auth = useAuthStore()

  const dashboardLoading = ref(false)
  const dashboardLoaded = ref(false)
  const dashboardError = ref('')
  const stats = ref({
    rooms_count: 0,
    assigned_exams_count: 0,
    submitted_attempts_count: 0,
    average_score_percent: null,
  })
  const recentExams = ref([])
  const rooms = ref([])

  const studentFirstName = computed(() => (
    auth.user?.first_name
    || String(auth.user?.name ?? 'Student').trim().split(/\s+/).filter(Boolean)[0]
    || 'Student'
  ))

  const notifications = computed(() => {
    const items = rooms.value.flatMap((room) => (
      asList(room.assigned_exams)
        .map((exam) => {
          const state = examNotificationState(exam)

          if (!['in_progress', 'available', 'retake', 'upcoming'].includes(state)) {
            return null
          }

          return {
            id: `${room.id}-${exam.id}`,
            examId: exam.id,
            roomId: room.id,
            title: exam.title,
            roomName: room.name,
            roomCode: room.code,
            tone: notificationTone(state),
            badge: notificationLabel(state),
            message: studentExamAvailabilityText(exam),
            actionLabel: studentExamActionLabel(exam),
            subject: exam.subject,
            totalItems: exam.total_items,
            durationMinutes: exam.duration_minutes,
            timeValue: examTimeValue(exam),
            state,
          }
        })
        .filter(Boolean)
    ))

    return items.sort((left, right) => {
      const priorityDelta = notificationPriority(left.state) - notificationPriority(right.state)
      if (priorityDelta !== 0) return priorityDelta
      return left.timeValue - right.timeValue
    })
  })

  const roomSnapshots = computed(() => (
    rooms.value.map((room) => {
      const assignedExams = asList(room.assigned_exams)

      const statusCounts = assignedExams.reduce((counts, exam) => {
        const state = examNotificationState(exam)
        counts[state] = (counts[state] ?? 0) + 1
        return counts
      }, {})

      const nextExam = assignedExams
        .map((exam) => ({
          exam,
          state: examNotificationState(exam),
          timeValue: examTimeValue(exam),
        }))
        .filter((item) => !['closed', 'unavailable'].includes(item.state))
        .sort((left, right) => {
          const priorityDelta = notificationPriority(left.state) - notificationPriority(right.state)
          if (priorityDelta !== 0) return priorityDelta
          return left.timeValue - right.timeValue
        })[0] ?? null

      return {
        ...room,
        joinedAtText: room.joined_at ? formatDateTime(room.joined_at) : 'Recently joined',
        openCount: (statusCounts.in_progress ?? 0) + (statusCounts.available ?? 0) + (statusCounts.retake ?? 0),
        upcomingCount: statusCounts.upcoming ?? 0,
        completedCount: statusCounts.review_only ?? 0,
        nextExam: nextExam ? {
          title: nextExam.exam.title,
          badge: notificationLabel(nextExam.state),
          message: studentExamAvailabilityText(nextExam.exam),
          tone: notificationTone(nextExam.state),
        } : null,
      }
    })
  ))

  const recentExamCards = computed(() => (
    recentExams.value.map((attempt) => {
      const scorePercent = Number(attempt.score_percent ?? 0)

      return {
        ...attempt,
        scoreDisplay: `${scorePercent.toFixed(1)}%`,
        scoreTone: scoreTone(scorePercent),
        submittedAtText: attempt.submitted_at ? formatDateTime(attempt.submitted_at) : 'Submission time unavailable',
      }
    })
  ))

  const statsCards = computed(() => {
    const averageScore = stats.value.average_score_percent
    return [
      {
        label: 'Exams Taken',
        value: String(stats.value.submitted_attempts_count ?? 0),
        hint: 'Submitted attempts recorded',
      },
      {
        label: 'Exam Notices',
        value: String(notifications.value.length),
        hint: 'Open, resumable, or upcoming exams',
      },
      {
        label: 'Average Score',
        value: averageScore === null ? 'N/A' : `${Number(averageScore).toFixed(1)}%`,
        hint: averageScore === null ? 'Complete an exam to see this' : 'Across submitted attempts',
      },
    ]
  })

  const hasDashboardData = computed(() => (
    recentExamCards.value.length > 0 || notifications.value.length > 0 || roomSnapshots.value.length > 0
  ))

  async function loadDashboard() {
    dashboardLoading.value = true
    dashboardError.value = ''

    try {
      const { data } = await studentDashboardApi.summary()

      stats.value = {
        rooms_count: Number(data?.stats?.rooms_count ?? 0),
        assigned_exams_count: Number(data?.stats?.assigned_exams_count ?? 0),
        submitted_attempts_count: Number(data?.stats?.submitted_attempts_count ?? 0),
        average_score_percent: data?.stats?.average_score_percent === null
          ? null
          : Number(data?.stats?.average_score_percent),
      }
      recentExams.value = asList(data?.recent_exams)
      rooms.value = asList(data?.rooms)
    } catch (error) {
      dashboardError.value = firstApiError(error, 'Unable to load your dashboard right now.')
      recentExams.value = []
      rooms.value = []
      stats.value = {
        rooms_count: 0,
        assigned_exams_count: 0,
        submitted_attempts_count: 0,
        average_score_percent: null,
      }
    } finally {
      dashboardLoading.value = false
      dashboardLoaded.value = true
    }
  }

  onMounted(() => {
    loadDashboard()
  })

  return {
    studentFirstName,
    dashboardLoading,
    dashboardLoaded,
    dashboardError,
    hasDashboardData,
    statsCards,
    notifications,
    roomSnapshots,
    recentExamCards,
    loadDashboard,
  }
}
