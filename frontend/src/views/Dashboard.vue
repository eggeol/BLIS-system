<template>
  <div class="dashboard-shell">
    <aside class="sidebar" :class="{ collapsed: sidebarCollapsed }">
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
      <header class="topbar">
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
            <article class="surface-card room-shell-card">
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
                  <p class="muted">Rooms you've added: <strong>{{ rooms.length }}</strong></p>

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
                          <button
                            v-for="exam in selectedRoom.assigned_exams"
                            :key="exam.id"
                            type="button"
                            class="exam-card"
                            title="Exam click action will be enabled in a future update."
                          >
                            <div>
                              <strong class="exam-card-title">{{ exam.title }}</strong>
                              <p class="exam-card-meta">{{ exam.progress ?? '0 / 0 answered' }}</p>
                              <p class="exam-card-date">{{ exam.schedule ?? 'No schedule yet' }}</p>
                            </div>
                          </button>
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
                          <button
                            v-for="exam in selectedRoom.assigned_exams"
                            :key="exam.id"
                            type="button"
                            class="exam-card"
                            title="Exam click action will be enabled in a future update."
                          >
                            <div>
                              <strong class="exam-card-title">{{ exam.title }}</strong>
                              <p class="exam-card-meta">{{ exam.progress ?? '0 / 0 answered' }}</p>
                              <p class="exam-card-date">{{ exam.schedule ?? 'No schedule yet' }}</p>
                            </div>
                          </button>
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
          </template>
        </section>

        <section v-else-if="activeNav === 'library'" class="library-view">
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

            <div class="library-empty-canvas" />
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
                          <label v-for="(option, optionIndex) in question.options" :key="optionIndex" class="digitalized-option">
                            <input type="radio" :name="`preview-question-${index}`" disabled />
                            <span>{{ option }}</span>
                          </label>
                        </div>

                        <p v-else class="digitalized-open-ended">Open-ended response</p>

                        <p v-if="question.answer" class="digitalized-answer">Answer: {{ question.answer }}</p>
                      </article>
                    </div>
                  </section>
                </div>

                <div class="modal-actions library-modal-actions">
                  <button type="button" class="ghost-btn" @click="closeLibraryQuestionModal">Close</button>
                </div>
              </div>
            </div>
          </teleport>
        </section>

        <section v-else-if="activeNav === 'exams'" class="library-view">
          <article class="surface-card room-shell-card">
            <header class="room-page-head">
              <div class="room-page-title">
                <FileText :size="18" />
                <h3>Exams</h3>
              </div>
              <button type="button" class="primary-btn add-room-btn">
                <Plus :size="16" />
                Add Exam
              </button>
            </header>

            <div class="library-empty-canvas" />
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
import { useAuthStore } from '@/stores/auth'
import api from '@/lib/api'
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

const auth = useAuthStore()
const router = useRouter()

const sidebarCollapsed = ref(false)
const activeNav = ref('')

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
const showLibraryQuestionModal = ref(false)
const libraryParsing = ref(false)
const libraryParseError = ref('')
const libraryDocxName = ref('')
const libraryFileInputKey = ref(0)
const digitalizedQuestions = ref([])
const libraryForm = reactive({
  questionName: '',
  subjectCategory: '',
  file: null,
})

const librarySubjectCategories = [
  'Library Science Fundamentals',
  'Cataloging and Classification',
  'Reference and Information Services',
  'Library Management',
  'Bibliography and Research',
  'Information Technology',
]

let mobileMediaQuery

const normalizedRole = computed(() => String(auth.user?.role ?? 'student').toLowerCase())
const isManagementRole = computed(() => ['admin', 'staff_master_examiner', 'faculty'].includes(normalizedRole.value))
const isRoomPage = computed(() => ['room', 'rooms'].includes(activeNav.value))

