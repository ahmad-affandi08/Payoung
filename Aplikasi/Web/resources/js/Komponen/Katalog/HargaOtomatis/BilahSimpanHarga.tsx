import Tombol from '@/Komponen/Formulir/Tombol';

type PropsBilahSimpanHarga = {
    /** Saklar menyala: tawarkan juga "Simpan dan hitung ulang sekarang". */
    aktif: boolean;
    siapSimpan: boolean;
    memproses: boolean;
    saatBatal: () => void;
    saatSimpanDanHitung: () => void;
};

/**
 * Bilah simpan yang menempel di dasar layar (D-268), hanya muncul bila ada yang diubah. Tombol "Simpan" mengirim
 * formulir yang membungkusnya, jadi Enter di isian mana pun menyimpan. Yang menyalakan penghitungan ulang harga
 * dipisah jelas dari yang hanya menyimpan aturan.
 */
export default function BilahSimpanHarga({
    aktif,
    siapSimpan,
    memproses,
    saatBatal,
    saatSimpanDanHitung,
}: PropsBilahSimpanHarga) {
    return (
        <div
            role="region"
            aria-label="Simpan perubahan"
            className="sticky bottom-0 z-10 mt-4 grid grid-cols-2 gap-2 border-t border-garis bg-permukaan px-4 py-3 tepi-bawah-aman sm:flex sm:flex-wrap sm:items-center"
        >
            <p className="col-span-2 min-w-48 text-label text-teks-utama sm:flex-1">
                <span className="block font-semibold">Ada perubahan yang belum disimpan</span>
                <span className="hidden text-teks-sekunder sm:block">
                    {siapSimpan
                        ? 'Aturan baru dipakai otomatis mulai pembelian berikutnya.'
                        : 'Lengkapi isian yang masih kosong supaya bisa disimpan.'}
                </span>
                {siapSimpan ? null : (
                    <span className="block text-teks-sekunder sm:hidden">Lengkapi isian yang masih kosong.</span>
                )}
            </p>
            <Tombol varian="sekunder" disabled={memproses} onClick={saatBatal}>
                Batalkan
            </Tombol>
            <Tombol type="submit" varian={aktif ? 'sekunder' : 'utama'} memproses={memproses} disabled={!siapSimpan}>
                {aktif ? 'Simpan saja' : 'Simpan'}
            </Tombol>
            {aktif ? (
                <div className="col-span-2 flex flex-col sm:contents">
                    <Tombol disabled={!siapSimpan || memproses} onClick={saatSimpanDanHitung}>
                        Simpan dan hitung ulang sekarang
                    </Tombol>
                </div>
            ) : null}
        </div>
    );
}
