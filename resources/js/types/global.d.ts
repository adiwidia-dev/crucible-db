import type { NativeProxyStatusSnapshot } from '@/components/native-proxy/health-status';
import type { Auth } from '@/types/auth';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            native_proxy_cli_download_url?: string;
            native_proxy_status?: NativeProxyStatusSnapshot | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
