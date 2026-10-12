import TataLetakAutentikasi from '@/TataLetak/TataLetakAutentikasi';

/** PGL-16: halaman setelah sesi akses dukungan berakhir (diakhiri petugas, dicabut Owner, waktu habis, atau tautan tidak sah). */
export default function HalamanAksesDukunganBerakhir({ Pesan }: { Pesan: string }) {
    return (
        <TataLetakAutentikasi judul="Akses dukungan berakhir" keterangan={Pesan}>
            <p className="text-keterangan text-teks-sekunder">Anda bisa menutup tab ini.</p>
        </TataLetakAutentikasi>
    );
}
