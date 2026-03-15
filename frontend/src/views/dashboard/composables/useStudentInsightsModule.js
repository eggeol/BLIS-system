import { computed } from 'vue'
import { ClipboardList, Clock3, Gauge, ShieldCheck } from 'lucide-vue-next'
import {
  DASHBOARD_ACTIVITIES,
  DASHBOARD_SCORE_HISTORY,
  DASHBOARD_SUBJECTS,
} from '@/constants/dashboardAnalytics'

export function useStudentInsightsModule() {
  const subjects = DASHBOARD_SUBJECTS
  const scoreHistory = DASHBOARD_SCORE_HISTORY
  const activities = DASHBOARD_ACTIVITIES

  const averageScore = computed(() => {
    if (subjects.length === 0) return 0

    const total = subjects.reduce((sum, subject) => sum + Number(subject.score ?? 0), 0)
    return Math.round(total / subjects.length)
  })

  const strongestSubject = computed(() => {
    if (subjects.length === 0) return null

    return subjects.reduce((best, current) => (
      Number(current.score ?? 0) > Number(best.score ?? 0) ? current : best
    ))
  })

  const focusSubjects = computed(() => (
    subjects.filter((subject) => Number(subject.score ?? 0) < 75)
  ))

  const statCards = [
    { label: 'Overall Average', value: `${averageScore.value}%`, trend: '+4% this week', positive: true, tone: 'navy', icon: Gauge },
    { label: 'Pass Probability', value: '84%', trend: '+2% this week', positive: true, tone: 'success', icon: ShieldCheck },
    { label: 'Exams Taken', value: '12', trend: '3 exams pending', positive: true, tone: 'gold', icon: ClipboardList },
    { label: 'Avg. Time per Exam', value: '58m', trend: '-5m faster', positive: true, tone: 'navy', icon: Clock3 },
  ]

  return {
    activities,
    averageScore,
    focusSubjects,
    scoreHistory,
    statCards,
    strongestSubject,
    subjects,
  }
}