const displayName = computed(() => auth.user?.name ?? 'User')
const displayRole = computed(() => {
  if (['staff_master_examiner', 'faculty'].includes(normalizedRole.value)) return 'Staff / Master Examiner'
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

const managementNavItems = [
  { key: 'library', label: 'Library', icon: BookOpen },
  { key: 'room', label: 'Room', icon: DoorOpen },
  { key: 'exams', label: 'Exams', icon: FileText },
  { key: 'reports', label: 'Reports', icon: BarChart3 },
  { key: 'settings', label: 'Settings', icon: Settings },
]

const navItems = computed(() => (isManagementRole.value ? managementNavItems : studentNavItems))

watchEffect(() => {
  if (!navItems.value.some((item) => item.key === activeNav.value)) {
    activeNav.value = navItems.value[0]?.key ?? ''
  }
})

function syncSidebarForViewport(eventOrQuery) {
  if (eventOrQuery.matches) {
    sidebarCollapsed.value = false
  }
}

onMounted(() => {
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
  if (['staff_master_examiner', 'faculty'].includes(normalized)) return 'Staff / Master Examiner'
  return 'Student'
}

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

function resetLibraryQuestionState() {
  libraryForm.questionName = ''
  libraryForm.subjectCategory = ''
  libraryForm.file = null
  libraryDocxName.value = ''
  libraryParseError.value = ''
  libraryParsing.value = false
  digitalizedQuestions.value = []
  libraryFileInputKey.value += 1
}

function openLibraryQuestionModal() {
  resetLibraryQuestionState()
  showLibraryQuestionModal.value = true
}

function closeLibraryQuestionModal() {
  showLibraryQuestionModal.value = false
  resetLibraryQuestionState()
}

async function handleLibraryDocxChange(event) {
  const file = event.target?.files?.[0] ?? null

  libraryParseError.value = ''
  digitalizedQuestions.value = []
  libraryForm.file = null
  libraryDocxName.value = ''

  if (!file) return

  const isDocx = file.name.toLowerCase().endsWith('.docx')
  if (!isDocx) {
    libraryParseError.value = 'Please upload a valid .docx file.'
    libraryFileInputKey.value += 1
    return
  }

  libraryForm.file = file
  libraryDocxName.value = file.name
  libraryParsing.value = true

  try {
    const mammothModule = await import('mammoth/mammoth.browser')
    const arrayBuffer = await file.arrayBuffer()
    const { value } = await mammothModule.extractRawText({ arrayBuffer })
    const parsedQuestions = parseQuestionsFromDocxText(value)

    if (parsedQuestions.length === 0) {
      libraryParseError.value = 'No question pattern found. Use numbered questions with optional A/B/C choices.'
      return
    }

    digitalizedQuestions.value = parsedQuestions
  } catch (error) {
    libraryParseError.value = 'Unable to parse DOCX file. Please check file formatting and try again.'
  } finally {
    libraryParsing.value = false
  }
}

function parseQuestionsFromDocxText(rawText) {
  const normalizedText = String(rawText ?? '').replace(/\r/g, '')
  const lines = normalizedText.split('\n').map((line) => line.trim())

  const parsed = []
  let current = null
  let activeOptionIndex = -1

  const commitCurrent = () => {
    if (!current || !current.text) return
    parsed.push({
      id: parsed.length + 1,
      text: current.text.trim(),
      options: current.options.map((option) => option.trim()).filter(Boolean),
      answer: current.answer.trim(),
    })
  }

  for (const line of lines) {
    if (!line) {
      activeOptionIndex = -1
      continue
    }

    const questionMatch = line.match(/^(?:Q(?:uestion)?\s*)?(\d+)[\).\:-]?\s+(.+)$/i)
    const optionMatch = line.match(/^([A-H])[\).\:-]\s+(.+)$/)
    const answerMatch = line.match(/^(?:Answer|Correct\s*Answer)\s*[:\-]\s*(.+)$/i)

    if (questionMatch) {
      commitCurrent()
      current = { text: questionMatch[2], options: [], answer: '' }
      activeOptionIndex = -1
      continue
    }

    if (optionMatch && current) {
      current.options.push(`${optionMatch[1]}. ${optionMatch[2]}`)
      activeOptionIndex = current.options.length - 1
      continue
    }

    if (answerMatch && current) {
      current.answer = answerMatch[1]
      activeOptionIndex = -1
      continue
    }

    if (!current) {
      current = { text: line, options: [], answer: '' }
      activeOptionIndex = -1
      continue
    }

    if (activeOptionIndex >= 0) {
      current.options[activeOptionIndex] = `${current.options[activeOptionIndex]} ${line}`.trim()
    } else {
      current.text = `${current.text} ${line}`.trim()
    }
  }

  commitCurrent()
  return parsed
}

async function fetchRoomDetails(roomId) {
  if (!roomId) return

  roomDetailsLoading.value = true
  roomError.value = ''
  try {
    const { data } = await api.get(`/rooms/${roomId}`)
    const room = data.room ?? null
    selectedRoom.value = room
      ? {
          ...room,
          members: room.members ?? [],
          assigned_exams: room.assigned_exams ?? [],
        }
      : null
    selectedRoomId.value = room?.id ?? null
  } catch (error) {
    roomError.value = firstApiError(error, 'Unable to load room details right now.')
    selectedRoom.value = null
    selectedRoomId.value = null
  } finally {
    roomDetailsLoading.value = false
  }
}

async function selectRoom(roomId) {
  await fetchRoomDetails(roomId)
}

async function fetchRooms(preferredRoomId = null) {
  if (!isRoomPage.value) return

  roomLoading.value = true
  roomError.value = ''
  try {
    const { data } = await api.get('/rooms')
    rooms.value = data.rooms ?? []

    if (rooms.value.length === 0) {
      showJoinRoomModal.value = false
      showEditRoomModal.value = false
      showDeleteRoomModal.value = false
      showLeaveRoomModal.value = false
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
    const { data } = await api.post('/rooms', { name: roomName.value.trim() })
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
    await api.patch(`/rooms/${selectedRoomId.value}`, { name: editRoomName.value.trim() })
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
    await api.delete(`/rooms/${selectedRoomId.value}`)
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
    const { data } = await api.post('/rooms/join', { code: joinCode.value.trim().toUpperCase() })
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
    await api.delete(`/rooms/${selectedRoomId.value}/leave`)
    showLeaveRoomModal.value = false
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

watch(
  () => activeNav.value,
  (value) => {
    if (value !== 'library' && showLibraryQuestionModal.value) {
      closeLibraryQuestionModal()
    }

    if (['room', 'rooms'].includes(value)) {
      if (value !== 'room') {
        showCreateRoomModal.value = false
        showJoinRoomModal.value = false
        showEditRoomModal.value = false
        showDeleteRoomModal.value = false
        showLeaveRoomModal.value = false
        selectedRoomId.value = null
        selectedRoom.value = null
      }
      fetchRooms()
      return
    }

    showCreateRoomModal.value = false
    showJoinRoomModal.value = false
    showEditRoomModal.value = false
    showDeleteRoomModal.value = false
    showLeaveRoomModal.value = false
    selectedRoomId.value = null
    selectedRoom.value = null
    roomError.value = ''
    roomMessage.value = ''
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
}

const fallbackPage = pageMap.dashboard
const currentPage = computed(() => pageMap[activeNav.value] ?? pageMap[navItems.value[0]?.key] ?? fallbackPage)

const statCards = [
  { label: 'Overall Average', value: '78%', trend: '+4% this week', positive: true, tone: 'navy', icon: Gauge },
  { label: 'Pass Probability', value: '84%', trend: '+2% this week', positive: true, tone: 'success', icon: ShieldCheck },
  { label: 'Exams Taken', value: '12', trend: '3 exams pending', positive: true, tone: 'gold', icon: ClipboardList },
  { label: 'Avg. Time per Exam', value: '58m', trend: '-5m faster', positive: true, tone: 'navy', icon: Clock3 },
]

const subjects = [
  { label: 'Library Science Fundamentals', score: 88 },
  { label: 'Cataloging and Classification', score: 72 },
  { label: 'Reference and Information Services', score: 65 },
  { label: 'Library Management', score: 90 },
  { label: 'Bibliography and Research', score: 78 },
  { label: 'Information Technology', score: 82 },
]

const scoreHistory = [62, 68, 71, 74, 76, 78]

const activities = [
  { title: 'Library Science Mock Exam 12', meta: 'Today, 9:42 AM', score: '82%', positive: true },
  { title: 'Cataloging Quiz Set B', meta: 'Yesterday, 3:15 PM', score: '68%', positive: false },
  { title: 'Reference Services Set A', meta: 'Feb 24, 10:00 AM', score: '71%', positive: false },
  { title: 'Library Management Full Set', meta: 'Feb 22, 2:30 PM', score: '90%', positive: true },
]
</script>

<style scoped>
.dashboard-shell {
  min-height: 100vh;
  min-height: 100dvh;
  display: grid;
  grid-template-columns: auto 1fr;
  background:
    radial-gradient(circle at 88% 0%, rgba(201, 168, 76, 0.24), transparent 30%),
    linear-gradient(180deg, rgba(26, 35, 126, 0.08), transparent 40%),
    var(--lnu-bg);
}

.sidebar {
  width: 260px;
  height: 100vh;
  height: 100dvh;
  position: sticky;
  top: 0;
  align-self: start;
  overflow-x: hidden;
  border-right: 1px solid rgba(240, 208, 128, 0.24);
  background: linear-gradient(180deg, var(--lnu-navy-deep), var(--lnu-navy));
  padding: 16px 12px;
  display: flex;
  flex-direction: column;
  transition: width 0.2s ease;
  color: rgba(255, 255, 255, 0.92);
}

.sidebar.collapsed {
  width: 84px;
  padding-left: 10px;
  padding-right: 10px;
}

.sidebar.collapsed .sidebar-top {
  flex-direction: column;
  align-items: center;
  gap: 10px;
}

.sidebar.collapsed .brand {
  width: 44px;
  height: 44px;
  padding: 0;
  justify-content: center;
  border-radius: 12px;
}

.sidebar.collapsed .brand-icon-wrap {
  width: 44px;
  height: 44px;
  border-radius: 12px;
}

.sidebar.collapsed .sidebar-toggle {
  width: 44px;
  height: 44px;
  border-radius: 12px;
}

.sidebar.collapsed .sidebar-nav {
  justify-items: center;
  gap: 8px;
}

.sidebar.collapsed .nav-item {
  width: 44px;
  height: 44px;
  padding: 0;
  justify-content: center;
  border-radius: 12px;
}

.sidebar-top {
  display: flex;
  align-items: center;
  gap: 8px;
}

.sidebar-toggle {
  width: 34px;
  height: 34px;
  border-radius: 9px;
  border: 1px solid rgba(240, 208, 128, 0.28);
  background: rgba(255, 255, 255, 0.07);
  color: var(--lnu-gold-light);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s ease;
}

.sidebar-toggle:hover {
  color: var(--lnu-gold-light);
  border-color: rgba(240, 208, 128, 0.5);
  background: rgba(255, 255, 255, 0.14);
}

.brand {
  display: flex;
  align-items: center;
  gap: 10px;
  border: none;
  background: transparent;
  color: var(--lnu-white);
  text-align: left;
  padding: 4px;
}

.brand-icon-wrap {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: rgba(240, 208, 128, 0.18);
  color: var(--lnu-gold-light);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.brand-text {
  display: grid;
  gap: 2px;
}

.brand-text strong {
  font-size: 14px;
  line-height: 1;
}

.brand-text small {
  font-size: 11px;
  color: rgba(240, 208, 128, 0.92);
}

.sidebar-nav {
  margin-top: 18px;
  display: grid;
  gap: 4px;
}

.nav-item {
  height: 40px;
  border: none;
  border-radius: 10px;
  background: transparent;
  color: rgba(255, 255, 255, 0.9);
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 10px;
  text-align: left;
  transition: background 0.2s ease, color 0.2s ease;
}

.nav-item:hover {
  background: rgba(255, 255, 255, 0.13);
  color: var(--lnu-white);
}

.nav-item.active {
  background: linear-gradient(135deg, var(--lnu-gold-light), var(--lnu-gold));
  color: var(--lnu-navy-deep);
}

.nav-icon {
  flex-shrink: 0;
}

.nav-label {
  font-size: 13px;
  font-weight: 600;
}

.sidebar-footer {
  margin-top: auto;
  border-top: 1px solid rgba(240, 208, 128, 0.22);
  padding-top: 12px;
  display: grid;
  gap: 10px;
}

.user-tile {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px;
  border: 1px solid rgba(255, 255, 255, 0.18);
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.06);
}

.avatar {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: var(--lnu-gold);
  color: var(--lnu-navy-deep);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 700;
}

.user-tile strong {
  display: block;
  font-size: 13px;
  color: var(--lnu-white);
}

.user-tile small {
  display: block;
  margin-top: 2px;
  color: rgba(255, 255, 255, 0.78);
  font-size: 11px;
}

.logout-btn {
  height: 38px;
  border: 1px solid rgba(240, 208, 128, 0.35);
  border-radius: 9px;
  background: rgba(240, 208, 128, 0.14);
  color: var(--lnu-gold-light);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-weight: 600;
}

.logout-btn:hover {
  background: rgba(240, 208, 128, 0.22);
  border-color: rgba(240, 208, 128, 0.56);
}

.mobile-logout {
  display: none;
}

.main-shell {
  min-width: 0;
  height: 100vh;
  height: 100dvh;
  min-height: 0;
  display: flex;
  flex-direction: column;
}

.topbar {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
  border-bottom: 1px solid rgba(240, 208, 128, 0.3);
  background: linear-gradient(120deg, var(--lnu-navy), var(--lnu-navy-light));
  padding: 20px 24px;
  color: var(--lnu-white);
}

.topbar h1 {
  margin: 0;
  font-size: 28px;
  line-height: 1.2;
}

.topbar p {
  margin: 6px 0 0;
  font-size: 14px;
  color: rgba(255, 255, 255, 0.82);
}

.topbar-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.exam-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border: 1px solid rgba(240, 208, 128, 0.55);
  border-radius: 999px;
  background: linear-gradient(180deg, var(--lnu-gold-light), var(--lnu-gold));
  padding: 7px 12px;
  color: var(--lnu-navy-deep);
  font-size: 12px;
  font-weight: 600;
}

.exam-chip strong {
  font-size: 12px;
}

.notif-btn {
  width: 34px;
  height: 34px;
  border-radius: 9px;
  border: 1px solid rgba(240, 208, 128, 0.4);
  background: rgba(255, 255, 255, 0.12);
  color: var(--lnu-gold-light);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.notif-btn:hover {
  color: var(--lnu-gold-light);
  background: rgba(255, 255, 255, 0.2);
}

.content-scroll {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 22px 24px;
  background:
    radial-gradient(circle at 100% 0%, rgba(201, 168, 76, 0.1), transparent 25%),
    transparent;
}

.dashboard-view {
  display: grid;
  gap: 18px;
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}

.stat-card {
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.97), rgba(249, 248, 244, 0.95));
  border: 1px solid rgba(26, 35, 126, 0.14);
  border-radius: var(--radius-md);
  padding: 14px;
  display: flex;
  align-items: center;
  gap: 10px;
  box-shadow: var(--shadow-sm);
}

.stat-icon {
  width: 34px;
  height: 34px;
  border-radius: 9px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.tone-navy {
  background: rgba(26, 35, 126, 0.12);
  color: var(--lnu-navy);
}

.tone-success {
  background: rgba(46, 125, 50, 0.12);
  color: var(--lnu-success);
}

.tone-gold {
  background: rgba(201, 168, 76, 0.2);
  color: var(--lnu-navy-deep);
}

.stat-label {
  margin: 0;
  font-size: 12px;
  color: var(--lnu-text-muted);
}

.stat-value {
  margin: 2px 0 0;
  font-size: 24px;
  font-weight: 700;
}

.stat-trend {
  margin: 2px 0 0;
  font-size: 11px;
  font-weight: 600;
}

.positive {
  color: var(--lnu-success);
}

.negative {
  color: var(--lnu-danger);
}

.dashboard-grid {
  display: grid;
  grid-template-columns: minmax(0, 300px) minmax(0, 1fr);
  gap: 14px;
}

.dashboard-grid.bottom {
  grid-template-columns: minmax(0, 1fr) minmax(0, 350px);
}

.surface-card {
  background: linear-gradient(180deg, rgba(201, 168, 76, 0.08), rgba(255, 255, 255, 0.96));
  border: 1px solid rgba(26, 35, 126, 0.14);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
  padding: 18px;
}

.surface-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 14px;
}

.surface-head h3 {
  margin: 0;
  font-size: 16px;
}

.pill {
  border-radius: 999px;
  padding: 5px 10px;
  font-size: 11px;
  font-weight: 700;
}

.pill.success {
  background: rgba(46, 125, 50, 0.18);
  color: var(--lnu-success);
}

.pill.navy {
  background: rgba(26, 35, 126, 0.16);
  color: var(--lnu-navy-deep);
}

.pill.neutral {
  background: rgba(201, 168, 76, 0.2);
  color: var(--lnu-navy-deep);
}

.probability-meter {
  display: flex;
  justify-content: center;
  margin-bottom: 14px;
}

.meter-ring {
  --size: 140px;
  --line: 13px;
  width: var(--size);
  height: var(--size);
  border-radius: 50%;
  display: grid;
  place-items: center;
  background:
    radial-gradient(closest-side, var(--lnu-bg) calc(100% - var(--line)), transparent 0 99.9%, transparent 0),
    conic-gradient(var(--lnu-success) calc(var(--value) * 1%), rgba(13, 21, 71, 0.08) 0);
}

.meter-ring strong {
  display: block;
  text-align: center;
  font-size: 24px;
}

.meter-ring span {
  display: block;
  text-align: center;
  font-size: 11px;
  color: var(--lnu-text-muted);
  margin-top: 2px;
}

.metric-list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 8px;
}

.metric-list li {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 13px;
}

.metric-list span {
  color: var(--lnu-text-muted);
}

.metric-list strong {
  color: var(--lnu-text);
}

.metric-list .ok {
  color: var(--lnu-success);
}

.subject-list {
  display: grid;
  gap: 12px;
}

.subject-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  font-size: 13px;
}

.subject-head .ok {
  color: var(--lnu-success);
}

.subject-head .danger {
  color: var(--lnu-danger);
}

.bar-track {
  margin-top: 5px;
  height: 8px;
  border-radius: 999px;
  background: var(--lnu-gray);
  overflow: hidden;
}

.bar-fill {
  height: 100%;
  border-radius: 999px;
  transition: width 0.3s ease;
}

.bar-fill.ok {
  background: var(--lnu-success);
}

.bar-fill.danger {
  background: var(--lnu-danger);
}

.history-chart {
  height: 170px;
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 8px;
  align-items: end;
}

.history-item {
  display: grid;
  gap: 4px;
  text-align: center;
}

.history-item span {
  font-size: 11px;
  font-weight: 600;
}

.history-item small {
  font-size: 10px;
  color: var(--lnu-text-muted);
}

.history-bar-track {
  height: 114px;
  border-radius: 8px 8px 4px 4px;
  background: rgba(13, 21, 71, 0.12);
  display: flex;
  align-items: flex-end;
  overflow: hidden;
}

.history-bar-fill {
  width: 100%;
  min-height: 8px;
  border-radius: 8px 8px 0 0;
}

.history-bar-fill.ok {
  background: var(--lnu-success);
}

.history-bar-fill.danger {
  background: var(--lnu-danger);
}

.activity-list {
  display: grid;
}

.activity-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 0;
  border-bottom: 1px solid rgba(13, 21, 71, 0.08);
}

