<template>
  <div class="auth-shell">
    <aside class="auth-brand">
      <div class="brand-card">
        <div class="brand-seal">
          <GraduationCap :size="30" />
        </div>
        <p class="brand-label">LNU LLE Platform</p>
        <h1>Create your reviewer account</h1>
        <p class="brand-copy">
          Register once and access your exam rooms, analytics, and mock examination modules.
        </p>

        <div class="brand-list">
          <div class="brand-item" v-for="item in highlights" :key="item.text">
            <component :is="item.icon" :size="16" class="brand-item-icon" />
            <span>{{ item.text }}</span>
          </div>
        </div>
      </div>
    </aside>

    <main class="auth-main">
      <section class="auth-card">
        <header class="auth-header">
          <p class="eyebrow">Registration</p>
          <h2>Create Account</h2>
          <p class="subtitle">Complete your details to start using the platform.</p>
        </header>

        <div v-if="apiError" class="alert alert-danger">{{ apiError }}</div>

        <form class="auth-form" @submit.prevent="handleSubmit">
          <label class="field-group">
            <span class="field-label">Full name</span>
            <div class="field-wrap">
              <UserRound :size="17" class="field-icon" />
              <input
                v-model="form.name"
                type="text"
                class="field-input"
                placeholder="e.g. Juan dela Cruz"
                autocomplete="name"
              />
            </div>
          </label>

          <label class="field-group">
            <span class="field-label">Student ID</span>
            <div class="field-wrap">
              <Hash :size="17" class="field-icon" />
              <input
                v-model="form.student_id"
                type="text"
                class="field-input"
                placeholder="e.g. 2301290"
                inputmode="numeric"
                pattern="[0-9]*"
                autocomplete="off"
              />
            </div>
          </label>

          <label class="field-group">
            <span class="field-label">Email address</span>
            <div class="field-wrap">
              <Mail :size="17" class="field-icon" />
              <input
                v-model="form.email"
                type="email"
                class="field-input"
                placeholder="you@lnu.edu.ph"
                autocomplete="email"
              />
            </div>
          </label>

          <div class="field-grid">
            <label class="field-group">
              <span class="field-label">Password</span>
              <div class="field-wrap">
                <LockKeyhole :size="17" class="field-icon" />
                <input
                  v-model="form.password"
                  :type="showPw ? 'text' : 'password'"
                  class="field-input field-input-with-toggle"
                  placeholder="Min. 8 characters"
                  autocomplete="new-password"
                />
                <button type="button" class="field-toggle" @click="showPw = !showPw">
                  <EyeOff v-if="showPw" :size="16" />
                  <Eye v-else :size="16" />
                </button>
              </div>
            </label>

            <label class="field-group">
              <span class="field-label">Confirm password</span>
              <div class="field-wrap">
                <LockKeyhole :size="17" class="field-icon" />
                <input
                  v-model="form.password_confirmation"
                  :type="showPw ? 'text' : 'password'"
                  class="field-input"
                  :class="{ 'field-input-error': pwMismatch }"
                  placeholder="Re-enter password"
                  autocomplete="new-password"
                />
              </div>
              <span v-if="pwMismatch" class="field-error">Passwords do not match.</span>
            </label>
          </div>

          <div class="pw-strength" v-if="form.password">
            <div class="pw-track">
              <div class="pw-fill" :style="{ width: `${pwStrength.pct}%`, background: pwStrength.color }" />
            </div>
            <span class="pw-label" :style="{ color: pwStrength.color }">{{ pwStrength.label }}</span>
          </div>

          <label class="terms-wrap">
            <input type="checkbox" v-model="form.agreed" />
            <span>
              I agree to the
              <a href="#" class="inline-link">Terms of Use</a>
              and
              <a href="#" class="inline-link">Privacy Policy</a>
            </span>
          </label>

          <button type="submit" class="submit-btn" :disabled="isLoading">
            <span v-if="!isLoading">Create Account</span>
            <span v-else class="spinner" />
          </button>
        </form>

        <p class="auth-footer">
          Already registered?
          <router-link to="/login" class="inline-link strong">Sign in</router-link>
        </p>
      </section>
    </main>
  </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/store/auth.store'
import {
  BarChart3,
  ClipboardCheck,
  Eye,
  EyeOff,
  GraduationCap,
  Hash,
  LockKeyhole,
  Mail,
  ShieldCheck,
  UserRound,
} from 'lucide-vue-next'

const form = reactive({
  name: '',
  student_id: '',
  email: '',
  password: '',
  password_confirmation: '',
  agreed: false,
})

