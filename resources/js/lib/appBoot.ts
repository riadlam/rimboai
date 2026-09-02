const BOOT_ID = 'app-boot';

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
