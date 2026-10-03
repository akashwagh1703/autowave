import CloseIcon from '@mui/icons-material/Close';
import DescriptionIcon from '@mui/icons-material/DescriptionOutlined';
import PlayCircleIcon from '@mui/icons-material/PlayCircleOutlined';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useSite } from './site';

function VideoPlayer({ video, title, onClose }) {
    const close = useRef(null);

    useEffect(() => {
        const onKey = (event) => event.key === 'Escape' && onClose();
        close.current?.focus();
        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4" role="dialog" aria-modal="true" aria-label={title} onClick={onClose}>
            <div className="relative w-full max-w-3xl" onClick={(event) => event.stopPropagation()}>
                <button ref={close} type="button" onClick={onClose} className="absolute -top-10 right-0 rounded-full p-1 text-white hover:bg-white/10" aria-label="Close video">
                    <CloseIcon />
                </button>
                <video controls autoPlay playsInline preload="metadata" className="max-h-[80vh] w-full rounded-lg bg-black">
                    <source src={video.url} type={video.type} />
                    Your browser cannot play this video.
                </video>
            </div>
        </div>
    );
}

/** "Watch video" and brochure links of a product, service or course (catalog files the business uploaded). */
export default function ItemFiles({ item, className = '' }) {
    const { theme } = useSite();
    const [playing, setPlaying] = useState(false);
    const stop = useCallback(() => setPlaying(false), []);

    if (!item.video && !(item.brochures?.length > 0)) {
        return null;
    }

    return (
        <div className={`flex flex-wrap items-center gap-x-4 gap-y-1 text-sm ${className}`}>
            {item.video ? (
                <button type="button" onClick={() => setPlaying(true)} className="inline-flex items-center gap-1 font-medium hover:underline" style={{ color: theme.color }}>
                    <PlayCircleIcon sx={{ fontSize: 18 }} />
                    Watch video
                </button>
            ) : null}
            {item.brochures?.map((brochure) => (
                <a key={brochure.url} href={brochure.url} target="_blank" rel="noopener" className="inline-flex items-center gap-1 font-medium hover:underline" style={{ color: theme.color }}>
                    <DescriptionIcon sx={{ fontSize: 18 }} />
                    {brochure.name}
                    <span className="text-xs text-slate-500">{brochure.extension}</span>
                </a>
            ))}
            {playing ? <VideoPlayer video={item.video} title={item.video.title || item.name} onClose={stop} /> : null}
        </div>
    );
}
