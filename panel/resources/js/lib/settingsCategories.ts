/**
 * The pages of Panel settings (docs/adr/0038), listed under it in the menu.
 * The settings each one saves are PanelSettings::CATEGORIES on the server.
 * Titles and descriptions are English keys, translated where they are shown.
 */
export const SETTINGS_CATEGORIES = [
    {
        key: 'servers',
        title: 'Servers',
        description:
            'Creating, reinstalling and moving servers, and the root passwords the panel makes up.',
    },
    {
        key: 'network',
        title: 'Network security',
        description: 'Networks no server may open connections to.',
    },
    {
        key: 'usage',
        title: 'Usage and quotas',
        description:
            'Monthly traffic quotas and limits on CPU, memory, disk and network use.',
    },
    {
        key: 'general',
        title: 'Console, drift report and help',
        description:
            'Console session limits, when stopped servers are reported, and the documentation link.',
    },
    {
        // Shown only: these stay in .env.
        key: 'environment',
        title: 'Set in .env only',
        description:
            'These protect the panel or depend on how it is installed, so they cannot be changed here.',
    },
] as const;

export type SettingsCategoryKey = (typeof SETTINGS_CATEGORIES)[number]['key'];
