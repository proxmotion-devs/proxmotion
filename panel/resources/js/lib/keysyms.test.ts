import { describe, expect, it } from 'vite-plus/test';
import { charToKeyPress, textToKeyPresses } from './keysyms';

describe('keysyms', () => {
    it('maps Latin-1 characters to their code point', () => {
        expect(charToKeyPress('a')).toEqual({ keysym: 0x61, shift: false });
        expect(charToKeyPress('ü')).toEqual({ keysym: 0xfc, shift: false });
    });

    it('presses shift for upper case and shifted US symbols', () => {
        expect(charToKeyPress('A')).toEqual({ keysym: 0x41, shift: true });
        expect(charToKeyPress('!')).toEqual({ keysym: 0x21, shift: true });
        expect(charToKeyPress('-')).toEqual({ keysym: 0x2d, shift: false });
    });

    it('uses the Unicode keysym range beyond Latin-1', () => {
        expect(charToKeyPress('ş')).toEqual({
            keysym: 0x0100015f,
            shift: false,
        });
    });

    it('maps newlines and tabs to Return and Tab, and drops control characters', () => {
        expect(textToKeyPresses('a\r\nb\t\u0007')).toEqual([
            { keysym: 0x61, shift: false },
            { keysym: 0xff0d, shift: false },
            { keysym: 0x62, shift: false },
            { keysym: 0xff09, shift: false },
        ]);
    });
});
