/**
 * A number input that may be left empty. Inputs can't hold `null`,
 * so "empty" is `''` in the form and `null` when sent to the server.
 */
export type OptionalNumber = number | '';

export function optionalNumber(value: OptionalNumber | null): number | null {
    return value === '' || value === null ? null : value;
}
