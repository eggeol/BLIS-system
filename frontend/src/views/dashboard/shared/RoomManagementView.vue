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

    <article v-if="roomLiveBoardActive" class="surface-card room-shell-card room-live-board-shell">
      <header class="room-page-head">
        <div class="room-page-title">
          <BarChart3 :size="18" />
          <h3>{{ liveBoardExam?.title || 'Live Quiz Board' }}</h3>
        </div>
        <button type="button" class="ghost-btn" @click="closeRoomLiveBoard">
          <ChevronLeft :size="14" />
          Back to Room
        </button>
      </header>

      <p class="muted room-live-board-sub">
        <span>{{ liveBoardRoom?.name || selectedRoom?.name || 'Room' }}</span>
        <span v-if="liveBoardRoom?.code"> ({{ liveBoardRoom.code }})</span>
        <span> • Updated {{ liveBoardLastUpdatedText }}</span>
      </p>

      <div v-if="liveBoardError" class="feedback danger">
        <AlertCircle :size="15" />
        <span>{{ liveBoardError }}</span>
      </div>

      <div class="live-board-toolbar">
        <label class="check-item">
          <input v-model="liveBoardOptions.show_names" type="checkbox" />
          <span>Show Names</span>
        </label>
        <label class="check-item">
          <input v-model="liveBoardOptions.show_responses" type="checkbox" />
          <span>Show Responses</span>
        </label>
        <label class="check-item">
          <input v-model="liveBoardOptions.show_results" type="checkbox" />
          <span>Show Results</span>
        </label>
        <button type="button" class="ghost-btn" :disabled="liveBoardLoading || liveBoardRefreshing" @click="loadLiveBoard(false)">
          <RefreshCw :size="14" :class="{ 'spin-soft': liveBoardLoading || liveBoardRefreshing }" />
          Refresh
        </button>
      </div>

      <div class="management-inline live-board-summary">
        <span class="pill neutral">{{ liveBoardSummary.students_total }} student(s)</span>
        <span class="pill navy">{{ liveBoardSummary.attempts_started }} started</span>
        <span class="pill success">{{ liveBoardSummary.attempts_submitted }} submitted</span>
      </div>

      <div v-if="liveBoardLoading" class="room-empty-state compact">
        <RefreshCw :size="30" class="spin-soft" />
        <h4>Loading Live Board</h4>
        <p>Fetching latest student responses.</p>
      </div>

      <div v-else-if="liveBoardRows.length === 0" class="room-empty-state compact">
        <FileText :size="34" />
        <h4>No Student Data Yet</h4>
        <p>Students will appear here when they are enrolled and start answering.</p>
      </div>

      <div v-else class="live-board-table-wrap">
        <table class="live-board-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Progress</th>
              <th v-for="item in liveBoardItemSummary" :key="`live-head-${item.item_number}`">
                {{ item.item_number }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, rowIndex) in liveBoardRows" :key="row.user.id">
              <td class="live-board-name-cell">
                <strong>{{ liveBoardDisplayName(row, rowIndex) }}</strong>
                <small v-if="liveBoardOptions.show_names && row.user.student_id" class="muted">
                  ID {{ row.user.student_id }}
                </small>
              </td>
              <td class="live-board-progress-cell">
                <span class="pill" :class="row.attempt?.status === 'submitted' ? 'success' : 'neutral'">
                  {{ liveBoardProgressLabel(row) }}
                </span>
              </td>
              <td
                v-for="item in row.items"
                :key="`live-cell-${row.user.id}-${item.item_number}`"
                class="live-board-answer-cell"
                :class="liveBoardCellClass(item)"
              >
                {{ liveBoardCellText(item) }}
              </td>
            </tr>
          </tbody>
          <tfoot v-if="liveBoardItemSummary.length > 0">
            <tr>
              <th colspan="2">Class Total</th>
              <th v-for="item in liveBoardItemSummary" :key="`live-total-${item.item_number}`">
                {{ liveBoardItemSummaryText(item) }}
              </th>
            </tr>
          </tfoot>
        </table>
      </div>
    </article>

    <article v-else class="surface-card room-shell-card">
      <header class="room-page-head">
        <div class="room-page-title">
          <DoorOpen :size="18" />
          <h3>Rooms</h3>
        </div>
        <button v-if="canCreateRooms" class="primary-btn add-room-btn" :disabled="roomLoading" @click="openCreateRoomModal">
          <Plus :size="16" />
          Add Room
        </button>
      </header>

      <div v-if="roomLoading && rooms.length === 0" class="room-empty-state">
        <RefreshCw :size="34" class="spin-soft" />
        <h4>Loading rooms</h4>
        <p>Please wait while we fetch your room list.</p>
      </div>

      <div v-else-if="rooms.length === 0" class="room-empty-state">
        <House :size="42" />
        <h4>{{ canCreateRooms ? 'Add a Room' : 'No Rooms Available' }}</h4>
        <p>
          {{
            canCreateRooms
              ? 'This page allows you to create and manage rooms for your assigned examinations.'
              : 'No rooms are available to manage yet.'
          }}
        </p>
      </div>

      <div v-else class="room-layout">
        <aside class="room-list-panel">
          <p class="muted">{{ roomCollectionLabel }}: <strong>{{ rooms.length }}</strong></p>

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
                <button class="ghost-btn room-head-edit-btn" :disabled="roomLoading || roomDetailsLoading" @click="exportRoomGrades">
                  <Download :size="14" />
                  Export Grades
                </button>
                <button class="danger-btn room-head-delete-btn" :disabled="roomLoading || roomDetailsLoading" @click="openDeleteRoomModal">
                  <Trash2 :size="14" />
                  Delete
                </button>
                <button class="ghost-btn room-head-edit-btn" :disabled="roomLoading || roomDetailsLoading" @click="openEditRoomModal">
                  <Pencil :size="14" />
                  Edit
                </button>
              </div>
            </header>

            <div class="room-detail-grid">
              <article v-if="isStaffRole" class="detail-card">
                <header class="room-section-head">
                  <h5>Room Exams</h5>
                </header>

                <div class="management-inline">
                  <span class="pill success">{{ selectedRoomActiveExams.length }} active</span>
                  <span class="pill neutral">{{ selectedRoomArchivedExams.length }} archived</span>
                </div>

                <p v-if="selectedRoomActiveExams.length === 0 && selectedRoomArchivedExams.length === 0" class="muted empty-detail">
                  No exams assigned to this room yet.
                </p>

                <div v-if="selectedRoomActiveExams.length > 0" class="exam-card-grid">
                  <article v-for="exam in selectedRoomActiveExams" :key="exam.id" class="exam-card">
                    <div>
                      <strong class="exam-card-title">{{ exam.title }}</strong>
                      <p class="exam-card-meta">{{ exam.progress ?? '0 / 0 answered' }}</p>
                      <p class="exam-card-meta">{{ examDeliveryModeLabel(exam.delivery_mode) }}</p>
                      <p class="exam-card-date">
                        Schedule: {{ formatExamSchedule(exam.schedule_start_at ?? exam.scheduled_at, exam.schedule_end_at) }}
                      </p>
                    </div>

                    <div class="exam-card-actions">
                      <button
                        v-if="canViewExamResults"
                        type="button"
                        class="primary-btn exam-start-btn"
                        :disabled="liveBoardLoading || liveBoardRefreshing"
                        @click="openRoomLiveBoard(exam)"
                      >
                        <BarChart3 :size="14" />
                        Open Live Board
                      </button>
                      <button
                        type="button"
                        class="ghost-btn"
                        :disabled="roomLoading || roomDetailsLoading"
                        @click="handleArchiveRoomExam(exam)"
                      >
                        <Archive :size="14" />
                        Archive
                      </button>
                    </div>
                  </article>
                </div>

                <details v-if="selectedRoomArchivedExams.length > 0" class="surface-card collapsible-card room-archive-card">
                  <summary class="collapsible-summary">
                    <div>
                      <strong>Archived Exams</strong>
                      <p class="muted">Moved out of the current room view to reduce clutter.</p>
                    </div>
                    <div class="collapsible-summary-meta">
                      <span class="pill neutral">{{ selectedRoomArchivedExams.length }} archived</span>
                      <ChevronDown :size="16" class="collapsible-icon" />
                    </div>
                  </summary>

                  <div class="collapsible-content">
                    <div class="exam-card-grid">
                      <article v-for="exam in selectedRoomArchivedExams" :key="`archived-${exam.id}`" class="exam-card archived-card">
                        <div>
                          <strong class="exam-card-title">{{ exam.title }}</strong>
                          <p class="exam-card-meta">{{ exam.total_items }} items • {{ exam.duration_minutes }} mins</p>
                          <p class="exam-card-date">
                            Archived {{ exam.room_archived_at ? new Date(exam.room_archived_at).toLocaleDateString() : 'from current room' }}
                          </p>
                        </div>

                        <div class="exam-card-actions">
                          <button
                            type="button"
                            class="ghost-btn"
                            :disabled="roomLoading || roomDetailsLoading"
                            @click="handleRestoreRoomExam(exam)"
                          >
                            <ArchiveRestore :size="14" />
                            Restore
                          </button>
                        </div>
                      </article>
                    </div>
                  </div>
                </details>
              </article>

              <article class="detail-card">
                <header class="room-section-head">
                  <h5>Students In Room</h5>
                </header>

                <div class="management-inline">
                  <span class="pill success">{{ selectedRoom.members_count ?? selectedRoom.members.length }} current</span>
                  <span class="pill navy">{{ selectedRoomYearSummary.label }}</span>
                </div>

                <p class="muted room-roster-copy">{{ selectedRoomRosterCopy }}</p>

                <p v-if="selectedRoomCurrentMembers.length === 0" class="muted empty-detail">
                  No members enrolled yet.
                </p>

                <div v-if="selectedRoomCurrentMembers.length > 0" class="room-roster-shell">
                  <div class="room-roster-grid management">
                    <article v-for="member in selectedRoomCurrentMembers" :key="member.id" class="room-roster-card">
                      <div class="room-roster-card-copy">
                        <strong>{{ member.name }}</strong>
                        <p v-if="member.student_id">Student ID: {{ member.student_id }}</p>
                        <p v-else>{{ member.email }}</p>
                      </div>
                      <div class="room-roster-card-actions">
                        <span v-if="selectedRoomYearSummary.is_mixed" class="pill navy">
                          {{ member.year_level_label || yearLevelLabel(member.year_level) }}
                        </span>
                        <button
                          v-if="canRemoveRoomMember(member)"
                          type="button"
                          class="member-kick-icon-btn"
                          :aria-label="`Remove ${member.name} from room`"
                          title="Remove from room"
                          :disabled="roomLoading || roomDetailsLoading"
                          @click="handleKickRoomMember(member)"
                        >
                          <UserMinus :size="14" />
                        </button>
                      </div>
                    </article>
                  </div>
                </div>
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
      <div v-if="showCreateRoomModal && canCreateRooms" class="modal-backdrop" @click.self="closeCreateRoomModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Add Room</h4>
            <button type="button" class="modal-close" @click="closeCreateRoomModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">Create a room for your class and share the generated code with students.</p>

          <label class="field-stack">
            <span class="field-label">Room name</span>
            <input
              v-model="roomName"
              type="text"
              class="text-input"
              maxlength="255"
              placeholder="e.g. LIS 4A - Mock Exam"
            />
          </label>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="roomLoading" @click="closeCreateRoomModal">Cancel</button>
            <button type="button" class="primary-btn" :disabled="roomLoading || !roomName.trim()" @click="handleCreateRoom">
              <Plus :size="16" />
              Create Room
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showEditRoomModal" class="modal-backdrop" @click.self="closeEditRoomModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Edit Room</h4>
            <button type="button" class="modal-close" @click="closeEditRoomModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">Update room name for <strong>{{ selectedRoom?.code }}</strong>.</p>

          <label class="field-stack">
            <span class="field-label">Room name</span>
            <input
              v-model="editRoomName"
              type="text"
              class="text-input"
              maxlength="255"
              placeholder="Enter updated room name"
            />
          </label>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="roomLoading" @click="closeEditRoomModal">Cancel</button>
            <button type="button" class="primary-btn" :disabled="roomLoading || !editRoomName.trim()" @click="handleUpdateRoom">
              <Pencil :size="16" />
              Save Changes
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showDeleteRoomModal" class="modal-backdrop" @click.self="closeDeleteRoomModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Delete Room</h4>
            <button type="button" class="modal-close" @click="closeDeleteRoomModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">
            Delete <strong>{{ selectedRoom?.name }}</strong> (<code>{{ selectedRoom?.code }}</code>)?
            This will remove room enrollments.
          </p>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="roomLoading" @click="closeDeleteRoomModal">Cancel</button>
            <button type="button" class="danger-btn" :disabled="roomLoading" @click="handleDeleteRoom">
              <Trash2 :size="16" />
              Delete Room
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
  Archive,
  ArchiveRestore,
  BarChart3,
  CheckCircle2,
  ChevronLeft,
  ChevronDown,
  DoorOpen,
  Download,
  FileText,
  House,
  Pencil,
  Plus,
  RefreshCw,
  Trash2,
  UserMinus,
  X,
} from 'lucide-vue-next'
import { examDeliveryModeLabel, formatExamSchedule } from '../composables/useDashboardFormatters'
import { useRoomsModule } from '../composables/useRoomsModule'

