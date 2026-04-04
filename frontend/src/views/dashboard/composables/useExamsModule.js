import { computed, onMounted, reactive, ref } from 'vue'
import { useDashboardDataServices } from './useDashboardDataServices'
import { normalizeExamDeliveryMode } from './useDashboardFormatters'

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function toDateTimeLocalValue(value) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''

  const timezoneOffset = date.getTimezoneOffset()
  const localDate = new Date(date.getTime() - (timezoneOffset * 60_000))
  return localDate.toISOString().slice(0, 16)
}

function toExamSchedulePayload(value) {
  if (!value) return null

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return null

  return date.toISOString()
}

function currentDateTimeLocalValue() {
  const now = new Date()
  now.setSeconds(0, 0)
  return toDateTimeLocalValue(now.toISOString())
}

function currentDayStartLocalValue() {
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return toDateTimeLocalValue(today.toISOString())
}

function categoryLabel(value) {
  const normalized = String(value ?? '').trim()
  return normalized || 'General'
}

function compareCategoryThenName(left, right) {
  const leftCategory = categoryLabel(left?.subject)
  const rightCategory = categoryLabel(right?.subject)

  if (leftCategory !== rightCategory) {
    return leftCategory.localeCompare(rightCategory)
  }

  const leftTitle = String(left?.title ?? '').trim()
  const rightTitle = String(right?.title ?? '').trim()

  return leftTitle.localeCompare(rightTitle)
}

function normalizeQuestionBankRecord(bank) {
  if (!bank?.id) return null

  return {
    id: Number(bank.id),
    title: String(bank.title ?? '').trim(),
    subject: bank.subject ? String(bank.subject).trim() : null,
    total_items: Number(bank.total_items ?? bank.questions_count ?? 0),
  }
}

function summarizeQuestionBankSubject(questionBanks) {
  const subjects = [...new Set(
    questionBanks
      .map((bank) => String(bank?.subject ?? '').trim())
      .filter(Boolean),
  )]

  if (subjects.length === 0) return null
  if (subjects.length === 1) return subjects[0]
  return 'Multiple Subjects'
}

function questionBanksForExam(exam) {
  const linkedBanks = Array.isArray(exam?.question_banks)
    ? exam.question_banks.map(normalizeQuestionBankRecord).filter(Boolean)
    : []

  if (linkedBanks.length > 0) {
    return linkedBanks
  }

  const legacyBank = normalizeQuestionBankRecord(exam?.question_bank)
  return legacyBank ? [legacyBank] : []
}

function questionBankSummaryTextFromList(questionBanks) {
  if (questionBanks.length === 0) return 'Not linked yet'
  if (questionBanks.length === 1) return questionBanks[0].title
  if (questionBanks.length === 2) return `${questionBanks[0].title}, ${questionBanks[1].title}`
  return `${questionBanks[0].title}, ${questionBanks[1].title}, +${questionBanks.length - 2} more`
}

function uniquePositiveIntegers(values) {
  return [...new Set(
    values
      .map((value) => Number(value))
      .filter((value) => Number.isFinite(value) && value > 0),
  )]
}

