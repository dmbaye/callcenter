<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CornerDownRight,
    Hash,
    Headset,
    ListTree,
    PhoneCall,
    PhoneIncoming,
    PhoneOff,
    Users,
    Volume2,
} from '@lucide/vue';
import type { Component } from 'vue';
import { index as ivr } from '@/routes/asterisk/ivr';
import { index as pjsip } from '@/routes/asterisk/pjsip';
import { index as queues } from '@/routes/asterisk/queues';
import type { CallFlowNode } from '@/types';

defineOptions({ name: 'CallFlowNode' });

defineProps<{ node: CallFlowNode }>();

const icons: Record<string, Component> = {
    number: PhoneIncoming,
    ivr: ListTree,
    key: Hash,
    queue: Users,
    extension: Headset,
    callback: PhoneCall,
    message: Volume2,
    hangup: PhoneOff,
    branch: CornerDownRight,
};

const links = { queues: queues().url, ivr: ivr().url, pjsip: pjsip().url };
</script>

<template>
    <div>
        <div
            class="flex items-start gap-2 rounded-md px-2 py-1.5"
            :class="
                node.warning
                    ? 'bg-negative/10'
                    : node.kind === 'number'
                      ? 'bg-accent/60'
                      : ''
            "
        >
            <component
                :is="
                    node.warning
                        ? AlertTriangle
                        : (icons[node.kind] ?? CornerDownRight)
                "
                class="mt-0.5 size-4 shrink-0"
                :class="
                    node.warning
                        ? 'text-negative-strong'
                        : 'text-muted-foreground'
                "
                aria-hidden="true"
            />
            <div class="min-w-0 text-sm">
                <span
                    :class="
                        node.kind === 'number' ||
                        node.kind === 'queue' ||
                        node.kind === 'ivr'
                            ? 'font-semibold'
                            : ''
                    "
                    >{{ node.label }}</span
                >
                <Link
                    v-if="node.link"
                    :href="links[node.link]"
                    class="ml-2 text-xs text-primary underline-offset-2 hover:underline"
                    >{{ t('modifier') }}</Link
                >
                <p
                    v-for="d in node.details"
                    :key="d"
                    class="text-xs text-muted-foreground"
                >
                    {{ d }}
                </p>
            </div>
        </div>
        <div v-if="node.children.length" class="ml-4 border-l pl-3">
            <CallFlowNode
                v-for="(child, i) in node.children"
                :key="i"
                :node="child"
            />
        </div>
    </div>
</template>
