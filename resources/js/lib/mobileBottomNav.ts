import { useEffect, useSyncExternalStore } from 'react';

let hideCount = 0;
const listeners = new Set<() => void>();

function subscribe(listener: () => void): () => void {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

function getSnapshot(): boolean {
    return hideCount > 0;
}

function emit(): void {
    listeners.forEach((listener) => listener());
}

/** True while a fullscreen overlay (e.g. trend preview) has hidden the phone bottom bar. */
export function useMobileBottomNavHidden(): boolean {
    return useSyncExternalStore(subscribe, getSnapshot, () => false);
}

/** Hide the phone bottom bar while mounted (supports nested callers via a ref counter). */
export function useHideMobileBottomNav(): void {
    useEffect(() => {
        hideCount += 1;
        emit();
        return () => {
            hideCount = Math.max(0, hideCount - 1);
            emit();
        };
    }, []);
}
