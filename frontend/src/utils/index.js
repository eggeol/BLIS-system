export function toErrorMessage(error, fallback = 'Something went wrong.') {
  return error?.response?.data?.message ?? fallback
}
