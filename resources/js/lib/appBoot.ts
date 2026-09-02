const BOOT_ID = 'app-boot';
const BOOT_TIMEOUT_MS = 8000;

export function dismissAppBoot(): void {
    document.getElementById(BOOT_ID)?.remove();
}

export function showAppBootError(): void {
    const boot = document.getElementById(BOOT_ID);
    if (!boot) return;

    const loading = boot.querySelector<HTMLElement>('[data-boot-loading]');
    const error = boot.querySelector<HTMLElement>('[data-boot-error]');
    if (loading) loading.hidden = true;
    if (error) error.hidden = false;
}

export function scheduleAppBootTimeout(): void {
    if (typeof window === 'undefined') return;

    window.setTimeout(() => {
        const boot = document.getElementById(BOOT_ID);
        if (!boot) return;

        showAppBootError();
    }, BOOT_TIMEOUT_MS);
}