.activity-item:last-child {
  border-bottom: none;
}

.activity-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  flex-shrink: 0;
}

.activity-dot.ok {
  background: var(--lnu-success);
}

.activity-dot.danger {
  background: var(--lnu-danger);
}

.activity-content {
  flex: 1;
  min-width: 0;
}

.activity-content strong {
  display: block;
  font-size: 13px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.activity-content small {
  display: block;
  margin-top: 2px;
  font-size: 11px;
  color: var(--lnu-text-muted);
}

.ok {
  color: var(--lnu-success);
}

.danger {
  color: var(--lnu-danger);
}

.room-view {
  display: grid;
  gap: 12px;
}

.room-shell-card {
  padding: 0;
  border: 1px solid rgba(13, 21, 71, 0.12);
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(249, 248, 244, 0.95));
  overflow: hidden;
}

.room-page-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 18px;
  border-bottom: 1px solid rgba(13, 21, 71, 0.12);
}

.room-page-title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: var(--lnu-navy-deep);
}

.room-page-title h3 {
  margin: 0;
  font-size: 28px;
}

.add-room-btn {
  height: 36px;
  padding: 0 12px;
  font-size: 13px;
}

.room-empty-state {
  min-height: 320px;
  display: grid;
  place-items: center;
  text-align: center;
  padding: 18px;
  color: var(--lnu-text-muted);
}

