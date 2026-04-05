import { computed, onMounted, reactive, ref } from 'vue'
import { reviewBotApi } from '@/api'
import { LIBRARY_SUBJECT_CATEGORIES } from '@/constants/librarySubjects'

function firstApiError(error, fallbackMessage) {
  return error?.response?.data?.message ?? fallbackMessage
}

function asList(value) {
  return Array.isArray(value) ? value : []
}

function subjectSortIndex(label) {
  const index = LIBRARY_SUBJECT_CATEGORIES.indexOf(label)
  return index === -1 ? Number.MAX_SAFE_INTEGER : index
}

function normalizeSubjectRecord(subject) {
  if (!subject || typeof subject !== 'object') return null

  const label = String(subject.subject ?? '').trim()
  if (!label) return null

  const focusAreas = asList(subject.focus_areas)
    .map((item) => String(item ?? '').trim())
    .filter(Boolean)
    .slice(0, 3)

  return {
    subject: label,
    description: String(subject.description ?? '').trim() || 'Practice questions for this BLIS core subject.',
    focus_areas: focusAreas,
    topic_count: Number(subject.topic_count ?? 0),
    bot_ready: Boolean(subject.bot_ready ?? true),
  }
}

function normalizeGeneratedQuestion(question, index) {
  if (!question || typeof question !== 'object') return null

  const prompt = String(question.prompt ?? '').trim()
  const type = String(question.type ?? 'multiple_choice').trim() || 'multiple_choice'
  const subject = String(question.subject ?? '').trim() || 'General'
  const correctOptionId = String(question.correct_option_id ?? '').trim()
  const explanation = String(question.explanation ?? '').trim()
  const rawOptions = asList(question.options)

  const options = rawOptions
    .map((option, optionIndex) => {
      if (!option || typeof option !== 'object') return null

      const id = String(option.id ?? `opt-${optionIndex + 1}`).trim() || `opt-${optionIndex + 1}`
      const text = String(option.text ?? '').trim()

      if (!text) return null

      return { id, text }
    })
    .filter(Boolean)

  if (!prompt || !correctOptionId || options.length < 2) {
    return null
  }

  return {
    id: String(question.id ?? `question-${index + 1}`),
    prompt,
    type,
    subject,
    options,
    correct_option_id: correctOptionId,
    explanation: explanation || 'Review the correct idea before moving on.',
  }
}

