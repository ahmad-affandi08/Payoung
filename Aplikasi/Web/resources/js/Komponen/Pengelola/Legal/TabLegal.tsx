import NavigasiTab from '@/Komponen/Pengelola/NavigasiTab';

export const daftarTab = [
    { label: 'Dokumen legal', href: '/legal' },
    { label: 'Templat pesan', href: '/template-komunikasi' },
];

/** Navigasi antara dokumen legal (P-06) dan templat komunikasi ke Owner (PGL-07). */
export default function TabLegal() {
    return <NavigasiTab label="Legal" daftarTab={daftarTab} />;
}
