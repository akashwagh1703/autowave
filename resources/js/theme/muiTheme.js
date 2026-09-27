import { createTheme } from '@mui/material/styles';
import { colors, fontFamily, radius } from './tokens';

export const muiTheme = createTheme({
    palette: {
        primary: {
            light: colors.brand[500],
            main: colors.brand[600],
            dark: colors.brand[700],
            contrastText: '#ffffff',
        },
        secondary: {
            main: colors.accent[600],
            light: colors.accent[500],
            contrastText: '#ffffff',
        },
        success: { main: colors.success },
        warning: { main: colors.warning },
        error: { main: colors.error },
        info: { main: colors.info },
    },
    shape: {
        borderRadius: radius.control,
    },
    typography: {
        fontFamily,
        button: {
            textTransform: 'none',
            fontWeight: 600,
        },
    },
    components: {
        MuiButton: {
            defaultProps: { disableElevation: true },
        },
        MuiCard: {
            styleOverrides: {
                root: { borderRadius: radius.card },
            },
        },
    },
});
