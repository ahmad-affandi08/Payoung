import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';

/**
 * Galat server yang tidak terikat ke isian yang sedang tampil (misal BR-02.1 saat memulihkan produk,
 * BR-03.2 saat menghapus). `Umum` sudah ditampilkan tata letak, jadi dilewati. Diumumkan lewat aria-live.
 */
export default function DaftarGalatServer({
    galat,
    kecuali = [],
}: {
    galat: Record<string, string | undefined>;
    /** Kunci yang sudah tampil di bawah isian formulir halaman ini. */
    kecuali?: string[];
}) {
    const pesan = Object.entries(galat)
        .filter(([kunci, isi]) => kunci !== 'Umum' && Boolean(isi) && !kecuali.includes(kunci))
        .map(([kunci, isi]) => ({ kunci, isi: isi ?? '' }));

    return (
        // Area live harus selalu ada di DOM supaya galat baru diumumkan; saat kosong ia `absolute` agar tidak menjadi
        // anak aliran yang menambah satu celah `gap` kosong di antara blok halaman.
        <div aria-live="polite" aria-atomic="true" className={pesan.length > 0 ? undefined : 'absolute'}>
            {pesan.length > 0 ? (
                <Pemberitahuan jenis="bahaya" judul="Perubahan tidak disimpan">
                    <ul className="list-disc pl-5">
                        {pesan.map((item) => (
                            <li key={item.kunci}>{item.isi}</li>
                        ))}
                    </ul>
                </Pemberitahuan>
            ) : null}
        </div>
    );
}