const showPw = ref(false)
const isLoading = ref(false)
const apiError = ref('')

const auth = useAuthStore()
const router = useRouter()

const highlights = [
  { icon: ClipboardCheck, text: 'Room-based class exam management' },
  { icon: BarChart3, text: 'Clear analytics and score tracking' },
  { icon: ShieldCheck, text: 'Role-based access for each account' },
]

const pwMismatch = computed(() =>
  form.password_confirmation.length > 0 && form.password !== form.password_confirmation,
)

const pwStrength = computed(() => {
  const pw = form.password
  if (pw.length < 6) return { pct: 20, color: 'var(--lnu-danger)', label: 'Weak' }
  if (pw.length < 10) return { pct: 55, color: 'var(--lnu-gold)', label: 'Fair' }
  if (/[A-Z]/.test(pw) && /[0-9]/.test(pw)) return { pct: 100, color: 'var(--lnu-success)', label: 'Strong' }
  return { pct: 75, color: 'var(--lnu-gold)', label: 'Good' }
})

async function handleSubmit() {
  apiError.value = ''

  if (!form.name || !form.student_id || !form.email || !form.password || !form.password_confirmation) {
    apiError.value = 'Please complete all required fields.'
    return
  }

  if (!/^\d{7,20}$/.test(form.student_id.trim())) {
    apiError.value = 'Student ID must be 7 to 20 digits.'
    return
  }

  if (pwMismatch.value) {
    apiError.value = 'Passwords do not match.'
    return
  }

  if (!form.agreed) {
    apiError.value = 'Please agree to the Terms of Use.'
    return
  }

  isLoading.value = true
  try {
    await auth.register(
      form.name,
      form.student_id.trim(),
      form.email,
      form.password,
      form.password_confirmation,
    )
    await router.push('/dashboard')
  } catch (error) {
    apiError.value = error.response?.data?.message ?? 'Registration failed. Please try again.'
  } finally {
    isLoading.value = false
  }
}
</script>

<style scoped>
.auth-shell {
  min-height: 100vh;
  display: grid;
  grid-template-columns: minmax(320px, 460px) 1fr;
  background:
    radial-gradient(circle at 10% 12%, rgba(26, 35, 126, 0.12), transparent 32%),
    radial-gradient(circle at 90% 90%, rgba(201, 168, 76, 0.24), transparent 35%),
    var(--lnu-bg);
}

.auth-brand {
  background: linear-gradient(155deg, var(--lnu-navy-deep), var(--lnu-navy));
  padding: 42px 34px;
  color: var(--lnu-white);
  display: flex;
}

.brand-card {
  border: 1px solid rgba(240, 208, 128, 0.25);
  border-radius: var(--radius-lg);
  padding: 30px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.06), rgba(255, 255, 255, 0));
  box-shadow: var(--shadow-md);
  width: 100%;
  display: flex;
  flex-direction: column;
}

.brand-seal {
  width: 58px;
  height: 58px;
  border-radius: 16px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(240, 208, 128, 0.16);
  color: var(--lnu-gold-light);
  border: 1px solid rgba(240, 208, 128, 0.35);
}

.brand-label {
  margin-top: 22px;
  font-size: 14px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: rgba(240, 208, 128, 0.88);
}

.brand-card h1 {
  margin: 10px 0 0;
  font-size: 31px;
  line-height: 1.2;
  color: var(--lnu-gold-light);
}

.brand-copy {
  margin-top: 14px;
  color: rgba(255, 255, 255, 0.82);
  font-size: 15px;
}

.brand-list {
  margin-top: auto;
  display: grid;
  gap: 12px;
  padding-top: 28px;
}

.brand-item {
  display: flex;
  align-items: center;
  gap: 10px;
  border: 1px solid rgba(255, 255, 255, 0.18);
  border-radius: var(--radius-md);
  padding: 11px 12px;
  font-size: 14px;
  color: rgba(255, 255, 255, 0.88);
}

.brand-item-icon {
  color: var(--lnu-gold-light);
  flex-shrink: 0;
}

.auth-main {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 42px 24px;
  background:
    radial-gradient(circle at top right, rgba(26, 35, 126, 0.12), transparent 40%),
    radial-gradient(circle at bottom left, rgba(201, 168, 76, 0.2), transparent 42%);
}

.auth-card {
  width: 100%;
  max-width: 540px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.97), rgba(253, 246, 227, 0.94));
  border: 1px solid rgba(26, 35, 126, 0.16);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-sm);
  padding: 32px;
}

