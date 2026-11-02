/**
 * The colour of Panel settings > Branding (docs/adr/0053): the same
 * luminance and text colour as App\Domain\Settings\Branding on the server,
 * and how well buttons in it stand out from the page.
 */

/** The page backgrounds of app.css: white and hsl(0 0% 3.9%). */
const BACKGROUNDS = { light: '#ffffff', dark: '#0a0a0a' } as const;

/** WCAG 2's minimum for parts of a user interface. */
export const MIN_CONTRAST = 3;

export function isBrandColor(value: string): boolean {
    return /^#[0-9a-f]{6}$/i.test(value);
}

/** Relative luminance of a #rrggbb colour (WCAG 2). */
export function luminance(color: string): number {
    const [r, g, b] = [1, 3, 5].map((start) => {
        const value = parseInt(color.slice(start, start + 2), 16) / 255;

        return value <= 0.03928
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrast(a: string, b: string): number {
    const [lighter, darker] = [luminance(a), luminance(b)].sort(
        (x, y) => y - x,
    );

    return (lighter + 0.05) / (darker + 0.05);
}

/** Black or white, whichever is easier to read on the colour. */
export function foreground(color: string): '#ffffff' | '#000000' {
    return contrast(color, '#ffffff') >= contrast(color, '#000000')
        ? '#ffffff'
        : '#000000';
}

/** The modes whose page background the colour hardly stands out from. */
export function lowContrastModes(color: string): Array<'light' | 'dark'> {
    return (['light', 'dark'] as const).filter(
        (mode) => contrast(color, BACKGROUNDS[mode]) < MIN_CONTRAST,
    );
}
