/**
 * Whether what was typed into a hostname confirmation (AGENTS.md §7.8) names
 * the server: trimmed and case-insensitive, as the server checks it again.
 */
export const confirmsHostname = (typed: string, hostname: string): boolean =>
    typed.trim().toLowerCase() === hostname.toLowerCase();
