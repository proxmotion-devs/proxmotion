/**
 * The name a server with the address 203.0.113.5 and VMID 100 would get from
 * a location's hostname template, like HostnameGenerator::example() (docs/adr/0031).
 */
export function hostnameExample(
    template: string,
    locationCode: string,
): string {
    return template
        .trim()
        .replaceAll('{ip}', '203-0-113-5')
        .replaceAll('{vmid}', '100')
        .replaceAll('{location}', locationCode)
        .toLowerCase();
}
