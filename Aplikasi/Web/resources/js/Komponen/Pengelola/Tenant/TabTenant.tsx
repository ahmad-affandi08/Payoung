import NavigasiTab from '@/Komponen/Pengelola/NavigasiTab';

export const daftarTabTenant = [
    { label: 'Daftar tenant', href: '/tenant' },
    { label: 'Tugas & kesehatan', href: '/tugas-tenant' },
];

/** Navigasi antar halaman tenant (P-07): daftar dan tugas otomatis dari skor kesehatan (PGL-09). */
export default function TabTenant() {
    return <NavigasiTab label="Tenant" daftarTab={daftarTabTenant} />;
}
