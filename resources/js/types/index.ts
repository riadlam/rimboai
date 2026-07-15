export type User = {
    id: number;
    name: string;
    email: string;
    tokens: number;
};

export type BrandVoice = {
    id: number;
    voice_key: string;
    name: string;
    description?: string | null;
    language?: string | null;
    gender?: string | null;
    category?: string | null;
    tags?: string[];
    sample_url?: string | null;
    is_default?: boolean;
    sort?: number;
};

export type BrandMusicExample = {
    id: number;
    example_key: string;
    title: string;
    style: string;
    description?: string | null;
    vocals: boolean;
    cover_url?: string | null;
    /** Empty until you paste a URL manually */
    sample_url?: string | null;
    tags?: string[];
    sort?: number;
};

export type BrandModel = {
    name: string;
    icon: string | null;
    description: string;
    endpoint_id: string;
    unit_price: number | string | null;
    unit: string | null;
    max_duration: number | null;
    enums: Array<string | number> | null;
    duration: string | null;
    credits: number | null;
    tags: string[];
    image_cover?: string | null;
    sort?: number;
    /** Whether this model supports remixing from source images (variations mode) */
    supports_variations?: boolean;
    /** Video lab: native soundtrack toggle (generate_audio) */
    supports_audio?: boolean;
    /** Music lab: can generate singing vocals */
    supports_vocals?: boolean;
    /** Music lab: accepts custom lyrics */
    supports_lyrics?: boolean;
    /** Music lab: can force instrumental / no vocals */
    supports_instrumental?: boolean;
    /** Music lab: max lyrics characters */
    max_lyrics_chars?: number | null;
    /** Music lab: max style/prompt characters */
    max_prompt_chars?: number | null;
    /** Music lab: hidden billing assumption for time-based units (not a UI slider) */
    default_duration_seconds?: number | null;
    /** Music lab: curated examples (sample_url may be empty until pasted) */
    examples?: BrandMusicExample[];
    /** Voice lab: available voices for this model */
    voices?: BrandVoice[];
    /** Video lab: which reference media types this catalog model can accept */
    media_capabilities?: {
        supports_ref_images: boolean;
        supports_ref_videos: boolean;
        supports_ref_audio: boolean;
        supports_first_frame: boolean;
        reference_endpoint_id?: string | null;
        first_frame_endpoint_id?: string | null;
        first_frame_param?: string | null;
    };
};

export type Brand = {
    name: string;
    icon: string | null;
    models: BrandModel[];
    sort?: number;
};

export type Tool = {
    name: string;
    poster: string;
    video: string;
    route: string;
    badge?: string;
};

export type TokenPackage = {
    slug: string;
    name: string;
    tokens: number;
    price_dzd: number;
};

export type PageProps = {
    app?: {
        name: string;
        url: string;
        env: string;
    };
    auth: {
        user: User | null;
    };
    tokenPackages?: TokenPackage[];
    flash?: {
        success?: string | null;
        error?: string | null;
    };
};
