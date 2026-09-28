import EmailIcon from '@mui/icons-material/Email';
import InstagramIcon from '@mui/icons-material/Instagram';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';

export const channelIcons = {
    whatsapp: WhatsAppIcon,
    instagram: InstagramIcon,
    email: EmailIcon,
};

export const channelColors = {
    whatsapp: 'text-emerald-600',
    instagram: 'text-pink-600',
    email: 'text-slate-500',
};

// A UUID for idempotent sends (the composer's client_id). crypto.randomUUID needs a secure context.
export function uuid() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
        const random = (Math.random() * 16) | 0;

        return (char === 'x' ? random : (random & 0x3) | 0x8).toString(16);
    });
}
