/**
 * The reports (docs/adr/0046), listed under Reports in the menu. Keys match
 * Reports::PAGES on the server. Titles and descriptions are English keys,
 * translated where they are shown.
 */
export const REPORTS = [
    {
        key: 'capacity',
        title: 'Capacity',
        description:
            'What nodes, storages and IP pools hold and can still take, counted like placement counts it.',
    },
    {
        key: 'traffic',
        title: 'Traffic and usage',
        description:
            'Traffic of a monthly period per server, location, package and billing system, and usage violations.',
    },
    {
        key: 'servers',
        title: 'Server inventory',
        description:
            'Servers by status, location, package, OS template and billing system, and servers created and deleted per month.',
    },
    {
        key: 'operations',
        title: 'Operations',
        description:
            'Tasks and app installations of the last days: how many succeeded or failed, and how long they took.',
    },
] as const;

export type ReportKey = (typeof REPORTS)[number]['key'];