const {
  isStaffRole,
  canCreateRooms,
  canViewExamResults,
  roomName,
  rooms,
  selectedRoomId,
  selectedRoom,
  showCreateRoomModal,
  showEditRoomModal,
  showDeleteRoomModal,
  editRoomName,
  roomLoading,
  roomDetailsLoading,
  roomError,
  roomMessage,
  roomLiveBoardActive,
  liveBoardExam,
  liveBoardRoom,
  liveBoardRows,
  liveBoardItemSummary,
  liveBoardSummary,
  liveBoardLoading,
  liveBoardRefreshing,
  liveBoardError,
  liveBoardOptions,
  liveBoardLastUpdatedText,
  roomCollectionLabel,
  selectedRoomActiveExams,
  selectedRoomArchivedExams,
  selectedRoomCurrentMembers,
  selectedRoomYearSummary,
  selectedRoomRosterCopy,
  yearLevelLabel,
  canRemoveRoomMember,
  openCreateRoomModal,
  closeCreateRoomModal,
  openEditRoomModal,
  closeEditRoomModal,
  openDeleteRoomModal,
  closeDeleteRoomModal,
  selectRoom,
  handleCreateRoom,
  handleUpdateRoom,
  handleDeleteRoom,
  handleKickRoomMember,
  handleArchiveRoomExam,
  handleRestoreRoomExam,
  openRoomLiveBoard,
  closeRoomLiveBoard,
  loadLiveBoard,
  liveBoardDisplayName,
  liveBoardProgressLabel,
  liveBoardCellText,
  liveBoardCellClass,
  liveBoardItemSummaryText,
  exportRoomGrades,
} = useRoomsModule({ mode: 'management' })
</script>

<style scoped src="../dashboard.css"></style>
