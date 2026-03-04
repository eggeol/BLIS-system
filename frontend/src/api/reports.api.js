import http from './http'

class ReportsApi {
  overview() {
    return http.get('/reports/overview')
  }

  completeResultsXlsx(examId, roomId) {
    return http.get(`/reports/exams/${examId}/rooms/${roomId}/complete-results.xlsx`, {
      responseType: 'blob',
    })
  }

  completeResultsCsv(examId, roomId) {
    return http.get(`/reports/exams/${examId}/rooms/${roomId}/complete-results.csv`, {
      responseType: 'blob',
    })
  }

  resultsSummaryPdf(examId, roomId) {
    return http.get(`/reports/exams/${examId}/rooms/${roomId}/summary.pdf`, {
      responseType: 'blob',
    })
  }

  answerKeyPdf(examId, roomId) {
    return http.get(`/reports/exams/${examId}/rooms/${roomId}/answer-key.pdf`, {
      responseType: 'blob',
    })
  }

  studentReportPdf(examId, roomId, studentId) {
    return http.get(`/reports/exams/${examId}/rooms/${roomId}/students/${studentId}/student-report.pdf`, {
      responseType: 'blob',
    })
  }

  studentReportsZip(examId, roomId) {
    return http.get(`/reports/exams/${examId}/rooms/${roomId}/student-reports.zip`, {
      responseType: 'blob',
    })
  }

  emailStudentReportPdf(examId, roomId, studentId, payload = {}) {
    return http.post(`/reports/exams/${examId}/rooms/${roomId}/students/${studentId}/student-report.email`, payload)
  }

  emailStudentReportsBulk(examId, roomId, payload = {}) {
    return http.post(`/reports/exams/${examId}/rooms/${roomId}/student-reports.email`, payload)
  }
}

export const reportsApi = new ReportsApi()
