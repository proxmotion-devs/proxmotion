export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type AppVariant = 'header' | 'sidebar';

/** Panel settings > Branding (docs/adr/0053), shared with every page. */
export type Branding = {
    name: string;
    /** #rrggbb, or null for the default theme */
    color: string | null;
    color_foreground: string | null;
    logo_url: string | null;
    logo_dark_url: string | null;
    favicon_url: string | null;
    support_email: string | null;
    support_url: string | null;
};

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};
