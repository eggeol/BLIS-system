function padTimePart(part) {
  return String(part).padStart(2, '0')
}

const DATE_TIME_FORMATTER = new Intl.DateTimeFormat(undefined, {
  year: 'numeric',
  month: 'long',
  day: 'numeric',
  hour: 'numeric',
  minute: '2-digit',
})

const CLOCK_TIME_FORMATTER = new Intl.DateTimeFormat(undefined, {
  hour: 'numeric',
  minute: '2-digit',
  second: '2-digit',
})

export function normalizeExamDeliveryMode(mode) {
  return 'open_navigation'
}

export function examDeliveryModeLabel(mode) {
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

  return DATE_TIME_FORMATTER.format(parsed)
}

export function formatClockTime(value) {
  const parsed = parseDateTime(value)
  if (!parsed) return 'n/a'

  return CLOCK_TIME_FORMATTER.format(parsed)
}

export function formatExamSchedule(startValue, endValue = null) {
  const start = parseDateTime(startValue)
  const end = parseDateTime(endValue)

  if (!start && !end) return 'No schedule set'
  if (start && end) return `${formatDateTime(start)} to ${formatDateTime(end)}`
  if (start) return `Starts ${formatDateTime(start)}`
  return `Ends ${formatDateTime(end)}`
}

export function formatRemainingDuration(totalSeconds) {
  const safeSeconds = Math.max(0, Math.floor(Number(totalSeconds ?? 0)))
  const hours = Math.floor(safeSeconds / 3600)
  const minutes = Math.floor((safeSeconds % 3600) / 60)
  const seconds = safeSeconds % 60

  return `${padTimePart(hours)}:${padTimePart(minutes)}:${padTimePart(seconds)}`
}
