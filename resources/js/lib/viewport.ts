import { useEffect, useState } from 'react';

/** Facebook / Instagram / Messenger in-app browsers on iOS. */
export function isInAppBrowser(): boolean {
    if (typeof navigator === 'undefined') return false;
    return /FBAN|FBAV|FBIOS|FB4A|FB_IAB|Instagram|MessengerForiOS|Line\//i.test(navigator.userAgent || '');
}

/** Phone-sized layout — in-app browsers are always treated as mobile. */
export function isMobileViewport(): boolean {
    if (typeof window === 'undefined') return true;
    if (isInAppBrowser()) return true;

    try {
        return window.matchMedia('(max-width: 767px)').matches;
    } catch {
        return window.innerWidth < 768;
    }
}

export function useMobileViewport(): boolean {
    const [mobile, setMobile] = useState(() => isMobileViewport());

    useEffect(() => {
        const update = () => setMobile(isMobileViewport());
        update();

        let mq: MediaQueryList | null = null;
        try {
            mq = window.matchMedia('(max-width: 767px)');
            mq.addEventListener('change', update);
        } catch {
            // ignore
        }

        window.addEventListener('resize', update);
        window.addEventListener('orientationchange', update);

        return () => {
            mq?.removeEventListener('change', update);
            window.removeEventListener('resize', update);
            window.removeEventListener('orientationchange', update);
        };
    }, []);

    return mobile;
}
