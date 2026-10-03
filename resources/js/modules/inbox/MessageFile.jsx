import DownloadIcon from '@mui/icons-material/Download';
import InsertDriveFileIcon from '@mui/icons-material/InsertDriveFileOutlined';
import { formatBytes } from '@/utils/format';

const PLAYABLE_AUDIO = ['audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/aac'];

/** The photo, video, voice note or document of an inbox message (private: loads through AutoWave). */
export default function MessageFile({ file, outbound }) {
    const muted = outbound ? 'text-brand-100' : 'text-slate-500';

    if (file.kind === 'image') {
        return (
            <a href={file.url} target="_blank" rel="noopener" className="block">
                <img src={file.url} alt={file.name} loading="lazy" className="max-h-64 max-w-full rounded-lg bg-slate-100 object-contain" />
            </a>
        );
    }

    if (file.kind === 'video') {
        return <video controls preload="metadata" src={file.url} className="max-h-64 max-w-full rounded-lg bg-black" />;
    }

    if (file.kind === 'audio' && PLAYABLE_AUDIO.includes(file.mime_type)) {
        return <audio controls preload="none" src={file.url} className="w-64 max-w-full" />;
    }

    return (
        <div className={`flex items-center gap-2 rounded-lg px-2 py-1.5 ${outbound ? 'bg-brand-700/60' : 'bg-slate-100'}`}>
            <InsertDriveFileIcon fontSize="small" className={`shrink-0 ${muted}`} />
            <a href={file.url} target="_blank" rel="noopener" className="min-w-0 flex-1 hover:underline" title={file.original_name}>
                <span className="block truncate font-medium">{file.name}</span>
                <span className={`text-[11px] ${muted}`}>{[file.extension, formatBytes(file.size_bytes)].filter(Boolean).join(' · ')}</span>
            </a>
            <a href={file.download_url} aria-label={`Download ${file.name}`} className={`shrink-0 rounded p-1 hover:bg-black/10 ${muted}`}>
                <DownloadIcon fontSize="small" />
            </a>
        </div>
    );
}
