// Minimal types for the parts of noVNC's RFB class that the console page uses.
declare module '@novnc/novnc' {
    export type RfbOptions = {
        credentials?: { username?: string; password?: string; target?: string };
        wsProtocols?: string[];
        shared?: boolean;
    };

    export default class RFB extends EventTarget {
        constructor(
            target: HTMLElement,
            urlOrChannel: string | WebSocket,
            options?: RfbOptions,
        );

        scaleViewport: boolean;
        resizeSession: boolean;
        focusOnClick: boolean;
        background: string;

        disconnect(): void;
        sendCredentials(credentials: { password?: string }): void;
        sendCtrlAltDel(): void;
        sendKey(keysym: number, code: string | null, down?: boolean): void;
        focus(options?: FocusOptions): void;
    }
}