.room-empty-state h4 {
  margin: 10px 0 6px;
  font-size: 42px;
  color: var(--lnu-text);
}

.room-empty-state p {
  margin: 0;
  max-width: 380px;
  font-size: 14px;
}

.spin-soft {
  animation: spin 0.95s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.room-layout {
  display: grid;
  grid-template-columns: minmax(0, 330px) minmax(0, 1fr);
  min-height: 520px;
  background:
    radial-gradient(circle at 0% 0%, rgba(201, 168, 76, 0.08), transparent 42%),
    transparent;
}

.room-list-panel {
  padding: 18px;
  border-right: 1px solid rgba(13, 21, 71, 0.12);
  background: linear-gradient(180deg, rgba(248, 244, 228, 0.82), rgba(255, 255, 255, 0.98));
  display: grid;
  align-content: start;
  gap: 12px;
}

.room-list-panel .muted {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.room-list-panel .muted strong {
  color: var(--lnu-navy-deep);
  font-size: 12px;
}

.room-detail-panel {
  padding: 18px;
  display: grid;
  align-content: start;
  gap: 14px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.86), rgba(255, 255, 255, 0.98));
}

.room-detail-loading {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--lnu-text-muted);
}

.room-detail-head {
  display: flex;
  align-items: flex-start;
  justify-content: flex-start;
  gap: 10px;
}

