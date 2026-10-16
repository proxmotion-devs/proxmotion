/**
 * Turns pasted text into X11 keysyms for typing into a VNC console.
 * The console sends key presses, not text, so the guest's keyboard layout
 * decides what arrives; shifted characters assume a US layout.
 */

export type KeyPress = { keysym: number; shift: boolean };

export const XK_SHIFT_L = 0xffe1;

const SPECIAL: Record<string, number> = {
    '\n': 0xff0d, // Return
    '\r': 0xff0d,
    '\t': 0xff09, // Tab
};

const US_SHIFTED = new Set('~!@#$%^&*()_+{}|:"<>?');

export function charToKeyPress(char: string): KeyPress | null {
    if (char in SPECIAL) {
        return { keysym: SPECIAL[char], shift: false };
    }

    const code = char.codePointAt(0);

    if (code === undefined || code < 0x20 || code === 0x7f) {
        return null;
    }

    // Latin-1 keysyms equal their code point; other characters use the Unicode keysym range.
    const keysym = code <= 0xff ? code : 0x01000000 | code;
    const shift = (char >= 'A' && char <= 'Z') || US_SHIFTED.has(char);

    return { keysym, shift };
}

export function textToKeyPresses(text: string): KeyPress[] {
    const presses: KeyPress[] = [];

    for (const char of text.replace(/\r\n/g, '\n')) {
        const press = charToKeyPress(char);

        if (press !== null) {
            presses.push(press);
        }
    }

    return presses;
}
