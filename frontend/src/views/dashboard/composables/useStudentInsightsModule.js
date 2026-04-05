import { computed, onMounted, ref } from 'vue'
import { studentAnalyticsApi } from '@/api/studentAnalytics.api'
import { LIBRARY_SUBJECT_CATEGORIES } from '@/constants/librarySubjects'
import { formatDateTime } from './useDashboardFormatters'

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function asList(value) {
  return Array.isArray(value) ? value : []
}

function scoreTone(score) {
  const numericScore = Number(score ?? 0)
  if (numericScore >= 85) return 'success'
  if (numericScore >= 75) return 'navy'
  if (numericScore >= 60) return 'gold'
  return 'danger'
}

function trendTone(trend) {
  if (trend === 'up') return 'success'
  if (trend === 'down') return 'danger'
  if (trend === 'steady') return 'navy'
  return 'neutral'
}

function bandLabel(band) {
  if (band === 'strong') return 'Doing Well'
  if (band === 'developing') return 'Almost There'
  if (band === 'focus') return 'Review More'
  return 'No Data'
}

function humanizeFeatureKey(feature) {
  return String(feature ?? '')
    .split('_')
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ')
}

export function useStudentInsightsModule() {
  const analyticsLoading = ref(false)
  const analyticsLoaded = ref(false)
  const analyticsError = ref('')
  const stats = ref({
    completed_attempts: 0,
    average_score_percent: null,
    latest_score_percent: null,
    latest_score_delta: null,
    focus_subjects_count: 0,
    passing_threshold: 75,
  })
  const subjectRows = ref([])
  const historyRows = ref([])
  const recentAttemptRows = ref([])
  const predictiveModel = ref({
    status: 'awaiting_ml_api',
    title: 'Predictive Readiness Model',
    description: 'Prepared for future J48 (C4.5) and logistic regression API integration.',
    ml_api_endpoint: null,
    expected_inputs: [],
    probability_percent: null,
    risk_band: null,
    decision_rules: [],
  })

  const subjects = computed(() => {
    const incoming = new Map(
      asList(subjectRows.value).map((subject) => [subject.label, subject]),
    )

    return LIBRARY_SUBJECT_CATEGORIES.map((label) => {
      const subject = incoming.get(label) ?? {
        label,
        attempts_count: 0,
        average_score_percent: null,
        latest_score_percent: null,
        best_score_percent: null,
        trend: 'none',
        trend_delta: null,
        performance_band: 'not_started',
      }

      return {
        ...subject,
        scoreTone: scoreTone(subject.average_score_percent ?? subject.latest_score_percent ?? 0),
        trendTone: trendTone(subject.trend),
        bandLabel: bandLabel(subject.performance_band),
        coveragePercent: Math.max(0, Math.min(100, Number(subject.average_score_percent ?? 0))),
      }
    })
  })

  const scoreHistory = computed(() => (
    asList(historyRows.value).map((attempt) => ({
      ...attempt,
      score: Number(attempt.score_percent ?? 0),
      scoreTone: scoreTone(attempt.score_percent),
      submittedAtText: attempt.submitted_at ? formatDateTime(attempt.submitted_at) : 'n/a',
    }))
  ))

  const recentAttempts = computed(() => (
    asList(recentAttemptRows.value).map((attempt) => ({
      ...attempt,
      scoreDisplay: attempt.score_percent === null ? 'N/A' : `${Number(attempt.score_percent).toFixed(1)}%`,
      scoreTone: scoreTone(attempt.score_percent),
      submittedAtText: attempt.submitted_at ? formatDateTime(attempt.submitted_at) : 'n/a',
    }))
  ))

  const averageScore = computed(() => (
    stats.value.average_score_percent === null ? null : Number(stats.value.average_score_percent)
  ))

  const strongestSubject = computed(() => (
    subjects.value
      .filter((subject) => subject.attempts_count > 0 && subject.average_score_percent !== null)
      .sort((left, right) => Number(right.average_score_percent ?? 0) - Number(left.average_score_percent ?? 0))[0]
      ?? null
  ))

  const focusSubjects = computed(() => (
    subjects.value
      .filter((subject) => subject.attempts_count > 0 && subject.performance_band === 'focus')
      .sort((left, right) => Number(left.average_score_percent ?? 0) - Number(right.average_score_percent ?? 0))
  ))

  const strengths = computed(() => (
    subjects.value
      .filter((subject) => subject.attempts_count > 0 && subject.performance_band === 'strong')
      .sort((left, right) => Number(right.average_score_percent ?? 0) - Number(left.average_score_percent ?? 0))
      .slice(0, 3)
  ))

  const latestScoreDeltaText = computed(() => {
    const delta = Number(stats.value.latest_score_delta ?? 0)
    if (!Number.isFinite(delta) || delta === 0) return 'Same as your last exam'
    return delta > 0
      ? `Up ${Math.abs(delta).toFixed(1)} points`
      : `Down ${Math.abs(delta).toFixed(1)} points`
  })

  const statCards = computed(() => {
    const latestScore = stats.value.latest_score_percent
    const focusCount = Number(stats.value.focus_subjects_count ?? 0)

    return [
      {
        label: 'Exams Taken',
        value: String(stats.value.completed_attempts ?? 0),
        hint: 'Finished exams',
        tone: 'navy',
      },
      {
        label: 'Overall Average',
        value: averageScore.value === null ? 'N/A' : `${averageScore.value.toFixed(1)}%`,
        hint: averageScore.value === null
          ? 'Take one exam to see this'
          : 'Based on finished exams',
        tone: 'success',
      },
      {
        label: 'Latest Result',
        value: latestScore === null ? 'N/A' : `${Number(latestScore).toFixed(1)}%`,
        hint: latestScore === null
          ? 'Shows after your next exam'
          : latestScoreDeltaText.value,
        tone: 'gold',
      },
      {
        label: 'Review Areas',
        value: String(focusCount),
        hint: focusCount === 0
          ? 'All attempted subjects are 75%+'
          : `${focusCount} subject${focusCount === 1 ? '' : 's'} below 75%`,
        tone: focusCount === 0 ? 'success' : 'danger',
      },
    ]
  })

  const guidanceItems = computed(() => {
    if ((stats.value.completed_attempts ?? 0) === 0) {
      return [
        {
          title: 'Take your first exam',
          description: 'Finish one exam to unlock your scores and subject feedback.',
          tone: 'navy',
        },
      ]
    }

    const items = []

    if (focusSubjects.value.length > 0) {
      const subjectLabels = focusSubjects.value.slice(0, 2).map((subject) => subject.label).join(', ')
      items.push({
        title: 'Start with these subjects',
        description: `${subjectLabels} are still below 75%.`,
        tone: 'danger',
      })
    } else {
      items.push({
        title: 'You are on track',
        description: 'All attempted subjects are already at 75% or higher.',
        tone: 'success',
      })
    }

    if (strengths.value.length > 0) {
      items.push({
        title: 'Strongest subject',
        description: strengths.value[0].label,
        tone: 'navy',
      })
    }

    items.push({
      title: 'Latest trend',
      description: latestScoreDeltaText.value,
      tone: 'gold',
    })

    return items
  })

  const predictivePanel = computed(() => ({
    ...predictiveModel.value,
    statusLabel: predictiveModel.value.status === 'awaiting_ml_api' ? 'Coming Soon' : 'Connected',
    featureLabels: asList(predictiveModel.value.expected_inputs).map(humanizeFeatureKey),
  }))

  const hasAttemptData = computed(() => (stats.value.completed_attempts ?? 0) > 0)

  async function loadAnalytics() {
    analyticsLoading.value = true
    analyticsError.value = ''

    try {
      const { data } = await studentAnalyticsApi.summary()

      stats.value = {
        completed_attempts: Number(data?.stats?.completed_attempts ?? 0),
        average_score_percent: data?.stats?.average_score_percent === null
          ? null
          : Number(data?.stats?.average_score_percent),
        latest_score_percent: data?.stats?.latest_score_percent === null
          ? null
          : Number(data?.stats?.latest_score_percent),
        latest_score_delta: data?.stats?.latest_score_delta === null
          ? null
          : Number(data?.stats?.latest_score_delta),
        focus_subjects_count: Number(data?.stats?.focus_subjects_count ?? 0),
        passing_threshold: Number(data?.stats?.passing_threshold ?? 75),
      }
      subjectRows.value = asList(data?.subjects)
      historyRows.value = asList(data?.history)
      recentAttemptRows.value = asList(data?.recent_attempts)
      predictiveModel.value = {
        ...predictiveModel.value,
        ...(data?.predictive_model ?? {}),
      }
    } catch (error) {
      analyticsError.value = firstApiError(error, 'Unable to load student analytics right now.')
      stats.value = {
        completed_attempts: 0,
        average_score_percent: null,
        latest_score_percent: null,
        latest_score_delta: null,
        focus_subjects_count: 0,
        passing_threshold: 75,
      }
      subjectRows.value = []
      historyRows.value = []
      recentAttemptRows.value = []
    } finally {
      analyticsLoading.value = false
      analyticsLoaded.value = true
    }
  }

  onMounted(() => {
    loadAnalytics()
  })

  return {
    analyticsLoading,
    analyticsLoaded,
    analyticsError,
    hasAttemptData,
    statCards,
    subjects,
    averageScore,
    strongestSubject,
    focusSubjects,
    strengths,
    scoreHistory,
    recentAttempts,
    guidanceItems,
    predictivePanel,
    loadAnalytics,
  }
}