.room-detail-head h4 {
  margin: 0;
  font-size: 24px;
  line-height: 1.2;
  font-weight: 800;
  color: var(--lnu-text);
}

.room-detail-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border: 1px solid rgba(13, 21, 71, 0.14);
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.9);
  box-shadow: 0 8px 20px rgba(13, 21, 71, 0.06);
  padding: 12px 14px;
}

.room-code-chip {
  display: grid;
  gap: 3px;
}

.room-code-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  color: var(--lnu-text-muted);
}

.room-code-value {
  font-family: 'Courier New', monospace;
  font-size: 20px;
  font-weight: 700;
  letter-spacing: 0.06em;
  color: var(--lnu-navy-deep);
}

.room-action-group {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.room-detail-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr);
  gap: 14px;
}

.detail-card {
  border: 1px solid rgba(13, 21, 71, 0.14);
  border-radius: 14px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(253, 252, 247, 0.96));
  box-shadow: 0 8px 18px rgba(13, 21, 71, 0.05);
  padding: 16px;
}

.room-section-head h5 {
  margin: 0;
  font-size: 21px;
  line-height: 1.2;
  color: var(--lnu-navy-deep);
}

.room-section-head {
  margin-bottom: 12px;
}

.exam-card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: 12px;
}

.exam-card {
  border: 1px solid rgba(156, 120, 136, 0.22);
  border-radius: 12px;
  background: linear-gradient(180deg, #f6ecef, #efe2e5);
  color: #3f3a3d;
  text-align: left;
  padding: 12px;
  min-height: 136px;
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.2s ease;
}

.exam-card:hover {
  transform: translateY(-1px);
  box-shadow: 0 8px 18px rgba(13, 21, 71, 0.1);
}

.exam-card-title {
  display: block;
  font-size: 15px;
  font-weight: 700;
  line-height: 1.2;
  color: #3f3a3d;
}

.exam-card-meta,
.exam-card-date {
  margin: 8px 0 0;
  font-size: 13px;
  color: #666;
}

.member-list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 10px;
}

