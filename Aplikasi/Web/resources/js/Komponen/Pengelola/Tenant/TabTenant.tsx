import { usePage } from '@inertiajs/react';

import NavigasiTab from '@/Komponen/Pengelola/NavigasiTab';
import { IzinPengelola, PunyaIzin, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

export const daftarTabTenant = [
    { label: 'Daftar tenant', href: '/tenant' },
    { label: 'Tugas & kesehatan', href: '/tugas-tenant' },
];

/** Navigasi antar halaman tenant (P-07): daftar, tugas otomatis dari skor kesehatan (PGL-09), dan siaran ke tenant (D-210) dan kampanye email ke prospek (D-216), keduanya Super Admin. */
export default function TabTenant() {
    const { Pengguna: pengguna } = usePage<PropsBersamaPengelola>().props;
    const bolehKomunikasi = PunyaIzin(pengguna, IzinPengelola.KomunikasiKelola);

    return (
        <NavigasiTab
            label="Tenant"
            daftarTab={
                bolehKomunikasi
                    ? [
                          ...daftarTabTenant,
                          { label: 'Komunikasi', href: '/komunikasi' },
                          { label: 'Kampanye prospek', href: '/kampanye-prospek' },
                      ]
                    : daftarTabTenant
            }
        />
    );
}
