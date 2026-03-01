<template>
  <div class="dashboard-shell" :class="{ embedded: embedded }">
    <aside v-if="!embedded" class="sidebar" :class="{ collapsed: sidebarCollapsed }">
      <div class="sidebar-top">
        <button class="sidebar-toggle" @click="sidebarCollapsed = !sidebarCollapsed">
          <ChevronRight v-if="sidebarCollapsed" :size="16" />
          <ChevronLeft v-else :size="16" />
        </button>

        <button class="brand" @click="sidebarCollapsed = false">
          <span class="brand-icon-wrap">
            <GraduationCap :size="20" />
          </span>
          <span v-if="!sidebarCollapsed" class="brand-text">
            <strong>LNU LLE</strong>
            <small>Review System</small>
          </span>
        </button>

        <button v-if="!sidebarCollapsed" class="logout-btn mobile-logout" @click="handleLogout">
          <LogOut :size="16" />
          Log out
        </button>
      </div>

      <nav class="sidebar-nav">
        <button
          v-for="item in navItems"
          :key="item.key"
          class="nav-item"
          :class="{ active: activeNav === item.key }"
          @click="activeNav = item.key"
        >
          <component :is="item.icon" :size="18" class="nav-icon" />
          <span v-if="!sidebarCollapsed" class="nav-label">{{ item.label }}</span>
        </button>
      </nav>

      <div class="sidebar-footer" v-if="!sidebarCollapsed">
        <div class="user-tile">
          <span class="avatar">{{ userInitials }}</span>
          <div>
            <strong>{{ displayName }}</strong>
            <small>{{ displayRole }}</small>
          </div>
        </div>

        <button class="logout-btn" @click="handleLogout">
          <LogOut :size="16" />
          Log out
        </button>
      </div>
    </aside>

    <section class="main-shell">
      <header v-if="!embedded" class="topbar">
        <div>
          <h1>{{ currentPage.title }}</h1>
          <p>{{ currentPage.sub }}</p>
        </div>

        <div class="topbar-actions">
          <span class="exam-chip">
            <CalendarDays :size="14" />
            Next exam: <strong>March 12, 2026</strong>
          </span>
          <button class="notif-btn" aria-label="Notifications">
            <Bell :size="16" />
          </button>
        </div>
      </header>

      <main class="content-scroll">
        <section v-if="activeNav === 'dashboard'" class="dashboard-view">
          <div class="stats-grid">
            <article class="stat-card" v-for="stat in statCards" :key="stat.label">
              <div class="stat-icon" :class="`tone-${stat.tone}`">
                <component :is="stat.icon" :size="17" />
              </div>
              <div>
                <p class="stat-label">{{ stat.label }}</p>
                <p class="stat-value">{{ stat.value }}</p>
                <p class="stat-trend" :class="{ positive: stat.positive, negative: !stat.positive }">
                  {{ stat.trend }}
                </p>
              </div>
            </article>
          </div>

          <div class="dashboard-grid">
            <article class="surface-card">
              <header class="surface-head">
                <h3>Pass Probability</h3>
                <span class="pill success">DSS</span>
              </header>

              <div class="probability-meter">
                <div class="meter-ring" :style="{ '--value': '84' }">
                  <strong>84%</strong>
                  <span>Likely to pass</span>
                </div>
              </div>

              <ul class="metric-list">
                <li><span>Current average</span><strong>78%</strong></li>
                <li><span>Passing threshold</span><strong>75%</strong></li>
                <li><span>Margin</span><strong class="ok">+3 pts</strong></li>
              </ul>
            </article>

            <article class="surface-card">
              <header class="surface-head">
                <h3>Subject Performance</h3>
                <span class="pill navy">{{ subjects.length }} subjects</span>
              </header>

              <div class="subject-list">
                <div class="subject-item" v-for="subject in subjects" :key="subject.label">
                  <div class="subject-head">
                    <span>{{ subject.label }}</span>
                    <strong :class="{ ok: subject.score >= 75, danger: subject.score < 75 }">{{ subject.score }}%</strong>
                  </div>
                  <div class="bar-track">
                    <div class="bar-fill" :style="{ width: `${subject.score}%` }" :class="{ ok: subject.score >= 75, danger: subject.score < 75 }" />
                  </div>
                </div>
              </div>
            </article>
          </div>

          <div class="dashboard-grid bottom">
            <article class="surface-card">
              <header class="surface-head">
                <h3>Score History</h3>
                <span class="pill neutral">Last 6 tests</span>
              </header>

              <div class="history-chart">
                <div class="history-item" v-for="(score, index) in scoreHistory" :key="index">
                  <span>{{ score }}%</span>
                  <div class="history-bar-track">
                    <div class="history-bar-fill" :style="{ height: `${score}%` }" :class="{ ok: score >= 75, danger: score < 75 }" />
                  </div>
                  <small>T{{ index + 1 }}</small>
                </div>
              </div>
            </article>

            <article class="surface-card">
              <header class="surface-head">
                <h3>Recent Activity</h3>
              </header>

              <div class="activity-list">
                <div class="activity-item" v-for="item in activities" :key="item.title">
                  <span class="activity-dot" :class="{ ok: item.positive, danger: !item.positive }" />
                  <div class="activity-content">
                    <strong>{{ item.title }}</strong>
                    <small>{{ item.meta }}</small>
                  </div>
                  <strong :class="{ ok: item.positive, danger: !item.positive }">{{ item.score }}</strong>
                </div>
              </div>
            </article>
          </div>
        </section>

        <section v-else-if="isRoomPage" class="room-view">
          <div v-if="roomMessage" class="feedback success">
            <CheckCircle2 :size="15" />
            <span>{{ roomMessage }}</span>
          </div>
          <div v-if="roomError" class="feedback danger">
            <AlertCircle :size="15" />
            <span>{{ roomError }}</span>
          </div>

          <template v-if="activeNav === 'room'">
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

              <div v-if="isLiveBoardTeacherPaced" class="management-inline">
                <span class="pill neutral">Teacher Paced</span>
                <span class="pill navy">
                  Current:
                  {{
                    liveBoardTeacherPacing?.is_active
                      ? `Q${liveBoardTeacherPacing.current_item_number ?? 1}`
                      : 'Not started'
                  }}
                </span>
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="liveBoardLoading || liveBoardRefreshing || liveBoardTeacherPacingBusy || liveBoardTeacherPacing?.is_active"
                  @click="updateTeacherPacing('start')"
                >
                  Start
                </button>
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="liveBoardLoading || liveBoardRefreshing || liveBoardTeacherPacingBusy || !liveBoardTeacherPacing?.is_active"
                  @click="updateTeacherPacing('previous')"
                >
                  Previous
                </button>
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="liveBoardLoading || liveBoardRefreshing || liveBoardTeacherPacingBusy || !liveBoardTeacherPacing?.is_active"
                  @click="updateTeacherPacing('next')"
                >
                  Next
                </button>
                <button
                  type="button"
                  class="danger-btn"
                  :disabled="liveBoardLoading || liveBoardRefreshing || liveBoardTeacherPacingBusy || !liveBoardTeacherPacing?.is_active"
                  @click="updateTeacherPacing('stop')"
                >
                  Stop
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
                <button class="primary-btn add-room-btn" :disabled="roomLoading" @click="openCreateRoomModal">
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
                <h4>Add a Room</h4>
                <p>This page allows you to create and manage rooms for your assigned examinations.</p>
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
                      <div>
                        <h4>{{ selectedRoom.name }}</h4>
                      </div>
                    </header>

                    <div class="room-detail-toolbar">
                      <div class="room-code-chip">
                        <span class="room-code-label">
                          <DoorOpen :size="14" />
                          Room Code
                        </span>
                        <strong class="room-code-value">{{ selectedRoom.code }}</strong>
                      </div>

                      <div class="room-action-group">
                        <button class="danger-btn" :disabled="roomLoading || roomDetailsLoading" @click="openDeleteRoomModal">
                          <Trash2 :size="14" />
                          Delete
                        </button>
                        <button class="ghost-btn" :disabled="roomLoading || roomDetailsLoading" @click="openEditRoomModal">
                          <Pencil :size="14" />
                          Edit
                        </button>
                      </div>
                    </div>

                    <div class="room-detail-grid">
                      <article class="detail-card">
                        <header class="room-section-head">
                          <h5>Exams</h5>
                        </header>

                        <p v-if="selectedRoom.assigned_exams.length === 0" class="muted empty-detail">No exams assigned to this room yet.</p>
                        <div v-else class="exam-card-grid">
                          <article v-for="exam in selectedRoom.assigned_exams" :key="exam.id" class="exam-card">
                            <div>
                              <strong class="exam-card-title">{{ exam.title }}</strong>
                              <p class="exam-card-meta">{{ exam.progress ?? '0 / 0 answered' }}</p>
                              <p class="exam-card-meta">{{ examDeliveryModeLabel(exam.delivery_mode) }}</p>
                              <p class="exam-card-date">Schedule: {{ formatExamSchedule(exam.scheduled_at) }}</p>
                            </div>

                            <div class="exam-card-actions">
                              <button
                                type="button"
                                class="primary-btn exam-start-btn"
                                :disabled="liveBoardLoading || liveBoardRefreshing"
                                @click="openRoomLiveBoard(exam)"
                              >
                                <BarChart3 :size="14" />
                                Open Live Board
                              </button>
                            </div>
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
                            <div>
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
              <div v-if="showCreateRoomModal" class="modal-backdrop" @click.self="closeCreateRoomModal">
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
          </template>

          <template v-else>
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
                      <div>
                        <h4>{{ selectedRoom.name }}</h4>
                      </div>
                    </header>

                    <div class="room-detail-toolbar">
                      <div class="room-code-chip">
                        <span class="room-code-label">
                          <DoorOpen :size="14" />
                          Room Code
                        </span>
                        <strong class="room-code-value">{{ selectedRoom.code }}</strong>
                      </div>

                      <div class="room-action-group">
                        <button class="danger-btn" :disabled="roomLoading || roomDetailsLoading" @click="openLeaveRoomModal">
                          <LogOut :size="14" />
                          Leave Room
                        </button>
                      </div>
                    </div>

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
                            :class="{ locked: !canStudentTakeExam(exam) }"
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
                              :disabled="!canStudentTakeExam(exam)"
                              @click="openExamSimulation(exam)"
                            >
                              Take Exam
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
                            <div>
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
                    <aside class="exam-attempt-sidebar">
                      <div class="exam-status-legend">
                        <template v-if="!isStudentExamSubmitted && isStudentOpenNavigationMode">
                          <span class="legend-item"><i class="legend-dot current" /> Current</span>
                          <span class="legend-item"><i class="legend-dot answered" /> Answered</span>
                          <span class="legend-item"><i class="legend-dot blank" /> Blank</span>
                          <span class="legend-item"><i class="legend-ribbon" /> Bookmarked</span>
                        </template>
                        <template v-else-if="!isStudentExamSubmitted && isStudentTeacherPacedMode">
                          <span class="legend-item"><i class="legend-dot current" /> Teacher-controlled question</span>
                          <span class="legend-item"><i class="legend-dot blank" /> Waiting for teacher pace</span>
                        </template>
                        <template v-else-if="!isStudentExamSubmitted && isStudentInstantFeedbackMode">
                          <span class="legend-item"><i class="legend-dot current" /> Current</span>
                          <span class="legend-item"><i class="legend-dot answered" /> Answered (locked)</span>
                        </template>
                        <template v-else>
                          <span class="legend-item"><i class="legend-dot current-outline" /> Current</span>
                          <span class="legend-item"><i class="legend-dot correct" /> Correct</span>
                          <span class="legend-item"><i class="legend-dot incorrect" /> Incorrect</span>
                          <span class="legend-item"><i class="legend-dot missed" /> Missed</span>
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

                        <div v-if="!isStudentExamSubmitted && isStudentTeacherPacedMode" class="feedback info">
                          <template v-if="isTeacherPacedSessionActive">
                            <CheckCircle2 :size="15" />
                            <span>
                              Teacher Paced is active.
                              {{
                                currentTeacherPacedItemNumber
                                  ? `Current class item: ${currentTeacherPacedItemNumber}`
                                  : 'Waiting for current item...'
                              }}
                            </span>
                          </template>
                          <template v-else>
                            <AlertCircle :size="15" />
                            <span>Teacher Paced is enabled. Wait for your teacher to start the session.</span>
                          </template>
                        </div>

                        <div v-if="!isStudentExamSubmitted && isStudentInstantFeedbackMode" class="feedback info">
                          <CheckCircle2 :size="15" />
                          <span>Instant Feedback mode: answer in order. Saved answers are locked.</span>
                        </div>

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
                            :class="{ selected: studentAnswerDraft.selected_option_id === option.id }"
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

                        <div
                          v-if="isStudentExamSubmitted || (isStudentInstantFeedbackMode && currentStudentExamQuestion.answer?.is_correct !== null)"
                          class="exam-attempt-review"
                        >
                          <p>
                            Your answer:
                            <strong>
                              {{
                                currentStudentExamQuestion.answer?.answer_text
                                  || currentStudentExamQuestion.answer?.selected_option_id
                                  || 'No answer'
                              }}
                            </strong>
                          </p>
                          <p v-if="currentStudentExamQuestion.correct_answer?.label || currentStudentExamQuestion.correct_answer?.text">
                            Correct answer:
                            <strong>
                              {{ currentStudentExamQuestion.correct_answer?.label || '' }}
                              <span v-if="currentStudentExamQuestion.correct_answer?.text">
                                {{ currentStudentExamQuestion.correct_answer.text }}
                              </span>
                            </strong>
                          </p>
                          <p>
                            Verdict:
                            <strong
                              :class="{
                                ok: currentStudentExamQuestion.answer?.is_correct === true,
                                danger: currentStudentExamQuestion.answer?.is_correct === false,
                              }"
                            >
                              {{
                                currentStudentExamQuestion.answer?.is_correct === true
                                  ? 'Correct'
                                  : (currentStudentExamQuestion.answer?.is_correct === false ? 'Incorrect' : 'Not Answered')
                              }}
                            </strong>
                          </p>
                        </div>
                      </article>

                      <div class="exam-attempt-footer">
                        <button
                          v-if="!isStudentExamSubmitted && currentStudentExamQuestion && isStudentOpenNavigationMode"
                          type="button"
                          class="ghost-btn bookmark-toggle-btn"
                          :class="{ 'bookmark-active': currentStudentExamQuestion.is_bookmarked }"
                          :disabled="studentExamSaving || studentExamSubmitting || studentExamBookmarking"
                          @click="toggleCurrentQuestionBookmark"
                        >
                          <span>{{ currentStudentExamQuestion.is_bookmarked ? 'Remove Bookmark' : 'Bookmark' }}</span>
                        </button>

                        <div v-if="isStudentOpenNavigationMode" class="exam-attempt-nav">
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

                        <div v-else-if="!isStudentExamSubmitted && isStudentInstantFeedbackMode" class="exam-attempt-nav">
                          <button
                            type="button"
                            class="ghost-btn"
                            :disabled="
                              studentExamSaving
                                || studentExamSubmitting
                                || studentExamCurrentIndex >= studentExamQuestions.length - 1
                                || !questionHasAnswer(currentStudentExamQuestion)
                            "
                            @click="goToStudentExamQuestion(1)"
                          >
                            Next Question
                          </button>
                        </div>

                        <button
                          v-if="!isStudentExamSubmitted"
                          type="button"
                          class="primary-btn"
                          :disabled="studentExamSubmitting || (isStudentInstantFeedbackMode && studentExamUnansweredCount > 0)"
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
                      :disabled="studentExamSubmitting || (isStudentInstantFeedbackMode && studentExamUnansweredCount > 0)"
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
          </template>
        </section>

        <section v-else-if="activeNav === 'library'" class="library-view">
          <div v-if="libraryMessage" class="feedback success">
            <CheckCircle2 :size="15" />
            <span>{{ libraryMessage }}</span>
          </div>
          <div v-if="libraryError" class="feedback danger">
            <AlertCircle :size="15" />
            <span>{{ libraryError }}</span>
          </div>

          <article class="surface-card room-shell-card">
            <header class="room-page-head">
              <div class="room-page-title">
                <BookOpen :size="18" />
                <h3>Library</h3>
              </div>
              <button type="button" class="primary-btn add-room-btn" @click="openLibraryQuestionModal">
                <Plus :size="16" />
                Add Questions
              </button>
            </header>

            <div v-if="libraryLoading && libraryQuestionBanks.length === 0" class="room-empty-state">
              <RefreshCw :size="34" class="spin-soft" />
              <h4>Loading question banks</h4>
              <p>Please wait while we fetch your library entries.</p>
            </div>

            <div v-else-if="libraryQuestionBanks.length === 0" class="room-empty-state">
              <BookOpen :size="42" />
              <h4>No Question Banks Yet</h4>
              <p>Upload a DOCX to convert and save your question set in the library.</p>
            </div>

            <div v-else class="management-list">
              <article v-for="bank in libraryQuestionBanks" :key="bank.id" class="management-item">
                <div class="management-item-main">
                  <strong>{{ bank.title }}</strong>
                  <p>{{ bank.subject || 'General' }} • {{ bank.total_items }} items</p>
                  <div class="management-inline">
                    <span class="pill neutral">{{ bank.questions_count ?? bank.total_items }} question(s)</span>
                    <span v-if="bank.creator?.name" class="pill navy">By {{ bank.creator.name }}</span>
                    <span v-if="bank.source_filename" class="pill success">{{ bank.source_filename }}</span>
                  </div>
                  <p class="muted">Updated {{ formatDateTime(bank.updated_at) }}</p>
                </div>

                <div class="management-actions">
                  <button
                    type="button"
                    class="danger-btn"
                    :disabled="libraryDeleting"
                    @click="openDeleteLibraryBankModal(bank)"
                  >
                    <Trash2 :size="15" />
                    Delete
                  </button>
                </div>
              </article>
            </div>
          </article>

          <teleport to="body">
            <div v-if="showLibraryQuestionModal" class="modal-backdrop" @click.self="closeLibraryQuestionModal">
              <div class="modal-card library-modal-card">
                <header class="modal-head library-modal-head">
                  <div class="library-modal-title-wrap">
                    <h4>Add Questions</h4>
                    <p>Upload your DOCX and review digitalized questions before saving.</p>
                  </div>
                  <button type="button" class="modal-close" @click="closeLibraryQuestionModal">
                    <X :size="16" />
                  </button>
                </header>

                <div class="library-modal-body">
                  <section class="library-form-panel">
                    <label class="field-stack">
                      <span class="field-label">Question Name</span>
                      <input
                        v-model.trim="libraryForm.questionName"
                        type="text"
                        class="text-input"
                        maxlength="255"
                        placeholder="e.g. Mock Exam - Library Science Set A"
                      />
                    </label>

                    <label class="field-stack">
                      <span class="field-label">Subject Category</span>
                      <select v-model="libraryForm.subjectCategory" class="text-input">
                        <option disabled value="">Select subject category</option>
                        <option v-for="subject in librarySubjectCategories" :key="subject" :value="subject">
                          {{ subject }}
                        </option>
                      </select>
                    </label>

                    <label class="field-stack">
                      <span class="field-label">Upload a DOCX file (Questions)</span>
                      <div class="library-upload-panel">
                        <input
                          :key="libraryFileInputKey"
                          type="file"
                          accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                          class="file-input"
                          :disabled="libraryParsing"
                          @change="handleLibraryDocxChange"
                        />
                        <p class="library-upload-note">
                          Use numbered questions with optional choices like <code>A.</code>, <code>B.</code>, <code>C.</code>.
                        </p>
                      </div>
                    </label>

                    <p v-if="libraryDocxName" class="library-file-chip">{{ libraryDocxName }}</p>

                    <div v-if="libraryParsing" class="feedback info">
                      <RefreshCw :size="15" class="spin-soft" />
                      <span>Converting DOCX to digitalized questions...</span>
                    </div>

                    <div v-if="libraryParseError" class="feedback danger">
                      <AlertCircle :size="15" />
                      <span>{{ libraryParseError }}</span>
                    </div>

                    <div v-if="libraryPreviewWarnings.length > 0" class="library-warning-list">
                      <p class="warning-list-title">Parser Notes</p>
                      <ul>
                        <li v-for="(warning, warningIndex) in libraryPreviewWarnings" :key="`warning-${warningIndex}`">
                          {{ warning }}
                        </li>
                      </ul>
                    </div>
                  </section>

                  <section class="digitalized-preview">
                    <header class="digitalized-head">
                      <h5>Digitalized Questions</h5>
                      <span class="pill neutral">{{ digitalizedQuestions.length }} items</span>
                    </header>

                    <div v-if="digitalizedQuestions.length === 0" class="digitalized-empty">
                      <FileText :size="30" />
                      <p>Converted questions will appear here after DOCX upload.</p>
                    </div>

                    <div v-else class="digitalized-list">
                      <article
                        v-for="(question, index) in digitalizedQuestions"
                        :key="`${question.id}-${index}`"
                        class="digitalized-card"
                      >
                        <p class="digitalized-question">{{ index + 1 }}. {{ question.text }}</p>

                        <div v-if="question.options.length > 0" class="digitalized-options">
                          <label
                            v-for="(option, optionIndex) in question.options"
                            :key="optionIndex"
                            class="digitalized-option"
                            :class="{ correct: option.is_correct }"
                          >
                            <input type="radio" :name="`preview-question-${index}`" :checked="option.is_correct" disabled />
                            <span>{{ option.label }}. {{ option.text }}</span>
                          </label>
                        </div>

                        <p v-else class="digitalized-open-ended">Open-ended response</p>

                        <p v-if="question.answer_label" class="digitalized-answer">
                          Answer: {{ question.answer_label }}<span v-if="question.answer_text">. {{ question.answer_text }}</span>
                        </p>
                      </article>
                    </div>
                  </section>
                </div>

                <div class="modal-actions library-modal-actions">
                  <button type="button" class="ghost-btn" :disabled="librarySaving" @click="closeLibraryQuestionModal">Close</button>
                  <button
                    type="button"
                    class="primary-btn"
                    :disabled="!canSaveLibraryQuestionBank"
                    @click="handleSaveLibraryQuestionBank"
                  >
                    <RefreshCw v-if="librarySaving" :size="16" class="spin-soft" />
                    <Plus v-else :size="16" />
                    {{ librarySaving ? 'Saving...' : 'Save Question Set' }}
                  </button>
                </div>
              </div>
            </div>
          </teleport>

          <teleport to="body">
            <div v-if="showDeleteLibraryBankModal" class="modal-backdrop" @click.self="closeDeleteLibraryBankModal">
              <div class="modal-card">
                <header class="modal-head">
                  <h4>Delete Question Set</h4>
                  <button type="button" class="modal-close" @click="closeDeleteLibraryBankModal">
                    <X :size="16" />
                  </button>
                </header>

                <p class="muted">
                  Delete <strong>{{ selectedLibraryBank?.title }}</strong>? This will also unlink it from exams that reference it.
                </p>
                <p class="muted">
                  This action is blocked if the set already has exam attempt records.
                </p>

                <div class="modal-actions">
                  <button type="button" class="ghost-btn" :disabled="libraryDeleting" @click="closeDeleteLibraryBankModal">
                    Cancel
                  </button>
                  <button type="button" class="danger-btn" :disabled="libraryDeleting" @click="handleDeleteLibraryBank">
                    <RefreshCw v-if="libraryDeleting" :size="14" class="spin-soft" />
                    <Trash2 v-else :size="14" />
                    <span>{{ libraryDeleting ? 'Deleting...' : 'Delete Question Set' }}</span>
                  </button>
                </div>
              </div>
            </div>
          </teleport>
        </section>

        <section v-else-if="activeNav === 'exams'" class="room-view">
          <div v-if="examMessage" class="feedback success">
            <CheckCircle2 :size="15" />
            <span>{{ examMessage }}</span>
          </div>
          <div v-if="examError" class="feedback danger">
            <AlertCircle :size="15" />
            <span>{{ examError }}</span>
          </div>

          <article class="surface-card room-shell-card">
            <header class="room-page-head">
              <div class="room-page-title">
                <FileText :size="18" />
                <h3>Exams</h3>
              </div>
              <button type="button" class="primary-btn add-room-btn" :disabled="examLoading" @click="openCreateExamModal">
                <Plus :size="16" />
                Add Exam
              </button>
            </header>

            <div v-if="examLoading && exams.length === 0" class="room-empty-state">
              <RefreshCw :size="34" class="spin-soft" />
              <h4>Loading exams</h4>
              <p>Please wait while we fetch exam records.</p>
            </div>

            <div v-else-if="exams.length === 0" class="room-empty-state">
              <FileText :size="42" />
              <h4>No Exams Yet</h4>
              <p>Create an exam and assign it to one or more rooms.</p>
            </div>

            <div v-else class="management-list">
              <article v-for="exam in exams" :key="exam.id" class="management-item">
                <div class="management-item-main">
                  <strong>{{ exam.title }}</strong>
                  <p>{{ exam.question_bank?.subject || exam.subject || 'General' }} • {{ exam.total_items }} items • {{ exam.duration_minutes }} mins</p>
                  <div class="management-inline">
                    <span class="pill navy">{{ exam.rooms_count ?? exam.rooms?.length ?? 0 }} room(s)</span>
                    <span class="pill neutral">{{ examDeliveryModeLabel(exam.delivery_mode) }}</span>
                    <span class="pill" :class="exam.one_take_only ? 'success' : 'neutral'">
                      {{ exam.one_take_only ? 'One Take Only' : 'Retake Allowed' }}
                    </span>
                    <span class="pill" :class="exam.shuffle_questions ? 'navy' : 'neutral'">
                      {{ exam.shuffle_questions ? 'Shuffled Items' : 'Fixed Order' }}
                    </span>
                    <span v-if="exam.creator?.name" class="pill success">By {{ exam.creator.name }}</span>
                  </div>
                  <p class="muted">Question Set: {{ exam.question_bank?.title || 'Not linked yet' }}</p>
                  <p v-if="exam.description" class="muted">{{ exam.description }}</p>
                  <p class="muted">Schedule: {{ formatExamSchedule(exam.scheduled_at) }}</p>
                </div>

                <div class="management-actions">
                  <button type="button" class="ghost-btn" @click="openEditExamModal(exam)">
                    <Pencil :size="15" />
                    Edit
                  </button>
                  <button type="button" class="danger-btn" @click="openDeleteExamModal(exam)">
                    <Trash2 :size="15" />
                    Delete
                  </button>
                </div>
              </article>
            </div>
          </article>

          <teleport to="body">
            <div v-if="showExamModal" class="modal-backdrop" @click.self="closeExamModal">
              <div class="modal-card">
                <header class="modal-head">
                  <h4>{{ examForm.id ? 'Edit Exam' : 'Create Exam' }}</h4>
                  <button type="button" class="modal-close" @click="closeExamModal">
                    <X :size="16" />
                  </button>
                </header>

                <label class="field-stack">
                  <span class="field-label">Title</span>
                  <input v-model.trim="examForm.title" type="text" class="text-input" maxlength="255" />
                </label>

                <div class="inline-form exam-meta-row">
                  <label class="field-stack narrow">
                    <span class="field-label">Items</span>
                    <input v-model.number="examForm.total_items" type="number" min="1" max="1000" class="text-input" />
                  </label>
                  <label class="field-stack narrow">
                    <span class="field-label">Minutes</span>
                    <input v-model.number="examForm.duration_minutes" type="number" min="1" max="600" class="text-input" />
                  </label>
                </div>

                <label class="field-stack">
                  <span class="field-label">Question Set</span>
                  <select v-model="examForm.question_bank_id" class="text-input">
                    <option :value="null">No question set linked</option>
                    <option v-for="bank in examQuestionBanks" :key="bank.id" :value="bank.id">
                      {{ bank.title }} • {{ bank.total_items }} items
                    </option>
                  </select>
                  <small v-if="examQuestionBanks.length === 0" class="muted">No saved question sets yet. Add one from Library first.</small>
                  <small class="muted">Link a question set so students can attempt this exam.</small>
                </label>

                <label class="field-stack">
                  <span class="field-label">Schedule (optional)</span>
                  <input v-model="examForm.scheduled_at" type="datetime-local" class="text-input" />
                  <small class="muted">Students can take this exam at or after the set schedule.</small>
                </label>

                <label class="field-stack">
                  <span class="field-label">Quiz Delivery Mode</span>
                  <select v-model="examForm.delivery_mode" class="text-input">
                    <option value="open_navigation">Open Navigation (students can skip and review)</option>
                    <option value="teacher_paced">Teacher Paced (teacher controls current question)</option>
                    <option value="instant_feedback">Instant Feedback (immediate result, no skipping/editing)</option>
                  </select>
                </label>

                <div class="field-stack">
                  <span class="field-label">Exam Attempt Options</span>
                  <div class="check-grid">
                    <label class="check-item">
                      <input v-model="examForm.one_take_only" type="checkbox" />
                      <span>One take only (students cannot retake after submitting)</span>
                    </label>
                    <label class="check-item">
                      <input
                        v-model="examForm.shuffle_questions"
                        type="checkbox"
                        :disabled="normalizeExamDeliveryMode(examForm.delivery_mode) === 'teacher_paced'"
                      />
                      <span>
                        {{
                          normalizeExamDeliveryMode(examForm.delivery_mode) === 'teacher_paced'
                            ? 'Shuffle disabled in Teacher Paced mode'
                            : 'Shuffle questions for each attempt'
                        }}
                      </span>
                    </label>
                  </div>
                </div>

                <label class="field-stack">
                  <span class="field-label">Description</span>
                  <textarea v-model.trim="examForm.description" class="text-input textarea-input" rows="3" />
                </label>

                <div class="field-stack">
                  <span class="field-label">Assign to rooms</span>
                  <div v-if="manageableRooms.length === 0" class="muted">No rooms available for assignment.</div>
                  <div v-else class="check-grid">
                    <label v-for="room in manageableRooms" :key="room.id" class="check-item">
                      <input v-model="examForm.room_ids" type="checkbox" :value="room.id" />
                      <span>{{ room.name }} <small>({{ room.code }})</small></span>
                    </label>
                  </div>
                </div>

                <div class="modal-actions">
                  <button type="button" class="ghost-btn" :disabled="examSaving" @click="closeExamModal">Cancel</button>
                  <button
                    type="button"
                    class="primary-btn"
                    :disabled="examSaving || !examForm.title.trim() || examForm.total_items < 1 || examForm.duration_minutes < 1"
                    @click="handleSaveExam"
                  >
                    <RefreshCw v-if="examSaving" :size="15" class="spin-soft" />
                    <span>{{ examSaving ? 'Saving...' : (examForm.id ? 'Update Exam' : 'Create Exam') }}</span>
                  </button>
                </div>
              </div>
            </div>
          </teleport>

          <teleport to="body">
            <div v-if="showDeleteExamModal" class="modal-backdrop" @click.self="closeDeleteExamModal">
              <div class="modal-card">
                <header class="modal-head">
                  <h4>Delete Exam</h4>
                  <button type="button" class="modal-close" @click="closeDeleteExamModal">
                    <X :size="16" />
                  </button>
                </header>

                <p class="muted">
                  Delete <strong>{{ selectedExam?.title }}</strong>? This also removes its room assignments.
                </p>

                <div class="modal-actions">
                  <button type="button" class="ghost-btn" :disabled="examSaving" @click="closeDeleteExamModal">Cancel</button>
                  <button type="button" class="danger-btn" :disabled="examSaving" @click="handleDeleteExam">
                    <Trash2 :size="15" />
                    Delete
                  </button>
                </div>
              </div>
            </div>
          </teleport>

        </section>

        <section v-else-if="activeNav === 'reports'" class="dashboard-view">
          <div v-if="reportError" class="feedback danger">
            <AlertCircle :size="15" />
            <span>{{ reportError }}</span>
          </div>

          <div class="stats-grid">
            <article class="stat-card" v-for="card in reportMetricCards" :key="card.label">
              <div class="stat-icon" :class="`tone-${card.tone}`">
                <component :is="card.icon" :size="17" />
              </div>
              <div>
                <p class="stat-label">{{ card.label }}</p>
                <p class="stat-value">{{ card.value }}</p>
                <p class="stat-trend" :class="{ positive: card.positive, negative: !card.positive }">
                  {{ card.trend }}
                </p>
              </div>
            </article>
          </div>

          <article class="surface-card">
            <header class="surface-head">
              <h3>Recent Activity</h3>
              <button type="button" class="ghost-btn" :disabled="reportLoading" @click="loadReports">
                <RefreshCw :size="14" :class="{ 'spin-soft': reportLoading }" />
                Refresh
              </button>
            </header>

            <div v-if="reportLoading" class="room-detail-loading">
              <RefreshCw :size="15" class="spin-soft" />
              <span>Loading report data...</span>
            </div>

            <div v-else-if="reportActivity.length === 0" class="room-detail-empty">
              <div>
                <h4>No activity yet</h4>
                <p>Recent operational logs will appear here.</p>
              </div>
            </div>

            <div v-else class="activity-list">
              <div v-for="activity in reportActivity" :key="activity.id" class="activity-item">
                <span class="activity-dot ok" />
                <div class="activity-content">
                  <strong>{{ activity.description || activity.action }}</strong>
                  <small>{{ formatDateTime(activity.created_at) }} • {{ activity.actor?.name || 'System' }}</small>
                </div>
                <strong class="ok">{{ activity.action }}</strong>
              </div>
            </div>
          </article>
        </section>

        <section v-else-if="activeNav === 'settings'" class="room-view">
          <div v-if="settingsMessage" class="feedback success">
            <CheckCircle2 :size="15" />
            <span>{{ settingsMessage }}</span>
          </div>
          <div v-if="settingsError" class="feedback danger">
            <AlertCircle :size="15" />
            <span>{{ settingsError }}</span>
          </div>

          <article class="surface-card">
            <header class="surface-head">
              <h3>System Settings</h3>
              <span class="pill" :class="settingsCanEdit ? 'success' : 'neutral'">
                {{ settingsCanEdit ? 'Admin editable' : 'Read only' }}
              </span>
            </header>

            <div v-if="settingsLoading" class="room-detail-loading">
              <RefreshCw :size="15" class="spin-soft" />
              <span>Loading settings...</span>
            </div>

            <template v-else>
              <div class="settings-grid">
                <label class="field-stack">
                  <span class="field-label">Platform Name</span>
                  <input v-model.trim="settingsForm.platform_name" type="text" class="text-input" :disabled="!settingsCanEdit" />
                </label>

                <label class="field-stack">
                  <span class="field-label">Academic Term</span>
                  <input v-model.trim="settingsForm.academic_term" type="text" class="text-input" :disabled="!settingsCanEdit" />
                </label>

                <label class="check-item">
                  <input v-model="settingsForm.allow_public_registration" type="checkbox" :disabled="!settingsCanEdit" />
                  <span>Allow public student registration</span>
                </label>

                <label class="check-item">
                  <input v-model="settingsForm.maintenance_mode" type="checkbox" :disabled="!settingsCanEdit" />
                  <span>Enable maintenance mode</span>
                </label>

                <label class="field-stack">
                  <span class="field-label">Announcement Banner</span>
                  <textarea
                    v-model.trim="settingsForm.announcement_banner"
                    rows="3"
                    class="text-input textarea-input"
                    :disabled="!settingsCanEdit"
                  />
                </label>
              </div>

              <div class="modal-actions">
                <button type="button" class="ghost-btn" :disabled="settingsLoading" @click="loadSystemSettings">Reload</button>
                <button type="button" class="primary-btn" :disabled="settingsSaving || !settingsCanEdit" @click="saveSystemSettings">
                  <RefreshCw v-if="settingsSaving" :size="14" class="spin-soft" />
                  <span>{{ settingsSaving ? 'Saving...' : 'Save Settings' }}</span>
                </button>
              </div>
            </template>
          </article>
        </section>

        <section v-else-if="activeNav === 'users' && isAdminRole" class="room-view">
          <div v-if="usersMessage" class="feedback success">
            <CheckCircle2 :size="15" />
            <span>{{ usersMessage }}</span>
          </div>
          <div v-if="usersError" class="feedback danger">
            <AlertCircle :size="15" />
            <span>{{ usersError }}</span>
          </div>

          <article class="surface-card room-shell-card">
            <header class="room-page-head">
              <div class="room-page-title">
                <UserRound :size="18" />
                <h3>Users</h3>
              </div>
              <button type="button" class="primary-btn add-room-btn" @click="openCreateUserModal">
                <Plus :size="16" />
                Add User
              </button>
            </header>

            <div class="management-toolbar">
              <input v-model.trim="userFilters.search" type="text" class="text-input" placeholder="Search name, email, or student ID" />
              <select v-model="userFilters.role" class="text-input narrow">
                <option value="">All roles</option>
                <option value="student">Student</option>
                <option value="staff_master_examiner">Staff / Master Examiner</option>
                <option value="admin">Admin</option>
              </select>
              <select v-model="userFilters.status" class="text-input narrow">
                <option value="">All status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
              <button type="button" class="ghost-btn" :disabled="usersLoading" @click="loadAdminUsers">
                <RefreshCw :size="14" :class="{ 'spin-soft': usersLoading }" />
                Apply
              </button>
            </div>

            <div v-if="usersLoading && adminUsers.length === 0" class="room-empty-state">
              <RefreshCw :size="34" class="spin-soft" />
              <h4>Loading users</h4>
              <p>Fetching accounts and role assignments.</p>
            </div>

            <div v-else-if="adminUsers.length === 0" class="room-empty-state">
              <UserRound :size="42" />
              <h4>No users found</h4>
              <p>Adjust filters or create a new account.</p>
            </div>

            <div v-else class="management-list">
              <article v-for="user in adminUsers" :key="user.id" class="management-item">
                <div class="management-item-main">
                  <strong>{{ user.name }}</strong>
                  <p>{{ user.email }}</p>
                  <p v-if="user.student_id" class="muted">Student ID: {{ user.student_id }}</p>
                  <div class="management-inline">
                    <span class="pill neutral">{{ displayMemberRole(user.role) }}</span>
                    <span class="pill" :class="user.is_active ? 'success' : 'neutral'">
                      {{ user.is_active ? 'Active' : 'Inactive' }}
                    </span>
                    <span class="pill navy">ID #{{ user.id }}</span>
                  </div>
                </div>
                <div class="management-actions">
                  <button type="button" class="ghost-btn" @click="openEditUserModal(user)">
                    <Pencil :size="15" />
                    Edit
                  </button>
                </div>
              </article>
            </div>
          </article>

          <teleport to="body">
            <div v-if="showUserModal" class="modal-backdrop" @click.self="closeUserModal">
              <div class="modal-card">
                <header class="modal-head">
                  <h4>{{ userForm.id ? 'Edit User' : 'Create User' }}</h4>
                  <button type="button" class="modal-close" @click="closeUserModal">
                    <X :size="16" />
                  </button>
                </header>

                <label class="field-stack">
                  <span class="field-label">Full Name</span>
                  <input v-model.trim="userForm.name" type="text" class="text-input" maxlength="255" />
                </label>

                <label class="field-stack">
                  <span class="field-label">Email</span>
                  <input v-model.trim="userForm.email" type="email" class="text-input" maxlength="255" />
                </label>

                <label class="field-stack">
                  <span class="field-label">Student ID</span>
                  <input
                    v-model.trim="userForm.student_id"
                    type="text"
                    class="text-input"
                    maxlength="32"
                    inputmode="numeric"
                    placeholder="e.g. 2301290"
                  />
                  <small class="muted">Required for student accounts. Leave blank for staff/admin.</small>
                </label>

                <div class="inline-form">
                  <label class="field-stack grow">
                    <span class="field-label">Role</span>
                    <select v-model="userForm.role" class="text-input">
                      <option value="student">Student</option>
                      <option value="staff_master_examiner">Staff / Master Examiner</option>
                      <option value="admin">Admin</option>
                    </select>
                  </label>
                  <label class="check-item">
                    <input v-model="userForm.is_active" type="checkbox" />
                    <span>Account active</span>
                  </label>
                </div>

                <label class="field-stack">
                  <span class="field-label">{{ userForm.id ? 'New Password (optional)' : 'Password' }}</span>
                  <input v-model="userForm.password" type="password" class="text-input" minlength="8" />
                </label>

                <div class="modal-actions">
                  <button type="button" class="ghost-btn" :disabled="usersSaving" @click="closeUserModal">Cancel</button>
                  <button
                    type="button"
                    class="primary-btn"
                    :disabled="usersSaving || !userForm.name.trim() || !userForm.email.trim() || (userForm.role === 'student' && !userForm.student_id.trim()) || (!userForm.id && userForm.password.length < 8)"
                    @click="handleSaveUser"
                  >
                    <RefreshCw v-if="usersSaving" :size="14" class="spin-soft" />
                    <span>{{ usersSaving ? 'Saving...' : (userForm.id ? 'Update User' : 'Create User') }}</span>
                  </button>
                </div>
              </div>
            </div>
          </teleport>
        </section>

        <section v-else-if="activeNav === 'audit' && isAdminRole" class="room-view">
          <div v-if="auditError" class="feedback danger">
            <AlertCircle :size="15" />
            <span>{{ auditError }}</span>
          </div>

          <article class="surface-card room-shell-card">
            <header class="room-page-head">
              <div class="room-page-title">
                <ClipboardList :size="18" />
                <h3>Audit Logs</h3>
              </div>
              <button type="button" class="ghost-btn" :disabled="auditLoading" @click="loadAuditLogs">
                <RefreshCw :size="14" :class="{ 'spin-soft': auditLoading }" />
                Refresh
              </button>
            </header>

            <div v-if="auditLoading && auditLogs.length === 0" class="room-empty-state">
              <RefreshCw :size="34" class="spin-soft" />
              <h4>Loading logs</h4>
              <p>Retrieving recent system actions.</p>
            </div>

            <div v-else-if="auditLogs.length === 0" class="room-empty-state">
              <ClipboardList :size="42" />
              <h4>No logs yet</h4>
              <p>Audit records will appear after system activity occurs.</p>
            </div>

            <div v-else class="management-list">
              <article v-for="log in auditLogs" :key="log.id" class="management-item">
                <div class="management-item-main">
                  <strong>{{ log.description || log.action }}</strong>
                  <p>{{ log.action }} • {{ formatDateTime(log.created_at) }}</p>
                  <div class="management-inline">
                    <span class="pill navy">{{ log.target_type || 'system' }}</span>
                    <span class="pill neutral">Target #{{ log.target_id || 'n/a' }}</span>
                    <span class="pill success">{{ log.actor?.name || 'System' }}</span>
                  </div>
                </div>
              </article>
            </div>
          </article>
        </section>

        <section v-else class="placeholder-view">
          <article class="surface-card placeholder-card">
            <component :is="currentPage.icon" :size="40" />
            <h3>{{ currentPage.title }}</h3>
            <p>This module is ready for content implementation.</p>
          </article>
        </section>
      </main>
    </section>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch, watchEffect } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/store/auth.store'