.member-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  border: 1px solid rgba(13, 21, 71, 0.12);
  border-radius: 12px;
  background: linear-gradient(180deg, #f2f3f5, #eaecf0);
  padding: 11px 12px;
}

.member-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: #53a9db;
  color: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.member-item > div {
  flex: 1;
  min-width: 0;
}

.member-item strong {
  display: block;
  font-size: 14px;
  color: #3f3f3f;
}

.member-item p {
  margin: 2px 0 0;
  color: var(--lnu-text-muted);
  font-size: 12px;
}

.empty-detail {
  margin-top: 2px;
}

.room-detail-empty {
  min-height: 260px;
  border: 1px dashed rgba(13, 21, 71, 0.2);
  border-radius: 12px;
  color: var(--lnu-text-muted);
  display: grid;
  place-items: center;
  text-align: center;
  padding: 16px;
}

.room-detail-empty h4 {
  margin: 10px 0 6px;
  color: var(--lnu-text);
}

.room-detail-empty p {
  margin: 0;
  max-width: 320px;
}

.room-tools {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.muted {
  margin: 0;
  color: var(--lnu-text-muted);
  font-size: 13px;
}

.field-stack {
  display: grid;
  gap: 8px;
}

.field-label {
  font-size: 13px;
  font-weight: 600;
  color: var(--lnu-text);
}

.inline-form {
  margin-top: 12px;
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.text-input {
  flex: 1;
  min-width: 180px;
  height: 40px;
  border: 1px solid rgba(26, 35, 126, 0.24);
  border-radius: 10px;
  padding: 0 12px;
  background: rgba(255, 255, 255, 0.96);
  color: var(--lnu-text);
}

.text-input:focus {
  outline: none;
  border-color: var(--lnu-navy);
  box-shadow: var(--focus-ring);
}

.text-input.code {
  font-family: 'Courier New', monospace;
  letter-spacing: 1px;
  text-transform: uppercase;
}

.primary-btn {
  height: 40px;
  border: none;
  border-radius: 10px;
  background: linear-gradient(135deg, var(--lnu-navy), var(--lnu-navy-light));
  color: var(--lnu-gold-light);
  padding: 0 14px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 700;
}

.primary-btn:hover:not(:disabled) {
  background: linear-gradient(135deg, var(--lnu-navy-light), var(--lnu-navy));
}

.primary-btn:disabled,
.ghost-btn:disabled,
.danger-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.ghost-btn {
  height: 34px;
  border: 1px solid rgba(26, 35, 126, 0.24);
  border-radius: 9px;
  background: rgba(201, 168, 76, 0.14);
  color: var(--lnu-navy-deep);
  padding: 0 10px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.ghost-btn:hover:not(:disabled) {
  color: var(--lnu-navy);
  border-color: rgba(26, 35, 126, 0.44);
  background: rgba(201, 168, 76, 0.2);
}

.danger-btn {
  height: 34px;
  border: 1px solid rgba(198, 40, 40, 0.3);
  border-radius: 9px;
  background: #ff4f52;
  color: #fff;
  padding: 0 12px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 700;
}

.danger-btn:hover:not(:disabled) {
  background: #ec3e42;
  border-color: rgba(198, 40, 40, 0.4);
}

.feedback {
  border-radius: 10px;
  border: 1px solid transparent;
  padding: 10px 12px;
  font-size: 13px;
  display: inline-flex;
  align-items: center;
  gap: 7px;
}

.feedback.success {
  background: rgba(46, 125, 50, 0.1);
  border-color: rgba(46, 125, 50, 0.2);
  color: var(--lnu-success);
}

.feedback.danger {
  background: rgba(198, 40, 40, 0.1);
  border-color: rgba(198, 40, 40, 0.2);
  color: var(--lnu-danger);
}

.feedback.info {
  background: rgba(26, 35, 126, 0.1);
  border-color: rgba(26, 35, 126, 0.2);
  color: var(--lnu-navy);
}

.room-list {
  display: grid;
  gap: 10px;
  max-height: 460px;
  overflow-y: auto;
  padding-right: 4px;
}

.room-item {
  width: 100%;
  border: 1px solid rgba(26, 35, 126, 0.16);
  border-radius: 12px;
  padding: 12px 13px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(249, 248, 244, 0.94));
  text-align: left;
}

.room-item-clickable {
  cursor: pointer;
  transition: all 0.18s ease;
}

.room-item-clickable:hover {
  border-color: rgba(26, 35, 126, 0.44);
  background: linear-gradient(180deg, rgba(240, 208, 128, 0.24), rgba(255, 255, 255, 0.98));
  transform: translateY(-1px);
}

.room-item.active {
  border-color: rgba(26, 35, 126, 0.56);
  box-shadow: 0 0 0 2px rgba(26, 35, 126, 0.12);
}

.room-item strong {
  display: block;
  font-size: 14px;
  color: var(--lnu-navy-deep);
}

.room-item p {
  margin: 2px 0 0;
  color: var(--lnu-text-muted);
  font-size: 12px;
}

.room-meta {
  text-align: right;
  display: grid;
  gap: 2px;
  justify-items: end;
}

.room-meta code {
  display: block;
  font-size: 14px;
  font-weight: 700;
  color: var(--lnu-navy);
}

.room-meta small {
  display: block;
  margin-top: 2px;
  color: var(--lnu-text-muted);
}

.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 50;
  background: rgba(6, 10, 30, 0.58);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
}

.modal-card {
  width: min(520px, 100%);
  border-radius: 14px;
  border: 1px solid rgba(26, 35, 126, 0.16);
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(253, 246, 227, 0.94));
  box-shadow: var(--shadow-md);
  padding: 16px;
  display: grid;
  gap: 12px;
}

