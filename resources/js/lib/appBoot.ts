const BOOT_ID = 'app-boot';
const BOOT_TIMEOUT_MS = 8000;

export function dismissAppBoot(): void {
    document.getElementById(BOOT_ID)?.remove();
}

export function scheduleAppBootTimeout(): void {
    if (typeof window === 'undefined') return;

    window.setTimeout(() => {
        const boot = document.getElementById(BOOT_ID);
        if (!boot) return;

        const hint = boot.querySelector<HTMLElement>('[data-boot-hint]');
        if (hint) {
            hint.hidden = false;
        }
    }, BOOT_TIMEOUT_MS);
}
