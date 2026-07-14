/** Stable colorful art per track id — never uses result cover/preview URLs. */
export function musicPalette(id: string) {
    let hash = 0;
    for (let i = 0; i < id.length; i++) hash = (hash * 31 + id.charCodeAt(i)) >>> 0;
    const h1 = hash % 360;
    const h2 = (h1 + 48 + (hash % 40)) % 360;
    const h3 = (h1 + 160 + (hash % 50)) % 360;
    return {
        base: `linear-gradient(145deg, hsl(${h1} 78% 42%) 0%, hsl(${h2} 82% 36%) 48%, hsl(${h3} 70% 22%) 100%)`,
        blobA: `hsla(${h1}, 95%, 62%, 0.85)`,
        blobB: `hsla(${h3}, 90%, 55%, 0.75)`,
        blobC: `hsla(${h2}, 100%, 68%, 0.55)`,
        bar: `linear-gradient(180deg, hsla(${(h1 + 20) % 360}, 100%, 88%, 0.95) 0%, hsla(${h1}, 95%, 62%, 0.95) 45%, hsla(${h3}, 90%, 48%, 0.85) 100%)`,
    };
}