import {
  examDeliveryModeLabel,
  formatClockTime,
  formatDateTime,
  formatExamSchedule,
  formatRemainingDuration,
  normalizeExamDeliveryMode,
  parseDateTime,
} from './composables/useDashboardFormatters'
import { useLibraryManager } from './composables/useLibraryManager'
import { useDashboardDataServices } from './composables/useDashboardDataServices'
import {
  DASHBOARD_ACTIVITIES,
  DASHBOARD_SCORE_HISTORY,
  DASHBOARD_SUBJECTS,
} from '@/constants/dashboardAnalytics'
import {
  AlertCircle,
  BarChart3,
  Bell,
  BookOpen,
  CalendarDays,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Clock3,
  ClipboardList,
  DoorOpen,
  FileText,
  Gauge,
  GraduationCap,
  House,
  LayoutDashboard,
  LogOut,
  Pencil,
  Plus,
  RefreshCw,
  Settings,
  ShieldCheck,
  Trash2,
  UserRound,
  X,
} from 'lucide-vue-next'

const props = defineProps({
  forcedNav: {
    type: String,
    default: '',
  },
  embedded: {
    type: Boolean,
    default: false,
  },
})

const auth = useAuthStore()
const router = useRouter()
const services = useDashboardDataServices()

const sidebarCollapsed = ref(false)
const DASHBOARD_NAV_STORAGE_KEY = 'blis.dashboard.active_nav'

