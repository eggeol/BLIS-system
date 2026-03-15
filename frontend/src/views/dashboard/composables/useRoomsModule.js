import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/store/auth.store'
import {
  formatClockTime,
  formatDateTime,
  formatExamSchedule,
  normalizeExamDeliveryMode,
  parseDateTime,
} from './useDashboardFormatters'
import { useDashboardDataServices } from './useDashboardDataServices'

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

function splitQuestionStemAndNumberedItems(questionText) {
  const normalized = String(questionText ?? '').replace(/\s+/g, ' ').trim()
  if (!normalized) {
    return { leadText: '', numberedItems: [] }
  }

  const firstNumberMatch = /(?:^|\s)1\.\s+/.exec(normalized)
  if (!firstNumberMatch) {
    return { leadText: normalized, numberedItems: [] }
  }

  const startsWithSpace = firstNumberMatch[0].startsWith(' ')
  const itemBlockStart = (firstNumberMatch.index ?? 0) + (startsWithSpace ? 1 : 0)
  const leadText = normalized.slice(0, itemBlockStart).trim()
  if (!leadText) {
    return { leadText: normalized, numberedItems: [] }
  }

  const itemBlock = normalized.slice(itemBlockStart).trim()
  const itemMatches = itemBlock.matchAll(/(\d+)\.\s*(.+?)(?=(?:\s+\d+\.\s*)|$)/g)
  const parsedItems = Array.from(itemMatches).map((match) => ({
    number: Number(match[1]),
    text: String(match[2] ?? '').trim(),
  }))

  if (parsedItems.length < 2) {
    return { leadText: normalized, numberedItems: [] }
  }

  const isSequential = parsedItems.every((item, index) => (
    index === 0 ? item.number === 1 : item.number === parsedItems[index - 1].number + 1
  ))

  const hasMostlyStatementText = parsedItems.filter((item) => /[A-Za-z]/.test(item.text)).length >= Math.max(2, Math.ceil(parsedItems.length * 0.7))

  if (!isSequential || !hasMostlyStatementText) {
    return { leadText: normalized, numberedItems: [] }
  }

  return {
    leadText,
    numberedItems: parsedItems.map((item) => item.text),
  }
}

function examScheduleStart(exam) {
  return exam?.schedule_start_at ?? exam?.scheduled_at ?? null
}

function examScheduleEnd(exam) {
  return exam?.schedule_end_at ?? null
}

