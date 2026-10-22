import { onMounted, onUnmounted, ref } from 'vue';
import { echo, onConnection } from '@/lib/echo';
import { stats as statsRoute } from '@/routes/admin/servers';
import type { ServerStats, ServerStatsPoint } from '@/types/admin';

/** How often the page asks while the socket is connected: only to stay watched. */
export const WATCH_MS = 30_000;
/** How often the page asks without a socket (docs/adr/0036 §5). */
export const POLL_MS = 10_000;
/** The sparklines show the last hour. */
export const HISTORY_MS = 60 * 60 * 1000;

type Answer = { stats: ServerStats | null; history: ServerStatsPoint[] };

/**
 * Live figures of one server: the last hour once, then each sample pushed on
 * the server's channel, or asked for every 10 seconds without a socket.
 */
export function useServerStats(serverUuid: string) {
    const stats = ref<ServerStats | null>(null);
    const history = ref<ServerStatsPoint[]>([]);
    /** Whether the socket is connected (figures are pushed). */
    const live = ref(false);
    /** False until the first answer, so the cards can say they are loading. */
    const loaded = ref(false);
    const failed = ref(false);

    let timer: ReturnType<typeof setTimeout> | null = null;
    let stopped = false;
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

        if (!stopped) {
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

            if (answer.stats !== null) {
                add(answer.stats);
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

    return { stats, history, live, loaded, failed };
}
