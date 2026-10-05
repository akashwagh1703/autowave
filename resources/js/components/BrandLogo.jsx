import { useId } from 'react';

/** The AutoWave mark (same drawing as public/favicon.svg). */
export function BrandMark({ size = 32, className = '' }) {
    const gradient = useId();

    return (
        <svg width={size} height={size} viewBox="0 0 64 64" aria-hidden="true" className={className}>
            <defs>
                <linearGradient id={gradient} x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stopColor="#6366f1" />
                    <stop offset="1" stopColor="#3730a3" />
                </linearGradient>
            </defs>
            <rect width="64" height="64" rx="16" fill={`url(#${gradient})`} />
            <path d="M11 40c7 0 8-17 15-17s8 17 15 17c5 0 7-7 12-7" fill="none" stroke="#fff" strokeWidth="6" strokeLinecap="round" strokeLinejoin="round" />
            <circle cx="49" cy="19" r="5" fill="#2dd4bf" />
        </svg>
    );
}

/** Mark and wordmark. `tone="light"` for dark backgrounds. */
export default function BrandLogo({ name = 'AutoWave', size = 32, tone = 'dark', className = '' }) {
    return (
        <span className={`inline-flex items-center gap-2.5 ${className}`}>
            <BrandMark size={size} />
            <span className={`font-display text-xl font-extrabold tracking-tight ${tone === 'light' ? 'text-white' : 'text-ink'}`}>{name}</span>
        </span>
    );
}
