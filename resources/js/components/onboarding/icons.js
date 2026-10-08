import BusinessIcon from '@mui/icons-material/Business';
import LocalCafeIcon from '@mui/icons-material/LocalCafe';
import LocalHospitalIcon from '@mui/icons-material/LocalHospital';
import PhotoCameraIcon from '@mui/icons-material/PhotoCamera';
import SchoolIcon from '@mui/icons-material/School';
import SpaIcon from '@mui/icons-material/Spa';
import SportsSoccerIcon from '@mui/icons-material/SportsSoccer';
import StorefrontIcon from '@mui/icons-material/Storefront';

// Keys match the `icon` values in config/catalog.php business types.
const icons = {
    business: BusinessIcon,
    cafe: LocalCafeIcon,
    camera: PhotoCameraIcon,
    clinic: LocalHospitalIcon,
    school: SchoolIcon,
    spa: SpaIcon,
    sports: SportsSoccerIcon,
    store: StorefrontIcon,
};

export function businessTypeIcon(name) {
    return icons[name] ?? BusinessIcon;
}
