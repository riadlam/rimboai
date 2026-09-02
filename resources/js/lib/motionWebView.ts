/** Skip opacity-0 entrance animations in in-app browsers where motion can stall. */
export function shouldSkipEntranceMotion(): boolean {
    if (typeof window === 'undefined') return false;

    try {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return true;
        }
    } catch {
        // ignore
    }

    const ua = navigator.userAgent || '';
    return /FBAN|FBAV|Instagram|Line\//i.test(ua);
}

/** Use on Framer Motion `initial` — visible immediately in restricted WebViews. */
export function entranceInitial<T extends Record<string, unknown>>(motion: T): T | false {
    return shouldSkipEntranceMotion() ? false : motion;
}