function readPersistedDashboardNav() {
  if (typeof window === 'undefined') return ''
  return window.localStorage.getItem(DASHBOARD_NAV_STORAGE_KEY) ?? ''
}

const hasForcedNav = computed(() => String(props.forcedNav ?? '').trim().length > 0)
const activeNav = ref(hasForcedNav.value ? props.forcedNav : readPersistedDashboardNav())

const roomName = ref('')
const joinCode = ref('')
const rooms = ref([])
const selectedRoomId = ref(null)
const selectedRoom = ref(null)
const showCreateRoomModal = ref(false)
const showJoinRoomModal = ref(false)
const showEditRoomModal = ref(false)
const showDeleteRoomModal = ref(false)
const showLeaveRoomModal = ref(false)
const editRoomName = ref('')
const roomLoading = ref(false)
const roomDetailsLoading = ref(false)
const roomError = ref('')
const roomMessage = ref('')

const exams = ref([])
const manageableRooms = ref([])
const examQuestionBanks = ref([])
const examLoading = ref(false)
const examSaving = ref(false)
const examError = ref('')
const examMessage = ref('')
const showExamModal = ref(false)
const showDeleteExamModal = ref(false)
const roomLiveBoardActive = ref(false)
const selectedExam = ref(null)
const liveBoardExam = ref(null)
const liveBoardRoomId = ref(null)
const liveBoardRows = ref([])
const liveBoardItemSummary = ref([])
const liveBoardSummary = ref({
  students_total: 0,
  attempts_started: 0,
  attempts_submitted: 0,
})
const liveBoardLoading = ref(false)
const liveBoardRefreshing = ref(false)
const liveBoardError = ref('')
const liveBoardUpdatedAt = ref(null)
const liveBoardTeacherPacing = ref(null)
const liveBoardTeacherPacingBusy = ref(false)
const liveBoardOptions = reactive({
  show_names: true,
  show_responses: true,
  show_results: true,
})
const showExamSimulationModal = ref(false)
const showStudentSubmitConfirmModal = ref(false)
const showStudentExitConfirmModal = ref(false)
const selectedStudentExam = ref(null)
const studentExamAttempt = ref(null)
const studentExamQuestions = ref([])
const studentExamCurrentIndex = ref(0)
const studentExamLoading = ref(false)
const studentExamSaving = ref(false)
const studentExamBookmarking = ref(false)
const studentExamSubmitting = ref(false)
const studentExamError = ref('')
const studentExamRemainingSeconds = ref(null)
const studentExamVisitedQuestionIds = ref([])
const studentExamTeacherPacing = ref(null)
const studentAnswerDraft = reactive({
  selected_option_id: null,
  answer_text: '',
})
const examForm = reactive({
  id: null,
  title: '',
  description: '',
  question_bank_id: null,
  total_items: 60,
  duration_minutes: 90,
  scheduled_at: '',
  delivery_mode: 'open_navigation',
  one_take_only: false,
  shuffle_questions: false,
  room_ids: [],
})

