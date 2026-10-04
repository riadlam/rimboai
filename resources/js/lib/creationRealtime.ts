import { getEcho } from '@/lib/echo';

export type CreationUpdatedEvent = {
    type?: string;
    creation_id?: number;
    creation?: {
        id?: number;
        type?: string;
        status?: string;
        progress_message?: string | null;
        progress_percent?: number | null;
        video_url?: string | null;
        preview_url?: string | null;
        thumbnail_url?: string | null;
        images?: string[];
        audio_url?: string | null;
        cover_url?: string | null;
        error?: string | null;
        token_balance?: number | null;
        [key: string]: unknown;
    };
};

export const CREATION_SAFETY_NET_MS = 6000;

export function isTerminalCreationStatus(status?: string | null): boolean {
    return status === 'completed' || status === 'failed' || status === 'cancelled';
}

/**
 * Subscribe to private user.{id} creation.updated (fal webhook → Pusher).
 * Does not leave the channel on cleanup (AppHeader / Lab may share it).
 */
export function subscribeCreationUpdated(
    userId: number,
    handler: (event: CreationUpdatedEvent) => void,
): (() => void) | null {
    const echo = getEcho();
    if (!echo) return null;

    const channelName = `user.${userId}`;
    const channel = echo.private(channelName);
    channel.listen('.creation.updated', handler);

    return () => {
        channel.stopListening('.creation.updated', handler);
    };
}

export function matchesCreationEvent(
    event: CreationUpdatedEvent,
    creationId: number,
    types?: string[],
): boolean {
    const eventId = event.creation_id ?? event.creation?.id;
    if (eventId !== creationId) return false;
    if (!types || types.length === 0) return true;
    const kind = event.type ?? event.creation?.type;
    return Boolean(kind && types.includes(kind));
}
