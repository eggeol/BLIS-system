import {
  formatDateTime,
  formatExamSchedule,
  parseDateTime,
} from './useDashboardFormatters'

export function examHasQuestionSet(exam) {
  if (Array.isArray(exam?.question_bank_ids) && exam.question_bank_ids.length > 0) {
    return true
  }

  const legacyQuestionBankId = Number(exam?.question_bank_id ?? 0)
  return Number.isFinite(legacyQuestionBankId) && legacyQuestionBankId > 0
}

export function examScheduleStart(exam) {
  return exam?.schedule_start_at ?? exam?.scheduled_at ?? null
}

export function examScheduleEnd(exam) {
  return exam?.schedule_end_at ?? null
}

export function studentMaxAttempts(exam) {
  const resolvedMaxAttempts = Number(exam?.student_max_attempts)

  if (Number.isFinite(resolvedMaxAttempts) && resolvedMaxAttempts > 0) {
    return resolvedMaxAttempts
  }

  return exam?.one_take_only ? 1 : 2
}

export function studentExamAttemptState(exam) {
  return String(exam?.student_attempt_state ?? 'not_started').toLowerCase()
}

export function isStudentExamInProgress(exam) {
  return studentExamAttemptState(exam) === 'in_progress'
}

export function isStudentExamCompleted(exam) {
  return studentExamAttemptState(exam) === 'submitted'
}

export function studentSubmittedAttempts(exam) {
  const submittedAttempts = Number(exam?.student_submitted_attempts)

  if (Number.isFinite(submittedAttempts) && submittedAttempts >= 0) {
    return submittedAttempts
  }

  return isStudentExamCompleted(exam) ? 1 : 0
}

export function studentAttemptsRemaining(exam) {
  return Math.max(0, studentMaxAttempts(exam) - studentSubmittedAttempts(exam))
}

export function studentSubmittedAttemptId(exam) {
  const attemptId = Number(exam?.student_attempt_id ?? 0)
  return Number.isFinite(attemptId) && attemptId > 0 ? attemptId : null
}

export function isStudentExamRetakeLimitReached(exam) {
  return isStudentExamCompleted(exam) && studentAttemptsRemaining(exam) <= 0
}

export function isStudentExamUpcoming(exam) {
  const scheduleStart = parseDateTime(examScheduleStart(exam))
  return Boolean(scheduleStart && scheduleStart.getTime() > Date.now())
}

export function isStudentExamEnded(exam) {
  const scheduleEnd = parseDateTime(examScheduleEnd(exam))
  return Boolean(scheduleEnd && scheduleEnd.getTime() < Date.now())
}

export function canStudentTakeExam(exam) {
  if (!examHasQuestionSet(exam)) return false
  if (isStudentExamUpcoming(exam)) return false
  if (isStudentExamEnded(exam)) return false
  if (isStudentExamRetakeLimitReached(exam)) return false

  return true
}

export function canStudentOpenExam(exam) {
  if (isStudentExamRetakeLimitReached(exam) && studentSubmittedAttemptId(exam)) {
    return true
  }

  return canStudentTakeExam(exam)
}

export function studentExamActionLabel(exam) {
  if (isStudentExamInProgress(exam)) return 'Resume Exam'
  if (isStudentExamCompleted(exam)) {
    return isStudentExamRetakeLimitReached(exam) ? 'Review Result' : 'Retake Exam'
  }

  return 'Take Exam'
}

export function studentExamAvailabilityText(exam) {
  if (!examHasQuestionSet(exam)) return 'Not available (no question set linked)'

  if (isStudentExamUpcoming(exam)) {
    return `Available on ${formatExamSchedule(examScheduleStart(exam), examScheduleEnd(exam))}`
  }

  if (isStudentExamEnded(exam)) {
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

  if (examScheduleEnd(exam)) {
    return `Available until ${formatDateTime(examScheduleEnd(exam))}`
  }

  return 'Available now'
}
