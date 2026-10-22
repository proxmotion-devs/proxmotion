import { describe, expect, it } from 'vite-plus/test';
import { hostnameExample } from '@/lib/hostnames';

describe('hostnameExample', () => {
    it('fills every placeholder', () => {
        expect(hostnameExample('ip-{ip}.example.com', 'fra1')).toBe(
            'ip-203-0-113-5.example.com',
        );
        expect(
            hostnameExample('vps{vmid}.{location}.example.com', 'fra1'),
        ).toBe('vps100.fra1.example.com');
    });

    it('lowercases and trims like the panel', () => {
        expect(hostnameExample('  IP-{ip}.Example.COM ', 'fra1')).toBe(
            'ip-203-0-113-5.example.com',
        );
    });
});
