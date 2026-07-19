import { apiPost } from '@/lib/api';

export type DiscardCreationType = 'image' | 'video' | 'music' | 'voice';

/** Soft-hide creations server-side (discarded = 1). Fire-and-forget safe. */
export async function discardCreations(type: DiscardCreationType, ids: number[]): Promise<void> {
    const unique = [...new Set(ids.filter((id) => Number.isFinite(id) && id > 0))];
    if (unique.length === 0) return;
    await apiPost('/lab/creations/discard', { type, ids: unique });
}
