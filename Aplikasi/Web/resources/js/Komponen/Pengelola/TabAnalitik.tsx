import { usePage } from '@inertiajs/react';

import TabTautan from '@/Komponen/Navigasi/TabTautan';

/** Tab antara analitik platform (`/analitik`) dan analitik situs dari Google Analytics (`/analitik/situs`, D-215). */
export default function TabAnalitik() {
    const { url } = usePage();
    const diSitus = url.startsWith('/analitik/situs');

    return (
        <TabTautan
            label="Analitik"
            tab={[
                { label: 'Platform', href: '/analitik', aktif: !diSitus },
                { label: 'Situs (Google Analytics)', href: '/analitik/situs', aktif: diSitus },
            ]}
        />
    );
}
