<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type RFB from '@novnc/novnc';
import {
    ArrowLeft,
    ClipboardPaste,
    Keyboard,
    Maximize,
    RotateCcw,
} from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { textToKeyPresses, XK_SHIFT_L } from '@/lib/keysyms';

type ConsoleState =
    | 'connecting'
    | 'connected'
    | 'disconnected'
    | 'idle'
    | 'failed';

const props = defineProps<{
    server: { uuid: string; hostname: string };
    console: { url: string; password: string; expires_at: string } | null;
    error: string | null;
    idleMinutes: number;
    /** null on the customer console opened from the billing system */
    backUrl: string | null;
}>();

const screen = ref<HTMLElement>();
const container = ref<HTMLElement>();
const rfb = shallowRef<RFB | null>(null);
const state = ref<ConsoleState>(props.console ? 'connecting' : 'failed');
const showPaste = ref(false);
const pasteText = ref('');
const typing = ref(false);

const STATE_LABELS: Record<ConsoleState, string> = {
    connecting: 'Connecting…',
    connected: 'Connected',
    disconnected: 'Disconnected',
    idle: 'Disconnected after inactivity',
    failed: 'Not connected',
};

let lastActivity = Date.now();
let idleTimer: ReturnType<typeof setInterval> | undefined;

const markActive = () => {
    lastActivity = Date.now();
};

async function connect(): Promise<void> {
    disconnect();

    if (!props.console || !screen.value) {
        state.value = 'failed';

        return;
    }

    state.value = 'connecting';
    markActive();

    // Loaded on demand: noVNC is large and only needed on this page.
    const { default: Rfb } = await import('@novnc/novnc');
    const client = new Rfb(screen.value, props.console.url, {
        credentials: { password: props.console.password },
        wsProtocols: ['binary'],
    });

    client.scaleViewport = true;
    client.background = '#000';
    client.addEventListener('connect', () => {
        state.value = 'connected';
        client.focus();
    });
    client.addEventListener('disconnect', () => {
        // Ignore the old client closing after a reconnect or an idle disconnect.
        if (rfb.value !== client) {
            return;
        }

        if (state.value !== 'idle') {
            state.value = 'disconnected';
        }

        rfb.value = null;
    });
    client.addEventListener('credentialsrequired', () => {
        client.sendCredentials({ password: props.console?.password });
    });

    rfb.value = client;
}

function disconnect(): void {
    const client = rfb.value;
    rfb.value = null;
    client?.disconnect();
}

/** Each reconnect opens a new session (and ends the previous one). */
function reconnect(): void {
    router.reload({ only: ['console', 'error'] });
}

function sendCtrlAltDel(): void {
    rfb.value?.sendCtrlAltDel();
    rfb.value?.focus();
}

async function typeText(): Promise<void> {
    const client = rfb.value;

    if (!client || typing.value) {
        return;
    }

    typing.value = true;

    for (const { keysym, shift } of textToKeyPresses(pasteText.value)) {
        if (shift) {
            client.sendKey(XK_SHIFT_L, 'ShiftLeft', true);
        }

        client.sendKey(keysym, null, true);
        client.sendKey(keysym, null, false);

        if (shift) {
            client.sendKey(XK_SHIFT_L, 'ShiftLeft', false);
        }

        // Some guests drop keys that arrive too fast.
        await new Promise((resolve) => setTimeout(resolve, 10));
    }

    typing.value = false;
    pasteText.value = '';
    showPaste.value = false;
    markActive();
    client.focus();
}

function toggleFullscreen(): void {
    if (document.fullscreenElement) {
        void document.exitFullscreen();
    } else {
        void container.value?.requestFullscreen();
    }
}

const activityEvents = [
    'keydown',
    'mousedown',
    'mousemove',
    'wheel',
    'touchstart',
] as const;

onMounted(() => {
    for (const event of activityEvents) {
        window.addEventListener(event, markActive, {
            capture: true,
            passive: true,
        });
    }

    // noVNC keeps the connection busy, so inactivity is measured here (ADR 0002).
    idleTimer = setInterval(() => {
        if (
            rfb.value &&
            Date.now() - lastActivity > props.idleMinutes * 60_000
        ) {
            state.value = 'idle';
            disconnect();
        }
    }, 30_000);

    void connect();
});

onBeforeUnmount(() => {
    for (const event of activityEvents) {
        window.removeEventListener(event, markActive, { capture: true });
    }

    clearInterval(idleTimer);
    disconnect();
});

watch(
    () => props.console,
    () => void connect(),
);
</script>

<template>
    <Head :title="server.hostname" />

    <div ref="container" class="flex h-dvh flex-col bg-black text-white">
        <header
            class="flex flex-wrap items-center gap-2 border-b border-white/10 bg-neutral-900 px-3 py-2"
        >
            <Link
                v-if="backUrl"
                :href="backUrl"
                class="inline-flex items-center gap-1 text-sm text-neutral-300 hover:text-white"
            >
                <ArrowLeft class="h-4 w-4" />
                {{ $t('Back') }}
            </Link>
            <span class="font-mono text-sm font-semibold">{{
                server.hostname
            }}</span>
            <span
                class="rounded px-2 py-0.5 text-xs"
                :class="
                    state === 'connected'
                        ? 'bg-emerald-600/30 text-emerald-300'
                        : 'bg-neutral-700 text-neutral-300'
                "
                data-test="console-state"
            >
                {{ $t(STATE_LABELS[state]) }}
            </span>

            <div class="ml-auto flex flex-wrap gap-2">
                <Button
                    size="sm"
                    variant="secondary"
                    :disabled="state !== 'connected'"
                    @click="sendCtrlAltDel"
                >
                    <Keyboard class="h-4 w-4" />
                    Ctrl+Alt+Del
                </Button>
                <Button
                    size="sm"
                    variant="secondary"
                    :disabled="state !== 'connected'"
                    @click="showPaste = !showPaste"
                >
                    <ClipboardPaste class="h-4 w-4" />
                    {{ $t('Paste text') }}
                </Button>
                <Button size="sm" variant="secondary" @click="toggleFullscreen">
                    <Maximize class="h-4 w-4" />
                    {{ $t('Full screen') }}
                </Button>
                <Button
                    size="sm"
                    variant="secondary"
                    :disabled="state === 'connecting' || state === 'connected'"
                    @click="reconnect"
                >
                    <RotateCcw class="h-4 w-4" />
                    {{ $t('Reconnect') }}
                </Button>
            </div>
        </header>

        <form
            v-if="showPaste"
            class="flex flex-col gap-2 border-b border-white/10 bg-neutral-900 p-3"
            @submit.prevent="typeText"
        >
            <textarea
                v-model="pasteText"
                v-focus
                rows="3"
                class="w-full rounded border border-white/20 bg-black p-2 font-mono text-sm"
                :placeholder="$t('Text to type into the console')"
            />
            <div class="flex items-center gap-2">
                <Button
                    size="sm"
                    type="submit"
                    :disabled="typing || pasteText === ''"
                >
                    {{ $t('Type text') }}
                </Button>
                <span class="text-xs text-neutral-400">{{
                    $t(
                        'The text is typed key by key and assumes a US keyboard layout.',
                    )
                }}</span>
            </div>
        </form>

        <p
            v-if="error"
            class="m-4 rounded border border-red-500/40 bg-red-950 p-3 text-sm text-red-200"
            role="alert"
        >
            {{ error }}
        </p>

        <div ref="screen" class="min-h-0 flex-1 overflow-hidden" />
    </div>
</template>
