import { computed, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '@/store/auth.store'
import { ClipboardList, DoorOpen, Gauge, ShieldCheck } from 'lucide-vue-next'
import { formatDateTime } from './useDashboardFormatters'
import { useDashboardDataServices } from './useDashboardDataServices'

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function numericValue(value) {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? numeric : null
}

function countValue(value) {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? Math.max(0, Math.round(numeric)) : 0
}

function clamp(value, min, max) {
  return Math.min(max, Math.max(min, value))
}

const NUMBER_FORMATTER = new Intl.NumberFormat()

function formatPercent(value, options = {}) {
  const {
    fallback = 'N/A',
    minimumFractionDigits = 0,
    maximumFractionDigits = 2,
  } = options
  const numeric = numericValue(value)
  if (numeric === null) return fallback

  return `${new Intl.NumberFormat(undefined, {
    minimumFractionDigits,
    maximumFractionDigits,
  }).format(numeric)}%`
}

function formatCount(value) {
  return NUMBER_FORMATTER.format(countValue(value))
}

export function useStudentInsightsModule() {
  const auth = useAuthStore()
  const services = useDashboardDataServices()

  const insightsLoading = ref(false)
  const insightsError = ref('')
  const insightsLoaded = ref(false)
  const insights = ref({
    summary: {},
    subjects: [],
    focus_subjects: [],
    score_history: [],
    recent_activity: [],
  })

  const isStudentRole = computed(() => String(auth.user?.role ?? '').toLowerCase() === 'student')

  async function loadInsights(force = false) {
    if (!isStudentRole.value) return
    if (insightsLoading.value && !force) return

    insightsLoading.value = true
    insightsError.value = ''

    try {
      const { data } = await services.getStudentAnalyticsOverview()
      insights.value = {
        summary: data?.summary ?? {},
        subjects: Array.isArray(data?.subjects) ? data.subjects : [],
        focus_subjects: Array.isArray(data?.focus_subjects) ? data.focus_subjects : [],
        score_history: Array.isArray(data?.score_history) ? data.score_history : [],
        recent_activity: Array.isArray(data?.recent_activity) ? data.recent_activity : [],
      }
    } catch (error) {
      insightsError.value = firstApiError(error, 'Unable to load your analytics right now.')
    } finally {
      insightsLoading.value = false
      insightsLoaded.value = true
    }
  }

  watch(
    () => auth.user?.id,
    (userId, previousUserId) => {
      if (String(userId ?? '') === String(previousUserId ?? '')) return

      insights.value = {
        summary: {},
        subjects: [],
        focus_subjects: [],
        score_history: [],
        recent_activity: [],
      }
      insightsLoaded.value = false
      insightsError.value = ''

      if (userId && isStudentRole.value) {
        void loadInsights(true)
      }
    },
  )

  onMounted(() => {
    if (isStudentRole.value) {
      void loadInsights()
    }
  })

  const summary = computed(() => {
    const raw = insights.value.summary ?? {}

    return {
      roomsJoined: countValue(raw.rooms_joined),
      availableExams: countValue(raw.available_exams),
      pendingExams: countValue(raw.pending_exams),
      attemptsStarted: countValue(raw.attempts_started),
      attemptsSubmitted: countValue(raw.attempts_submitted),
      completedExams: countValue(raw.completed_exams),
      inProgressAttempts: countValue(raw.in_progress_attempts),
      passingAttempts: countValue(raw.passing_attempts),
      failingAttempts: countValue(raw.failing_attempts),
      passingThreshold: numericValue(raw.passing_threshold) ?? 75,
      averageScorePercent: numericValue(raw.average_score_percent),
      passRatePercent: numericValue(raw.pass_rate_percent),
      bestScorePercent: numericValue(raw.best_score_percent),
      latestScorePercent: numericValue(raw.latest_score_percent),
    }
  })

  const subjects = computed(() => (
    (insights.value.subjects ?? []).map((subject, index) => {
      const score = numericValue(subject?.score ?? subject?.average_score_percent) ?? 0

      return {
        id: `${subject?.label ?? 'subject'}-${index}`,
        label: String(subject?.label ?? 'Uncategorized'),
        score,
        scoreLabel: formatPercent(score, { maximumFractionDigits: 0 }),
        latestScorePercent: numericValue(subject?.latest_score_percent),
        passRatePercent: numericValue(subject?.pass_rate_percent),
        passRateLabel: formatPercent(subject?.pass_rate_percent),
        attemptsSubmitted: countValue(subject?.attempts_submitted),
        lastSubmittedAt: subject?.last_submitted_at ?? null,
      }
    })
  ))

  const focusSubjects = computed(() => {
    const threshold = summary.value.passingThreshold
    const backendFocusLabels = new Set(
      (insights.value.focus_subjects ?? []).map((subject) => String(subject?.label ?? '').trim()).filter(Boolean),
    )

    return subjects.value.filter((subject) => (
      backendFocusLabels.size > 0
        ? backendFocusLabels.has(subject.label)
        : subject.score < threshold
    ))
  })

  const strongestSubject = computed(() => {
    if (subjects.value.length === 0) return null

    return [...subjects.value]
      .sort((left, right) => right.score - left.score)[0] ?? null
  })

  const weakestSubject = computed(() => {
    if (subjects.value.length === 0) return null

    return [...subjects.value]
      .sort((left, right) => left.score - right.score)[0] ?? null
  })

  const scoreHistory = computed(() => (
    (insights.value.score_history ?? []).map((entry, index) => {
      const score = numericValue(entry?.score_percent ?? entry?.score) ?? 0

      return {
        id: entry?.attempt_id ?? index + 1,
        label: String(entry?.label ?? `Attempt ${index + 1}`),
        shortLabel: String(entry?.short_label ?? `A${index + 1}`),
        subject: entry?.subject ?? null,
        roomName: entry?.room_name ?? null,
        submittedAt: entry?.submitted_at ?? null,
        score,
        scoreLabel: formatPercent(score, { maximumFractionDigits: 0 }),
      }
    })
  ))

  const activities = computed(() => (
    (insights.value.recent_activity ?? []).map((activity, index) => {
      const status = String(activity?.status ?? '').toLowerCase()
      const scorePercent = numericValue(activity?.score_percent)
      const roomLabel = activity?.room_name
        ? `${activity.room_name}${activity?.room_code ? ` (${activity.room_code})` : ''}`
        : 'Exam activity'
      const stateLabel = status === 'submitted'
        ? 'Submitted'
        : (status === 'in_progress' ? 'In progress' : 'Started')

      return {
        id: activity?.id ?? index + 1,
        title: String(activity?.title ?? 'Exam Activity'),
        meta: `${roomLabel} • ${formatDateTime(activity?.occurred_at)}`,
        score: status === 'submitted'
          ? formatPercent(scorePercent, { maximumFractionDigits: 0 })
          : stateLabel,
        positive: status === 'submitted'
          ? (scorePercent ?? 0) >= summary.value.passingThreshold
          : true,
      }
    })
  ))

  const averageScore = computed(() => summary.value.averageScorePercent ?? 0)
  const averageScoreLabel = computed(() => formatPercent(summary.value.averageScorePercent))
  const passingRateLabel = computed(() => formatPercent(summary.value.passRatePercent))
  const bestScoreLabel = computed(() => formatPercent(summary.value.bestScorePercent))

  const marginFromPassing = computed(() => {
    if (summary.value.averageScorePercent === null) return null

    return Number((summary.value.averageScorePercent - summary.value.passingThreshold).toFixed(2))
  })

  const marginLabel = computed(() => {
    if (marginFromPassing.value === null) return 'N/A'
    const prefix = marginFromPassing.value >= 0 ? '+' : ''
    return `${prefix}${marginFromPassing.value.toFixed(2)} pts`
  })

  const passingRateValue = computed(() => clamp(summary.value.passRatePercent ?? 0, 0, 100))
  const passingRateStatusLabel = computed(() => {
    if (summary.value.attemptsSubmitted === 0) return 'No completed exams yet'
    if ((summary.value.passRatePercent ?? 0) >= 75) return 'Most recent sessions are passing'
    if ((summary.value.passRatePercent ?? 0) >= 50) return 'Mixed results across recent sessions'
    return 'Review needed on recent sessions'
  })

  const standingTone = computed(() => {
    if (summary.value.attemptsSubmitted === 0) {
      return summary.value.availableExams > 0 ? 'navy' : 'neutral'
    }

    return (summary.value.averageScorePercent ?? 0) >= summary.value.passingThreshold
      ? 'success'
      : 'danger'
  })

  const standingLabel = computed(() => {
    if (summary.value.attemptsSubmitted === 0) {
      return summary.value.availableExams > 0 ? 'Ready To Start' : 'Awaiting Activity'
    }

    return standingTone.value === 'success' ? 'On Track' : 'Needs Attention'
  })

  const standingHeadline = computed(() => {
    if (summary.value.attemptsSubmitted === 0) {
      return summary.value.availableExams > 0
        ? 'You have exam activity ready to start'
        : 'Your dashboard is ready when classes assign work'
    }

    return standingTone.value === 'success'
      ? 'Your current performance is above the passing line'
      : 'A few recent results need review before the next exam'
  })

  const standingMessage = computed(() => {
    if (summary.value.attemptsSubmitted === 0) {
      if (summary.value.availableExams > 0) {
        return `${formatCount(summary.value.availableExams)} assigned exam(s) across ${formatCount(summary.value.roomsJoined)} room(s). Start with your pending sessions to build your record.`
      }

      return 'Join a room or wait for an assigned exam to start seeing performance insights here.'
    }

    const focusCount = formatCount(focusSubjects.value.length)
    const focusLine = focusSubjects.value.length > 0
      ? `${focusCount} subject(s) are below the ${formatPercent(summary.value.passingThreshold, { maximumFractionDigits: 0 })} target.`
      : 'All tracked subjects are currently above the passing target.'

    return `${formatCount(summary.value.completedExams)} completed exam(s), ${formatCount(summary.value.pendingExams)} still pending. ${focusLine}`
  })

  const recentResults = computed(() => (
    [...scoreHistory.value]
      .reverse()
      .slice(0, 4)
      .map((entry) => ({
        ...entry,
        meta: [
          entry.subject,
          entry.roomName,
          entry.submittedAt ? formatDateTime(entry.submittedAt) : null,
        ].filter(Boolean).join(' • '),
      }))
  ))

  const statCards = computed(() => [
    {
      label: 'Overall Average',
      value: averageScoreLabel.value,
      trend: `${formatCount(summary.value.attemptsSubmitted)} submitted attempt(s)`,
      positive: (summary.value.averageScorePercent ?? 0) >= summary.value.passingThreshold,
      tone: 'navy',
      icon: Gauge,
    },
    {
      label: 'Passing Rate',
      value: passingRateLabel.value,
      trend: `${formatCount(summary.value.passingAttempts)} passing / ${formatCount(summary.value.attemptsSubmitted)} submitted`,
      positive: (summary.value.passRatePercent ?? 0) >= 50,
      tone: 'success',
      icon: ShieldCheck,
    },
    {
      label: 'Completed Exams',
      value: formatCount(summary.value.completedExams),
      trend: `${formatCount(summary.value.pendingExams)} pending`,
      positive: summary.value.completedExams > 0,
      tone: 'gold',
      icon: ClipboardList,
    },
    {
      label: 'Rooms Joined',
      value: formatCount(summary.value.roomsJoined),
      trend: `${formatCount(summary.value.availableExams)} assigned exam(s)`,
      positive: summary.value.roomsJoined > 0,
      tone: 'navy',
      icon: DoorOpen,
    },
  ])

  const hasInsightsData = computed(() => (
    summary.value.availableExams > 0
    || summary.value.attemptsStarted > 0
    || subjects.value.length > 0
    || scoreHistory.value.length > 0
  ))

  const showInitialLoading = computed(() => insightsLoading.value && !insightsLoaded.value)

  return {
    activities,
    averageScore,
    averageScoreLabel,
    bestScoreLabel,
    focusSubjects,
    formatPercent,
    hasInsightsData,
    insightsError,
    insightsLoaded,
    insightsLoading,
    loadInsights,
    marginFromPassing,
    marginLabel,
    passingRateLabel,
    passingRateStatusLabel,
    passingRateValue,
    recentResults,
    scoreHistory,
    statCards,
    standingHeadline,
    standingLabel,
    standingMessage,
    standingTone,
    strongestSubject,
    subjects,
    showInitialLoading,
    summary,
    weakestSubject,
  }
}
