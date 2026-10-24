import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';

/** Galat bentuk isian dari server (misal `Akun.3.Kode`) diringkas di atas formulir. */
export default function RingkasanGalat({ galat }: { galat: Record<string, string | undefined> }) {
    const daftar = Object.entries(galat).filter(([kunci, pesan]) => kunci !== 'Umum' && pesan);

    if (daftar.length === 0) {
        return null;
    }

    return (
        <Pemberitahuan jenis="bahaya" judul="Periksa kembali isian">
            <ul className="list-disc pl-5">
                {daftar.map(([kunci, pesan]) => (
                    <li key={kunci}>{pesan}</li>
                ))}
            </ul>
        </Pemberitahuan>
    );
}