export function useReviewBotModule() {
  const rawSubjectOptions = ref([])
  const reviewLoading = ref(false)
  const quizLoading = ref(false)
  const reviewError = ref('')
  const reviewMessage = ref('')
  const generatorMode = ref('core_blueprint')
  const quizSubmitted = ref(false)
  const generatedQuestions = ref([])
  const answers = reactive({})

  const form = reactive({
    questionCount: 10,
    subjects: [],
  })

  const questionCountOptions = [5, 10, 15, 20, 30]

  const subjectOptions = computed(() => (
    [...rawSubjectOptions.value].sort((left, right) => subjectSortIndex(left.subject) - subjectSortIndex(right.subject))
  ))

  const selectedSubjects = computed(() => (
    subjectOptions.value.filter((subject) => form.subjects.includes(subject.subject))
  ))

  const availableBotTopicCount = computed(() => (
    subjectOptions.value.reduce((total, subject) => total + Number(subject.topic_count ?? 0), 0)
  ))

  const selectedFocusAreas = computed(() => {
    const source = selectedSubjects.value.length > 0 ? selectedSubjects.value : subjectOptions.value.slice(0, 2)

    return [...new Set(
      source.flatMap((subject) => subject.focus_areas ?? []),
    )].slice(0, 6)
  })

  const hasFullCoverage = computed(() => (
    subjectOptions.value.length > 0 && form.subjects.length === subjectOptions.value.length
  ))

  const hasQuiz = computed(() => generatedQuestions.value.length > 0)
  const canGenerate = computed(() => (
    form.subjects.length > 0
    && Number(form.questionCount) >= 3
    && Number(form.questionCount) <= 30
  ))

  const answeredCount = computed(() => (
    generatedQuestions.value.filter((question) => Boolean(answers[question.id])).length
  ))

  const correctCount = computed(() => {
    if (!quizSubmitted.value) return 0

    return generatedQuestions.value.filter((question) => (
      String(answers[question.id] ?? '') === String(question.correct_option_id)
    )).length
  })

  const scorePercent = computed(() => {
    if (!quizSubmitted.value || generatedQuestions.value.length === 0) return 0
    return Math.round((correctCount.value / generatedQuestions.value.length) * 100)
  })

  const generatorLabel = computed(() => {
    if (generatorMode.value === 'core_blueprint') return 'BLIS Core Bot'
    return 'BLIS Review Bot'
  })

  const generatorDescription = computed(() => {
    if (generatorMode.value === 'core_blueprint') {
      return 'The bot built this set from its built-in BLIS core subject reviewer.'
    }

    return 'A BLIS practice set is ready for you.'
  })

  const scoreMessage = computed(() => {
    if (!quizSubmitted.value) return ''
    if (scorePercent.value >= 85) return 'Strong work. You are showing solid recall in this review set.'
    if (scorePercent.value >= 75) return 'Good job. Review the missed explanations to keep improving.'
    return 'Use the explanations below and try another set after reviewing the weak areas.'
  })

  function clearAnswers() {
    Object.keys(answers).forEach((key) => {
      delete answers[key]
    })
  }

  function resetQuizState() {
    generatedQuestions.value = []
    quizSubmitted.value = false
    generatorMode.value = 'core_blueprint'
    clearAnswers()
  }

  function setQuestionCount(count) {
    form.questionCount = Number(count)
  }

  function toggleSubject(subject) {
    if (form.subjects.includes(subject)) {
      form.subjects = form.subjects.filter((item) => item !== subject)
      return
    }

    form.subjects = [...form.subjects, subject]
  }

  function selectAllSubjects() {
    form.subjects = subjectOptions.value.map((subject) => subject.subject)
  }

  function clearSelectedSubjects() {
    form.subjects = []
  }

  function answerQuestion(questionId, optionId) {
    if (quizSubmitted.value) return
    answers[questionId] = optionId
  }

  function submitQuiz() {
    reviewError.value = ''
    reviewMessage.value = ''

    if (!hasQuiz.value) {
      reviewError.value = 'Generate a BLIS review set first.'
      return
    }

    quizSubmitted.value = true
    reviewMessage.value = `Score: ${correctCount.value}/${generatedQuestions.value.length}`
  }

  async function loadSubjectOptions() {
    reviewLoading.value = true
    reviewError.value = ''

    try {
      const { data } = await reviewBotApi.listSubjects()
      rawSubjectOptions.value = asList(data?.subjects)
        .map(normalizeSubjectRecord)
        .filter(Boolean)

      form.subjects = form.subjects.filter((selected) => (
        rawSubjectOptions.value.some((subject) => subject.subject === selected)
      ))
    } catch (error) {
      reviewError.value = firstApiError(error, 'Unable to load BLIS review subjects.')
    } finally {
      reviewLoading.value = false
    }
  }

  async function generateQuiz() {
    if (!canGenerate.value) {
      reviewError.value = 'Choose at least one BLIS core subject and a valid question count first.'
      return
    }

    quizLoading.value = true
    reviewError.value = ''
    reviewMessage.value = ''
    quizSubmitted.value = false
    clearAnswers()

    try {
      const payload = {
        question_count: Number(form.questionCount),
        subjects: [...form.subjects],
      }

      const { data } = await reviewBotApi.generateQuiz(payload)
      const questions = asList(data?.questions)
        .map(normalizeGeneratedQuestion)
        .filter(Boolean)

      generatedQuestions.value = questions
      generatorMode.value = String(data?.generator ?? 'core_blueprint')
      reviewMessage.value = String(data?.message ?? 'Quiz ready.')

      if (questions.length === 0) {
        reviewError.value = 'No review questions were generated for this request.'
      }
    } catch (error) {
      resetQuizState()
      reviewError.value = firstApiError(error, 'Unable to generate a BLIS review set right now.')
    } finally {
      quizLoading.value = false
    }
  }

  onMounted(() => {
    loadSubjectOptions()
  })

  return {
    subjectOptions,
    reviewLoading,
    quizLoading,
    reviewError,
    reviewMessage,
    generatorMode,
    generatorLabel,
    generatorDescription,
    quizSubmitted,
    generatedQuestions,
    answers,
    form,
    questionCountOptions,
    selectedSubjects,
    selectedFocusAreas,
    availableBotTopicCount,
    hasFullCoverage,
    hasQuiz,
    canGenerate,
    answeredCount,
    correctCount,
    scorePercent,
    scoreMessage,
    setQuestionCount,
    toggleSubject,
    selectAllSubjects,
    clearSelectedSubjects,
    answerQuestion,
    submitQuiz,
    generateQuiz,
    loadSubjectOptions,
    resetQuizState,
  }
}
