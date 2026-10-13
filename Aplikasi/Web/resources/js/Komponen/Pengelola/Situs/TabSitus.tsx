import NavigasiTab from '@/Komponen/Pengelola/NavigasiTab';

export const daftarTab = [
    { label: 'Halaman', href: '/situs/halaman' },
    { label: 'Pengaturan', href: '/situs/pengaturan' },
    { label: 'Gambar', href: '/situs/gambar' },
    { label: 'Artikel', href: '/situs/artikel' },
    { label: 'Bantuan', href: '/situs/bantuan' },
    { label: 'Prospek', href: '/situs/prospek' },
];

/** Navigasi antar bagian konsol situs pemasaran (D-21). */
export default function TabSitus() {
    return <NavigasiTab label="Situs pemasaran" daftarTab={daftarTab} />;
}