const reportLoading = ref(false)
const reportError = ref('')
const reportMetrics = ref({})
const reportActivity = ref([])

const settingsLoading = ref(false)
const settingsSaving = ref(false)
const settingsError = ref('')
const settingsMessage = ref('')
const settingsCanEdit = ref(false)
const settingsForm = reactive({
  platform_name: '',
  academic_term: '',
  allow_public_registration: true,
  maintenance_mode: false,
  announcement_banner: '',
})

const adminUsers = ref([])
const usersLoading = ref(false)
const usersSaving = ref(false)
const usersError = ref('')
const usersMessage = ref('')
const showUserModal = ref(false)
const userFilters = reactive({
  search: '',
  role: '',
  status: '',
})
const userForm = reactive({
  id: null,
  name: '',
  email: '',
  student_id: '',
  role: 'student',
  is_active: true,
  password: '',
})

const auditLogs = ref([])
const auditLoading = ref(false)
const auditError = ref('')

let mobileMediaQuery
let studentExamTimerInterval
let studentExamSyncInterval
let liveBoardRefreshInterval
let studentExamSyncing = false

const normalizedRole = computed(() => String(auth.user?.role ?? 'student').toLowerCase())
const isManagementRole = computed(() => ['admin', 'staff_master_examiner'].includes(normalizedRole.value))
const isAdminRole = computed(() => normalizedRole.value === 'admin')
const isRoomPage = computed(() => ['room', 'rooms'].includes(activeNav.value))

const displayName = computed(() => auth.user?.name ?? 'User')
const displayRole = computed(() => {
  if (normalizedRole.value === 'staff_master_examiner') return 'Staff / Master Examiner'
  if (normalizedRole.value === 'admin') return 'Administrator'
  return 'Student'
})
const userInitials = computed(() => {
  const parts = displayName.value.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return 'U'
  if (parts.length === 1) return parts[0].slice(0, 1).toUpperCase()
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
})

