import { computed, onMounted, reactive, ref } from 'vue'
import { reviewBotApi } from '@/api'

function firstApiError(error, fallbackMessage) {
  return error?.response?.data?.message ?? fallbackMessage
}

function normalizeSubjectRecord(subject) {
  if (!subject || typeof subject !== 'object') return null

  const label = String(subject.subject ?? '').trim() || 'General'

  return {
    subject: label,
    question_count: Number(subject.question_count ?? 0),
    bank_count: Number(subject.bank_count ?? 0),
  }
}

function normalizeGeneratedQuestion(question, index) {
  if (!question || typeof question !== 'object') return null

  const prompt = String(question.prompt ?? '').trim()
  const type = String(question.type ?? 'multiple_choice').trim() || 'multiple_choice'
  const subject = String(question.subject ?? '').trim() || 'General'
  const correctOptionId = String(question.correct_option_id ?? '').trim()
  const explanation = String(question.explanation ?? '').trim()
  const rawOptions = Array.isArray(question.options) ? question.options : []

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
  const subjectOptions = ref([])
  const reviewLoading = ref(false)
  const quizLoading = ref(false)
  const reviewError = ref('')
  const reviewMessage = ref('')
  const generatorMode = ref('library_remix')
  const quizSubmitted = ref(false)
  const generatedQuestions = ref([])
  const answers = reactive({})

  const form = reactive({
    questionCount: 10,
    subjects: [],
  })

  const questionCountOptions = [5, 10, 15, 20]

  const hasQuiz = computed(() => generatedQuestions.value.length > 0)
  const canGenerate = computed(() => form.subjects.length > 0 && Number(form.questionCount) >= 3)
  const answeredCount = computed(() => (
    generatedQuestions.value.filter((question) => Boolean(answers[question.id])).length
  ))
  const allAnswered = computed(() => (
    hasQuiz.value && answeredCount.value === generatedQuestions.value.length
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
  const generatorLabel = computed(() => (
    generatorMode.value === 'ai' ? 'AI Remix' : 'Library Remix'
  ))

  function clearAnswers() {
    Object.keys(answers).forEach((key) => {
      delete answers[key]
    })
  }

  function resetQuizState() {
    generatedQuestions.value = []
    quizSubmitted.value = false
    generatorMode.value = 'library_remix'
    clearAnswers()
  }

  function setQuestionCount(count) {
    form.questionCount = Number(count)
  }

  function answerQuestion(questionId, optionId) {
    if (quizSubmitted.value) return
    answers[questionId] = optionId
  }

  function submitQuiz() {
    reviewError.value = ''
    reviewMessage.value = ''

    if (!hasQuiz.value) {
      reviewError.value = 'Generate a review set first.'
      return
    }

    if (!allAnswered.value) {
      reviewError.value = 'Answer every question before submitting your review set.'
      return
    }

    quizSubmitted.value = true
    reviewMessage.value = `You scored ${correctCount.value} out of ${generatedQuestions.value.length}.`
  }

  async function loadSubjectOptions() {
    reviewLoading.value = true
    reviewError.value = ''

    try {
      const { data } = await reviewBotApi.listSubjects()
      subjectOptions.value = Array.isArray(data?.subjects)
        ? data.subjects.map(normalizeSubjectRecord).filter(Boolean)
        : []
    } catch (error) {
      reviewError.value = firstApiError(error, 'Unable to load review subjects.')
    } finally {
      reviewLoading.value = false
    }
  }

  async function generateQuiz() {
    if (!canGenerate.value) {
      reviewError.value = 'Choose at least one subject and a valid question count first.'
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
      const questions = Array.isArray(data?.questions)
        ? data.questions.map(normalizeGeneratedQuestion).filter(Boolean)
        : []

      generatedQuestions.value = questions
      generatorMode.value = String(data?.generator ?? 'library_remix')
      reviewMessage.value = String(data?.message ?? 'Review set generated successfully.')

      if (questions.length === 0) {
        reviewError.value = 'No review questions were generated for this request.'
      }
    } catch (error) {
      resetQuizState()
      reviewError.value = firstApiError(error, 'Unable to generate a review set right now.')
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
    quizSubmitted,
    generatedQuestions,
    answers,
    form,
    questionCountOptions,
    hasQuiz,
    canGenerate,
    answeredCount,
    allAnswered,
    correctCount,
    scorePercent,
    setQuestionCount,
    answerQuestion,
    submitQuiz,
    generateQuiz,
    loadSubjectOptions,
    resetQuizState,
  }
}
