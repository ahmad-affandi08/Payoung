type PropsGambarProduk = {
    nama: string;
    /** `UrlGambar`/`UrlGambarKecil` dari server; null = produk belum punya foto. */
    url: string | null;
    /**
     * Skala teks inisial; ukuran wadah tetap ditentukan `className` pemanggil. `kecil` untuk baris tabel, `sedang`
     * untuk kepala halaman, `besar` untuk kartu menu publik.
     */
    ukuran?: 'kecil' | 'sedang' | 'besar';
    /**
     * Kelas wadah: ukuran, latar, bingkai, dan display (`flex`/`grid`). Komponen hanya meratakan isinya ke tengah
     * dan memotong tepi, jadi setiap pemanggil tetap memakai bentuk wadahnya sendiri.
     */
    className?: string;
};

/**
 * Gambar produk dengan placeholder inisial saat foto belum ada — sama seperti ubin katalog di aplikasi Kasir
 * (`UbinProduk`), supaya produk tanpa foto tetap punya penanda yang terbaca alih-alih kotak kosong. Inisial
 * diambil dari dua kata pertama nama ("Es Kopi Susu" → "EK"); nama kosong → "?". Placeholder murni dekoratif
 * (`aria-hidden`), karena nama produknya sudah tertulis di sebelah gambar.
 */
export default function GambarProduk({ nama, url, ukuran = 'kecil', className = '' }: PropsGambarProduk) {
    return (
        <div className={`items-center justify-center overflow-hidden ${className}`}>
            {url ? (
                <img src={url} alt="" loading="lazy" className="size-full object-cover" />
            ) : (
                <span
                    aria-hidden="true"
                    className={`font-mono font-semibold text-teks-sekunder ${UKURAN_TEKS[ukuran]}`}
                >
                    {AmbilInisialProduk(nama)}
                </span>
            )}
        </div>
    );
}

const UKURAN_TEKS: Record<NonNullable<PropsGambarProduk['ukuran']>, string> = {
    kecil: 'text-label',
    sedang: 'text-subjudul',
    besar: 'text-judul',
};

/** Dua huruf pertama dari dua kata pertama nama produk; nama kosong/spasi saja → "?". */
export function AmbilInisialProduk(nama: string): string {
    const kata = nama
        .trim()
        .split(/\s+/u)
        .filter((k) => k !== '');

    if (kata.length === 0) {
        return '?';
    }

    return kata
        .slice(0, 2)
        .map((k) => k.charAt(0).toUpperCase())
        .join('');
}
