import { auditApi } from '@/api/audit.api'
import { examsApi } from '@/api/exams.api'
import { questionsApi } from '@/api/questions.api'
import { reportsApi } from '@/api/reports.api'
import { roomsApi } from '@/api/rooms.api'
import { settingsApi } from '@/api/settings.api'
import { studentExamsApi } from '@/api/studentExams.api'
import { usersApi } from '@/api/users.api'

export function useDashboardDataServices() {
  return {
    getAttempt: (attemptId) => studentExamsApi.getAttempt(attemptId),
    startExam: (examId, payload) => studentExamsApi.startExam(examId, payload),
    saveAnswer: (attemptId, payload) => studentExamsApi.saveAnswer(attemptId, payload),
    bookmarkQuestion: (attemptId, questionId, payload) => studentExamsApi.bookmarkQuestion(attemptId, questionId, payload),
    submitAttempt: (attemptId) => studentExamsApi.submitAttempt(attemptId),

    getRooms: () => roomsApi.list(),
    getRoom: (roomId) => roomsApi.detail(roomId),
    createRoom: (payload) => roomsApi.create(payload),
    updateRoom: (roomId, payload) => roomsApi.update(roomId, payload),
    deleteRoom: (roomId) => roomsApi.remove(roomId),
    joinRoom: (payload) => roomsApi.join(payload),
    removeRoomMember: (roomId, memberId) => roomsApi.removeMember(roomId, memberId),
    leaveRoom: (roomId) => roomsApi.leave(roomId),

    getLibraryBanks: () => questionsApi.listBanks(),

    getExams: () => examsApi.list(),
    createExam: (payload) => examsApi.create(payload),
    updateExam: (examId, payload) => examsApi.update(examId, payload),
    deleteExam: (examId) => examsApi.remove(examId),
    getLiveBoard: (examId, roomId) => examsApi.liveBoard(examId, roomId),

    getReportsOverview: () => reportsApi.overview(),
    exportCompleteResultsXlsx: (examId, roomId) => reportsApi.completeResultsXlsx(examId, roomId),
    exportCompleteResultsCsv: (examId, roomId) => reportsApi.completeResultsCsv(examId, roomId),
    exportResultsSummaryPdf: (examId, roomId) => reportsApi.resultsSummaryPdf(examId, roomId),
    exportAnswerKeyPdf: (examId, roomId) => reportsApi.answerKeyPdf(examId, roomId),
    exportStudentReportPdf: (examId, roomId, studentId) => reportsApi.studentReportPdf(examId, roomId, studentId),
    exportStudentReportsZip: (examId, roomId) => reportsApi.studentReportsZip(examId, roomId),
    emailStudentReportPdf: (examId, roomId, studentId, payload) => reportsApi.emailStudentReportPdf(examId, roomId, studentId, payload),
    emailStudentReportsBulk: (examId, roomId, payload) => reportsApi.emailStudentReportsBulk(examId, roomId, payload),

    getSystemSettings: () => settingsApi.getSystem(),
    saveSystemSettings: (payload) => settingsApi.updateSystem(payload),

    getUsers: (params) => usersApi.list(params),
    createUser: (payload) => usersApi.create(payload),
    updateUser: (userId, payload) => usersApi.update(userId, payload),
    recoverUserAccount: (userId, payload) => usersApi.recoverAccount(userId, payload),

    getAuditLogs: (params) => auditApi.list(params),
  }
}
