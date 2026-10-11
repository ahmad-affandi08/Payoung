import { usePage } from '@inertiajs/react';

import TabTautan from '@/Komponen/Navigasi/TabTautan';

const daftarTab = [
    { label: 'Tim', href: '/kelola/tim' },
    { label: 'Peran & izin', href: '/kelola/peran' },
];

/**
 * Navigasi antara daftar pengguna dan peran (F-02 langkah 3, §19.1): tautan halaman Inertia dengan gaya tab bersama
 * `TabTautan`.
 */
export default function TabPengguna() {
    const { url } = usePage();

    return (
        <TabTautan label="Tim & peran" tab={daftarTab.map((tab) => ({ ...tab, aktif: url.startsWith(tab.href) }))} />
    );
}
