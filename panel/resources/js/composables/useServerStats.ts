import { onMounted, onUnmounted, ref } from 'vue';
import { echo, onConnection } from '@/lib/echo';
import { stats as statsRoute } from '@/routes/admin/servers';
import type {
    ServerDiskUsage,
    ServerStats,
    ServerStatsPoint,
} from '@/types/admin';

/** How often the page asks while the socket is connected: only to stay watched. */
export const WATCH_MS = 30_000;
/** How often the page asks without a socket (docs/adr/0036 §5). */
export const POLL_MS = 10_000;
/** The sparklines show the last hour. */
export const HISTORY_MS = 60 * 60 * 1000;
/**
 * How soon the page asks again while a running server's disk space is not known
 * yet: the panel asks the guest agent only after its answer (docs/adr/0036 §7).
 */
export const DISK_WAIT_MS = 2_000;
/** At most this many quick requests in a row, for a guest agent that hangs. */
export const DISK_WAITS = 5;

type Answer = {
    stats: ServerStats | null;
    disk?: ServerDiskUsage | null;
    history: ServerStatsPoint[];
};

/**
 * Live figures of one server: the last hour once, then each sample pushed on
 * the server's channel, or asked for every 10 seconds without a socket. Disk
 * space comes only with the answers to those requests (it is never pushed), so
 * while a running server's is not known yet the page asks again after 2 s.
 */
export function useServerStats(serverUuid: string) {
    const stats = ref<ServerStats | null>(null);
    const disk = ref<ServerDiskUsage | null>(null);
    const history = ref<ServerStatsPoint[]>([]);
    /** Whether the socket is connected (figures are pushed). */
    const live = ref(false);
    /** False until the first answer, so the cards can say they are loading. */
    const loaded = ref(false);
    const failed = ref(false);

    let timer: ReturnType<typeof setTimeout> | null = null;
    let stopped = false;
    /** Quick requests left while the disk space is not known yet. */
    let diskWaits = DISK_WAITS;
    let waitingForDisk = false;
    let stopListening: (() => void) | null = null;
    const channel = `admin.servers.${serverUuid}`;

    const add = (sample: ServerStats) => {
        if (stats.value !== null && sample.time <= stats.value.time) {
            return;
        }

        stats.value = sample;
        history.value = [
            ...history.value.filter(
                (point) => point.time > sample.time - HISTORY_MS,
            ),
            sample,
        ];
    };

    const schedule = () => {
        if (timer !== null) {
            clearTimeout(timer);
        }

        if (stopped) {
            return;
        }

        if (waitingForDisk && diskWaits > 0) {
            diskWaits--;
            timer = setTimeout(load, DISK_WAIT_MS);
        } else {
            timer = setTimeout(load, live.value ? WATCH_MS : POLL_MS);
        }
    };

    async function load(withHistory = false): Promise<void> {
        try {
            const response = await fetch(
                statsRoute.url(serverUuid, {
                    query: withHistory ? { history: 1 } : {},
                }),
                { headers: { Accept: 'application/json' } },
            );

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const answer = (await response.json()) as Answer;

            if (withHistory) {
                history.value = answer.history;
            }

            // Without figures the panel sends no disk space either: keep the last one.
            if (answer.stats !== null) {
                add(answer.stats);
                disk.value = answer.disk ?? null;
            }

            waitingForDisk =
                answer.stats?.status === 'running' && disk.value === null;

            if (!waitingForDisk) {
                diskWaits = DISK_WAITS;
            }

            failed.value = false;
        } catch {
            failed.value = true;
        } finally {
            loaded.value = true;
            schedule();
        }
    }

    onMounted(() => {
        const connection = echo();

        if (connection !== null) {
            connection
                .private(channel)
                .listen('.stats.updated', (sample: ServerStats) => add(sample));

            // Back to polling while the socket is down, and back again once it reconnects.
            stopListening = onConnection(connection, (connected) => {
                if (connected !== live.value) {
                    live.value = connected;

                    if (loaded.value) {
                        schedule();
                    }
                }
            });
        }

        void load(true);
    });

    onUnmounted(() => {
        stopped = true;

        if (timer !== null) {
            clearTimeout(timer);
        }

        stopListening?.();
        echo()?.leave(channel);
    });

    return { stats, disk, history, live, loaded, failed };
}
