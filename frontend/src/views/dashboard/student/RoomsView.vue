<template>
  <section class="room-view">
    <div v-if="roomMessage" class="feedback success">
      <CheckCircle2 :size="15" />
      <span>{{ roomMessage }}</span>
    </div>
    <div v-if="roomError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ roomError }}</span>
    </div>

    <article class="surface-card room-shell-card">
      <header class="room-page-head">
        <div class="room-page-title">
          <DoorOpen :size="18" />
          <h3>Rooms</h3>
        </div>
        <button class="primary-btn add-room-btn" :disabled="roomLoading" @click="openJoinRoomModal">
          <DoorOpen :size="16" />
          Join Room
        </button>
      </header>

      <div v-if="roomLoading && rooms.length === 0" class="room-empty-state">
        <RefreshCw :size="34" class="spin-soft" />
        <h4>Loading rooms</h4>
        <p>Please wait while we fetch your room list.</p>
      </div>

      <div v-else-if="rooms.length === 0" class="room-empty-state">
        <DoorOpen :size="42" />
        <h4>Join a Room</h4>
        <p>Use your room code to join and view assigned exams and enrolled classmates.</p>
      </div>

      <div v-else class="room-layout">
        <aside class="room-list-panel">
          <p class="muted">My Rooms: <strong>{{ rooms.length }}</strong></p>

          <div class="room-list">
            <button
              v-for="room in rooms"
              :key="room.id"
              type="button"
              class="room-item room-item-clickable"
              :class="{ active: selectedRoomId === room.id }"
              @click="selectRoom(room.id)"
            >
              <div>
                <strong>{{ room.name }}</strong>
                <p>Code: {{ room.code }}</p>
              </div>
              <div class="room-meta">
                <small>{{ room.members_count ?? 0 }} members</small>
                <small v-if="room.creator?.name">By {{ room.creator.name }}</small>
              </div>
            </button>
          </div>
        </aside>

        <section class="room-detail-panel">
          <div v-if="roomDetailsLoading" class="room-detail-loading">
            <RefreshCw :size="18" class="spin-soft" />
            <span>Loading room details...</span>
          </div>

          <template v-else-if="selectedRoom">
            <header class="room-detail-head">
              <div class="room-detail-head-copy">
                <h4>{{ selectedRoom.name }}</h4>
                <p class="room-detail-subtitle">
                  <DoorOpen :size="14" />
                  Room Code:
                  <strong>{{ selectedRoom.code }}</strong>
                </p>
              </div>
              <div class="room-detail-head-actions">
                <button class="danger-btn room-head-leave-btn" :disabled="roomLoading || roomDetailsLoading" @click="openLeaveRoomModal">
                  <LogOut :size="14" />
                  Leave Room
                </button>
              </div>
            </header>

            <div class="room-detail-grid">
              <article class="detail-card">
                <header class="room-section-head">
                  <h5>Exams</h5>
                </header>

                <p v-if="selectedRoom.assigned_exams.length === 0" class="muted empty-detail">No exams assigned to this room yet.</p>
                <div v-else class="exam-card-grid">
                  <article
                    v-for="exam in selectedRoom.assigned_exams"
                    :key="exam.id"
                    class="exam-card"
                    :class="{ locked: !canStudentOpenExam(exam) }"
                  >
                    <div>
                      <strong class="exam-card-title">{{ exam.title }}</strong>
                      <p class="exam-card-meta">{{ exam.total_items }} items • {{ exam.duration_minutes }} mins</p>
                      <p class="exam-card-meta">{{ examDeliveryModeLabel(exam.delivery_mode) }}</p>
                      <p class="exam-card-date">{{ studentExamAvailabilityText(exam) }}</p>
                    </div>
                    <button
                      type="button"
                      class="primary-btn exam-start-btn"
                      :class="{
                        resume: isStudentExamInProgress(exam),
                        retake: isStudentExamCompleted(exam) && !isStudentExamRetakeLimitReached(exam),
                        review: isStudentExamRetakeLimitReached(exam),
                      }"
                      :disabled="!canStudentOpenExam(exam)"
                      @click="openExamSimulation(exam)"
                    >
                      {{ studentExamActionLabel(exam) }}
                    </button>
                  </article>
                </div>
              </article>

              <article class="detail-card">
                <header class="room-section-head">
                  <h5>In Room</h5>
                </header>

                <p v-if="selectedRoom.members.length === 0" class="muted empty-detail">No members enrolled yet.</p>
                <ul v-else class="member-list">
                  <li v-for="member in selectedRoom.members" :key="member.id" class="member-item">
                    <span class="member-avatar">
                      <UserRound :size="16" />
                    </span>
                    <div class="member-info">
                      <strong>{{ member.name }}</strong>
                      <p>{{ member.email }}</p>
                    </div>
                    <span class="pill neutral">{{ displayMemberRole(member.role) }}</span>
                  </li>
                </ul>
              </article>
            </div>
          </template>

          <div v-else class="room-detail-empty">
            <DoorOpen :size="30" />
            <h4>Select a room</h4>
            <p>Choose a room from the list to view members and assigned exams.</p>
          </div>
        </section>
      </div>
    </article>

    <teleport to="body">
      <div v-if="showJoinRoomModal" class="modal-backdrop" @click.self="closeJoinRoomModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Join Room</h4>
            <button type="button" class="modal-close" @click="closeJoinRoomModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">Enter the room code provided by your examiner.</p>

          <label class="field-stack">
            <span class="field-label">Room code</span>
            <input
              v-model="joinCode"
              type="text"
              class="text-input code"
              maxlength="12"
              placeholder="Enter room code"
              @input="joinCode = joinCode.toUpperCase()"
            />
          </label>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="roomLoading" @click="closeJoinRoomModal">Cancel</button>
            <button type="button" class="primary-btn" :disabled="roomLoading || !joinCode.trim()" @click="handleJoinRoom">
              <DoorOpen :size="16" />
              Join
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showLeaveRoomModal" class="modal-backdrop" @click.self="closeLeaveRoomModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Leave Room</h4>
            <button type="button" class="modal-close" @click="closeLeaveRoomModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">
            Leave <strong>{{ selectedRoom?.name }}</strong> (<code>{{ selectedRoom?.code }}</code>)?
          </p>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="roomLoading" @click="closeLeaveRoomModal">Cancel</button>
            <button type="button" class="danger-btn" :disabled="roomLoading" @click="handleLeaveRoom">
              <LogOut :size="16" />
              Leave Room
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showExamSimulationModal" class="exam-attempt-backdrop">
        <div class="exam-attempt-shell">
          <header class="exam-attempt-head">
            <div>
              <h3>{{ selectedStudentExam?.title || 'Exam Attempt' }}</h3>
              <p>
                {{ selectedRoom?.name || 'Unknown Room' }}
                <span v-if="selectedRoom?.code"> • {{ selectedRoom.code }}</span>
              </p>
            </div>

            <div class="exam-attempt-head-meta">
              <span v-if="studentExamAttempt" class="pill neutral">
                {{ studentExamAttempt.answered_count }}/{{ studentExamAttempt.total_items }} answered
              </span>
              <span v-if="selectedStudentExam" class="pill neutral">
                {{ examDeliveryModeLabel(selectedStudentExam.delivery_mode) }}
              </span>
              <span v-if="studentExamAttempt && !isStudentExamSubmitted && studentExamRemainingSeconds !== null" class="pill navy">
                Time: {{ formatRemainingDuration(studentExamRemainingSeconds) }}
              </span>
              <span v-if="studentExamAttempt && isStudentExamSubmitted" class="pill success">
                Score: {{ Number(studentExamAttempt.score_percent ?? 0).toFixed(2) }}%
              </span>
              <span v-if="studentExamAttempt && isStudentExamSubmitted" class="pill neutral">
                {{ Number(studentExamAttempt.correct_answers ?? 0) }}/{{ Number(studentExamAttempt.total_items ?? 0) }} correct
              </span>
              <button
                type="button"
                class="ghost-btn"
                :disabled="studentExamSubmitting"
                @click="handleExamAttemptCloseClick"
              >
                {{ isStudentExamSubmitted ? 'Close' : 'Exit' }}
              </button>
            </div>
          </header>

          <div v-if="studentExamError" class="feedback danger">
            <AlertCircle :size="15" />
            <span>{{ studentExamError }}</span>
          </div>

          <div v-if="studentExamLoading" class="room-detail-loading exam-attempt-loading">
            <RefreshCw :size="16" class="spin-soft" />
            <span>Preparing exam attempt...</span>
          </div>

          <div v-else-if="studentExamAttempt" class="exam-attempt-layout">
            <aside class="exam-attempt-sidebar" :class="{ 'is-collapsed': examAttemptSidebarCollapsed }">
              <div class="exam-status-legend">
                <template v-if="!isStudentExamSubmitted">
                  <span class="legend-item"><i class="legend-dot current" /> Current</span>
                  <span class="legend-item"><i class="legend-dot answered" /> Answered</span>
                  <span class="legend-item"><i class="legend-dot blank" /> Blank</span>
                  <span class="legend-item"><i class="legend-ribbon" /> Bookmarked</span>
                </template>
                <template v-else>
                  <span class="legend-item"><i class="legend-dot current-outline" /> Current</span>
                  <span class="legend-item"><i class="legend-dot answered" /> Answered</span>
                  <span class="legend-item"><i class="legend-dot missed" /> Not answered</span>
                  <span class="legend-item"><i class="legend-ribbon" /> Bookmarked</span>
                </template>
              </div>

              <div v-if="isStudentOpenNavigationMode || isStudentExamSubmitted" class="exam-question-jump immersive">
                <button
                  v-for="(question, index) in studentExamQuestions"
                  :key="question.question_id"
                  type="button"
                  class="exam-jump-btn"
                  :class="questionPaletteClass(question, index)"
                  :disabled="studentExamSaving || studentExamSubmitting || studentExamBookmarking || (!isStudentOpenNavigationMode && !isStudentExamSubmitted)"
                  @click="goToStudentExamQuestionIndex(index)"
                >
                  {{ question.item_number }}
                </button>
              </div>
            </aside>

            <section class="exam-attempt-main">
              <article v-if="currentStudentExamQuestion" class="exam-attempt-card immersive">
                <header class="surface-head">
                  <h4>Question {{ currentStudentExamQuestion.item_number }}</h4>
                  <span class="pill neutral">{{ currentStudentExamQuestion.question_type.replace('_', ' ') }}</span>
                </header>

                <div v-if="currentQuestionStem.numberedItems.length > 0" class="exam-question-stem">
                  <p class="exam-attempt-question-text">{{ currentQuestionStem.leadText }}</p>
                  <ol class="exam-question-list">
                    <li
                      v-for="(itemText, itemIndex) in currentQuestionStem.numberedItems"
                      :key="`stem-item-${currentStudentExamQuestion.question_id}-${itemIndex}`"
                    >
                      {{ itemText }}
                    </li>
                  </ol>
                </div>

                <p v-else class="exam-attempt-question-text">{{ currentStudentExamQuestion.question_text }}</p>

                <div v-if="currentStudentExamQuestion.question_type === 'open_ended'" class="field-stack">
                  <textarea
                    v-model="studentAnswerDraft.answer_text"
                    class="text-input textarea-input"
                    rows="5"
                    placeholder="Type your answer here..."
                    :disabled="isCurrentQuestionInputLocked"
                    @blur="handleStudentOpenEndedBlur"
                  />
                </div>

                <div v-else class="exam-option-grid">
                  <label
                    v-for="option in currentStudentExamQuestion.options"
                    :key="option.id"
                    class="exam-option-card"
                    :class="examOptionCardClass(option)"
                  >
                    <input
                      :checked="studentAnswerDraft.selected_option_id === option.id"
                      type="radio"
                      :disabled="isCurrentQuestionInputLocked"
                      @change="handleStudentOptionSelect(option.id)"
                    />
                    <span>{{ option.label }}. {{ option.text }}</span>
                  </label>
                </div>
              </article>

              <div class="exam-attempt-footer">
                <button
                  v-if="!isStudentExamSubmitted && currentStudentExamQuestion && isStudentOpenNavigationMode"
                  type="button"
                  class="bookmark-toggle-btn"
                  :class="{ 'is-bookmarked': currentStudentExamQuestion.is_bookmarked, 'is-loading': studentExamBookmarking }"
                  :aria-pressed="currentStudentExamQuestion.is_bookmarked ? 'true' : 'false'"
                  :title="currentStudentExamQuestion.is_bookmarked ? 'Remove bookmark' : 'Bookmark this question'"
                  :disabled="studentExamSaving || studentExamSubmitting || studentExamBookmarking"
                  @click="toggleCurrentQuestionBookmark"
                >
                  <RefreshCw v-if="studentExamBookmarking" :size="14" class="spin-soft" />
                  <BookmarkCheck v-else-if="currentStudentExamQuestion.is_bookmarked" :size="14" />
                  <Bookmark v-else :size="14" />
                  <span class="bookmark-toggle-label">
                    {{ currentStudentExamQuestion.is_bookmarked ? 'Bookmarked' : 'Bookmark' }}
                  </span>
                </button>

                <div class="exam-attempt-nav">
                  <button
                    type="button"
                    class="ghost-btn exam-sidebar-inline-toggle"
                    :aria-expanded="(!examAttemptSidebarCollapsed).toString()"
                    @click="toggleExamAttemptSidebar"
                  >
                    {{ examAttemptSidebarCollapsed ? 'Show Question List' : 'Hide Question List' }}
                    <ChevronRight :size="15" :class="{ 'is-open': !examAttemptSidebarCollapsed }" />
                  </button>
                  <button
                    type="button"
                    class="ghost-btn"
                    :disabled="studentExamCurrentIndex === 0 || studentExamSaving || studentExamSubmitting || studentExamBookmarking"
                    @click="goToStudentExamQuestion(-1)"
                  >
                    Previous
                  </button>
                  <button
                    type="button"
                    class="ghost-btn"
                    :disabled="studentExamCurrentIndex >= studentExamQuestions.length - 1 || studentExamSaving || studentExamSubmitting || studentExamBookmarking"
                    @click="goToStudentExamQuestion(1)"
                  >
                    Next
                  </button>
                </div>

                <button
                  v-if="!isStudentExamSubmitted"
                  type="button"
                  class="primary-btn"
                  :disabled="studentExamSubmitting"
                  @click="openStudentExamSubmitConfirm"
                >
                  <RefreshCw v-if="studentExamSubmitting" :size="14" class="spin-soft" />
                  <span>{{ studentExamSubmitting ? 'Submitting...' : 'Submit Exam' }}</span>
                </button>
              </div>
            </section>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div
        v-if="showStudentSubmitConfirmModal && !isStudentExamSubmitted"
        class="modal-backdrop exam-attempt-modal-backdrop"
        @click.self="closeStudentExamSubmitConfirm"
      >
        <div class="modal-card">
          <header class="modal-head">
            <h4>Submit Exam?</h4>
            <button type="button" class="modal-close" :disabled="studentExamSubmitting" @click="closeStudentExamSubmitConfirm">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">Submitting your exam is final and cannot be undone.</p>

          <div v-if="studentExamUnansweredCount > 0" class="feedback danger">
            <AlertCircle :size="15" />
            <span>
              You still have
              <strong>{{ studentExamUnansweredCount }}</strong>
              unanswered {{ studentExamUnansweredCount === 1 ? 'item' : 'items' }}.
            </span>
          </div>

          <div v-else class="feedback info">
            <CheckCircle2 :size="15" />
            <span>All items are answered. Ready to submit.</span>
          </div>

          <div v-if="studentExamSaving || studentExamBookmarking" class="feedback info">
            <RefreshCw :size="14" class="spin-soft" />
            <span>Syncing your latest answer changes...</span>
          </div>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="studentExamSubmitting" @click="closeStudentExamSubmitConfirm">
              Cancel
            </button>
            <button
              type="button"
              class="danger-btn"
              :disabled="studentExamSubmitting"
              @click="confirmStudentExamSubmit"
            >
              <RefreshCw v-if="studentExamSubmitting" :size="14" class="spin-soft" />
              <span>{{ studentExamSubmitting ? 'Submitting...' : 'Submit Final' }}</span>
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div
        v-if="showStudentExitConfirmModal && !isStudentExamSubmitted"
        class="modal-backdrop exam-attempt-modal-backdrop"
        @click.self="closeStudentExamExitConfirm"
      >
        <div class="modal-card">
          <header class="modal-head">
            <h4>Exit Exam?</h4>
            <button type="button" class="modal-close" :disabled="studentExamSubmitting" @click="closeStudentExamExitConfirm">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">Are you sure you want to exit this exam? You can return as long as the exam remains available.</p>

          <div class="feedback danger">
            <AlertCircle :size="15" />
            <span>Any unsynced changes in your current response may not be saved.</span>
          </div>

          <div v-if="studentExamSaving || studentExamBookmarking" class="feedback info">
            <RefreshCw :size="14" class="spin-soft" />
            <span>Please wait, your latest changes are still syncing.</span>
          </div>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="studentExamSubmitting" @click="closeStudentExamExitConfirm">
              Stay
            </button>
            <button type="button" class="danger-btn" :disabled="studentExamSubmitting" @click="confirmStudentExamExit">
              Exit Exam
            </button>
          </div>
        </div>
      </div>
    </teleport>
  </section>
