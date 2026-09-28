import Chip from '@mui/material/Chip';

export default function StageChip({ stage, size = 'small' }) {
    if (!stage) {
        return null;
    }

    return (
        <Chip
            size={size}
            variant="outlined"
            label={stage.name}
            icon={<span className="ml-2 inline-block h-2 w-2 rounded-full" style={{ backgroundColor: stage.color }} />}
            sx={{ borderColor: stage.color, color: 'text.primary', fontWeight: 500 }}
        />
    );
}
