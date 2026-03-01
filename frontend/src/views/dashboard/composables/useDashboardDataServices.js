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
    leaveRoom: (roomId) => roomsApi.leave(roomId),

    getLibraryBanks: () => questionsApi.listBanks(),

    getExams: () => examsApi.list(),
    createExam: (payload) => examsApi.create(payload),
    updateExam: (examId, payload) => examsApi.update(examId, payload),
    deleteExam: (examId) => examsApi.remove(examId),
    getLiveBoard: (examId, roomId) => examsApi.liveBoard(examId, roomId),
    updateTeacherPacing: (examId, payload) => examsApi.updateTeacherPacing(examId, payload),

    getReportsOverview: () => reportsApi.overview(),

    getSystemSettings: () => settingsApi.getSystem(),
    saveSystemSettings: (payload) => settingsApi.updateSystem(payload),

    getUsers: (params) => usersApi.list(params),
    createUser: (payload) => usersApi.create(payload),
    updateUser: (userId, payload) => usersApi.update(userId, payload),

    getAuditLogs: (params) => auditApi.list(params),
  }
}
