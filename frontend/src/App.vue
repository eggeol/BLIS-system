<template>
  <router-view />
</template>

<script setup>
import { onMounted } from 'vue'
import { useAuthStore } from './store/auth.store'

const auth = useAuthStore()

onMounted(() => {
  if (!auth.initialized) {
    auth.fetchMe({ silent: true })
  }
})
</script>
