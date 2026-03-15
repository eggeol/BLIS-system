import { computed, reactive, ref } from 'vue'
import { questionsApi } from '@/api/questions.api'
import { LIBRARY_SUBJECT_CATEGORIES } from '@/constants/librarySubjects'

function asBooleanValue(source) {
  if (typeof source === 'boolean') return source
  if (source && typeof source === 'object' && 'value' in source) {
    return Boolean(source.value)
  }

  return false
}

export function useLibraryManager({
  canManageLibraries,
  parseApiError,
  onBanksChanged,
} = {}) {
  const showLibraryQuestionModal = ref(false)
  const showDeleteLibraryBankModal = ref(false)

  const libraryLoading = ref(false)
  const librarySaving = ref(false)
  const libraryDeleting = ref(false)
  const libraryError = ref('')
  const libraryMessage = ref('')

  const libraryParsing = ref(false)
  const libraryParseError = ref('')
  const libraryDocxName = ref('')
  const libraryFileInputKey = ref(0)

  const libraryQuestionBanks = ref([])
  const selectedLibraryBank = ref(null)
  const libraryPreviewWarnings = ref([])
  const digitalizedQuestions = ref([])

  const groupedLibraryPreviewWarnings = computed(() => {
    if (libraryPreviewWarnings.value.length === 0) return []

    const warnings = libraryPreviewWarnings.value
    const grouped = []

    const parseWarning = (text) => {
      const match = text.match(/^Question (\d+) (has no detected correct answer\.|has fewer than 2 options\.|does not match any option label\.)(.*?)$/)
      if (match) {
        return { isGroupable: true, number: parseInt(match[1], 10), suffix: match[2] + match[3] }
      }
      return { isGroupable: false, text }
    }

    let currentGroup = null

    for (const warning of warnings) {
      const parsed = parseWarning(warning)

      if (!parsed.isGroupable) {
        if (currentGroup) {
          grouped.push(formatGroup(currentGroup, digitalizedQuestions.value.length))
          currentGroup = null
        }
        grouped.push(warning)
        continue
      }

      if (!currentGroup) {
        currentGroup = { numbers: [parsed.number], suffix: parsed.suffix }
      } else if (currentGroup.suffix === parsed.suffix) {
        currentGroup.numbers.push(parsed.number)
      } else {
        grouped.push(formatGroup(currentGroup, digitalizedQuestions.value.length))
        currentGroup = { numbers: [parsed.number], suffix: parsed.suffix }
      }
    }

    if (currentGroup) {
      grouped.push(formatGroup(currentGroup, digitalizedQuestions.value.length))
    }

    return grouped
  })

  function formatGroup(group, totalQuestionsLength) {
    if (group.numbers.length === 1) {
      return `Question ${group.numbers[0]} ${group.suffix}`
    }

    const nums = group.numbers.sort((a, b) => a - b)
    const totalAffected = nums.length

    // If 0 valid items at all
    if (totalQuestionsLength > 0 && totalAffected === totalQuestionsLength) {
      return `All ${totalQuestionsLength} questions ${group.suffix.replace(/^has /, 'have ')}`
    }

    // Dynamic inversion threshold: only flip the message if the number of VALID items
    // is ≤ 20% of the total (minimum safety floor of 3).
    // This ensures the inverted form only appears when it produces a noticeably shorter message.
    // e.g.  20 items → threshold = 4   (≤4 valid → invert)
    //       50 items → threshold = 10  (≤10 valid → invert)
    //      100 items → threshold = 20  (≤20 valid → invert)
    const invertThreshold = Math.max(3, Math.round(totalQuestionsLength * 0.2))

    if (totalQuestionsLength > 0 && totalAffected > (totalQuestionsLength / 2)) {
      const allItemNumbers = Array.from({ length: totalQuestionsLength }, (_, i) => i + 1)
      const validItems = allItemNumbers.filter(n => !nums.includes(n))

      if (validItems.length <= invertThreshold) {
        let inverseSubject = 'answers'
        if (group.suffix.includes('options')) inverseSubject = 'options'

        return `Only Question${validItems.length > 1 ? 's' : ''} ${validItems.join(', ')} have valid ${inverseSubject}. (All others ${group.suffix.replace(/^has /, 'have ')})`
      }
    }

    const ranges = buildRanges(nums)
    return `Question${ranges.length > 1 || nums.length > 1 ? 's' : ''} ${ranges.join(', ')} ${group.suffix.replace(/^has /, 'have ')}`
  }

  function buildRanges(nums) {
    if (nums.length === 0) return []
    const ranges = []
    let rangeStart = nums[0]
    let rangeEnd = nums[0]

    for (let i = 1; i < nums.length; i++) {
      if (nums[i] === rangeEnd + 1) {
        rangeEnd = nums[i]
      } else {
        ranges.push(rangeStart === rangeEnd ? `${rangeStart}` : `${rangeStart}-${rangeEnd}`)
        rangeStart = nums[i]
        rangeEnd = nums[i]
      }
    }
    ranges.push(rangeStart === rangeEnd ? `${rangeStart}` : `${rangeStart}-${rangeEnd}`)
    return ranges
  }

  const libraryForm = reactive({
    questionName: '',
    subjectCategory: '',
    file: null,
  })

  const librarySubjectCategories = LIBRARY_SUBJECT_CATEGORIES

  const canSaveLibraryQuestionBank = computed(() => (
    !librarySaving.value
    && !libraryParsing.value
    && libraryForm.questionName.trim().length > 0
    && libraryForm.subjectCategory.trim().length > 0
    && digitalizedQuestions.value.length > 0
  ))

  function resolveApiError(error, fallbackMessage) {
    if (typeof parseApiError === 'function') {
      return parseApiError(error, fallbackMessage)
    }

    return fallbackMessage
  }

  async function notifyBanksChanged() {
    if (typeof onBanksChanged !== 'function') return
    await onBanksChanged()
  }

  function resetLibraryQuestionState() {
    libraryForm.questionName = ''
    libraryForm.subjectCategory = ''
    libraryForm.file = null
    libraryDocxName.value = ''
    libraryParseError.value = ''
    libraryParsing.value = false
    libraryPreviewWarnings.value = []
    digitalizedQuestions.value = []
    libraryFileInputKey.value += 1
  }

  function openLibraryQuestionModal() {
    resetLibraryQuestionState()
    showLibraryQuestionModal.value = true
  }

  function closeLibraryQuestionModal() {
    showLibraryQuestionModal.value = false
    resetLibraryQuestionState()
  }

  function openDeleteLibraryBankModal(bank) {
    if (!bank?.id) return

    selectedLibraryBank.value = bank
    showDeleteLibraryBankModal.value = true
  }

  function closeDeleteLibraryBankModal() {
    showDeleteLibraryBankModal.value = false
    selectedLibraryBank.value = null
  }

  async function handleLibraryDocxChange(event) {
    const file = event.target?.files?.[0] ?? null

    libraryParseError.value = ''
    libraryPreviewWarnings.value = []
    digitalizedQuestions.value = []
    libraryForm.file = null
    libraryDocxName.value = ''

    if (!file) return

    const normalizedFileName = file.name.toLowerCase()
    const isLegacyDoc = normalizedFileName.endsWith('.doc') && !normalizedFileName.endsWith('.docx')
    const isDocx = normalizedFileName.endsWith('.docx')
    if (!isDocx) {
      libraryParseError.value = isLegacyDoc
        ? 'Legacy .doc files are not supported. Open the file in Word or Google Docs and save it as .docx, then try again.'
        : 'Please upload a valid .docx file.'
      libraryFileInputKey.value += 1
      return
    }

    libraryForm.file = file
    libraryDocxName.value = file.name
    libraryParsing.value = true

    try {
      const formData = new FormData()
      formData.append('file', file)

      const { data } = await questionsApi.importPreview(formData)

      const preview = data.preview ?? {}
      const previewQuestions = preview.questions ?? []

      if (previewQuestions.length === 0) {
        libraryParseError.value = 'No valid question pattern was detected in this DOCX file.'
        return
      }

      digitalizedQuestions.value = previewQuestions.map((question, index) => {
        const options = Array.isArray(question.options) ? question.options : []

        return {
          id: Number(question.item_number ?? (index + 1)),
          item_number: Number(question.item_number ?? (index + 1)),
          text: String(question.text ?? ''),
          question_type: String(question.question_type ?? (options.length > 0 ? 'multiple_choice' : 'open_ended')),
          options: options.map((option, optionIndex) => ({
            label: String(option.label ?? String.fromCharCode(65 + optionIndex)),
            text: String(option.text ?? ''),
            is_correct: Boolean(option.is_correct),
          })),
          answer_label: question.answer_label ? String(question.answer_label) : '',
          answer_text: question.answer_text ? String(question.answer_text) : '',
        }
      })

      libraryPreviewWarnings.value = Array.isArray(preview.warnings)
        ? preview.warnings.map((warning) => String(warning))
        : []
    } catch (error) {
      libraryParseError.value = resolveApiError(error, 'Unable to parse DOCX file. Please check file formatting and try again.')
    } finally {
      libraryParsing.value = false
    }
  }

  async function loadLibraryBanks() {
    if (!asBooleanValue(canManageLibraries)) return

    libraryLoading.value = true
    libraryError.value = ''

    try {
      const { data } = await questionsApi.listBanks()
      libraryQuestionBanks.value = data.banks ?? []
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to load question banks right now.')
    } finally {
      libraryLoading.value = false
    }
  }

  async function handleSaveLibraryQuestionBank() {
    if (!canSaveLibraryQuestionBank.value) return

    librarySaving.value = true
    libraryParseError.value = ''
    libraryError.value = ''
    libraryMessage.value = ''

    const payload = {
      title: libraryForm.questionName.trim(),
      subject: libraryForm.subjectCategory.trim(),
      source_filename: libraryDocxName.value || null,
      questions: digitalizedQuestions.value.map((question, questionIndex) => ({
        item_number: Number(question.item_number ?? (questionIndex + 1)),
        text: String(question.text ?? '').trim(),
        question_type: String(question.question_type ?? (question.options?.length ? 'multiple_choice' : 'open_ended')),
        answer_label: question.answer_label ? String(question.answer_label).trim().toUpperCase() : null,
        answer_text: question.answer_text ? String(question.answer_text).trim() : null,
        options: (question.options ?? []).map((option, optionIndex) => ({
          label: String(option.label ?? String.fromCharCode(65 + optionIndex)).trim().toUpperCase(),
          text: String(option.text ?? '').trim(),
          is_correct: Boolean(option.is_correct),
        })),
      })),
    }

    try {
      await questionsApi.saveBank(payload)
      closeLibraryQuestionModal()
      libraryMessage.value = 'Question bank saved successfully.'
      await Promise.all([
        loadLibraryBanks(),
        notifyBanksChanged(),
      ])
    } catch (error) {
      libraryParseError.value = resolveApiError(error, 'Unable to save question bank.')
    } finally {
      librarySaving.value = false
    }
  }

  async function handleDeleteLibraryBank() {
    if (!selectedLibraryBank.value?.id || libraryDeleting.value) return

    libraryDeleting.value = true
    libraryError.value = ''
    libraryMessage.value = ''

    try {
      await questionsApi.deleteBank(selectedLibraryBank.value.id)
      closeDeleteLibraryBankModal()
      libraryMessage.value = 'Question bank deleted successfully.'
      await Promise.all([
        loadLibraryBanks(),
        notifyBanksChanged(),
      ])
    } catch (error) {
      libraryError.value = resolveApiError(error, 'Unable to delete question bank.')
    } finally {
      libraryDeleting.value = false
    }
  }

  return {
    showLibraryQuestionModal,
    showDeleteLibraryBankModal,
    libraryLoading,
    librarySaving,
    libraryDeleting,
    libraryError,
    libraryMessage,
    libraryParsing,
    libraryParseError,
    libraryDocxName,
    libraryFileInputKey,
    libraryQuestionBanks,
    selectedLibraryBank,
    libraryPreviewWarnings,
    groupedLibraryPreviewWarnings,
    digitalizedQuestions,
    libraryForm,
    librarySubjectCategories,
    canSaveLibraryQuestionBank,
    openLibraryQuestionModal,
    closeLibraryQuestionModal,
    openDeleteLibraryBankModal,
    closeDeleteLibraryBankModal,
    handleLibraryDocxChange,
    loadLibraryBanks,
    handleSaveLibraryQuestionBank,
    handleDeleteLibraryBank,
  }
}
