import { StyledEngineProvider, ThemeProvider } from '@mui/material/styles';
import { muiTheme } from '@/theme/muiTheme';

// Tailwind's preflight is the CSS reset, so MUI's CssBaseline is intentionally not used.
export default function AppProviders({ children }) {
    return (
        <StyledEngineProvider enableCssLayer>
            <ThemeProvider theme={muiTheme}>{children}</ThemeProvider>
        </StyledEngineProvider>
    );
}
