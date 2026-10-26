import { usePage } from '@inertiajs/react';

import TabTautan from '@/Komponen/Navigasi/TabTautan';

/** Tab antara daftar kampanye prospek dan daftar kontaknya (D-216). */
export default function NavigasiKampanye() {
    const { url } = usePage();
    const diKontak = url.startsWith('/kampanye-prospek/kontak');

    return (
        <TabTautan
            label="Kampanye prospek"
            tab={[
                { label: 'Kampanye', href: '/kampanye-prospek', aktif: !diKontak },
                { label: 'Kontak', href: '/kampanye-prospek/kontak', aktif: diKontak },
            ]}
        />
    );
}
