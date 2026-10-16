/**
 * Talks to the panel's internal console endpoints (routes/internal.php).
 *
 * @param {{ baseUrl: string, secret: string, fetchImpl?: typeof fetch, timeoutMs?: number }} options
 */
export function createPanelClient({ baseUrl, secret, fetchImpl = fetch, timeoutMs = 5000 }) {
    const root = baseUrl.replace(/\/+$/, '');

    async function post(path, body) {
        return fetchImpl(`${root}${path}`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Console-Proxy-Secret': secret,
            },
            body: JSON.stringify(body),
            signal: AbortSignal.timeout(timeoutMs),
        });
    }

    return {
        /**
         * Exchange a single-use ticket for the upstream connection, or null if
         * the ticket is unknown, expired or already used.
         */
        async redeem(ticket) {
            const response = await post('/internal/console/redeem', { ticket });

            if (response.status === 404 || response.status === 422) {
                return null;
            }

            if (!response.ok) {
                throw new Error(`Panel answered ${response.status} to redeem`);
            }

            return response.json();
        },

        /** Whether the session may continue. */
        async heartbeat(session) {
            const response = await post('/internal/console/heartbeat', { session });

            if (!response.ok) {
                throw new Error(`Panel answered ${response.status} to heartbeat`);
            }

            const data = await response.json();

            return data.active === true;
        },
    };
}