.library-modal-card {
  width: min(980px, 100%);
  max-height: min(92vh, 900px);
  overflow: auto;
  padding: 0;
}

.modal-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.modal-head h4 {
  margin: 0;
  font-size: 20px;
  color: var(--lnu-navy-deep);
}

.modal-close {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid rgba(13, 21, 71, 0.16);
  background: rgba(255, 255, 255, 0.86);
  color: var(--lnu-text-muted);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.modal-close:hover {
  color: var(--lnu-text);
  border-color: rgba(13, 21, 71, 0.32);
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

.library-modal-head {
  position: sticky;
  top: 0;
  z-index: 2;
  padding: 16px 18px 14px;
  border-bottom: 1px solid rgba(13, 21, 71, 0.14);
  background: linear-gradient(180deg, rgba(26, 35, 126, 0.1), rgba(255, 255, 255, 0.98) 72%);
  backdrop-filter: blur(1px);
}

.library-modal-title-wrap {
  min-width: 0;
}

.library-modal-title-wrap h4 {
  margin: 0;
  font-size: 22px;
}

.library-modal-title-wrap p {
  margin: 6px 0 0;
  color: var(--lnu-text-muted);
  font-size: 13px;
  line-height: 1.4;
}

.library-modal-body {
  padding: 16px 18px;
  display: grid;
  grid-template-columns: minmax(280px, 360px) minmax(0, 1fr);
  gap: 14px;
}

.library-form-panel {
  border: 1px solid rgba(13, 21, 71, 0.12);
  border-radius: 12px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(249, 248, 244, 0.95));
  padding: 12px;
  display: grid;
  gap: 12px;
  align-content: start;
}

.library-upload-panel {
  border: 1px dashed rgba(26, 35, 126, 0.32);
  border-radius: 10px;
  background: rgba(26, 35, 126, 0.03);
  padding: 10px;
  display: grid;
  gap: 8px;
}

.library-upload-note {
  margin: 0;
  font-size: 12px;
  line-height: 1.4;
  color: var(--lnu-text-muted);
}

.library-upload-note code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  font-size: 11px;
  color: var(--lnu-navy);
}

.file-input {
  width: 100%;
  min-height: 44px;
  border: 1px solid rgba(26, 35, 126, 0.24);
  border-radius: 10px;
  padding: 6px 8px;
  background: rgba(255, 255, 255, 0.96);
  color: var(--lnu-text);
  font-size: 13px;
}

.file-input::file-selector-button {
  border: none;
  border-radius: 8px;
  background: linear-gradient(135deg, var(--lnu-navy), var(--lnu-navy-light));
  color: var(--lnu-gold-light);
  padding: 7px 10px;
  margin-right: 10px;
  font-weight: 700;
  cursor: pointer;
}

.file-input:disabled {
  opacity: 0.75;
  cursor: not-allowed;
}

.file-input:focus {
  outline: none;
  border-color: var(--lnu-navy);
  box-shadow: var(--focus-ring);
}

.library-file-chip {
  margin: 0;
  padding: 8px 10px;
  border-radius: 9px;
  border: 1px solid rgba(26, 35, 126, 0.16);
  background: rgba(240, 208, 128, 0.22);
  color: var(--lnu-navy-deep);
  font-size: 12px;
  font-weight: 600;
  word-break: break-word;
}

.digitalized-preview {
  border: 1px solid rgba(13, 21, 71, 0.14);
  border-radius: 12px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(249, 248, 244, 0.94));
  padding: 12px;
  display: flex;
  flex-direction: column;
  min-height: 380px;
}

.digitalized-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.digitalized-head h5 {
  margin: 0;
  font-size: 16px;
  color: var(--lnu-navy-deep);
}

.digitalized-list {
  display: grid;
  gap: 10px;
  max-height: 500px;
  overflow: auto;
  padding-right: 3px;
}

.digitalized-empty {
  flex: 1;
  min-height: 230px;
  border: 1px dashed rgba(13, 21, 71, 0.2);
  border-radius: 10px;
  background: rgba(13, 21, 71, 0.03);
  color: var(--lnu-text-muted);
  display: grid;
  place-items: center;
  text-align: center;
  gap: 8px;
  padding: 16px;
}

.digitalized-empty p {
  margin: 0;
  max-width: 300px;
  line-height: 1.4;
}

.digitalized-card {
  border: 1px solid rgba(26, 35, 126, 0.14);
  border-radius: 10px;
  padding: 10px 11px;
  background: #fff;
  display: grid;
  gap: 8px;
}

.digitalized-question {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
  color: var(--lnu-navy-deep);
  line-height: 1.4;
}

