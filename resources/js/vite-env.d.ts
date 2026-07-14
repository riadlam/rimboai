/// <reference types="vite/client" />

interface ImportMetaEnv {
    readonly VITE_APP_NAME: string;
    /** Public site origin, e.g. https://rimboai.com — empty = same-origin */
    readonly VITE_APP_URL?: string;
    readonly VITE_DEV_SERVER_HOST?: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
    readonly glob: (pattern: string) => Record<string, () => Promise<unknown>>;
}