.auth-header h2 {
  margin: 6px 0 4px;
  font-size: 28px;
  line-height: 1.2;
  color: var(--lnu-text);
}

.eyebrow {
  font-size: 14px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--lnu-navy-light);
  font-weight: 700;
}

.subtitle {
  font-size: 15px;
  color: var(--lnu-text-muted);
}

.alert {
  margin-top: 16px;
  border-radius: var(--radius-sm);
  padding: 10px 12px;
  font-size: 14px;
}

.alert-danger {
  background: rgba(198, 40, 40, 0.12);
  color: var(--lnu-danger);
  border: 1px solid rgba(198, 40, 40, 0.2);
}

.auth-form {
  margin-top: 20px;
  display: grid;
  gap: 16px;
}

.field-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
}

.field-group {
  display: grid;
  gap: 8px;
}

.field-label {
  font-size: 14px;
  font-weight: 600;
  color: var(--lnu-text);
}

.field-wrap {
  position: relative;
}

.field-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--lnu-navy-light);
  opacity: 0.8;
}

.field-input {
  width: 100%;
  height: 44px;
  border: 1px solid rgba(13, 21, 71, 0.2);
  border-radius: var(--radius-sm);
  padding: 0 12px 0 40px;
  color: var(--lnu-text);
  background: rgba(255, 255, 255, 0.9);
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.field-input:focus {
  border-color: var(--lnu-navy);
  box-shadow: var(--focus-ring);
  outline: none;
}

.field-input::placeholder {
  color: var(--lnu-gray-dark);
}

.field-select {
  appearance: none;
  padding-right: 38px;
}

.field-select-icon {
  position: absolute;
  top: 50%;
  right: 12px;
  transform: translateY(-50%);
  color: var(--lnu-text-muted);
  pointer-events: none;
}

.field-input-with-toggle {
  padding-right: 44px;
}

.field-toggle {
  position: absolute;
  top: 50%;
  right: 8px;
  transform: translateY(-50%);
  width: 30px;
  height: 30px;
  border: none;
  border-radius: 7px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--lnu-text-muted);
  background: transparent;
  transition: background 0.2s ease, color 0.2s ease;
}

.field-toggle:hover {
  background: rgba(13, 21, 71, 0.08);
  color: var(--lnu-text);
}

.field-input-error {
  border-color: var(--lnu-danger);
}

.field-error {
  font-size: 14px;
  color: var(--lnu-danger);
}

.pw-strength {
  display: flex;
  align-items: center;
  gap: 10px;
}

.pw-track {
  flex: 1;
  height: 5px;
  border-radius: 999px;
  background: var(--lnu-gray);
  overflow: hidden;
}

.pw-fill {
  height: 100%;
  border-radius: 999px;
  transition: width 0.25s ease;
}

.pw-label {
  font-size: 14px;
  font-weight: 600;
}

.terms-wrap {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  font-size: 14px;
  color: var(--lnu-text-muted);
}

.terms-wrap input {
  margin-top: 2px;
  accent-color: var(--lnu-navy);
}

.inline-link {
  color: var(--lnu-navy-light);
  text-decoration: none;
}

.inline-link:hover {
  text-decoration: underline;
}

.inline-link.strong {
  font-weight: 700;
}

.submit-btn {
  height: 44px;
  border: none;
  border-radius: var(--radius-sm);
  background: var(--lnu-navy);
  color: var(--lnu-gold-light);
  font-weight: 700;
  letter-spacing: 0.01em;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: transform 0.15s ease, background 0.2s ease, box-shadow 0.2s ease;
}

.submit-btn:hover:not(:disabled) {
  background: var(--lnu-navy-light);
  box-shadow: 0 10px 18px rgba(26, 35, 126, 0.25);
  transform: translateY(-1px);
}

.submit-btn:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}

.spinner {
  width: 16px;
  height: 16px;
  border-radius: 50%;
  border: 2px solid rgba(240, 208, 128, 0.35);
  border-top-color: var(--lnu-gold-light);
  animation: spin 0.8s linear infinite;
}

.auth-footer {
  margin-top: 18px;
  color: var(--lnu-text-muted);
  text-align: center;
  font-size: 15px;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

@media (max-width: 980px) {
  .auth-shell {
    grid-template-columns: 1fr;
  }

  .auth-brand {
    display: none;
  }

  .auth-main {
    padding: 24px 16px;
  }

  .auth-card {
    padding: 24px;
  }

  .field-grid {
    grid-template-columns: 1fr;
  }
}
</style>