.digitalized-options {
  display: grid;
  gap: 6px;
}

.digitalized-option {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: var(--lnu-text);
  font-size: 13px;
}

.digitalized-option input {
  accent-color: var(--lnu-navy);
}

.digitalized-open-ended {
  margin: 0;
  color: var(--lnu-text-muted);
  font-size: 13px;
  font-style: italic;
}

.digitalized-answer {
  margin: 0;
  color: var(--lnu-success);
  font-size: 12px;
  font-weight: 700;
}

.library-modal-actions {
  position: sticky;
  bottom: 0;
  z-index: 1;
  border-top: 1px solid rgba(13, 21, 71, 0.14);
  padding: 12px 18px 14px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.94), #fff);
}

.library-view {
  min-height: 100%;
}

.library-empty-canvas {
  min-height: 420px;
  border-radius: 12px;
  border: 1px solid rgba(13, 21, 71, 0.12);
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.92), rgba(249, 248, 244, 0.88));
}

.placeholder-view {
  min-height: 100%;
  display: grid;
  place-items: center;
}

.placeholder-card {
  max-width: 420px;
  text-align: center;
  background: linear-gradient(180deg, rgba(26, 35, 126, 0.06), rgba(201, 168, 76, 0.12));
}

.placeholder-card :deep(svg) {
  color: var(--lnu-navy-light);
}

.placeholder-card h3 {
  margin: 14px 0 8px;
  font-size: 24px;
}

.placeholder-card p {
  margin: 0;
  color: var(--lnu-text-muted);
}

@media (max-width: 1200px) {
  .stats-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .library-modal-body {
    grid-template-columns: 1fr;
  }

  .digitalized-preview {
    min-height: 320px;
  }

  .dashboard-grid,
  .dashboard-grid.bottom {
    grid-template-columns: 1fr;
  }

  .room-tools {
    grid-template-columns: 1fr;
  }

  .room-layout {
    grid-template-columns: 1fr;
  }

  .room-list-panel {
    border-right: none;
    border-bottom: 1px solid rgba(13, 21, 71, 0.12);
  }

  .room-list {
    max-height: 280px;
  }

  .room-detail-grid {
    grid-template-columns: 1fr;
  }

  .room-detail-toolbar {
    flex-wrap: wrap;
  }

  .room-section-head h5 {
    font-size: 20px;
  }
}

@media (max-width: 900px) {
  .dashboard-shell {
    display: flex;
    flex-direction: column;
    min-height: 0;
  }

  .library-modal-card {
    max-height: calc(100vh - 16px);
  }

  .library-modal-head {
    padding: 14px 14px 12px;
  }

  .library-modal-body {
    padding: 12px 14px;
  }

  .library-modal-actions {
    padding: 10px 14px 12px;
  }

  .sidebar {
    width: 100%;
    height: auto;
    position: static;
    top: auto;
    align-self: stretch;
    padding: 10px 12px 8px;
    border-right: none;
    border-bottom: 1px solid rgba(240, 208, 128, 0.24);
  }

  .sidebar.collapsed {
    width: 100%;
  }

  .sidebar-toggle {
    display: none;
  }

  .sidebar-top {
    justify-content: space-between;
    gap: 10px;
  }

  .brand {
    margin-right: auto;
  }

  .mobile-logout {
    display: inline-flex;
    flex-shrink: 0;
    min-width: 118px;
    height: 36px;
    padding: 0 12px;
    border-radius: 10px;
  }

  .sidebar-nav {
    margin-top: 12px;
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 4px;
    scrollbar-width: none;
  }

  .sidebar-nav::-webkit-scrollbar {
    display: none;
  }

  .nav-item {
    flex: 0 0 auto;
    min-width: 132px;
    justify-content: center;
  }

  .sidebar.collapsed .nav-item {
    justify-content: center;
  }

  .sidebar-footer {
    display: none;
  }

  .user-tile {
    display: none;
  }

  .topbar {
    padding: 16px;
  }

  .content-scroll {
    min-height: unset;
    overflow-y: visible;
    padding: 16px;
  }

  .main-shell {
    height: auto;
    min-height: 0;
  }
}

@media (max-width: 640px) {
  .stats-grid {
    grid-template-columns: 1fr;
  }

  .topbar {
    flex-direction: column;
    align-items: flex-start;
  }

  .topbar h1 {
    font-size: 24px;
  }

  .nav-item {
    min-width: 120px;
    height: 38px;
  }

  .room-page-head {
    flex-direction: column;
    align-items: stretch;
  }

  .add-room-btn {
    width: 100%;
    justify-content: center;
  }

  .room-empty-state h4 {
    font-size: 34px;
  }

  .room-list-panel,
  .room-detail-panel {
    padding: 12px;
  }

  .room-list {
    max-height: none;
  }

  .room-detail-toolbar {
    flex-direction: column;
    align-items: stretch;
  }

  .room-action-group {
    width: 100%;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .room-code-value {
    font-size: 18px;
  }

  .exam-card-grid {
    grid-template-columns: 1fr;
  }

  .room-item:not(.room-item-clickable) {
    flex-direction: column;
    align-items: flex-start;
  }

  .room-meta {
    text-align: left;
  }

  .inline-form {
    flex-direction: column;
    align-items: stretch;
  }

  .inline-form .primary-btn,
  .inline-form .text-input {
    width: 100%;
  }

  .modal-actions {
    flex-direction: column-reverse;
  }

  .modal-actions .primary-btn,
  .modal-actions .ghost-btn {
    width: 100%;
    justify-content: center;
  }
}
</style>
