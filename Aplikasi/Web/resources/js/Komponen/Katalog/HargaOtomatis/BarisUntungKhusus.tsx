import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import PemilihProduk from '@/Komponen/Katalog/PemilihProduk';
import { RapikanPersen } from '@/Pustaka/HargaOtomatis';

/** Satu untung khusus di dalam formulir. `kunci` dan `sunting` hanya untuk layar, tidak dikirim ke server. */
export type BarisKhusus = {
    kunci: string;
    cakupan: 'Kategori' | 'Produk';
    uuidKategori: string;
    produk: { Uuid: string; Nama: string } | null;
    persen: string;
    sunting: boolean;
};

const OpsiCakupan = [
    { Nilai: 'Kategori', Label: 'Satu kategori' },
    { Nilai: 'Produk', Label: 'Satu produk' },
];

/** Baris sudah lengkap bila sasaran dan untungnya terisi. */
export function CekBarisLengkap(b: BarisKhusus): boolean {
    return b.persen.trim() !== '' && (b.cakupan === 'Kategori' ? b.uuidKategori !== '' : b.produk !== null);
}

type PropsBarisUntungKhusus = {
    baris: BarisKhusus;
    opsiKategori: { Uuid: string; Nama: string }[];
    bolehUbah: boolean;
    saatUbah: (perubahan: Partial<BarisKhusus>) => void;
    saatHapus: () => void;
};

/**
 * Satu untung khusus (D-268): ringkas satu baris (jenis, nama sasaran, untung) dengan Ubah dan Hapus. Baris baru atau
 * yang sedang diubah terbuka sebagai isian lengkap, dan Selesai baru bisa ditekan bila sudah lengkap.
 */
export default function BarisUntungKhusus({
    baris,
    opsiKategori,
    bolehUbah,
    saatUbah,
    saatHapus,
}: PropsBarisUntungKhusus) {
    if (!baris.sunting) {
        const nama =
            baris.cakupan === 'Kategori'
                ? (opsiKategori.find((k) => k.Uuid === baris.uuidKategori)?.Nama ?? '-')
                : (baris.produk?.Nama ?? '-');

        return (
            <li className="flex flex-wrap items-center gap-2 rounded-panel border border-garis p-3">
                <span className="rounded-kontrol bg-permukaan-redup px-2 py-0.5 text-keterangan font-semibold text-teks-sekunder">
                    {baris.cakupan === 'Kategori' ? 'Kategori' : 'Produk'}
                </span>
                <span className="min-w-32 flex-1 break-words text-isi font-semibold text-teks-utama">{nama}</span>
                <span className="text-isi font-semibold tabular-nums text-brand">
                    Untung {RapikanPersen(baris.persen)}%
                </span>
                {bolehUbah ? (
                    <>
                        <Tombol
                            varian="sekunder"
                            aria-label={`Ubah untung khusus ${nama}`}
                            onClick={() => saatUbah({ sunting: true })}
                        >
                            Ubah
                        </Tombol>
                        <Tombol varian="sekunder" aria-label={`Hapus untung khusus ${nama}`} onClick={saatHapus}>
                            Hapus
                        </Tombol>
                    </>
                ) : null}
            </li>
        );
    }

    return (
        <li className="flex flex-col gap-3 rounded-panel border border-brand p-3">
            <div className="grid gap-3 sm:grid-cols-[11rem_minmax(0,1fr)_9rem]">
                <BidangPilihan
                    label="Berlaku untuk"
                    nilai={baris.cakupan}
                    opsi={OpsiCakupan}
                    saatBerubah={(nilai) =>
                        saatUbah({
                            cakupan: nilai === 'Produk' ? 'Produk' : 'Kategori',
                            uuidKategori: '',
                            produk: null,
                        })
                    }
                />
                {baris.cakupan === 'Kategori' ? (
                    <BidangPilihan
                        label="Kategori"
                        nilai={baris.uuidKategori}
                        opsi={opsiKategori.map((k) => ({ Nilai: k.Uuid, Label: k.Nama }))}
                        saatBerubah={(nilai) => saatUbah({ uuidKategori: nilai })}
                    />
                ) : (
                    <PemilihProduk
                        label="Produk"
                        jenis={['Stok']}
                        saatPilih={(terpilih) => saatUbah({ produk: { Uuid: terpilih.Uuid, Nama: terpilih.Nama } })}
                        {...(baris.produk === null ? {} : { keterangan: `Dipilih: ${baris.produk.Nama}` })}
                    />
                )}
                <BidangJumlah
                    label="Untung"
                    nilai={baris.persen}
                    saatBerubah={(nilai) => saatUbah({ persen: nilai })}
                    desimal={2}
                    digitBulat={4}
                    akhiran="%"
                />
            </div>
            <div className="flex flex-wrap gap-2">
                <Tombol disabled={!CekBarisLengkap(baris)} onClick={() => saatUbah({ sunting: false })}>
                    Selesai
                </Tombol>
                <Tombol varian="sekunder" onClick={saatHapus}>
                    Hapus
                </Tombol>
            </div>
        </li>
    );
}
