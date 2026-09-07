import { useEffect, useRef } from 'react';

declare global {
    interface Window {
        turnstile?: {
            render: (
                el: HTMLElement,
                options: {
                    sitekey: string;
                    callback?: (token: string) => void;
                    'expired-callback'?: () => void;
                    'error-callback'?: () => void;
                    theme?: 'light' | 'dark' | 'auto';
                },
            ) => string;
            reset: (widgetId?: string) => void;
            remove: (widgetId?: string) => void;
        };
        onTurnstileLoad?: () => void;
    }
}

type Props = {
    siteKey: string;
    onToken: (token: string) => void;
    onExpire?: () => void;
    className?: string;
};

const SCRIPT_ID = 'cf-turnstile-script';

export default function TurnstileWidget({ siteKey, onToken, onExpire, className }: Props) {
    const containerRef = useRef<HTMLDivElement>(null);
    const widgetIdRef = useRef<string | null>(null);
    const onTokenRef = useRef(onToken);
    const onExpireRef = useRef(onExpire);

    useEffect(() => {
        onTokenRef.current = onToken;
        onExpireRef.current = onExpire;
    }, [onToken, onExpire]);

    useEffect(() => {
        if (!siteKey || !containerRef.current) {
            return;
        }

        let cancelled = false;

        const render = () => {
            if (cancelled || !containerRef.current || !window.turnstile) {
                return;
            }
            if (widgetIdRef.current !== null) {
                try {
                    window.turnstile.remove(widgetIdRef.current);
                } catch {
                    // ignore
                }
                widgetIdRef.current = null;
                containerRef.current.innerHTML = '';
            }

            widgetIdRef.current = window.turnstile.render(containerRef.current, {
                sitekey: siteKey,
                theme: 'dark',
                callback: (token) => onTokenRef.current(token),
                'expired-callback': () => onExpireRef.current?.(),
                'error-callback': () => onExpireRef.current?.(),
            });
        };

        if (window.turnstile) {
            render();
        } else {
            const existing = document.getElementById(SCRIPT_ID) as HTMLScriptElement | null;
            const prev = window.onTurnstileLoad;
            window.onTurnstileLoad = () => {
                prev?.();
                if (!cancelled) {
                    render();
                }
            };
            if (!existing) {
                const script = document.createElement('script');
                script.id = SCRIPT_ID;
                script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=onTurnstileLoad&render=explicit';
                script.async = true;
                document.head.appendChild(script);
            } else if (window.turnstile) {
                render();
            }
        }

        return () => {
            cancelled = true;
            if (widgetIdRef.current !== null && window.turnstile) {
                try {
                    window.turnstile.remove(widgetIdRef.current);
                } catch {
                    // ignore
                }
                widgetIdRef.current = null;
            }
        };
    }, [siteKey]);

    return <div ref={containerRef} className={className} />;
}
