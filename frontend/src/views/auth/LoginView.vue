<template>
  <div class="auth-shell">
    <aside class="auth-brand">
      <div class="brand-card">
        <div class="brand-seal">
          <GraduationCap :size="30" />
        </div>
        <p class="brand-label">LNU LLE Platform</p>
        <h1>LLE Examination and Readiness System</h1>
        <p class="brand-copy">
          Access your reviewer workspace, take mock exams, and monitor your progress from one clean dashboard.
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
          <p class="eyebrow">Welcome back</p>
          <h2>Sign in to continue</h2>
          <p class="subtitle">Use your registered account to open your LLE workspace.</p>
        </header>

        <div v-if="apiError" class="alert alert-danger">{{ apiError }}</div>

        <form class="auth-form" @submit.prevent="handleSubmit">
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

          <label class="field-group">
            <span class="field-label">Password</span>
            <div class="field-wrap">
              <LockKeyhole :size="17" class="field-icon" />
              <input
                v-model="form.password"
                :type="showPw ? 'text' : 'password'"
                class="field-input field-input-with-toggle"
                placeholder="Enter your password"
                autocomplete="current-password"
              />
              <button type="button" class="field-toggle" @click="showPw = !showPw">
                <EyeOff v-if="showPw" :size="16" />
                <Eye v-else :size="16" />
              </button>
            </div>
          </label>

          <div class="form-row">
            <label class="remember-wrap">
              <input type="checkbox" v-model="form.remember" />
              <span>Remember me</span>
            </label>
            <router-link to="/forgot-password" class="inline-link">Forgot password?</router-link>
          </div>

          <button type="submit" class="submit-btn" :disabled="isLoading">
            <span v-if="!isLoading">Sign In</span>
            <span v-else class="spinner" />
          </button>
        </form>

        <p class="auth-footer">
          No account yet?
          <router-link to="/register" class="inline-link strong">Create one</router-link>
        </p>
      </section>
    </main>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/store/auth.store'
import {
  BarChart3,
  ClipboardCheck,
  Eye,
  EyeOff,
  GraduationCap,
  LockKeyhole,
  Mail,
  ShieldCheck,
} from 'lucide-vue-next'

const form = reactive({ email: '', password: '', remember: false })
const showPw = ref(false)
const isLoading = ref(false)
const apiError = ref('')

const auth = useAuthStore()
const router = useRouter()

const highlights = [
  { icon: ClipboardCheck, text: 'Practice tests and exam drills' },
  { icon: BarChart3, text: 'Performance analytics by subject' },
  { icon: ShieldCheck, text: 'Secure role-based access control' },
]

async function handleSubmit() {
  apiError.value = ''

  if (!form.email || !form.password) {
    apiError.value = 'Email and password are required.'
    return
  }

  isLoading.value = true
  try {
    await auth.login(form.email, form.password)
    await router.push('/dashboard')
  } catch (error) {
    apiError.value = error.response?.data?.message ?? 'Invalid email or password. Please try again.'
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
    radial-gradient(circle at 12% 8%, rgba(26, 35, 126, 0.12), transparent 30%),
    radial-gradient(circle at 88% 92%, rgba(201, 168, 76, 0.25), transparent 34%),
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
  max-width: 460px;
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

.form-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 2px;
  font-size: 14px;
}

.remember-wrap {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: var(--lnu-text-muted);
}

.remember-wrap input {
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
}
</style>

