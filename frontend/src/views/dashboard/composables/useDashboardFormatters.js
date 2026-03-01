function padTimePart(part) {
  return String(part).padStart(2, '0')
}

export function normalizeExamDeliveryMode(mode) {
  const normalized = String(mode ?? '').toLowerCase()

  if (normalized === 'teacher_paced' || normalized === 'live_quiz') return 'teacher_paced'
  if (normalized === 'instant_feedback') return 'instant_feedback'
  return 'open_navigation'
}

export function examDeliveryModeLabel(mode) {
  const normalized = normalizeExamDeliveryMode(mode)
  if (normalized === 'teacher_paced') return 'Teacher Paced'
  if (normalized === 'instant_feedback') return 'Instant Feedback'
  return 'Open Navigation'
}

export function parseDateTime(value) {
  if (!value) return null

  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return null

  return parsed
}

export function formatDateTime(value) {
  const parsed = parseDateTime(value)
  if (!parsed) return 'n/a'

  const year = parsed.getFullYear()
  const month = padTimePart(parsed.getMonth() + 1)
  const day = padTimePart(parsed.getDate())
  const hour = padTimePart(parsed.getHours())
  const minute = padTimePart(parsed.getMinutes())
  const second = padTimePart(parsed.getSeconds())

  return `${year}-${month}-${day} ${hour}:${minute}:${second}`
}

export function formatClockTime(value) {
  const parsed = parseDateTime(value)
  if (!parsed) return 'n/a'

  return `${padTimePart(parsed.getHours())}:${padTimePart(parsed.getMinutes())}:${padTimePart(parsed.getSeconds())}`
}

export function formatExamSchedule(value) {
  const parsed = parseDateTime(value)
  if (!parsed) return 'No schedule set'
  return formatDateTime(parsed)
}

export function formatRemainingDuration(totalSeconds) {
  const safeSeconds = Math.max(0, Math.floor(Number(totalSeconds ?? 0)))
  const hours = Math.floor(safeSeconds / 3600)
  const minutes = Math.floor((safeSeconds % 3600) / 60)
  const seconds = safeSeconds % 60

  return `${padTimePart(hours)}:${padTimePart(minutes)}:${padTimePart(seconds)}`
}

