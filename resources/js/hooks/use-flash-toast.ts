import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

const toastDedupeWindowMs = 750;

let lastToast: { key: string; shownAt: number } | null = null;

export function isFlashToast(data: unknown): data is FlashToast {
    if (!data || typeof data !== 'object') {
        return false;
    }

    const toast = data as Partial<FlashToast>;

    return (
        typeof toast.message === 'string' &&
        ['success', 'info', 'warning', 'error'].includes(String(toast.type))
    );
}

export function showFlashToast(data: unknown): void {
    if (!isFlashToast(data)) {
        return;
    }

    const key = `${data.type}:${data.message}`;
    const now = Date.now();

    if (
        lastToast?.key === key &&
        now - lastToast.shownAt < toastDedupeWindowMs
    ) {
        return;
    }

    lastToast = { key, shownAt: now };
    toast[data.type](data.message);
}

export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            showFlashToast(flash?.toast);
        });
    }, []);
}
