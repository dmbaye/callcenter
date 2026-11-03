<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <h3
    data-slot="card-title"
    :class="cn('leading-[var(--card-title-leading)] [font-weight:var(--card-title-weight)] text-[var(--heading)]', props.class)"
  >
    <slot />
  </h3>
</template>