export function useExamsModule() {
  const services = useDashboardDataServices()

  const exams = ref([])
  const manageableRooms = ref([])
  const examQuestionBanks = ref([])
  const examLoading = ref(false)
  const examSaving = ref(false)
  const examError = ref('')
  const examMessage = ref('')
  const showExamModal = ref(false)
  const showDeleteExamModal = ref(false)
  const selectedExam = ref(null)
  const examForm = reactive({
    id: null,
    title: '',
    description: '',
    question_bank_ids: [],
    total_items: 60,
    duration_minutes: 90,
    schedule_start_at: '',
    schedule_end_at: '',
    one_take_only: false,
    shuffle_questions: false,
    results_visibility_mode: 'hidden',
    room_ids: [],
  })

  const groupedExamQuestionBanks = computed(() => {
    const groups = new Map()

    examQuestionBanks.value.forEach((bank) => {
      const label = categoryLabel(bank.subject)
      if (!groups.has(label)) {
        groups.set(label, [])
      }

      groups.get(label).push(bank)
    })

    return Array.from(groups.entries())
      .sort(([leftLabel], [rightLabel]) => leftLabel.localeCompare(rightLabel))
      .map(([label, banks]) => ({
        label,
        banks: [...banks].sort(compareCategoryThenName),
      }))
  })

  const selectedQuestionBanks = computed(() => {
    const selectedIds = uniquePositiveIntegers(examForm.question_bank_ids)
    const bankLookup = new Map(examQuestionBanks.value.map((bank) => [bank.id, bank]))

    return selectedIds
      .map((id) => bankLookup.get(id) ?? null)
      .filter(Boolean)
  })

  const selectedQuestionBankSubject = computed(() => (
    summarizeQuestionBankSubject(selectedQuestionBanks.value)
  ))

  const selectedQuestionBankTotalItems = computed(() => (
    selectedQuestionBanks.value.reduce((total, bank) => total + Number(bank.total_items ?? 0), 0)
  ))

  const createExamScheduleMin = computed(() => (
    examForm.id ? '' : currentDayStartLocalValue()
  ))

  const createExamScheduleEndMin = computed(() => {
    if (examForm.id) return ''

    const currentDayMin = createExamScheduleMin.value

    if (!examForm.schedule_start_at) {
      return currentDayMin
    }

    return examForm.schedule_start_at > currentDayMin
      ? examForm.schedule_start_at
      : currentDayMin
  })

  function resetExamForm() {
    examForm.id = null
    examForm.title = ''
    examForm.description = ''
    examForm.question_bank_ids = []
    examForm.total_items = 60
    examForm.duration_minutes = 90
    examForm.schedule_start_at = currentDateTimeLocalValue()
    examForm.schedule_end_at = ''
    examForm.one_take_only = false
    examForm.shuffle_questions = false
    examForm.results_visibility_mode = 'hidden'
    examForm.room_ids = []
  }

  function openCreateExamModal() {
    resetExamForm()
    examError.value = ''
    examMessage.value = ''
    showExamModal.value = true
  }

  function openEditExamModal(exam) {
    const linkedBanks = questionBanksForExam(exam)

    examForm.id = exam.id
    examForm.title = exam.title ?? ''
    examForm.description = exam.description ?? ''
    examForm.question_bank_ids = linkedBanks.map((bank) => bank.id)
    examForm.total_items = Number(exam.total_items ?? 1)
    examForm.duration_minutes = Number(exam.duration_minutes ?? 1)
    examForm.schedule_start_at = toDateTimeLocalValue(exam.schedule_start_at ?? exam.scheduled_at)
    examForm.schedule_end_at = toDateTimeLocalValue(exam.schedule_end_at)
    examForm.one_take_only = Boolean(exam.one_take_only)
    examForm.shuffle_questions = Boolean(exam.shuffle_questions)
    examForm.results_visibility_mode = exam.results_visibility_mode || 'hidden'
    examForm.room_ids = (exam.rooms ?? []).map((room) => room.id)
    examError.value = ''
    examMessage.value = ''
    showExamModal.value = true
  }

  function closeExamModal() {
    showExamModal.value = false
    resetExamForm()
  }

  function openDeleteExamModal(exam) {
    selectedExam.value = exam
    showDeleteExamModal.value = true
  }

  function closeDeleteExamModal() {
    showDeleteExamModal.value = false
    selectedExam.value = null
  }

  async function fetchManageableRooms() {
    try {
      const { data } = await services.getRooms()
      manageableRooms.value = (data.rooms ?? []).map((room) => ({
        id: room.id,
        name: room.name,
        code: room.code,
      }))
    } catch (error) {
      manageableRooms.value = []
    }
  }

  async function fetchExamQuestionBanks() {
    try {
      const { data } = await services.getLibraryBanks()
      examQuestionBanks.value = (data.banks ?? [])
        .map(normalizeQuestionBankRecord)
        .filter(Boolean)
        .sort(compareCategoryThenName)
    } catch (error) {
      examQuestionBanks.value = []
    }
  }

  async function loadExams() {
    examLoading.value = true
    examError.value = ''
    examMessage.value = ''

    try {
      const [{ data: examData }] = await Promise.all([
        services.getExams(),
        fetchManageableRooms(),
        fetchExamQuestionBanks(),
      ])

      exams.value = (examData.exams ?? [])
        .map((exam) => {
          const linkedBanks = questionBanksForExam(exam)

          return {
            ...exam,
            question_banks: linkedBanks,
            question_bank_ids: linkedBanks.map((bank) => bank.id),
            archived_rooms: Array.isArray(exam.archived_rooms) ? exam.archived_rooms : [],
            subject: exam.subject ?? summarizeQuestionBankSubject(linkedBanks),
            schedule_start_at: exam.schedule_start_at ?? exam.scheduled_at ?? null,
            schedule_end_at: exam.schedule_end_at ?? null,
            delivery_mode: normalizeExamDeliveryMode(exam.delivery_mode),
          }
        })
        .sort((left, right) => compareCategoryThenName(
          { subject: summarizeQuestionBankSubject(left.question_banks) ?? left.subject, title: left.title },
          { subject: summarizeQuestionBankSubject(right.question_banks) ?? right.subject, title: right.title },
        ))
    } catch (error) {
      examError.value = firstApiError(error, 'Unable to load exams right now.')
    } finally {
      examLoading.value = false
    }
  }

  async function handleSaveExam() {
    if (!examForm.title.trim()) return

    examSaving.value = true
    examError.value = ''
    examMessage.value = ''

    const scheduleStartAt = toExamSchedulePayload(examForm.schedule_start_at)
    const scheduleEndAt = toExamSchedulePayload(examForm.schedule_end_at)

    if ((examForm.schedule_start_at && !scheduleStartAt) || (examForm.schedule_end_at && !scheduleEndAt)) {
      examError.value = 'Please provide valid start/end schedule values.'
      examSaving.value = false
      return
    }

    if (!examForm.id) {
      const currentDayStart = new Date()
      currentDayStart.setHours(0, 0, 0, 0)
      const currentDayStartMs = currentDayStart.getTime()

      if (scheduleStartAt && new Date(scheduleStartAt).getTime() < currentDayStartMs) {
        examError.value = 'Schedule start cannot be before the current day.'
        examSaving.value = false
        return
      }

      if (scheduleEndAt && new Date(scheduleEndAt).getTime() < currentDayStartMs) {
        examError.value = 'Schedule end cannot be before the current day.'
        examSaving.value = false
        return
      }
    }

    if (scheduleStartAt && scheduleEndAt && new Date(scheduleEndAt).getTime() < new Date(scheduleStartAt).getTime()) {
      examError.value = 'Schedule end must be after or equal to schedule start.'
      examSaving.value = false
      return
    }

    const selectedBankIds = uniquePositiveIntegers(examForm.question_bank_ids)
    const availableQuestionCount = selectedQuestionBanks.value.reduce(
      (total, bank) => total + Number(bank.total_items ?? 0),
      0,
    )

    if (selectedQuestionBanks.value.length > 0 && Number(examForm.total_items) > availableQuestionCount) {
      examError.value = 'Selected question sets do not have enough questions for the item count.'
      examSaving.value = false
      return
    }

    const payload = {
      title: examForm.title.trim(),
      description: examForm.description.trim() || null,
      question_bank_id: selectedBankIds[0] ?? null,
      question_bank_ids: selectedBankIds,
      total_items: Number(examForm.total_items),
      duration_minutes: Number(examForm.duration_minutes),
      scheduled_at: scheduleStartAt,
      schedule_start_at: scheduleStartAt,
      schedule_end_at: scheduleEndAt,
      one_take_only: Boolean(examForm.one_take_only),
      shuffle_questions: Boolean(examForm.shuffle_questions),
      results_visibility_mode: examForm.results_visibility_mode,
      room_ids: [...examForm.room_ids],
    }

    try {
      if (examForm.id) {
        await services.updateExam(examForm.id, payload)
        examMessage.value = 'Exam updated successfully.'
      } else {
        await services.createExam(payload)
        examMessage.value = 'Exam created successfully.'
      }

      closeExamModal()
      await loadExams()
    } catch (error) {
      examError.value = firstApiError(error, 'Unable to save exam.')
    } finally {
      examSaving.value = false
    }
  }

  async function handleDeleteExam() {
    if (!selectedExam.value?.id) return

    examSaving.value = true
    examError.value = ''
    examMessage.value = ''

    try {
      await services.deleteExam(selectedExam.value.id)
      closeDeleteExamModal()
      examMessage.value = 'Exam deleted successfully.'
      await loadExams()
    } catch (error) {
      examError.value = firstApiError(error, 'Unable to delete exam.')
    } finally {
      examSaving.value = false
    }
  }

  function examQuestionBankCount(exam) {
    return questionBanksForExam(exam).length
  }

  function examQuestionBankSubject(exam) {
    return summarizeQuestionBankSubject(questionBanksForExam(exam)) ?? exam?.subject ?? 'General'
  }

  function examQuestionBankSummaryText(exam) {
    return questionBankSummaryTextFromList(questionBanksForExam(exam))
  }

  onMounted(async () => {
    await loadExams()
  })

  return {
    exams,
    manageableRooms,
    examQuestionBanks,
    groupedExamQuestionBanks,
    selectedQuestionBanks,
    selectedQuestionBankSubject,
    selectedQuestionBankTotalItems,
    createExamScheduleMin,
    createExamScheduleEndMin,
    examLoading,
    examSaving,
    examError,
    examMessage,
    showExamModal,
    showDeleteExamModal,
    selectedExam,
    examForm,
    examQuestionBankCount,
    examQuestionBankSubject,
    examQuestionBankSummaryText,
    loadExams,
    openCreateExamModal,
    openEditExamModal,
    closeExamModal,
    openDeleteExamModal,
    closeDeleteExamModal,
    handleSaveExam,
    handleDeleteExam,
  }
}
