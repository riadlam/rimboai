import { useCallback, useEffect, useRef, useState } from 'react';
import { apiGet } from '@/lib/api';

export type LabCreationsType =
    | 'text-to-image'
    | 'text-to-video'
    | 'text-to-music'
    | 'text-to-sound'
    | 'text-to-voice';

export type CreationsPagePayload = {
    type: string;
    images?: unknown[];
    tracks?: unknown[];
    voices?: unknown[];
    next_cursor: number | null;
    has_more: boolean;
};

type UseCreationsPageOptions<T> = {
    type: LabCreationsType | null;
    enabled?: boolean;
    limit?: number;
    mapResponse: (data: CreationsPagePayload) => T[];
    /** First-page merge so in-flight local cards are not wiped. Defaults to replace. */
    mergeFirstPage?: (prev: T[], fetched: T[]) => T[];
    /** Append merge for later pages (dedupe). Defaults to id/creationId append. */
    mergeAppendPage?: (prev: T[], fetched: T[]) => T[];
};

function defaultAppendPage<T extends { id?: string; creationId?: number | null }>(prev: T[], fetched: T[]): T[] {
    const ids = new Set(prev.map((p) => p.id).filter(Boolean) as string[]);
    const creationIds = new Set(
        prev.map((p) => p.creationId).filter((id): id is number => typeof id === 'number'),
    );
    const extra = fetched.filter((item) => {
        if (item.id && ids.has(item.id)) return false;
        if (typeof item.creationId === 'number' && creationIds.has(item.creationId)) return false;
        return true;
    });
    return [...prev, ...extra];
}

/**
 * Cursor-paginated `/lab/creations` loader with IntersectionObserver load-more.
 */
export function useCreationsPage<T extends { id?: string; creationId?: number | null }>({
    type,
    enabled = true,
    limit = 20,
    mapResponse,
    mergeFirstPage,
    mergeAppendPage,
}: UseCreationsPageOptions<T>) {
    const [items, setItems] = useState<T[]>([]);
    const [nextCursor, setNextCursor] = useState<number | null>(null);
    const [hasMore, setHasMore] = useState(false);
    const [loading, setLoading] = useState(false);
    const [loadingMore, setLoadingMore] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const scrollRootRef = useRef<HTMLDivElement | null>(null);
    const sentinelRef = useRef<HTMLDivElement | null>(null);

    const abortRef = useRef<AbortController | null>(null);
    const inFlightRef = useRef(false);
    const nextCursorRef = useRef<number | null>(null);
    const hasMoreRef = useRef(false);
    const emptySkipRef = useRef(0);
    const typeRef = useRef(type);
    const mapResponseRef = useRef(mapResponse);
    const mergeFirstRef = useRef(mergeFirstPage);
    const mergeAppendRef = useRef(mergeAppendPage);

    typeRef.current = type;
    mapResponseRef.current = mapResponse;
    mergeFirstRef.current = mergeFirstPage;
    mergeAppendRef.current = mergeAppendPage;
    nextCursorRef.current = nextCursor;
    hasMoreRef.current = hasMore;

    const fetchPage = useCallback(
        async (cursor: number | null, mode: 'first' | 'more') => {
            const activeType = typeRef.current;
            if (!activeType || !enabled) return;
            if (inFlightRef.current) return;
            if (mode === 'more' && !hasMoreRef.current) return;

            inFlightRef.current = true;
            abortRef.current?.abort();
            const controller = new AbortController();
            abortRef.current = controller;

            if (mode === 'first') {
                setLoading(true);
            } else {
                setLoadingMore(true);
            }
            setError(null);

            let continueCursor: number | null = null;

            try {
                const params = new URLSearchParams({
                    type: activeType,
                    limit: String(limit),
                });
                if (cursor != null) {
                    params.set('cursor', String(cursor));
                }

                const data = await apiGet<CreationsPagePayload>(`/lab/creations?${params.toString()}`);
                if (controller.signal.aborted || typeRef.current !== activeType) return;

                const mapped = mapResponseRef.current(data);
                const next = data.next_cursor ?? null;
                const more = Boolean(data.has_more);

                setItems((prev) => {
                    if (mode === 'first') {
                        return mergeFirstRef.current ? mergeFirstRef.current(prev, mapped) : mapped;
                    }
                    return (mergeAppendRef.current ?? defaultAppendPage)(prev, mapped);
                });
                setNextCursor(next);
                setHasMore(more);
                nextCursorRef.current = next;
                hasMoreRef.current = more;

                // History maps away non-completed rows — keep paging until we have cards or exhaust.
                if (mapped.length === 0 && more && next != null && emptySkipRef.current < 8) {
                    emptySkipRef.current += 1;
                    continueCursor = next;
                } else if (mapped.length > 0) {
                    emptySkipRef.current = 0;
                }
            } catch (e) {
                if (controller.signal.aborted) return;
                if (mode === 'first') {
                    setItems([]);
                    setNextCursor(null);
                    setHasMore(false);
                    nextCursorRef.current = null;
                    hasMoreRef.current = false;
                }
                setError(e instanceof Error ? e.message : 'Failed to load');
            } finally {
                if (!controller.signal.aborted) {
                    inFlightRef.current = false;
                    if (continueCursor == null) {
                        if (mode === 'first') setLoading(false);
                        else setLoadingMore(false);
                    }
                } else {
                    inFlightRef.current = false;
                }
            }

            if (continueCursor != null && !controller.signal.aborted && typeRef.current === activeType) {
                void fetchPage(continueCursor, mode);
            }
        },
        [enabled, limit],
    );

    const loadMore = useCallback(() => {
        if (!hasMoreRef.current || inFlightRef.current) return;
        const cursor = nextCursorRef.current;
        if (cursor == null) return;
        void fetchPage(cursor, 'more');
    }, [fetchPage]);

    // Reset + first page when type/enabled changes.
    useEffect(() => {
        abortRef.current?.abort();
        inFlightRef.current = false;

        if (!enabled || !type) {
            setItems([]);
            setNextCursor(null);
            setHasMore(false);
            setLoading(false);
            setLoadingMore(false);
            setError(null);
            nextCursorRef.current = null;
            hasMoreRef.current = false;
            return;
        }

        // Drop prior pages immediately so tab/type switches never flash the wrong list.
        setItems([]);
        setNextCursor(null);
        setHasMore(false);
        nextCursorRef.current = null;
        hasMoreRef.current = false;
        emptySkipRef.current = 0;
        void fetchPage(null, 'first');

        return () => {
            abortRef.current?.abort();
        };
    }, [type, enabled, fetchPage]);

    // Sentinel observer — root is the library/history scroll container when available.
    useEffect(() => {
        if (!enabled || !type || !hasMore) return;
        const sentinel = sentinelRef.current;
        if (!sentinel) return;

        const root = scrollRootRef.current;
        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    loadMore();
                }
            },
            { root: root ?? null, rootMargin: '240px 0px', threshold: 0 },
        );
        observer.observe(sentinel);
        return () => observer.disconnect();
    }, [enabled, type, hasMore, loading, loadingMore, items.length, loadMore]);

    return {
        items,
        setItems,
        nextCursor,
        hasMore,
        loading,
        loadingMore,
        error,
        loadMore,
        scrollRootRef,
        sentinelRef,
    };
}