const studentNavItems = [
  { key: 'dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { key: 'rooms', label: 'Rooms', icon: DoorOpen },
  { key: 'analytics', label: 'Analytics', icon: BarChart3 },
]

const staffNavItems = [
  { key: 'library', label: 'Library', icon: BookOpen },
  { key: 'room', label: 'Room', icon: DoorOpen },
  { key: 'exams', label: 'Exams', icon: FileText },
  { key: 'reports', label: 'Reports', icon: BarChart3 },
  { key: 'settings', label: 'Settings', icon: Settings },
]

const adminNavItems = [
  { key: 'users', label: 'Users', icon: UserRound },
  { key: 'library', label: 'Library', icon: BookOpen },
  { key: 'room', label: 'Room', icon: DoorOpen },
  { key: 'exams', label: 'Exams', icon: FileText },
  { key: 'reports', label: 'Reports', icon: BarChart3 },
  { key: 'settings', label: 'Settings', icon: Settings },
  { key: 'audit', label: 'Audit', icon: ClipboardList },
]

const navItems = computed(() => {
  if (!isManagementRole.value) return studentNavItems
  return isAdminRole.value ? adminNavItems : staffNavItems
})

const roomCollectionLabel = computed(() => {
  if (isAdminRole.value) return 'All rooms in the platform'
  if (isManagementRole.value) return "Rooms you've added"
  return 'Rooms joined'
})

const {
  showLibraryQuestionModal,
  showDeleteLibraryBankModal,
  libraryLoading,
  librarySaving,
  libraryDeleting,
  libraryError,
  libraryMessage,
  libraryParsing,
  libraryParseError,
  libraryDocxName,
  libraryFileInputKey,
  libraryQuestionBanks,
  selectedLibraryBank,
  libraryPreviewWarnings,
  digitalizedQuestions,
  libraryForm,
  librarySubjectCategories,
  canSaveLibraryQuestionBank,
  openLibraryQuestionModal,
  closeLibraryQuestionModal,
  openDeleteLibraryBankModal,
  closeDeleteLibraryBankModal,
  handleLibraryDocxChange,
  loadLibraryBanks,
  handleSaveLibraryQuestionBank,
  handleDeleteLibraryBank,
} = useLibraryManager({
  canManageLibraries: isManagementRole,
  parseApiError: firstApiError,
  onBanksChanged: fetchExamQuestionBanks,
})

const currentStudentExamQuestion = computed(() => (
  studentExamQuestions.value[studentExamCurrentIndex.value] ?? null
))

const currentQuestionStem = computed(() => (
  splitQuestionStemAndNumberedItems(currentStudentExamQuestion.value?.question_text ?? '')
))

const isStudentExamSubmitted = computed(() => (
  String(studentExamAttempt.value?.status ?? '') === 'submitted'
))

const currentStudentExamDeliveryMode = computed(() => (
  normalizeExamDeliveryMode(selectedStudentExam.value?.delivery_mode)
))

const isStudentOpenNavigationMode = computed(() => (
  currentStudentExamDeliveryMode.value === 'open_navigation'
))

const isStudentTeacherPacedMode = computed(() => (
  currentStudentExamDeliveryMode.value === 'teacher_paced'
))

const isStudentInstantFeedbackMode = computed(() => (
  currentStudentExamDeliveryMode.value === 'instant_feedback'
))

const currentTeacherPacedItemNumber = computed(() => {
  const itemNumber = Number(studentExamTeacherPacing.value?.current_item_number ?? 0)
  return Number.isFinite(itemNumber) && itemNumber > 0 ? itemNumber : null
})

const isTeacherPacedSessionActive = computed(() => (
  Boolean(studentExamTeacherPacing.value?.is_active)
))

const isCurrentTeacherPacedQuestion = computed(() => {
  if (!isStudentTeacherPacedMode.value) return true
  if (!isTeacherPacedSessionActive.value) return false

  return Number(currentStudentExamQuestion.value?.item_number) === currentTeacherPacedItemNumber.value
})

const isCurrentInstantFeedbackQuestionLocked = computed(() => (
  isStudentInstantFeedbackMode.value
    && !isStudentExamSubmitted.value
    && questionHasAnswer(currentStudentExamQuestion.value)
))

const isCurrentQuestionInputLocked = computed(() => (
  isStudentExamSubmitted.value
    || studentExamSaving.value
    || studentExamSubmitting.value
    || studentExamBookmarking.value
    || (isStudentTeacherPacedMode.value && !isCurrentTeacherPacedQuestion.value)
    || isCurrentInstantFeedbackQuestionLocked.value
))

const studentInstantNextRequiredIndex = computed(() => {
  if (!isStudentInstantFeedbackMode.value || isStudentExamSubmitted.value) {
    return studentExamQuestions.value.length - 1
  }

  const nextRequiredItem = Number(studentExamAttempt.value?.next_required_item_number ?? 0)
  if (!Number.isFinite(nextRequiredItem) || nextRequiredItem < 1) {
    return studentExamQuestions.value.length - 1
  }

  const index = studentExamQuestions.value.findIndex((question) => Number(question.item_number) === nextRequiredItem)
  return index >= 0 ? index : studentExamQuestions.value.length - 1
})

const studentExamUnansweredCount = computed(() => (
  studentExamQuestions.value.filter((question) => !questionHasAnswer(question)).length
))

const liveBoardRoom = computed(() => {
  const roomId = Number(liveBoardRoomId.value)
  if (!Number.isFinite(roomId) || roomId < 1) return null

  if (Number(selectedRoom.value?.id) === roomId) {
    return selectedRoom.value
  }

  return rooms.value.find((room) => Number(room.id) === roomId) ?? null
})

const liveBoardLastUpdatedText = computed(() => (
  liveBoardUpdatedAt.value ? formatClockTime(liveBoardUpdatedAt.value) : 'n/a'
))

const liveBoardDeliveryMode = computed(() => (
  normalizeExamDeliveryMode(liveBoardExam.value?.delivery_mode)
))

const isLiveBoardTeacherPaced = computed(() => (
  liveBoardDeliveryMode.value === 'teacher_paced'
))

watchEffect(() => {
  if (hasForcedNav.value) return
  if (!navItems.value.some((item) => item.key === activeNav.value)) {
    activeNav.value = navItems.value[0]?.key ?? ''
  }
})

watch(
  () => props.forcedNav,
  (value) => {
    if (!hasForcedNav.value) return
    activeNav.value = String(value ?? '').trim()
  },
  { immediate: true },
)

watch(
  () => activeNav.value,
  (value) => {
    if (hasForcedNav.value) return
    if (typeof window === 'undefined') return
    if (!value) return

    window.localStorage.setItem(DASHBOARD_NAV_STORAGE_KEY, value)
  },
)

function syncSidebarForViewport(eventOrQuery) {
  if (eventOrQuery.matches) {
    sidebarCollapsed.value = false
  }
}

onMounted(() => {
  if (props.embedded) return
  if (typeof window === 'undefined') return

  mobileMediaQuery = window.matchMedia('(max-width: 900px)')
  syncSidebarForViewport(mobileMediaQuery)

  if (typeof mobileMediaQuery.addEventListener === 'function') {
    mobileMediaQuery.addEventListener('change', syncSidebarForViewport)
    return
  }

  mobileMediaQuery.addListener(syncSidebarForViewport)
})

onBeforeUnmount(() => {
  clearStudentExamTimer()
  stopStudentExamAutoSync()
  stopLiveBoardAutoRefresh()

  if (!mobileMediaQuery) return

  if (typeof mobileMediaQuery.removeEventListener === 'function') {
    mobileMediaQuery.removeEventListener('change', syncSidebarForViewport)
    return
  }

  mobileMediaQuery.removeListener(syncSidebarForViewport)
})

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

function displayMemberRole(role) {
  const normalized = String(role ?? '').toLowerCase()
  if (normalized === 'admin') return 'Administrator'
  if (normalized === 'staff_master_examiner') return 'Staff / Master Examiner'
  return 'Student'
}

function splitQuestionStemAndNumberedItems(questionText) {
  const normalized = String(questionText ?? '').replace(/\s+/g, ' ').trim()
  if (!normalized) {
    return { leadText: '', numberedItems: [] }
  }

  const firstNumberMatch = /(?:^|\s)1\.\s+/.exec(normalized)
  if (!firstNumberMatch) {
    return { leadText: normalized, numberedItems: [] }
  }

  const startsWithSpace = firstNumberMatch[0].startsWith(' ')
  const itemBlockStart = (firstNumberMatch.index ?? 0) + (startsWithSpace ? 1 : 0)
  const leadText = normalized.slice(0, itemBlockStart).trim()
  if (!leadText) {
    return { leadText: normalized, numberedItems: [] }
  }

  const itemBlock = normalized.slice(itemBlockStart).trim()
  const itemMatches = itemBlock.matchAll(/(\d+)\.\s*(.+?)(?=(?:\s+\d+\.\s*)|$)/g)
  const parsedItems = Array.from(itemMatches).map((match) => ({
    number: Number(match[1]),
    text: String(match[2] ?? '').trim(),
  }))

  if (parsedItems.length < 2) {
    return { leadText: normalized, numberedItems: [] }
  }

  const isSequential = parsedItems.every((item, index) => (
    index === 0 ? item.number === 1 : item.number === parsedItems[index - 1].number + 1
  ))

  const hasMostlyStatementText = parsedItems.filter((item) => /[A-Za-z]/.test(item.text)).length >= Math.max(2, Math.ceil(parsedItems.length * 0.7))

  if (!isSequential || !hasMostlyStatementText) {
    return { leadText: normalized, numberedItems: [] }
  }

  return {
    leadText,
    numberedItems: parsedItems.map((item) => item.text),
  }
}

function toDateTimeLocalValue(value) {
  const parsed = parseDateTime(value)
  if (!parsed) return ''

  const pad = (part) => String(part).padStart(2, '0')

  return `${parsed.getFullYear()}-${pad(parsed.getMonth() + 1)}-${pad(parsed.getDate())}T${pad(parsed.getHours())}:${pad(parsed.getMinutes())}`
}

function toExamSchedulePayload(value) {
  if (!value) return null

  const parsed = parseDateTime(value)
  if (!parsed) return null

  return parsed.toISOString()
}

function canStudentTakeExam(exam) {
  if (!exam?.question_bank_id) return false

  const schedule = parseDateTime(exam?.scheduled_at)
  if (!schedule) return true

  return schedule.getTime() <= Date.now()
}

function studentExamAvailabilityText(exam) {
  if (!exam?.question_bank_id) return 'Not available (no question set linked)'
  if (canStudentTakeExam(exam)) return 'Available now'

  return `Available on ${formatExamSchedule(exam?.scheduled_at)}`
}

function clearStudentExamTimer() {
  if (!studentExamTimerInterval) return
  clearInterval(studentExamTimerInterval)
  studentExamTimerInterval = null
}

function startStudentExamTimer(remainingSeconds) {
  clearStudentExamTimer()

  if (!Number.isFinite(Number(remainingSeconds))) {
    studentExamRemainingSeconds.value = null
    return
  }

  studentExamRemainingSeconds.value = Math.max(0, Number(remainingSeconds))

  if (studentExamRemainingSeconds.value <= 0 || isStudentExamSubmitted.value) return

  studentExamTimerInterval = setInterval(() => {
    if (studentExamRemainingSeconds.value === null || studentExamRemainingSeconds.value <= 0) {
      clearStudentExamTimer()
      return
    }

    studentExamRemainingSeconds.value -= 1
  }, 1000)
}

function stopStudentExamAutoSync() {
  if (!studentExamSyncInterval) return
  clearInterval(studentExamSyncInterval)
  studentExamSyncInterval = null
  studentExamSyncing = false
}

async function refreshStudentExamAttemptStatus(silent = true) {
  if (!showExamSimulationModal.value || isStudentExamSubmitted.value) return
  if (studentExamLoading.value || studentExamSaving.value || studentExamSubmitting.value || studentExamBookmarking.value) return
  if (studentExamSyncing) return

  const attemptId = studentExamAttempt.value?.id
  if (!attemptId) return

  const currentQuestionId = currentStudentExamQuestion.value?.question_id ?? null
  studentExamSyncing = true

  try {
    const { data } = await services.getAttempt(attemptId)
    applyStudentAttemptPayload(data, currentQuestionId)
  } catch (error) {
    if (!silent) {
      studentExamError.value = firstApiError(error, 'Unable to refresh attempt status.')
    }
  } finally {
    studentExamSyncing = false
  }
}

function startStudentExamAutoSync() {
  stopStudentExamAutoSync()

  studentExamSyncInterval = setInterval(() => {
    refreshStudentExamAttemptStatus(true)
  }, 4000)
}

function syncStudentAnswerDraft() {
  const current = currentStudentExamQuestion.value

  if (!current) {
    studentAnswerDraft.selected_option_id = null
    studentAnswerDraft.answer_text = ''
    return
  }

  studentAnswerDraft.selected_option_id = current.answer?.selected_option_id ?? null
  studentAnswerDraft.answer_text = current.answer?.answer_text ?? ''
}

function questionHasAnswer(question) {
  if (!question) return false

  const hasSelectedOption = question.answer?.selected_option_id !== null && question.answer?.selected_option_id !== undefined
  const hasTextAnswer = String(question.answer?.answer_text ?? '').trim().length > 0

  return hasSelectedOption || hasTextAnswer
}

function isQuestionVisited(questionId) {
  return studentExamVisitedQuestionIds.value.includes(Number(questionId))
}

function markQuestionVisited(questionId) {
  const normalizedId = Number(questionId)
  if (!normalizedId) return
  if (isQuestionVisited(normalizedId)) return

  studentExamVisitedQuestionIds.value = [
    ...studentExamVisitedQuestionIds.value,
    normalizedId,
  ]
}

function markCurrentQuestionVisited() {
  const currentQuestionId = currentStudentExamQuestion.value?.question_id
  if (!currentQuestionId) return

  markQuestionVisited(currentQuestionId)
}

function questionPaletteStatus(question, index) {
  if (!question) return 'pre-pending'

  const hasAnswer = questionHasAnswer(question)

  if (isStudentExamSubmitted.value) {
    if (!hasAnswer) return 'post-missed'
    if (question.answer?.is_correct === true) return 'post-correct'
    if (question.answer?.is_correct === false) return 'post-incorrect'
    return 'post-answered'
  }

  if (studentExamCurrentIndex.value === index) return 'pre-current'
  if (hasAnswer) return 'pre-answered'
  if (isQuestionVisited(question.question_id)) return 'pre-blank'
  return 'pre-pending'
}

function questionPaletteClass(question, index) {
  const status = questionPaletteStatus(question, index)

  return {
    'is-current': studentExamCurrentIndex.value === index,
    'has-bookmark': Boolean(question?.is_bookmarked),
    'pre-current': status === 'pre-current',
    'pre-answered': status === 'pre-answered',
    'pre-blank': status === 'pre-blank',
    'pre-pending': status === 'pre-pending',
    'post-correct': status === 'post-correct',
    'post-incorrect': status === 'post-incorrect',
    'post-missed': status === 'post-missed',
    'post-answered': status === 'post-answered',
  }
}

function resetExamForm() {
  examForm.id = null
  examForm.title = ''
  examForm.description = ''
  examForm.question_bank_id = null
  examForm.total_items = 60
  examForm.duration_minutes = 90
  examForm.scheduled_at = ''
  examForm.delivery_mode = 'open_navigation'
  examForm.one_take_only = false
  examForm.shuffle_questions = false
  examForm.room_ids = []
}

function openCreateExamModal() {
  resetExamForm()
  examError.value = ''
  examMessage.value = ''
  showExamModal.value = true
}

function openEditExamModal(exam) {
  examForm.id = exam.id
  examForm.title = exam.title ?? ''
  examForm.description = exam.description ?? ''
  examForm.question_bank_id = exam.question_bank_id
    ? Number(exam.question_bank_id)
    : (exam.question_bank?.id ?? null)
  examForm.total_items = Number(exam.total_items ?? 1)
  examForm.duration_minutes = Number(exam.duration_minutes ?? 1)
  examForm.scheduled_at = toDateTimeLocalValue(exam.scheduled_at)
  examForm.delivery_mode = normalizeExamDeliveryMode(exam.delivery_mode)
  examForm.one_take_only = Boolean(exam.one_take_only)
  examForm.shuffle_questions = Boolean(exam.shuffle_questions)
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

function applyStudentAttemptPayload(payload, preferredQuestionId = null) {
  const previousVisitedQuestionIds = [...studentExamVisitedQuestionIds.value]
  const examPayload = payload?.exam
    ? {
        ...payload.exam,
        delivery_mode: normalizeExamDeliveryMode(payload.exam.delivery_mode),
      }
    : null

  studentExamAttempt.value = payload?.attempt ?? null
  selectedStudentExam.value = examPayload ?? selectedStudentExam.value
  studentExamQuestions.value = payload?.questions ?? []
  studentExamTeacherPacing.value = payload?.teacher_pacing ?? null

  const answeredQuestionIds = studentExamQuestions.value
    .filter((question) => questionHasAnswer(question))
    .map((question) => Number(question.question_id))

  studentExamVisitedQuestionIds.value = Array.from(new Set([
    ...previousVisitedQuestionIds,
    ...answeredQuestionIds,
  ]))

  const defaultIndex = studentExamQuestions.value.findIndex((question) => !question.answer?.selected_option_id && !question.answer?.answer_text)
  const preferredIndex = preferredQuestionId
    ? studentExamQuestions.value.findIndex((question) => question.question_id === preferredQuestionId)
    : -1
  const teacherPacedIndex = currentTeacherPacedItemNumber.value
    ? studentExamQuestions.value.findIndex((question) => Number(question.item_number) === currentTeacherPacedItemNumber.value)
    : -1
  const instantMaxIndex = studentInstantNextRequiredIndex.value

  if (isStudentTeacherPacedMode.value && !isStudentExamSubmitted.value) {
    if (teacherPacedIndex >= 0) {
      studentExamCurrentIndex.value = teacherPacedIndex
    } else {
      studentExamCurrentIndex.value = 0
    }
  } else if (preferredIndex >= 0 && (!isStudentInstantFeedbackMode.value || preferredIndex <= instantMaxIndex)) {
    studentExamCurrentIndex.value = preferredIndex
  } else if (isStudentInstantFeedbackMode.value && !isStudentExamSubmitted.value) {
    studentExamCurrentIndex.value = Math.max(0, instantMaxIndex)
  } else if (defaultIndex >= 0) {
    studentExamCurrentIndex.value = defaultIndex
  } else {
    studentExamCurrentIndex.value = 0
  }

  startStudentExamTimer(payload?.attempt?.remaining_seconds)
  syncStudentAnswerDraft()
}

async function openExamSimulation(exam) {
  if (!canStudentTakeExam(exam) || !selectedRoomId.value) return

  selectedStudentExam.value = {
    ...exam,
    delivery_mode: normalizeExamDeliveryMode(exam.delivery_mode),
  }
  studentExamError.value = ''
  showExamSimulationModal.value = true
  studentExamLoading.value = true

  try {
    const { data } = await services.startExam(exam.id, {
      room_id: selectedRoomId.value,
    })

    const isFreshTake = data?.resumed === false
    const isShuffleEnabled = Boolean(data?.exam?.shuffle_questions)

    if (isFreshTake) {
      // Prevent visual carry-over from a prior attempt when starting a new take.
      studentExamVisitedQuestionIds.value = []
    }

    if (isFreshTake && isShuffleEnabled && Array.isArray(data?.questions)) {
      data.questions = data.questions.map((question) => ({
        ...question,
        is_bookmarked: false,
      }))
    }

    applyStudentAttemptPayload(data)
    startStudentExamAutoSync()
    roomMessage.value = data?.message ?? 'Exam attempt is ready.'
  } catch (error) {
    stopStudentExamAutoSync()
    studentExamError.value = firstApiError(error, 'Unable to start exam attempt.')
  } finally {
    studentExamLoading.value = false
  }
}

function closeExamSimulationModal() {
  clearStudentExamTimer()
  stopStudentExamAutoSync()

  showExamSimulationModal.value = false
  showStudentSubmitConfirmModal.value = false
  showStudentExitConfirmModal.value = false
  selectedStudentExam.value = null
  studentExamAttempt.value = null
  studentExamQuestions.value = []
  studentExamCurrentIndex.value = 0
  studentExamLoading.value = false
  studentExamSaving.value = false
  studentExamBookmarking.value = false
  studentExamSubmitting.value = false
  studentExamError.value = ''
  studentExamRemainingSeconds.value = null
  studentExamVisitedQuestionIds.value = []
  studentExamTeacherPacing.value = null
  studentAnswerDraft.selected_option_id = null
  studentAnswerDraft.answer_text = ''
}

function goToStudentExamQuestionIndex(targetIndex) {
  const maxIndex = studentExamQuestions.value.length - 1
  if (maxIndex < 0) return

  let resolvedTargetIndex = Math.min(Math.max(targetIndex, 0), maxIndex)

  if (!isStudentExamSubmitted.value && isStudentTeacherPacedMode.value) {
    if (!isTeacherPacedSessionActive.value) return

    const teacherPacedIndex = currentTeacherPacedItemNumber.value
      ? studentExamQuestions.value.findIndex((question) => Number(question.item_number) === currentTeacherPacedItemNumber.value)
      : -1

    if (teacherPacedIndex < 0) return
    resolvedTargetIndex = teacherPacedIndex
  }

  if (!isStudentExamSubmitted.value && isStudentInstantFeedbackMode.value) {
    resolvedTargetIndex = Math.min(resolvedTargetIndex, Math.max(0, studentInstantNextRequiredIndex.value))
  }

  markCurrentQuestionVisited()
  studentExamCurrentIndex.value = resolvedTargetIndex
  syncStudentAnswerDraft()
}

function goToStudentExamQuestion(step) {
  goToStudentExamQuestionIndex(studentExamCurrentIndex.value + step)
}

function handleExamAttemptCloseClick() {
  if (studentExamSubmitting.value) return

  if (isStudentExamSubmitted.value) {
    closeExamSimulationModal()
    return
  }

  showStudentSubmitConfirmModal.value = false
  showStudentExitConfirmModal.value = true
  studentExamError.value = ''
}

function closeStudentExamExitConfirm() {
  if (studentExamSubmitting.value) return

  showStudentExitConfirmModal.value = false
}

function confirmStudentExamExit() {
  if (studentExamSubmitting.value) return

  if (studentExamSaving.value || studentExamBookmarking.value) {
    studentExamError.value = 'Please wait for the latest answer changes to finish syncing before exiting.'
    return
  }

  showStudentExitConfirmModal.value = false
  closeExamSimulationModal()
}

function openStudentExamSubmitConfirm() {
  if (isStudentExamSubmitted.value) return
  if (!studentExamAttempt.value?.id) return
  if (studentExamLoading.value || studentExamSubmitting.value) return

  showStudentExitConfirmModal.value = false
  showStudentSubmitConfirmModal.value = true
  studentExamError.value = ''
}

function closeStudentExamSubmitConfirm() {
  if (studentExamSubmitting.value) return

  showStudentSubmitConfirmModal.value = false
}

async function confirmStudentExamSubmit() {
  if (studentExamSubmitting.value) return

  if (studentExamSaving.value || studentExamBookmarking.value) {
    studentExamError.value = 'Please wait for the latest answer changes to finish syncing before submitting.'
    return
  }

  showStudentSubmitConfirmModal.value = false
  await submitStudentExam()
}

async function saveStudentExamAnswer() {
  const attemptId = studentExamAttempt.value?.id
  const currentQuestion = currentStudentExamQuestion.value
  if (!attemptId || !currentQuestion || isStudentExamSubmitted.value) return
  if (isStudentTeacherPacedMode.value && !isCurrentTeacherPacedQuestion.value) return
  if (isStudentInstantFeedbackMode.value && questionHasAnswer(currentQuestion)) return

  const normalizedDraftText = studentAnswerDraft.answer_text?.trim() || null
  const normalizedSavedText = currentQuestion.answer?.answer_text?.trim() || null
  const selectedOptionId = studentAnswerDraft.selected_option_id ?? null
  const savedOptionId = currentQuestion.answer?.selected_option_id ?? null

  if (currentQuestion.question_type === 'open_ended' && normalizedDraftText === normalizedSavedText) return
  if (currentQuestion.question_type !== 'open_ended' && selectedOptionId === savedOptionId) return

  studentExamSaving.value = true
  studentExamError.value = ''
  markQuestionVisited(currentQuestion.question_id)

  const payload = {
    question_id: currentQuestion.question_id,
  }

  if (currentQuestion.question_type === 'open_ended') {
    payload.answer_text = normalizedDraftText
  } else {
    payload.selected_option_id = selectedOptionId
  }

  try {
    const { data } = await services.saveAnswer(attemptId, payload)
    applyStudentAttemptPayload(data, currentQuestion.question_id)
  } catch (error) {
    studentExamError.value = firstApiError(error, 'Unable to save answer.')
  } finally {
    studentExamSaving.value = false
  }
}

async function handleStudentOptionSelect(optionId) {
  if (isCurrentQuestionInputLocked.value) return

  studentAnswerDraft.selected_option_id = Number(optionId)
  await saveStudentExamAnswer()
}

async function handleStudentOpenEndedBlur() {
  if (isCurrentQuestionInputLocked.value) return

  await saveStudentExamAnswer()
}

async function toggleCurrentQuestionBookmark() {
  const attemptId = studentExamAttempt.value?.id
  const currentQuestion = currentStudentExamQuestion.value
  if (!attemptId || !currentQuestion || isStudentExamSubmitted.value) return
  if (!isStudentOpenNavigationMode.value) return
  if (studentExamBookmarking.value) return

  studentExamBookmarking.value = true
  studentExamError.value = ''

  try {
    const { data } = await services.bookmarkQuestion(
      attemptId,
      currentQuestion.question_id,
      { is_bookmarked: !Boolean(currentQuestion.is_bookmarked) },
    )
    applyStudentAttemptPayload(data, currentQuestion.question_id)
  } catch (error) {
    studentExamError.value = firstApiError(error, 'Unable to update bookmark.')
  } finally {
    studentExamBookmarking.value = false
  }
}

async function submitStudentExam() {
  const attemptId = studentExamAttempt.value?.id
  if (!attemptId || isStudentExamSubmitted.value) return

  markCurrentQuestionVisited()
  studentExamSubmitting.value = true
  studentExamError.value = ''

  try {
    const { data } = await services.submitAttempt(attemptId)
    applyStudentAttemptPayload(data)
    roomMessage.value = 'Exam submitted. Result is now available.'
  } catch (error) {
    studentExamError.value = firstApiError(error, 'Unable to submit exam attempt.')
  } finally {
    studentExamSubmitting.value = false
  }
}

function resetUserForm() {
  userForm.id = null
  userForm.name = ''
  userForm.email = ''
  userForm.student_id = ''
  userForm.role = 'student'
  userForm.is_active = true
  userForm.password = ''
}

function openCreateUserModal() {
  resetUserForm()
  usersError.value = ''
  usersMessage.value = ''
  showUserModal.value = true
}

function openEditUserModal(user) {
  userForm.id = user.id
  userForm.name = user.name ?? ''
  userForm.email = user.email ?? ''
  userForm.student_id = user.student_id ?? ''
  userForm.role = user.role ?? 'student'
  userForm.is_active = Boolean(user.is_active)
  userForm.password = ''
  usersError.value = ''
  usersMessage.value = ''
  showUserModal.value = true
}

function closeUserModal() {
  showUserModal.value = false
  resetUserForm()
}

const reportMetricCards = computed(() => {
  if (!isManagementRole.value) return []

  const data = reportMetrics.value ?? {}

  if (isAdminRole.value) {
    return [
      {
        label: 'Total Users',
        value: data.total_users ?? 0,
        trend: `${data.active_users ?? 0} active`,
        positive: true,
        tone: 'navy',
        icon: UserRound,
      },
      {
        label: 'Students',
        value: data.students ?? 0,
        trend: `${data.staff ?? 0} staff`,
        positive: true,
        tone: 'gold',
        icon: ShieldCheck,
      },
      {
        label: 'Rooms',
        value: data.total_rooms ?? 0,
        trend: `${data.exam_assignments ?? 0} assignments`,
        positive: true,
        tone: 'success',
        icon: DoorOpen,
      },
      {
        label: 'Exams',
        value: data.total_exams ?? 0,
        trend: `${data.inactive_users ?? 0} inactive users`,
        positive: (data.inactive_users ?? 0) === 0,
        tone: 'navy',
        icon: FileText,
      },
    ]
  }

  return [
    {
      label: 'Managed Rooms',
      value: data.managed_rooms ?? 0,
      trend: `${data.students_enrolled ?? 0} students enrolled`,
      positive: true,
      tone: 'navy',
      icon: DoorOpen,
    },
    {
      label: 'Managed Exams',
      value: data.managed_exams ?? 0,
      trend: `${data.exam_assignments ?? 0} assignments`,
      positive: true,
      tone: 'gold',
      icon: FileText,
    },
  ]
})

function openCreateRoomModal() {
  roomName.value = ''
  showCreateRoomModal.value = true
}

function closeCreateRoomModal() {
  showCreateRoomModal.value = false
  roomName.value = ''
}

function openJoinRoomModal() {
  joinCode.value = ''
  showJoinRoomModal.value = true
}

function closeJoinRoomModal() {
  showJoinRoomModal.value = false
  joinCode.value = ''
}

function openEditRoomModal() {
  if (!selectedRoom.value) return
  editRoomName.value = selectedRoom.value.name ?? ''
  showEditRoomModal.value = true
}

function closeEditRoomModal() {
  showEditRoomModal.value = false
  editRoomName.value = ''
}

function openDeleteRoomModal() {
  if (!selectedRoom.value) return
  showDeleteRoomModal.value = true
}

function closeDeleteRoomModal() {
  showDeleteRoomModal.value = false
}

function openLeaveRoomModal() {
  if (!selectedRoom.value) return
  showLeaveRoomModal.value = true
}

function closeLeaveRoomModal() {
  showLeaveRoomModal.value = false
}

async function fetchRoomDetails(roomId) {
  if (!roomId) return

  roomDetailsLoading.value = true
  roomError.value = ''
  try {
    const { data } = await services.getRoom(roomId)
    const room = data.room ?? null
    selectedRoom.value = room
      ? {
          ...room,
          members: room.members ?? [],
          assigned_exams: (room.assigned_exams ?? []).map((exam) => ({
            ...exam,
            delivery_mode: normalizeExamDeliveryMode(exam.delivery_mode),
          })),
        }
      : null
    selectedRoomId.value = room?.id ?? null
  } catch (error) {
    roomError.value = firstApiError(error, 'Unable to load room details right now.')
    selectedRoom.value = null
    selectedRoomId.value = null
    closeExamSimulationModal()
  } finally {
    roomDetailsLoading.value = false
  }
}

async function selectRoom(roomId) {
  closeExamSimulationModal()
  closeRoomLiveBoard()
  await fetchRoomDetails(roomId)
}

async function fetchRooms(preferredRoomId = null) {
  if (!isRoomPage.value) return

  roomLoading.value = true
  roomError.value = ''
  try {
    const { data } = await services.getRooms()
    rooms.value = data.rooms ?? []

    if (rooms.value.length === 0) {
      showJoinRoomModal.value = false
      showEditRoomModal.value = false
      showDeleteRoomModal.value = false
      showLeaveRoomModal.value = false
      closeExamSimulationModal()
      selectedRoomId.value = null
      selectedRoom.value = null
      return
    }

    const hasPreferredRoom = preferredRoomId !== null && rooms.value.some((room) => room.id === preferredRoomId)
    const hasCurrentRoom = selectedRoomId.value !== null && rooms.value.some((room) => room.id === selectedRoomId.value)
    const roomIdToLoad = hasPreferredRoom
      ? preferredRoomId
      : (hasCurrentRoom ? selectedRoomId.value : rooms.value[0].id)

    await fetchRoomDetails(roomIdToLoad)
  } catch (error) {
    roomError.value = firstApiError(error, 'Unable to load rooms right now.')
  } finally {
    roomLoading.value = false
  }
}

async function handleCreateRoom() {
  if (!roomName.value.trim()) return

  roomLoading.value = true
  roomError.value = ''
  roomMessage.value = ''

  try {
    const { data } = await services.createRoom({ name: roomName.value.trim() })
    roomName.value = ''
    showCreateRoomModal.value = false
    roomMessage.value = 'Room created. Share the room code with students.'
    await fetchRooms(data?.room?.id ?? null)
  } catch (error) {
    roomError.value = firstApiError(error, 'Unable to create room.')
  } finally {
    roomLoading.value = false
  }
}

async function handleUpdateRoom() {
  if (!selectedRoomId.value || !editRoomName.value.trim()) return

  roomLoading.value = true
  roomError.value = ''
  roomMessage.value = ''

  try {
    await services.updateRoom(selectedRoomId.value, { name: editRoomName.value.trim() })
    closeEditRoomModal()
    roomMessage.value = 'Room updated successfully.'
    await fetchRooms(selectedRoomId.value)
  } catch (error) {
    roomError.value = firstApiError(error, 'Unable to update room.')
  } finally {
    roomLoading.value = false
  }
}

async function handleDeleteRoom() {
  if (!selectedRoomId.value) return

  roomLoading.value = true
  roomError.value = ''
  roomMessage.value = ''

  try {
    await services.deleteRoom(selectedRoomId.value)
    closeDeleteRoomModal()
    roomMessage.value = 'Room deleted successfully.'
    selectedRoomId.value = null
    selectedRoom.value = null
    await fetchRooms()
  } catch (error) {
    roomError.value = firstApiError(error, 'Unable to delete room.')
  } finally {
    roomLoading.value = false
  }
}

async function handleJoinRoom() {
  if (!joinCode.value.trim()) return

  roomLoading.value = true
  roomError.value = ''
  roomMessage.value = ''

  try {
    const { data } = await services.joinRoom({ code: joinCode.value.trim().toUpperCase() })
    showJoinRoomModal.value = false
    joinCode.value = ''
    roomMessage.value = 'Joined room successfully.'
    await fetchRooms(data?.room?.id ?? null)
  } catch (error) {
    roomError.value = firstApiError(error, 'Unable to join room with that code.')
  } finally {
    roomLoading.value = false
  }
}

async function handleLeaveRoom() {
  if (!selectedRoomId.value) return

  roomLoading.value = true
  roomError.value = ''
  roomMessage.value = ''

  try {
    await services.leaveRoom(selectedRoomId.value)
    showLeaveRoomModal.value = false
    closeExamSimulationModal()
    roomMessage.value = 'You have left the room.'
    selectedRoomId.value = null
    selectedRoom.value = null
    await fetchRooms()
  } catch (error) {
    roomError.value = firstApiError(error, 'Unable to leave room right now.')
  } finally {
    roomLoading.value = false
  }
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
    examQuestionBanks.value = (data.banks ?? []).map((bank) => ({
      id: bank.id,
      title: bank.title,
      subject: bank.subject,
      total_items: Number(bank.total_items ?? bank.questions_count ?? 0),
    }))
  } catch (error) {
    examQuestionBanks.value = []
  }
}

async function loadExams() {
  if (!isManagementRole.value) return

  examLoading.value = true
  examError.value = ''
  examMessage.value = ''

  try {
    const [{ data: examData }] = await Promise.all([
      services.getExams(),
      fetchManageableRooms(),
      fetchExamQuestionBanks(),
    ])
    exams.value = (examData.exams ?? []).map((exam) => ({
      ...exam,
      delivery_mode: normalizeExamDeliveryMode(exam.delivery_mode),
    }))
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

  const scheduledAt = toExamSchedulePayload(examForm.scheduled_at)
  if (examForm.scheduled_at && !scheduledAt) {
    examError.value = 'Please provide a valid schedule date and time.'
    examSaving.value = false
    return
  }

  const selectedBank = examQuestionBanks.value.find((bank) => bank.id === Number(examForm.question_bank_id))

  if (selectedBank && Number(examForm.total_items) > Number(selectedBank.total_items)) {
    examError.value = 'Selected question bank does not have enough questions for the item count.'
    examSaving.value = false
    return
  }

  const payload = {
    title: examForm.title.trim(),
    description: examForm.description.trim() || null,
    question_bank_id: examForm.question_bank_id ? Number(examForm.question_bank_id) : null,
    total_items: Number(examForm.total_items),
    duration_minutes: Number(examForm.duration_minutes),
    scheduled_at: scheduledAt,
    delivery_mode: normalizeExamDeliveryMode(examForm.delivery_mode),
    one_take_only: Boolean(examForm.one_take_only),
    shuffle_questions: normalizeExamDeliveryMode(examForm.delivery_mode) === 'teacher_paced'
      ? false
      : Boolean(examForm.shuffle_questions),
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

function resetLiveBoardState() {
  liveBoardRows.value = []
  liveBoardItemSummary.value = []
  liveBoardSummary.value = {
    students_total: 0,
    attempts_started: 0,
    attempts_submitted: 0,
  }
  liveBoardError.value = ''
  liveBoardUpdatedAt.value = null
  liveBoardTeacherPacing.value = null
  liveBoardTeacherPacingBusy.value = false
}

function stopLiveBoardAutoRefresh() {
  if (!liveBoardRefreshInterval) return
  clearInterval(liveBoardRefreshInterval)
  liveBoardRefreshInterval = null
}

function startLiveBoardAutoRefresh() {
  stopLiveBoardAutoRefresh()
  liveBoardRefreshInterval = setInterval(() => {
    if (!roomLiveBoardActive.value || !liveBoardExam.value?.id || !liveBoardRoomId.value) return
    loadLiveBoard(true)
  }, 5000)
}

async function openRoomLiveBoard(exam) {
  const roomId = Number(selectedRoom.value?.id ?? selectedRoomId.value)
  if (!Number.isFinite(roomId) || roomId < 1) {
    roomError.value = 'Select a room first before opening the live board.'
    return
  }

  liveBoardExam.value = {
    ...exam,
    delivery_mode: normalizeExamDeliveryMode(exam.delivery_mode),
  }
  liveBoardRoomId.value = roomId
  liveBoardOptions.show_names = true
  liveBoardOptions.show_responses = true
  liveBoardOptions.show_results = true
  resetLiveBoardState()
  roomLiveBoardActive.value = true

  if (typeof window !== 'undefined') {
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  await loadLiveBoard(false)
  startLiveBoardAutoRefresh()
}

function closeRoomLiveBoard() {
  roomLiveBoardActive.value = false
  stopLiveBoardAutoRefresh()
  liveBoardExam.value = null
  liveBoardRoomId.value = null
  resetLiveBoardState()
}

async function loadLiveBoard(silent = false) {
  const examId = liveBoardExam.value?.id
  const roomId = Number(liveBoardRoomId.value)
  if (!examId || !Number.isFinite(roomId) || roomId < 1) return

  if (silent) {
    liveBoardRefreshing.value = true
  } else {
    liveBoardLoading.value = true
  }

  if (!silent) {
    liveBoardError.value = ''
  }

  try {
    const { data } = await services.getLiveBoard(examId, roomId)

    if (data?.exam) {
      liveBoardExam.value = {
        ...data.exam,
        delivery_mode: normalizeExamDeliveryMode(data.exam.delivery_mode),
      }
    }

    liveBoardRows.value = data.rows ?? []
    liveBoardItemSummary.value = data.item_summary ?? []
    liveBoardSummary.value = data.summary ?? {
      students_total: 0,
      attempts_started: 0,
      attempts_submitted: 0,
    }
    liveBoardTeacherPacing.value = data.teacher_pacing ?? null
    liveBoardUpdatedAt.value = data.generated_at ?? new Date().toISOString()
  } catch (error) {
    liveBoardError.value = firstApiError(error, 'Unable to load live dashboard.')
  } finally {
    if (silent) {
      liveBoardRefreshing.value = false
    } else {
      liveBoardLoading.value = false
    }
  }
}

async function updateTeacherPacing(action) {
  const examId = liveBoardExam.value?.id
  const roomId = Number(liveBoardRoomId.value)
  if (!examId || !Number.isFinite(roomId) || roomId < 1) return
  if (!isLiveBoardTeacherPaced.value) return
  if (liveBoardTeacherPacingBusy.value) return

  liveBoardTeacherPacingBusy.value = true
  liveBoardError.value = ''

  try {
    const { data } = await services.updateTeacherPacing(examId, {
      room_id: roomId,
      action,
    })

    liveBoardTeacherPacing.value = data.teacher_pacing ?? liveBoardTeacherPacing.value
    await loadLiveBoard(true)
  } catch (error) {
    liveBoardError.value = firstApiError(error, 'Unable to update teacher pacing.')
  } finally {
    liveBoardTeacherPacingBusy.value = false
  }
}

function liveBoardDisplayName(row, index) {
  if (liveBoardOptions.show_names) return row?.user?.name || 'Unknown Student'
  return `Student ${index + 1}`
}

function liveBoardProgressLabel(row) {
  if (!row?.attempt) return 'Not started'

  const answered = Number(row.attempt.answered_count ?? 0)
  const total = Number(row.attempt.total_items ?? 0)
  const status = row.attempt.status === 'submitted' ? 'Submitted' : 'In progress'
  return `${answered}/${total} • ${status}`
}

function liveBoardResponseText(item) {
  const raw = String(item?.response ?? '').trim()
  if (!raw) return 'Answered'
  return raw.length > 30 ? `${raw.slice(0, 30)}...` : raw
}

function liveBoardCellText(item) {
  if (!item?.answered) return '--'

  if (liveBoardOptions.show_results) {
    if (item.is_correct === true) {
      return liveBoardOptions.show_responses ? `Correct: ${liveBoardResponseText(item)}` : 'Correct'
    }
    if (item.is_correct === false) {
      return liveBoardOptions.show_responses ? `Wrong: ${liveBoardResponseText(item)}` : 'Wrong'
    }
  }

  if (liveBoardOptions.show_responses) return liveBoardResponseText(item)
  return 'Answered'
}

function liveBoardCellClass(item) {
  if (!item?.answered) return 'pending'
  if (!liveBoardOptions.show_results) return 'answered'
  if (item.is_correct === true) return 'correct'
  if (item.is_correct === false) return 'incorrect'
  return 'answered'
}

function liveBoardItemSummaryText(item) {
  if (liveBoardOptions.show_results) {
    if (!Number.isFinite(Number(item?.correct_percent))) return '--'
    return `${Number(item.correct_percent)}%`
  }

  if (!Number.isFinite(Number(item?.answered_percent))) return '--'
  return `${Number(item.answered_percent)}%`
}

async function loadReports() {
  if (!isManagementRole.value) return

  reportLoading.value = true
  reportError.value = ''
  try {
    const { data } = await services.getReportsOverview()
    reportMetrics.value = data.metrics ?? {}
    reportActivity.value = data.recent_activity ?? []
  } catch (error) {
    reportError.value = firstApiError(error, 'Unable to load report data.')
  } finally {
    reportLoading.value = false
  }
}

async function loadSystemSettings() {
  if (!isManagementRole.value) return

  settingsLoading.value = true
  settingsError.value = ''
  settingsMessage.value = ''

  try {
    const { data } = await services.getSystemSettings()
    const settings = data.settings ?? {}
    settingsCanEdit.value = Boolean(data.can_edit)
    settingsForm.platform_name = settings.platform_name ?? ''
    settingsForm.academic_term = settings.academic_term ?? ''
    settingsForm.allow_public_registration = Boolean(settings.allow_public_registration)
    settingsForm.maintenance_mode = Boolean(settings.maintenance_mode)
    settingsForm.announcement_banner = settings.announcement_banner ?? ''
  } catch (error) {
    settingsError.value = firstApiError(error, 'Unable to load system settings.')
  } finally {
    settingsLoading.value = false
  }
}

async function saveSystemSettings() {
  if (!settingsCanEdit.value) return

  settingsSaving.value = true
  settingsError.value = ''
  settingsMessage.value = ''

  try {
    await services.saveSystemSettings({
      platform_name: settingsForm.platform_name.trim(),
      academic_term: settingsForm.academic_term.trim(),
      allow_public_registration: settingsForm.allow_public_registration,
      maintenance_mode: settingsForm.maintenance_mode,
      announcement_banner: settingsForm.announcement_banner.trim(),
    })
    settingsMessage.value = 'System settings updated.'
  } catch (error) {
    settingsError.value = firstApiError(error, 'Unable to save settings.')
  } finally {
    settingsSaving.value = false
  }
}

async function loadAdminUsers() {
  if (!isAdminRole.value) return

  usersLoading.value = true
  usersError.value = ''
  usersMessage.value = ''

  try {
    const params = {}
    if (userFilters.search.trim()) params.search = userFilters.search.trim()
    if (userFilters.role) params.role = userFilters.role
    if (userFilters.status) params.status = userFilters.status

    const { data } = await services.getUsers(params)
    adminUsers.value = data.users ?? []
  } catch (error) {
    usersError.value = firstApiError(error, 'Unable to load users.')
  } finally {
    usersLoading.value = false
  }
}

async function handleSaveUser() {
  if (!isAdminRole.value) return
  if (!userForm.name.trim() || !userForm.email.trim()) return
  if (userForm.role === 'student' && !/^\d{7,20}$/.test(userForm.student_id.trim())) {
    usersError.value = 'Student ID must be 7 to 20 digits for student accounts.'
    return
  }

  usersSaving.value = true
  usersError.value = ''
  usersMessage.value = ''

  const payload = {
    name: userForm.name.trim(),
    email: userForm.email.trim(),
    student_id: userForm.role === 'student' ? (userForm.student_id.trim() || null) : null,
    role: userForm.role,
    is_active: Boolean(userForm.is_active),
  }

  if (userForm.password.trim()) {
    payload.password = userForm.password
  }

  try {
    if (userForm.id) {
      await services.updateUser(userForm.id, payload)
      usersMessage.value = 'User updated successfully.'
    } else {
      if (!payload.password || payload.password.length < 8) {
        usersError.value = 'Password must be at least 8 characters for new users.'
        usersSaving.value = false
        return
      }

      await services.createUser(payload)
      usersMessage.value = 'User created successfully.'
    }

    closeUserModal()
    await loadAdminUsers()
  } catch (error) {
    usersError.value = firstApiError(error, 'Unable to save user.')
  } finally {
    usersSaving.value = false
  }
}

async function loadAuditLogs() {
  if (!isAdminRole.value) return

  auditLoading.value = true
  auditError.value = ''

  try {
    const { data } = await services.getAuditLogs()
    auditLogs.value = data.logs ?? []
  } catch (error) {
    auditError.value = firstApiError(error, 'Unable to load audit logs.')
  } finally {
    auditLoading.value = false
  }
}

watch(
  () => currentStudentExamQuestion.value?.question_id,
  () => {
    syncStudentAnswerDraft()
  },
)

watch(
  () => examForm.delivery_mode,
  (mode) => {
    if (normalizeExamDeliveryMode(mode) === 'teacher_paced') {
      examForm.shuffle_questions = false
    }
  },
)

watch(
  () => studentExamRemainingSeconds.value,
  async (remainingSeconds) => {
    if (remainingSeconds !== 0) return
    if (!showExamSimulationModal.value || isStudentExamSubmitted.value) return
    if (studentExamLoading.value || studentExamSaving.value || studentExamSubmitting.value) return

    clearStudentExamTimer()
    await refreshStudentExamAttemptStatus(false)
  },
)

watch(
  () => activeNav.value,
  async (value) => {
    if (value !== 'library' && showLibraryQuestionModal.value) {
      closeLibraryQuestionModal()
    }

    if (value !== 'library' && showDeleteLibraryBankModal.value) {
      closeDeleteLibraryBankModal()
    }

    if (value !== 'exams') {
      showExamModal.value = false
      showDeleteExamModal.value = false
      selectedExam.value = null
    }

    if (value !== 'users') {
      closeUserModal()
    }

    if (value !== 'room') {
      closeRoomLiveBoard()
    }

    if (['room', 'rooms'].includes(value)) {
      if (value !== 'room') {
        showCreateRoomModal.value = false
        showJoinRoomModal.value = false
        showEditRoomModal.value = false
        showDeleteRoomModal.value = false
        showLeaveRoomModal.value = false
        closeExamSimulationModal()
        closeRoomLiveBoard()
        selectedRoomId.value = null
        selectedRoom.value = null
      }
      await fetchRooms()
      return
    }

    showCreateRoomModal.value = false
    showJoinRoomModal.value = false
    showEditRoomModal.value = false
    showDeleteRoomModal.value = false
    showLeaveRoomModal.value = false
    closeExamSimulationModal()
    closeRoomLiveBoard()
    selectedRoomId.value = null
    selectedRoom.value = null
    roomError.value = ''
    roomMessage.value = ''

    if (value === 'library') {
      await loadLibraryBanks()
      return
    }

    if (value === 'exams') {
      await loadExams()
      return
    }

    if (value === 'reports') {
      await loadReports()
      return
    }

    if (value === 'settings') {
      await loadSystemSettings()
      return
    }

    if (value === 'users') {
      await loadAdminUsers()
      return
    }

    if (value === 'audit') {
      await loadAuditLogs()
    }
  },
  { immediate: true },
)

async function handleLogout() {
  await auth.logout()
  await router.push('/login')
}

const pageMap = {
  dashboard: {
    title: 'Dashboard',
    sub: 'Your LLE review performance at a glance',
    icon: LayoutDashboard,
  },
  rooms: {
    title: 'Rooms',
    sub: 'Join and track your assigned room memberships',
    icon: DoorOpen,
  },
  room: {
    title: 'Rooms',
    sub: 'Create rooms, review enrollment, and track assigned exams',
    icon: DoorOpen,
  },
  analytics: {
    title: 'Analytics',
    sub: 'Monitor trends and identify weak areas quickly',
    icon: BarChart3,
  },
  library: {
    title: 'Library',
    sub: 'Manage exam content and question pools',
    icon: BookOpen,
  },
  exams: {
    title: 'Exams',
    sub: 'Configure exam structures and schedules',
    icon: FileText,
  },
  reports: {
    title: 'Reports',
    sub: 'Review aggregate and student-level insights',
    icon: BarChart3,
  },
  settings: {
    title: 'Settings',
    sub: 'Manage preferences and account behavior',
    icon: Settings,
  },
  users: {
    title: 'Users',
    sub: 'Create accounts, assign roles, and manage account status',
    icon: UserRound,
  },
  audit: {
    title: 'Audit Logs',
    sub: 'Track key system actions and account activity',
    icon: ClipboardList,
  },
}

const fallbackPage = pageMap.dashboard
const currentPage = computed(() => pageMap[activeNav.value] ?? pageMap[navItems.value[0]?.key] ?? fallbackPage)

const statCards = [
  { label: 'Overall Average', value: '78%', trend: '+4% this week', positive: true, tone: 'navy', icon: Gauge },
  { label: 'Pass Probability', value: '84%', trend: '+2% this week', positive: true, tone: 'success', icon: ShieldCheck },
  { label: 'Exams Taken', value: '12', trend: '3 exams pending', positive: true, tone: 'gold', icon: ClipboardList },
  { label: 'Avg. Time per Exam', value: '58m', trend: '-5m faster', positive: true, tone: 'navy', icon: Clock3 },
]

const subjects = DASHBOARD_SUBJECTS
const scoreHistory = DASHBOARD_SCORE_HISTORY
const activities = DASHBOARD_ACTIVITIES
</script>

<style scoped src="./dashboard.css"></style>