export function useRoomsModule({ mode = 'student' } = {}) {
  const auth = useAuthStore()
  const services = useDashboardDataServices()

  const modeKey = String(mode ?? 'student').toLowerCase()
  const isManagementView = computed(() => modeKey === 'management')

  const roomName = ref('')
  const joinCode = ref('')
  const rooms = ref([])
  const selectedRoomId = ref(null)
  const selectedRoom = ref(null)
  const showCreateRoomModal = ref(false)
  const showJoinRoomModal = ref(false)
  const showEditRoomModal = ref(false)
  const showDeleteRoomModal = ref(false)
  const showLeaveRoomModal = ref(false)
  const editRoomName = ref('')
  const roomLoading = ref(false)
  const roomDetailsLoading = ref(false)
  const roomError = ref('')
  const roomMessage = ref('')

  const roomLiveBoardActive = ref(false)
  const liveBoardExam = ref(null)
  const liveBoardRoomId = ref(null)
  const liveBoardRows = ref([])
  const liveBoardItemSummary = ref([])
  const liveBoardSummary = ref({
    students_total: 0,
    attempts_started: 0,
    attempts_submitted: 0,
  })
  const liveBoardLoading = ref(false)
  const liveBoardRefreshing = ref(false)
  const liveBoardError = ref('')
  const liveBoardUpdatedAt = ref(null)
  const liveBoardOptions = reactive({
    show_names: true,
    show_responses: true,
    show_results: true,
  })

  const showExamSimulationModal = ref(false)
  const showStudentSubmitConfirmModal = ref(false)
  const showStudentExitConfirmModal = ref(false)
  const selectedStudentExam = ref(null)
  const studentExamAttempt = ref(null)
  const studentExamReviewVisible = ref(false)
  const studentExamQuestions = ref([])
  const studentExamCurrentIndex = ref(0)
  const isExamMobileViewport = ref(false)
  const examAttemptSidebarCollapsed = ref(false)
  const studentExamLoading = ref(false)
  const studentExamSaving = ref(false)
  const studentExamBookmarking = ref(false)
  const studentExamSubmitting = ref(false)
  const studentExamError = ref('')
  const studentExamRemainingSeconds = ref(null)
  const studentExamVisitedQuestionIds = ref([])
  const studentAnswerDraft = reactive({
    selected_option_id: null,
    answer_text: '',
  })

  let examAttemptMobileMediaQuery
  let studentExamTimerInterval
  let studentExamSyncInterval
  let liveBoardRefreshInterval
  let studentExamSyncing = false

  const normalizedRole = computed(() => String(auth.user?.role ?? 'student').toLowerCase())
  const isAdminRole = computed(() => normalizedRole.value === 'admin')
  const isStaffRole = computed(() => ['staff_master_examiner', 'faculty'].includes(normalizedRole.value))
  const isManagementRole = computed(() => isAdminRole.value || isStaffRole.value)
  const canCreateRooms = computed(() => isStaffRole.value)
  const canViewExamResults = computed(() => isStaffRole.value)

  const roomCollectionLabel = computed(() => {
    if (isAdminRole.value) return 'All rooms in the platform'
    if (isManagementRole.value) return "Rooms you've added"
    return 'Rooms joined'
  })

  const currentStudentExamQuestion = computed(() => (
    studentExamQuestions.value[studentExamCurrentIndex.value] ?? null
  ))

  const currentQuestionStem = computed(() => (
    splitQuestionStemAndNumberedItems(currentStudentExamQuestion.value?.question_text ?? '')
  ))

  const isStudentExamSubmitted = computed(() => (
    String(studentExamAttempt.value?.status ?? '') === 'submitted'
  ))

  const isStudentExamResultSummaryVisible = computed(() => (
    isStudentExamSubmitted.value && !studentExamReviewVisible.value
  ))

  const isStudentOpenNavigationMode = computed(() => (
    !isStudentExamSubmitted.value
  ))

  const isCurrentQuestionInputLocked = computed(() => (
    isStudentExamSubmitted.value
      || studentExamSaving.value
      || studentExamSubmitting.value
      || studentExamBookmarking.value
  ))

  const studentExamUnansweredCount = computed(() => (
    studentExamQuestions.value.filter((question) => !questionHasAnswer(question)).length
  ))

  const studentExamResultSummary = computed(() => {
    const total = Math.max(0, Number(studentExamAttempt.value?.total_items ?? studentExamQuestions.value.length))
    const answered = Math.max(0, Number(studentExamAttempt.value?.answered_count ?? 0))
    const correct = Math.max(0, Number(studentExamAttempt.value?.correct_answers ?? 0))
    const incorrect = Math.max(answered - correct, 0)
    const missed = Math.max(total - answered, 0)
    const rawScore = Number(studentExamAttempt.value?.score_percent ?? 0)
    const score = Number.isFinite(rawScore) ? rawScore : 0
    const submittedAtText = studentExamAttempt.value?.submitted_at
      ? formatDateTime(studentExamAttempt.value.submitted_at)
      : 'n/a'

    let tone = 'needs-work'
    let label = 'Needs more review'
    let headline = 'Keep practicing and review the missed items.'
    let message = 'Focus on the questions you missed, then review the correct answers before your next attempt.'

    if (score >= 90) {
      tone = 'excellent'
      label = 'Outstanding result'
      headline = 'Strong finish.'
      message = 'You cleared this exam with a high score. Review the full breakdown if you want to confirm every item.'
    } else if (score >= 75) {
      tone = 'passing'
      label = 'Passing result'
      headline = 'You passed this attempt.'
      message = 'Your score is in a good range. Check the answer review for the few items that still need cleanup.'
    } else if (score >= 60) {
      tone = 'review'
      label = 'Close, but needs review'
      headline = 'You are within reach.'
      message = 'You answered a fair share correctly, but there are still several items to revisit before the next attempt.'
    }

    return {
      total,
      answered,
      correct,
      incorrect,
      missed,
      score,
      scoreDisplay: score.toFixed(2),
      submittedAtText,
      tone,
      label,
      headline,
      message,
    }
  })

  const liveBoardRoom = computed(() => {
    const roomId = Number(liveBoardRoomId.value)
    if (!Number.isFinite(roomId) || roomId < 1) return null

    if (Number(selectedRoom.value?.id) === roomId) {
      return selectedRoom.value
    }

    return rooms.value.find((room) => Number(room.id) === roomId) ?? null
  })

  const liveBoardLastUpdatedText = computed(() => (
    liveBoardUpdatedAt.value ? formatClockTime(liveBoardUpdatedAt.value) : 'n/a'
  ))

  watch(
    () => showExamSimulationModal.value,
    (isOpen) => {
      if (!isOpen) return

      const mobileViewport = isExamMobileViewport.value
        || (typeof window !== 'undefined' && window.matchMedia('(max-width: 1200px)').matches)

      isExamMobileViewport.value = mobileViewport
      examAttemptSidebarCollapsed.value = mobileViewport
    },
  )

  function syncExamAttemptSidebarForViewport(eventOrQuery) {
    const mobileViewport = Boolean(eventOrQuery?.matches)
    isExamMobileViewport.value = mobileViewport

    if (mobileViewport) {
      examAttemptSidebarCollapsed.value = true
      return
    }

    examAttemptSidebarCollapsed.value = false
  }

  function toggleExamAttemptSidebar() {
    examAttemptSidebarCollapsed.value = !examAttemptSidebarCollapsed.value
  }

  onMounted(() => {
    if (typeof window !== 'undefined') {
      examAttemptMobileMediaQuery = window.matchMedia('(max-width: 1200px)')
      syncExamAttemptSidebarForViewport(examAttemptMobileMediaQuery)

      if (typeof examAttemptMobileMediaQuery.addEventListener === 'function') {
        examAttemptMobileMediaQuery.addEventListener('change', syncExamAttemptSidebarForViewport)
      } else {
        examAttemptMobileMediaQuery.addListener(syncExamAttemptSidebarForViewport)
      }
    }

    fetchRooms()
  })

  onBeforeUnmount(() => {
    clearStudentExamTimer()
    stopStudentExamAutoSync()
    stopLiveBoardAutoRefresh()

    if (!examAttemptMobileMediaQuery) return

    if (typeof examAttemptMobileMediaQuery.removeEventListener === 'function') {
      examAttemptMobileMediaQuery.removeEventListener('change', syncExamAttemptSidebarForViewport)
    } else {
      examAttemptMobileMediaQuery.removeListener(syncExamAttemptSidebarForViewport)
    }
  })

  function canRemoveRoomMember(member) {
    if (!isManagementRole.value) return false

    const memberRole = String(member?.role ?? '').toLowerCase()
    return memberRole === 'student'
  }

  function studentMaxAttempts(exam) {
    const resolvedMaxAttempts = Number(exam?.student_max_attempts)

    if (Number.isFinite(resolvedMaxAttempts) && resolvedMaxAttempts > 0) {
      return resolvedMaxAttempts
    }

    return exam?.one_take_only ? 1 : 2
  }

  function studentSubmittedAttempts(exam) {
    const submittedAttempts = Number(exam?.student_submitted_attempts)

    if (Number.isFinite(submittedAttempts) && submittedAttempts >= 0) {
      return submittedAttempts
    }

    return isStudentExamCompleted(exam) ? 1 : 0
  }

  function studentAttemptsRemaining(exam) {
    return Math.max(0, studentMaxAttempts(exam) - studentSubmittedAttempts(exam))
  }

  function isStudentExamRetakeLimitReached(exam) {
    return isStudentExamCompleted(exam) && studentAttemptsRemaining(exam) <= 0
  }

  function canStudentTakeExam(exam) {
    if (!exam?.question_bank_id) return false

    const now = Date.now()
    const scheduleStart = parseDateTime(examScheduleStart(exam))
    const scheduleEnd = parseDateTime(examScheduleEnd(exam))

    if (scheduleStart && scheduleStart.getTime() > now) return false
    if (scheduleEnd && scheduleEnd.getTime() < now) return false
    if (isStudentExamRetakeLimitReached(exam)) return false

    return true
  }

  function studentExamAttemptState(exam) {
    return String(exam?.student_attempt_state ?? 'not_started').toLowerCase()
  }

  function isStudentExamInProgress(exam) {
    return studentExamAttemptState(exam) === 'in_progress'
  }

  function isStudentExamCompleted(exam) {
    return studentExamAttemptState(exam) === 'submitted'
  }

  function studentSubmittedAttemptId(exam) {
    const attemptId = Number(exam?.student_attempt_id ?? 0)
    return Number.isFinite(attemptId) && attemptId > 0 ? attemptId : null
  }

  function canStudentOpenExam(exam) {
    if (isStudentExamRetakeLimitReached(exam) && studentSubmittedAttemptId(exam)) {
      return true
    }

    return canStudentTakeExam(exam)
  }

  function studentExamActionLabel(exam) {
    if (isStudentExamInProgress(exam)) return 'Resume Exam'
    if (isStudentExamCompleted(exam)) {
      return isStudentExamRetakeLimitReached(exam) ? 'Review Result' : 'Retake Exam'
    }

    return 'Take Exam'
  }

  function studentExamAvailabilityText(exam) {
    if (!exam?.question_bank_id) return 'Not available (no question set linked)'

    const now = Date.now()
    const scheduleStart = parseDateTime(examScheduleStart(exam))
    const scheduleEnd = parseDateTime(examScheduleEnd(exam))

    if (scheduleStart && scheduleStart.getTime() > now) {
      return `Available on ${formatExamSchedule(examScheduleStart(exam), examScheduleEnd(exam))}`
    }

    if (scheduleEnd && scheduleEnd.getTime() < now) {
      return `Window ended on ${formatDateTime(examScheduleEnd(exam))}`
    }

    if (isStudentExamRetakeLimitReached(exam)) {
      return studentMaxAttempts(exam) === 1
        ? 'Completed (review only)'
        : 'Retake limit reached (review only)'
    }

    if (isStudentExamInProgress(exam)) {
      return 'In progress (resume anytime)'
    }

    if (isStudentExamCompleted(exam)) {
      const remaining = studentAttemptsRemaining(exam)

      if (remaining === 1) {
        return '1 retake remaining'
      }

      return `Available for retake (${remaining} attempts left)`
    }

    if (scheduleEnd) {
      return `Available until ${formatDateTime(examScheduleEnd(exam))}`
    }

    return 'Available now'
  }

  function clearStudentExamTimer() {
    if (!studentExamTimerInterval) return
    clearInterval(studentExamTimerInterval)
    studentExamTimerInterval = null
  }

  function startStudentExamTimer(remainingSeconds) {
    clearStudentExamTimer()

    if (!Number.isFinite(Number(remainingSeconds))) {
      studentExamRemainingSeconds.value = null
      return
    }

    studentExamRemainingSeconds.value = Math.max(0, Number(remainingSeconds))

    if (studentExamRemainingSeconds.value <= 0 || isStudentExamSubmitted.value) return

    studentExamTimerInterval = setInterval(() => {
      if (studentExamRemainingSeconds.value === null || studentExamRemainingSeconds.value <= 0) {
        clearStudentExamTimer()
        return
      }

      studentExamRemainingSeconds.value -= 1
    }, 1000)
  }

  function stopStudentExamAutoSync() {
    if (!studentExamSyncInterval) return
    clearInterval(studentExamSyncInterval)
    studentExamSyncInterval = null
    studentExamSyncing = false
  }

  async function refreshStudentExamAttemptStatus(silent = true) {
    if (!showExamSimulationModal.value || isStudentExamSubmitted.value) return
    if (studentExamLoading.value || studentExamSaving.value || studentExamSubmitting.value || studentExamBookmarking.value) return
    if (studentExamSyncing) return

    const attemptId = studentExamAttempt.value?.id
    if (!attemptId) return

    const currentQuestionId = currentStudentExamQuestion.value?.question_id ?? null
    studentExamSyncing = true

    try {
      const { data } = await services.getAttempt(attemptId)
      applyStudentAttemptPayload(data, currentQuestionId)
    } catch (error) {
      if (!silent) {
        studentExamError.value = firstApiError(error, 'Unable to refresh attempt status.')
      }
    } finally {
      studentExamSyncing = false
    }
  }

  function startStudentExamAutoSync() {
    stopStudentExamAutoSync()

    studentExamSyncInterval = setInterval(() => {
      refreshStudentExamAttemptStatus(true)
    }, 4000)
  }

  function syncStudentAnswerDraft() {
    const current = currentStudentExamQuestion.value

    if (!current) {
      studentAnswerDraft.selected_option_id = null
      studentAnswerDraft.answer_text = ''
      return
    }

    studentAnswerDraft.selected_option_id = current.answer?.selected_option_id ?? null
    studentAnswerDraft.answer_text = current.answer?.answer_text ?? ''
  }

  function studentQuestionReviewStatus(question) {
    if (!question) return 'missed'

    if (!questionHasAnswer(question)) {
      return 'missed'
    }

    if (question.answer?.is_correct === true) {
      return 'correct'
    }

    if (question.answer?.is_correct === false) {
      return 'incorrect'
    }

    return 'answered'
  }

  function studentQuestionReviewLabel(question) {
    const status = studentQuestionReviewStatus(question)

    if (status === 'correct') return 'Correct answer'
    if (status === 'incorrect') return 'Incorrect answer'
    if (status === 'missed') return 'No answer submitted'
    return 'Answer submitted'
  }

  function studentQuestionReviewMessage(question) {
    const status = studentQuestionReviewStatus(question)

    if (status === 'correct') return 'You answered this item correctly.'
    if (status === 'incorrect') return 'Your selected answer did not match the answer key.'
    if (status === 'missed') return 'This item was left unanswered in the submitted attempt.'
    return 'This item was submitted and is ready for review.'
  }

  function studentQuestionCorrectAnswerText(question) {
    const label = String(question?.correct_answer?.label ?? '').trim()
    const text = String(question?.correct_answer?.text ?? '').trim()

    if (label && text) return `${label}. ${text}`
    if (text) return text
    if (label) return label
    return ''
  }

  function examOptionCardClass(option) {
    const currentQuestion = currentStudentExamQuestion.value
    const optionId = Number(option?.id ?? 0)
    const selectedOptionId = Number(studentAnswerDraft.selected_option_id ?? 0)
    const isSelected = optionId > 0 && selectedOptionId > 0 && optionId === selectedOptionId
    const reviewStatus = studentQuestionReviewStatus(currentQuestion)
    const isCorrectOption = isStudentExamSubmitted.value && option?.is_correct === true
    const isIncorrectSelection = isStudentExamSubmitted.value && isSelected && reviewStatus === 'incorrect'
    const isNeutralSubmittedOption = isStudentExamSubmitted.value && !isSelected && !isCorrectOption

    return {
      selected: isSelected,
      'submitted-correct': isCorrectOption,
      'submitted-incorrect': isIncorrectSelection,
      'submitted-neutral': isNeutralSubmittedOption,
    }
  }

  function questionHasAnswer(question) {
    if (!question) return false

    const hasSelectedOption = question.answer?.selected_option_id !== null && question.answer?.selected_option_id !== undefined
    const hasTextAnswer = String(question.answer?.answer_text ?? '').trim().length > 0

    return hasSelectedOption || hasTextAnswer
  }

  function isQuestionVisited(questionId) {
    return studentExamVisitedQuestionIds.value.includes(Number(questionId))
  }

  function markQuestionVisited(questionId) {
    const normalizedId = Number(questionId)
    if (!normalizedId) return
    if (isQuestionVisited(normalizedId)) return

    studentExamVisitedQuestionIds.value = [
      ...studentExamVisitedQuestionIds.value,
      normalizedId,
    ]
  }

  function markCurrentQuestionVisited() {
    const currentQuestionId = currentStudentExamQuestion.value?.question_id
    if (!currentQuestionId) return

    markQuestionVisited(currentQuestionId)
  }

  function questionPaletteStatus(question, index) {
    if (!question) return 'pre-pending'

    const hasAnswer = questionHasAnswer(question)

    if (isStudentExamSubmitted.value) {
      if (!hasAnswer) return 'post-missed'

      if (question.answer?.is_correct === true) return 'post-correct'
      if (question.answer?.is_correct === false) return 'post-incorrect'

      return 'post-answered'
    }

    if (studentExamCurrentIndex.value === index) return 'pre-current'
    if (hasAnswer) return 'pre-answered'
    if (isQuestionVisited(question.question_id)) return 'pre-blank'
    return 'pre-pending'
  }

  function questionPaletteClass(question, index) {
    const status = questionPaletteStatus(question, index)

    return {
      'is-current': studentExamCurrentIndex.value === index,
      'has-bookmark': Boolean(question?.is_bookmarked),
      'pre-current': status === 'pre-current',
      'pre-answered': status === 'pre-answered',
      'pre-blank': status === 'pre-blank',
      'pre-pending': status === 'pre-pending',
      'post-correct': status === 'post-correct',
      'post-incorrect': status === 'post-incorrect',
      'post-missed': status === 'post-missed',
      'post-answered': status === 'post-answered',
    }
  }

  function applyStudentAttemptPayload(payload, preferredQuestionId = null) {
    const nextAttemptStatus = String(payload?.attempt?.status ?? '').toLowerCase()
    const previousVisitedQuestionIds = [...studentExamVisitedQuestionIds.value]
    const examPayload = payload?.exam
      ? {
          ...payload.exam,
          delivery_mode: normalizeExamDeliveryMode(payload.exam.delivery_mode),
        }
      : null

    studentExamAttempt.value = payload?.attempt ?? null
    selectedStudentExam.value = examPayload ?? selectedStudentExam.value
    studentExamQuestions.value = payload?.questions ?? []
    studentExamReviewVisible.value = nextAttemptStatus === 'submitted' ? false : studentExamReviewVisible.value
    syncSelectedRoomAssignedExam(examPayload, payload?.attempt ?? null)

    const answeredQuestionIds = studentExamQuestions.value
      .filter((question) => questionHasAnswer(question))
      .map((question) => Number(question.question_id))

    studentExamVisitedQuestionIds.value = Array.from(new Set([
      ...previousVisitedQuestionIds,
      ...answeredQuestionIds,
    ]))

    const defaultIndex = studentExamQuestions.value.findIndex((question) => !question.answer?.selected_option_id && !question.answer?.answer_text)
    const preferredIndex = preferredQuestionId
      ? studentExamQuestions.value.findIndex((question) => question.question_id === preferredQuestionId)
      : -1

    if (preferredIndex >= 0) {
      studentExamCurrentIndex.value = preferredIndex
    } else if (defaultIndex >= 0) {
      studentExamCurrentIndex.value = defaultIndex
    } else {
      studentExamCurrentIndex.value = 0
    }

    startStudentExamTimer(payload?.attempt?.remaining_seconds)
    syncStudentAnswerDraft()
  }

  function syncSelectedRoomAssignedExam(examPayload, attemptPayload) {
    if (!selectedRoom.value || !Array.isArray(selectedRoom.value.assigned_exams)) return

    const examId = Number(examPayload?.id ?? 0)
    if (!Number.isFinite(examId) || examId < 1) return

    selectedRoom.value = {
      ...selectedRoom.value,
      assigned_exams: selectedRoom.value.assigned_exams.map((exam) => {
        if (Number(exam.id) !== examId) {
          return exam
        }

        const maxAttempts = Number(exam.student_max_attempts ?? studentMaxAttempts(exam))
        const currentSubmittedAttempts = Number(exam.student_submitted_attempts ?? 0)
        const wasAlreadySubmitted = String(exam.student_attempt_state ?? '').toLowerCase() === 'submitted'
        const nextSubmittedAttempts = attemptPayload?.status === 'submitted'
          ? Math.max(currentSubmittedAttempts + (wasAlreadySubmitted ? 0 : 1), currentSubmittedAttempts || 1)
          : currentSubmittedAttempts
        const attemptsRemaining = Math.max(0, maxAttempts - nextSubmittedAttempts)

        return {
          ...exam,
          ...examPayload,
          delivery_mode: normalizeExamDeliveryMode(examPayload?.delivery_mode ?? exam.delivery_mode),
          student_attempt_state: attemptPayload?.status ?? exam.student_attempt_state ?? 'not_started',
          student_attempt_id: attemptPayload?.id ?? exam.student_attempt_id ?? null,
          student_submitted_at: attemptPayload?.submitted_at ?? exam.student_submitted_at ?? null,
          student_submitted_attempts: nextSubmittedAttempts,
          student_max_attempts: maxAttempts,
          student_attempts_remaining: attemptsRemaining,
          student_can_start_attempt: attemptsRemaining > 0,
        }
      }),
    }
  }

  async function openExamSimulation(exam) {
    if (!canStudentOpenExam(exam) || !selectedRoomId.value) return

    const reviewOnlyMode = isStudentExamRetakeLimitReached(exam)
    const submittedAttemptId = studentSubmittedAttemptId(exam)

    selectedStudentExam.value = {
      ...exam,
      delivery_mode: normalizeExamDeliveryMode(exam.delivery_mode),
    }
    studentExamError.value = ''
    showExamSimulationModal.value = true
    studentExamLoading.value = true

    try {
      if (reviewOnlyMode && submittedAttemptId) {
        const { data } = await services.getAttempt(submittedAttemptId)
        applyStudentAttemptPayload(data)
        roomMessage.value = 'Reviewing your submitted attempt.'
        return
      }

      const { data } = await services.startExam(exam.id, {
        room_id: selectedRoomId.value,
      })

      const isFreshTake = data?.resumed === false
      const isShuffleEnabled = Boolean(data?.exam?.shuffle_questions)

      if (isFreshTake) {
        studentExamVisitedQuestionIds.value = []
      }

      if (isFreshTake && isShuffleEnabled && Array.isArray(data?.questions)) {
        data.questions = data.questions.map((question) => ({
          ...question,
          is_bookmarked: false,
        }))
      }

      applyStudentAttemptPayload(data)
      startStudentExamAutoSync()
      roomMessage.value = data?.message ?? 'Exam attempt is ready.'
    } catch (error) {
      stopStudentExamAutoSync()
      studentExamError.value = firstApiError(error, 'Unable to start exam attempt.')
    } finally {
      studentExamLoading.value = false
    }
  }

  function closeExamSimulationModal() {
    clearStudentExamTimer()
    stopStudentExamAutoSync()

    showExamSimulationModal.value = false
    showStudentSubmitConfirmModal.value = false
    showStudentExitConfirmModal.value = false
    selectedStudentExam.value = null
    studentExamAttempt.value = null
    studentExamReviewVisible.value = false
    studentExamQuestions.value = []
    studentExamCurrentIndex.value = 0
    studentExamLoading.value = false
    studentExamSaving.value = false
    studentExamBookmarking.value = false
    studentExamSubmitting.value = false
    studentExamError.value = ''
    studentExamRemainingSeconds.value = null
    studentExamVisitedQuestionIds.value = []
    studentAnswerDraft.selected_option_id = null
    studentAnswerDraft.answer_text = ''
  }

  function goToStudentExamQuestionIndex(targetIndex) {
    const maxIndex = studentExamQuestions.value.length - 1
    if (maxIndex < 0) return

    const resolvedTargetIndex = Math.min(Math.max(targetIndex, 0), maxIndex)

    markCurrentQuestionVisited()
    studentExamCurrentIndex.value = resolvedTargetIndex
    syncStudentAnswerDraft()
  }

  function goToStudentExamQuestion(step) {
    goToStudentExamQuestionIndex(studentExamCurrentIndex.value + step)
  }

  function handleExamAttemptCloseClick() {
    if (studentExamSubmitting.value) return

    if (isStudentExamSubmitted.value) {
      closeExamSimulationModal()
      return
    }

    showStudentSubmitConfirmModal.value = false
    showStudentExitConfirmModal.value = true
    studentExamError.value = ''
  }

  function openStudentExamReview() {
    if (!isStudentExamSubmitted.value || studentExamQuestions.value.length === 0) return

    const preferredIndex = studentExamQuestions.value.findIndex((question) => {
      const status = studentQuestionReviewStatus(question)
      return status === 'incorrect' || status === 'missed'
    })

    studentExamReviewVisible.value = true
    studentExamCurrentIndex.value = preferredIndex >= 0 ? preferredIndex : 0

    if (!isExamMobileViewport.value) {
      examAttemptSidebarCollapsed.value = false
    }

    syncStudentAnswerDraft()
  }

  function showStudentExamResultSummary() {
    if (!isStudentExamSubmitted.value) return

    studentExamReviewVisible.value = false

    if (isExamMobileViewport.value) {
      examAttemptSidebarCollapsed.value = true
    }
  }

  function closeStudentExamExitConfirm() {
    if (studentExamSubmitting.value) return
    showStudentExitConfirmModal.value = false
  }

  function confirmStudentExamExit() {
    if (studentExamSubmitting.value) return

    if (studentExamSaving.value || studentExamBookmarking.value) {
      studentExamError.value = 'Please wait for the latest answer changes to finish syncing before exiting.'
      return
    }

    showStudentExitConfirmModal.value = false
    closeExamSimulationModal()
  }

  function openStudentExamSubmitConfirm() {
    if (isStudentExamSubmitted.value) return
    if (!studentExamAttempt.value?.id) return
    if (studentExamLoading.value || studentExamSubmitting.value) return

    showStudentExitConfirmModal.value = false
    showStudentSubmitConfirmModal.value = true
    studentExamError.value = ''
  }

  function closeStudentExamSubmitConfirm() {
    if (studentExamSubmitting.value) return
    showStudentSubmitConfirmModal.value = false
  }

  async function confirmStudentExamSubmit() {
    if (studentExamSubmitting.value) return

    if (studentExamSaving.value || studentExamBookmarking.value) {
      studentExamError.value = 'Please wait for the latest answer changes to finish syncing before submitting.'
      return
    }

    showStudentSubmitConfirmModal.value = false
    await submitStudentExam()
  }

  async function saveStudentExamAnswer() {
    const attemptId = studentExamAttempt.value?.id
    const currentQuestion = currentStudentExamQuestion.value
    if (!attemptId || !currentQuestion || isStudentExamSubmitted.value) return

    const normalizedDraftText = studentAnswerDraft.answer_text?.trim() || null
    const normalizedSavedText = currentQuestion.answer?.answer_text?.trim() || null
    const selectedOptionId = studentAnswerDraft.selected_option_id ?? null
    const savedOptionId = currentQuestion.answer?.selected_option_id ?? null

    if (currentQuestion.question_type === 'open_ended' && normalizedDraftText === normalizedSavedText) return
    if (currentQuestion.question_type !== 'open_ended' && selectedOptionId === savedOptionId) return

    studentExamSaving.value = true
    studentExamError.value = ''
    markQuestionVisited(currentQuestion.question_id)

    const payload = {
      question_id: currentQuestion.question_id,
    }

    if (currentQuestion.question_type === 'open_ended') {
      payload.answer_text = normalizedDraftText
    } else {
      payload.selected_option_id = selectedOptionId
    }

    try {
      const { data } = await services.saveAnswer(attemptId, payload)
      applyStudentAttemptPayload(data, currentQuestion.question_id)
    } catch (error) {
      studentExamError.value = firstApiError(error, 'Unable to save answer.')
    } finally {
      studentExamSaving.value = false
    }
  }

  async function handleStudentOptionSelect(optionId) {
    if (isCurrentQuestionInputLocked.value) return

    studentAnswerDraft.selected_option_id = Number(optionId)
    await saveStudentExamAnswer()
  }

  async function handleStudentOpenEndedBlur() {
    if (isCurrentQuestionInputLocked.value) return
    await saveStudentExamAnswer()
  }

  async function toggleCurrentQuestionBookmark() {
    const attemptId = studentExamAttempt.value?.id
    const currentQuestion = currentStudentExamQuestion.value
    if (!attemptId || !currentQuestion || isStudentExamSubmitted.value) return
    if (!isStudentOpenNavigationMode.value) return
    if (studentExamBookmarking.value) return

    studentExamBookmarking.value = true
    studentExamError.value = ''

    try {
      const { data } = await services.bookmarkQuestion(
        attemptId,
        currentQuestion.question_id,
        { is_bookmarked: !Boolean(currentQuestion.is_bookmarked) },
      )
      applyStudentAttemptPayload(data, currentQuestion.question_id)
    } catch (error) {
      studentExamError.value = firstApiError(error, 'Unable to update bookmark.')
    } finally {
      studentExamBookmarking.value = false
    }
  }

  async function submitStudentExam() {
    const attemptId = studentExamAttempt.value?.id
    if (!attemptId || isStudentExamSubmitted.value) return

    markCurrentQuestionVisited()
    studentExamSubmitting.value = true
    studentExamError.value = ''

    try {
      const { data } = await services.submitAttempt(attemptId)
      applyStudentAttemptPayload(data)
      roomMessage.value = 'Exam submitted. Result is now available.'
    } catch (error) {
      studentExamError.value = firstApiError(error, 'Unable to submit exam attempt.')
    } finally {
      studentExamSubmitting.value = false
    }
  }

  function openCreateRoomModal() {
    if (!canCreateRooms.value) return
    roomName.value = ''
    showCreateRoomModal.value = true
  }

  function closeCreateRoomModal() {
    showCreateRoomModal.value = false
    roomName.value = ''
  }

  function openJoinRoomModal() {
    joinCode.value = ''
    showJoinRoomModal.value = true
  }

  function closeJoinRoomModal() {
    showJoinRoomModal.value = false
    joinCode.value = ''
  }

  function openEditRoomModal() {
    if (!selectedRoom.value) return
    editRoomName.value = selectedRoom.value.name ?? ''
    showEditRoomModal.value = true
  }

  function closeEditRoomModal() {
    showEditRoomModal.value = false
    editRoomName.value = ''
  }

  function openDeleteRoomModal() {
    if (!selectedRoom.value) return
    showDeleteRoomModal.value = true
  }

  function closeDeleteRoomModal() {
    showDeleteRoomModal.value = false
  }

  function openLeaveRoomModal() {
    if (!selectedRoom.value) return
    showLeaveRoomModal.value = true
  }

  function closeLeaveRoomModal() {
    showLeaveRoomModal.value = false
  }

  async function fetchRoomDetails(roomId) {
    if (!roomId) return

    roomDetailsLoading.value = true
    roomError.value = ''

    try {
      const { data } = await services.getRoom(roomId)
      const room = data.room ?? null

      selectedRoom.value = room
        ? {
            ...room,
            members: room.members ?? [],
            assigned_exams: (room.assigned_exams ?? []).map((exam) => ({
              ...exam,
              schedule_start_at: exam.schedule_start_at ?? exam.scheduled_at ?? null,
              schedule_end_at: exam.schedule_end_at ?? null,
              delivery_mode: normalizeExamDeliveryMode(exam.delivery_mode),
            })),
          }
        : null

      selectedRoomId.value = room?.id ?? null
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to load room details right now.')
      selectedRoom.value = null
      selectedRoomId.value = null
      closeExamSimulationModal()
    } finally {
      roomDetailsLoading.value = false
    }
  }

  async function selectRoom(roomId) {
    closeExamSimulationModal()
    closeRoomLiveBoard()
    await fetchRoomDetails(roomId)
  }

  async function fetchRooms(preferredRoomId = null) {
    roomLoading.value = true
    roomError.value = ''

    try {
      const { data } = await services.getRooms()
      rooms.value = data.rooms ?? []

      if (rooms.value.length === 0) {
        showJoinRoomModal.value = false
        showEditRoomModal.value = false
        showDeleteRoomModal.value = false
        showLeaveRoomModal.value = false
        closeExamSimulationModal()
        selectedRoomId.value = null
        selectedRoom.value = null
        return
      }

      const hasPreferredRoom = preferredRoomId !== null && rooms.value.some((room) => room.id === preferredRoomId)
      const hasCurrentRoom = selectedRoomId.value !== null && rooms.value.some((room) => room.id === selectedRoomId.value)
      const roomIdToLoad = hasPreferredRoom
        ? preferredRoomId
        : (hasCurrentRoom ? selectedRoomId.value : rooms.value[0].id)

      await fetchRoomDetails(roomIdToLoad)
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to load rooms right now.')
    } finally {
      roomLoading.value = false
    }
  }

  async function handleCreateRoom() {
    if (!canCreateRooms.value) return
    if (!roomName.value.trim()) return

    roomLoading.value = true
    roomError.value = ''
    roomMessage.value = ''

    try {
      const { data } = await services.createRoom({ name: roomName.value.trim() })
      roomName.value = ''
      showCreateRoomModal.value = false
      roomMessage.value = 'Room created. Share the room code with students.'
      await fetchRooms(data?.room?.id ?? null)
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to create room.')
    } finally {
      roomLoading.value = false
    }
  }

  async function handleUpdateRoom() {
    if (!selectedRoomId.value || !editRoomName.value.trim()) return

    roomLoading.value = true
    roomError.value = ''
    roomMessage.value = ''

    try {
      await services.updateRoom(selectedRoomId.value, { name: editRoomName.value.trim() })
      closeEditRoomModal()
      roomMessage.value = 'Room updated successfully.'
      await fetchRooms(selectedRoomId.value)
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to update room.')
    } finally {
      roomLoading.value = false
    }
  }

  async function handleDeleteRoom() {
    if (!selectedRoomId.value) return

    roomLoading.value = true
    roomError.value = ''
    roomMessage.value = ''

    try {
      await services.deleteRoom(selectedRoomId.value)
      closeDeleteRoomModal()
      roomMessage.value = 'Room deleted successfully.'
      selectedRoomId.value = null
      selectedRoom.value = null
      await fetchRooms()
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to delete room.')
    } finally {
      roomLoading.value = false
    }
  }

  async function handleJoinRoom() {
    if (!joinCode.value.trim()) return

    roomLoading.value = true
    roomError.value = ''
    roomMessage.value = ''

    try {
      const { data } = await services.joinRoom({ code: joinCode.value.trim().toUpperCase() })
      showJoinRoomModal.value = false
      joinCode.value = ''
      roomMessage.value = 'Joined room successfully.'
      await fetchRooms(data?.room?.id ?? null)
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to join room with that code.')
    } finally {
      roomLoading.value = false
    }
  }

  async function handleLeaveRoom() {
    if (!selectedRoomId.value) return

    roomLoading.value = true
    roomError.value = ''
    roomMessage.value = ''

    try {
      await services.leaveRoom(selectedRoomId.value)
      showLeaveRoomModal.value = false
      closeExamSimulationModal()
      roomMessage.value = 'You have left the room.'
      selectedRoomId.value = null
      selectedRoom.value = null
      await fetchRooms()
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to leave room right now.')
    } finally {
      roomLoading.value = false
    }
  }

  async function handleKickRoomMember(member) {
    if (!selectedRoomId.value) return
    if (!canRemoveRoomMember(member)) return

    const memberId = Number(member?.id ?? 0)
    if (!Number.isFinite(memberId) || memberId < 1) return

    const memberName = String(member?.name ?? 'this student').trim() || 'this student'
    const roomNameLabel = String(selectedRoom.value?.name ?? 'this room').trim() || 'this room'

    if (typeof window !== 'undefined') {
      const confirmed = window.confirm(`Remove ${memberName} from ${roomNameLabel}?`)
      if (!confirmed) return
    }

    roomLoading.value = true
    roomError.value = ''
    roomMessage.value = ''

    try {
      const { data } = await services.removeRoomMember(selectedRoomId.value, memberId)
      roomMessage.value = data?.message ?? `${memberName} has been removed from the room.`
      await fetchRooms(selectedRoomId.value)
    } catch (error) {
      roomError.value = firstApiError(error, 'Unable to remove student from this room right now.')
    } finally {
      roomLoading.value = false
    }
  }

  function resetLiveBoardState() {
    liveBoardRows.value = []
    liveBoardItemSummary.value = []
    liveBoardSummary.value = {
      students_total: 0,
      attempts_started: 0,
      attempts_submitted: 0,
    }
    liveBoardError.value = ''
    liveBoardUpdatedAt.value = null
  }

  function stopLiveBoardAutoRefresh() {
    if (!liveBoardRefreshInterval) return
    clearInterval(liveBoardRefreshInterval)
    liveBoardRefreshInterval = null
  }

  function startLiveBoardAutoRefresh() {
    stopLiveBoardAutoRefresh()
    liveBoardRefreshInterval = setInterval(() => {
      if (!roomLiveBoardActive.value || !liveBoardExam.value?.id || !liveBoardRoomId.value) return
      loadLiveBoard(true)
    }, 5000)
  }

  async function openRoomLiveBoard(exam) {
    if (!canViewExamResults.value) return

    const roomId = Number(selectedRoom.value?.id ?? selectedRoomId.value)
    if (!Number.isFinite(roomId) || roomId < 1) {
      roomError.value = 'Select a room first before opening the live board.'
      return
    }

    liveBoardExam.value = {
      ...exam,
      delivery_mode: normalizeExamDeliveryMode(exam.delivery_mode),
    }
    liveBoardRoomId.value = roomId
    liveBoardOptions.show_names = true
    liveBoardOptions.show_responses = true
    liveBoardOptions.show_results = true
    resetLiveBoardState()
    roomLiveBoardActive.value = true

    if (typeof window !== 'undefined') {
      window.scrollTo({ top: 0, behavior: 'smooth' })
    }

    await loadLiveBoard(false)
    startLiveBoardAutoRefresh()
  }

  function closeRoomLiveBoard() {
    roomLiveBoardActive.value = false
    stopLiveBoardAutoRefresh()
    liveBoardExam.value = null
    liveBoardRoomId.value = null
    resetLiveBoardState()
  }

  async function loadLiveBoard(silent = false) {
    if (!canViewExamResults.value) return

    const examId = liveBoardExam.value?.id
    const roomId = Number(liveBoardRoomId.value)
    if (!examId || !Number.isFinite(roomId) || roomId < 1) return

    if (silent) {
      liveBoardRefreshing.value = true
    } else {
      liveBoardLoading.value = true
    }

    if (!silent) {
      liveBoardError.value = ''
    }

    try {
      const { data } = await services.getLiveBoard(examId, roomId)

      if (data?.exam) {
        liveBoardExam.value = {
          ...data.exam,
          delivery_mode: normalizeExamDeliveryMode(data.exam.delivery_mode),
        }
      }

      liveBoardRows.value = data.rows ?? []
      liveBoardItemSummary.value = data.item_summary ?? []
      liveBoardSummary.value = data.summary ?? {
        students_total: 0,
        attempts_started: 0,
        attempts_submitted: 0,
      }
      liveBoardUpdatedAt.value = data.generated_at ?? new Date().toISOString()
    } catch (error) {
      liveBoardError.value = firstApiError(error, 'Unable to load live dashboard.')
    } finally {
      if (silent) {
        liveBoardRefreshing.value = false
      } else {
        liveBoardLoading.value = false
      }
    }
  }

  function liveBoardDisplayName(row, index) {
    if (liveBoardOptions.show_names) return row?.user?.name || 'Unknown Student'
    return `Student ${index + 1}`
  }

  function liveBoardProgressLabel(row) {
    if (!row?.attempt) return 'Not started'

    const answered = Number(row.attempt.answered_count ?? 0)
    const total = Number(row.attempt.total_items ?? 0)
    const status = row.attempt.status === 'submitted' ? 'Submitted' : 'In progress'
    return `${answered}/${total} • ${status}`
  }

  function liveBoardResponseText(item) {
    const raw = String(item?.response ?? '').trim()
    if (!raw) return 'Answered'
    return raw.length > 30 ? `${raw.slice(0, 30)}...` : raw
  }

  function liveBoardCellText(item) {
    if (!item?.answered) return '--'

    if (liveBoardOptions.show_results) {
      if (item.is_correct === true) {
        return liveBoardOptions.show_responses ? `Correct: ${liveBoardResponseText(item)}` : 'Correct'
      }
      if (item.is_correct === false) {
        return liveBoardOptions.show_responses ? `Wrong: ${liveBoardResponseText(item)}` : 'Wrong'
      }
    }

    if (liveBoardOptions.show_responses) return liveBoardResponseText(item)
    return 'Answered'
  }

  function liveBoardCellClass(item) {
    if (!item?.answered) return 'pending'
    if (!liveBoardOptions.show_results) return 'answered'
    if (item.is_correct === true) return 'correct'
    if (item.is_correct === false) return 'incorrect'
    return 'answered'
  }

  function liveBoardItemSummaryText(item) {
    if (liveBoardOptions.show_results) {
      if (!Number.isFinite(Number(item?.correct_percent))) return '--'
      return `${Number(item.correct_percent)}%`
    }

    if (!Number.isFinite(Number(item?.answered_percent))) return '--'
    return `${Number(item.answered_percent)}%`
  }

  watch(
    () => currentStudentExamQuestion.value?.question_id,
    () => {
      syncStudentAnswerDraft()
    },
  )

  watch(
    () => studentExamRemainingSeconds.value,
    async (remainingSeconds) => {
      if (remainingSeconds !== 0) return
      if (!showExamSimulationModal.value || isStudentExamSubmitted.value) return
      if (studentExamLoading.value || studentExamSaving.value || studentExamSubmitting.value) return

      clearStudentExamTimer()
      await refreshStudentExamAttemptStatus(false)
    },
  )

  return {
    isManagementView,
    isAdminRole,
    isStaffRole,
    canCreateRooms,
    canViewExamResults,
    roomName,
    joinCode,
    rooms,
    selectedRoomId,
    selectedRoom,
    showCreateRoomModal,
    showJoinRoomModal,
    showEditRoomModal,
    showDeleteRoomModal,
    showLeaveRoomModal,
    editRoomName,
    roomLoading,
    roomDetailsLoading,
    roomError,
    roomMessage,
    roomLiveBoardActive,
    liveBoardExam,
    liveBoardRoom,
    liveBoardRows,
    liveBoardItemSummary,
    liveBoardSummary,
    liveBoardLoading,
    liveBoardRefreshing,
    liveBoardError,
    liveBoardOptions,
    liveBoardLastUpdatedText,
    showExamSimulationModal,
    showStudentSubmitConfirmModal,
    showStudentExitConfirmModal,
    selectedStudentExam,
    studentExamAttempt,
    studentExamReviewVisible,
    studentExamQuestions,
    studentExamCurrentIndex,
    examAttemptSidebarCollapsed,
    studentExamLoading,
    studentExamSaving,
    studentExamBookmarking,
    studentExamSubmitting,
    studentExamError,
    studentExamRemainingSeconds,
    studentAnswerDraft,
    roomCollectionLabel,
    currentStudentExamQuestion,
    currentQuestionStem,
    isStudentExamSubmitted,
    isStudentExamResultSummaryVisible,
    isStudentOpenNavigationMode,
    isCurrentQuestionInputLocked,
    studentExamUnansweredCount,
    studentExamResultSummary,
    displayMemberRole,
    canRemoveRoomMember,
    canStudentOpenExam,
    isStudentExamInProgress,
    isStudentExamCompleted,
    isStudentExamRetakeLimitReached,
    studentExamActionLabel,
    studentExamAvailabilityText,
    examOptionCardClass,
    questionPaletteClass,
    studentQuestionReviewStatus,
    studentQuestionReviewLabel,
    studentQuestionReviewMessage,
    studentQuestionCorrectAnswerText,
    toggleExamAttemptSidebar,
    openExamSimulation,
    openStudentExamReview,
    goToStudentExamQuestionIndex,
    goToStudentExamQuestion,
    handleExamAttemptCloseClick,
    showStudentExamResultSummary,
    closeStudentExamExitConfirm,
    confirmStudentExamExit,
    openStudentExamSubmitConfirm,
    closeStudentExamSubmitConfirm,
    confirmStudentExamSubmit,
    handleStudentOptionSelect,
    handleStudentOpenEndedBlur,
    toggleCurrentQuestionBookmark,
    openCreateRoomModal,
    closeCreateRoomModal,
    openJoinRoomModal,
    closeJoinRoomModal,
    openEditRoomModal,
    closeEditRoomModal,
    openDeleteRoomModal,
    closeDeleteRoomModal,
    openLeaveRoomModal,
    closeLeaveRoomModal,
    selectRoom,
    handleCreateRoom,
    handleUpdateRoom,
    handleDeleteRoom,
    handleJoinRoom,
    handleLeaveRoom,
    handleKickRoomMember,
    openRoomLiveBoard,
    closeRoomLiveBoard,
    loadLiveBoard,
    liveBoardDisplayName,
    liveBoardProgressLabel,
    liveBoardCellText,
    liveBoardCellClass,
    liveBoardItemSummaryText,
  }
}
