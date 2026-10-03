import DescriptionIcon from '@mui/icons-material/DescriptionOutlined';
import DownloadIcon from '@mui/icons-material/Download';
import { formatBytes } from '@/utils/format';
import { Section, SectionHeading, useSite } from './site';

/** The Video section: up to three uploaded videos, played on the page. */
export function Videos({ config, data }) {
    const { theme } = useSite();

    return (
        <Section id="video">
            <SectionHeading title={config.heading} intro={config.intro} />
            <div className={data.length > 1 ? 'grid gap-6 md:grid-cols-2' : 'mx-auto max-w-3xl'}>
                {data.map((video) => (
                    <figure key={video.url}>
                        <video controls playsInline preload="metadata" className="aspect-video w-full bg-black" style={{ borderRadius: theme.radius }}>
                            <source src={video.url} type={video.type} />
                            Your browser cannot play this video.
                        </video>
                        {video.title ? <figcaption className="mt-2 text-center text-sm text-slate-600">{video.title}</figcaption> : null}
                    </figure>
                ))}
            </div>
        </Section>
    );
}

/** The Downloads section: brochures, price lists or forms visitors can open. */
export function Downloads({ config, data }) {
    const { theme } = useSite();

    return (
        <Section id="downloads" tone="muted" className="max-w-3xl">
            <SectionHeading title={config.heading} intro={config.intro} />
            <ul className="grid gap-3 sm:grid-cols-2">
                {data.map((file) => (
                    <li key={file.url}>
                        <a
                            href={file.url}
                            target="_blank"
                            rel="noopener"
                            className="flex items-center gap-3 border border-slate-100 bg-white p-4 shadow-sm transition hover:shadow-md"
                            style={{ borderRadius: theme.radius }}
                        >
                            <DescriptionIcon style={{ color: theme.color }} />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-medium text-slate-900">{file.name}</span>
                                <span className="text-xs text-slate-500">{[file.extension, formatBytes(file.size_bytes)].filter(Boolean).join(' · ')}</span>
                            </span>
                            <DownloadIcon fontSize="small" className="shrink-0 text-slate-400" />
                        </a>
                    </li>
                ))}
            </ul>
        </Section>
    );
}
