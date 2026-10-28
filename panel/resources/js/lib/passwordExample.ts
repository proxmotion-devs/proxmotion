/**
 * An example of the password format set on Panel settings, shown while an
 * administrator edits it. The server makes the real passwords
 * (App\Domain\Servers\PasswordGenerator); this only shows what they look like.
 */
export type PasswordCharacters = 'lower' | 'mixed' | 'symbols';

export const PASSWORD_LENGTH = { min: 12, max: 64 } as const;

const LOWER = 'abcdefghijklmnopqrstuvwxyz';
const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const DIGITS = '0123456789';
const SYMBOLS = '!@#%+=.-_';

const KINDS: Record<string, string> = {
    lower: LOWER,
    upper: UPPER,
    digit: DIGITS,
    symbol: SYMBOLS,
};

const SETS: Record<PasswordCharacters, string[]> = {
    lower: ['lower', 'digit'],
    mixed: ['lower', 'upper', 'digit'],
    symbols: ['lower', 'upper', 'digit', 'symbol'],
};

/**
 * An example password, or null while the length is out of range.
 */
export function passwordExample(
    length: number,
    characters: PasswordCharacters,
    prefix: string,
    suffix: string,
): string | null {
    if (
        !Number.isInteger(length) ||
        length < PASSWORD_LENGTH.min ||
        length > PASSWORD_LENGTH.max
    ) {
        return null;
    }

    const kinds = SETS[characters];
    const alphabet = kinds.map((kind) => KINDS[kind]).join('');
    const letters = kinds
        .filter((kind) => kind === 'lower' || kind === 'upper')
        .map((kind) => KINDS[kind])
        .join('');
    let random: string;

    // Like the server: the random part starts with a letter and holds every kind of its set.
    do {
        random = Array.from({ length }, (_, position) =>
            position === 0
                ? letters[randomIndex(letters.length)]
                : alphabet[randomIndex(alphabet.length)],
        ).join('');
    } while (
        !kinds.every((kind) =>
            KINDS[kind].split('').some((char) => random.includes(char)),
        )
    );

    return `${prefix}${random}${suffix}`;
}

/**
 * Whether Proxmox can put the password into cloud-init's user data as it is,
 * like PasswordGenerator::fitsCloudInit(): a letter first, then printable
 * ASCII without spaces and colons. Windows servers get one that does not fit
 * through the guest agent once setup has finished.
 */
export function fitsCloudInit(password: string): boolean {
    return /^[A-Za-z][\x21-\x39\x3B-\x7E]*$/.test(password);
}

function randomIndex(size: number): number {
    const values = new Uint32Array(1);
    crypto.getRandomValues(values);

    return values[0] % size;
}
