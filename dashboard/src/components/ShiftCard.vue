<script setup lang="ts">
import { computed, ref } from 'vue'
import type { RuleViolation, Shift, StaffMember } from '../api/types'
import { duration } from '../week'
import ViolationList from './ViolationList.vue'

const props = defineProps<{
  shift: Shift
  staff: StaffMember[]
  /** Result of the last assign attempt on this shift. */
  feedback?: RuleViolation[]
  busy?: boolean
}>()

const emit = defineEmits<{
  assign: [staffMemberId: string]
  unassign: [assignmentId: string]
}>()

const selected = ref('')
const full = computed(() => props.shift.assignments.length >= props.shift.headcount)
const assignedIds = computed(() => new Set(props.shift.assignments.map((a) => a.staffMember.id)))
const candidates = computed(() => props.staff.filter((s) => !assignedIds.value.has(s.id)))

function assign(): void {
  if (selected.value) {
    emit('assign', selected.value)
    selected.value = ''
  }
}
</script>

<template>
  <article class="shift" :class="{ full, understaffed: !full }" :data-shift-id="shift.id">
    <header>
      <strong class="time">{{ shift.startLocal }}–{{ shift.endLocal }}</strong>
      <span class="duration">{{ duration(shift.durationMinutes) }}</span>
      <span
        v-if="shift.crossesDstTransition"
        class="dst"
        title="Clocks change during this shift: the duration is real elapsed time"
      >DST</span>
    </header>
    <p class="role">
      {{ shift.jobRole }}<template v-if="shift.requiredSkill"> · needs {{ shift.requiredSkill }}</template>
    </p>
    <p class="fill">{{ shift.assignments.length }}/{{ shift.headcount }} staffed</p>

    <ul class="assigned">
      <li v-for="a in shift.assignments" :key="a.id">
        {{ a.staffMember.name }}
        <button
          type="button"
          class="link"
          :aria-label="`Remove ${a.staffMember.name}`"
          :disabled="busy"
          @click="emit('unassign', a.id)"
        >×</button>
      </li>
    </ul>

    <form v-if="!full" class="assign" @submit.prevent="assign">
      <select v-model="selected" :aria-label="`Assign staff to ${shift.startLocal} shift`" :disabled="busy">
        <option value="">Assign…</option>
        <option v-for="s in candidates" :key="s.id" :value="s.id">{{ s.name }} ({{ s.jobRole }})</option>
      </select>
      <button type="submit" :disabled="busy || !selected">Assign</button>
    </form>

    <ViolationList v-if="feedback?.length" :violations="feedback" />
  </article>
</template>
