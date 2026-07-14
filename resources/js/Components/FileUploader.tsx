import { useRef, useState, type DragEvent } from 'react';

type Props = {
    accept?: string;
    hint?: string;
    label?: string;
    onFile?: (file: File) => void;
};

export default function FileUploader({
    accept = 'video/*',
    hint = 'MP4, MOV, AVI up to 500MB',
    label = 'Upload Video',
    onFile,
}: Props) {
    const [dragging, setDragging] = useState(false);
    const [fileName, setFileName] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const handleFile = (file?: File) => {
        if (!file) return;
        setFileName(file.name);
        onFile?.(file);
    };

    const onDrop = (e: DragEvent) => {
        e.preventDefault();
        setDragging(false);
        handleFile(e.dataTransfer.files[0]);
    };

    return (
        <div className="space-y-1.5">
            <label className="block text-sm font-medium text-text-primary dark:text-[var(--dark-text-primary)]">
                {label}
            </label>
            <div
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={(e) => {
                    e.preventDefault();
                    setDragging(false);
                }}
                onDrop={onDrop}
                onClick={() => inputRef.current?.click()}
                className={`relative flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed p-6 transition-all duration-200 ${
                    dragging
                        ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/20'
                        : 'border-border dark:border-[var(--dark-border)] hover:border-brand-400'
                }`}
            >
                <div className="flex flex-col items-center gap-2">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-surface-tertiary dark:bg-[var(--dark-surface-tertiary)]">
                        <svg
                            className="h-6 w-6 text-text-tertiary dark:text-[var(--dark-text-tertiary)]"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth="1.5"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"
                            />
                        </svg>
                    </div>
                    <p className="text-sm font-medium text-text-primary dark:text-[var(--dark-text-primary)]">
                        {fileName || 'Click or drag to upload'}
                    </p>
                    <p className="text-xs text-text-tertiary dark:text-[var(--dark-text-tertiary)]">{hint}</p>
                </div>
                <input
                    ref={inputRef}
                    type="file"
                    accept={accept}
                    className="hidden"
                    onChange={(e) => handleFile(e.target.files?.[0])}
                />
            </div>
        </div>
    );
}