</template>

<script setup>
import {
  AlertCircle,
  Bookmark,
  BookmarkCheck,
  CheckCircle2,
  ChevronRight,
  DoorOpen,
  LogOut,
  RefreshCw,
  UserRound,
  X,
} from 'lucide-vue-next'
import { examDeliveryModeLabel, formatRemainingDuration } from '../composables/useDashboardFormatters'
import { useRoomsModule } from '../composables/useRoomsModule'

const {
  joinCode,
  rooms,
  selectedRoomId,
  selectedRoom,
  showJoinRoomModal,
  showLeaveRoomModal,
  roomLoading,
  roomDetailsLoading,
  roomError,
  roomMessage,
  showExamSimulationModal,
  showStudentSubmitConfirmModal,
  showStudentExitConfirmModal,
  selectedStudentExam,
  studentExamAttempt,
  studentExamQuestions,
  studentExamCurrentIndex,
  examAttemptSidebarCollapsed,
  studentExamLoading,
  studentExamSaving,
  studentExamBookmarking,
  studentExamSubmitting,
  studentExamError,
  studentExamRemainingSeconds,
  studentAnswerDraft,
  currentStudentExamQuestion,
  currentQuestionStem,
  isStudentExamSubmitted,
  isStudentOpenNavigationMode,
  isCurrentQuestionInputLocked,
  studentExamUnansweredCount,
  displayMemberRole,
  canStudentOpenExam,
  isStudentExamInProgress,
  isStudentExamCompleted,
  isStudentExamRetakeLimitReached,
  studentExamActionLabel,
  studentExamAvailabilityText,
  examOptionCardClass,
  questionPaletteClass,
  toggleExamAttemptSidebar,
  openExamSimulation,
  goToStudentExamQuestionIndex,
  goToStudentExamQuestion,
  handleExamAttemptCloseClick,
  closeStudentExamExitConfirm,
  confirmStudentExamExit,
  openStudentExamSubmitConfirm,
  closeStudentExamSubmitConfirm,
  confirmStudentExamSubmit,
  handleStudentOptionSelect,
  handleStudentOpenEndedBlur,
  toggleCurrentQuestionBookmark,
  openJoinRoomModal,
  closeJoinRoomModal,
  openLeaveRoomModal,
  closeLeaveRoomModal,
  selectRoom,
  handleJoinRoom,
  handleLeaveRoom,
} = useRoomsModule({ mode: 'student' })
</script>

<style scoped src="../dashboard.css"></style>
