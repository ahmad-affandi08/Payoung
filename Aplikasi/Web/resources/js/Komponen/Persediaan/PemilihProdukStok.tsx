import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import {
    AmbilHasilCari,
    BATAS_CARI_PRODUK,
    KerangkaPemilihProduk,
    useNilaiTertunda,
    usePilihSetelahPindai,
    useSorotPertama,
} from '@/Komponen/Katalog/PemilihProduk';
import { CommandItem } from '@/Komponen/Ui/command';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import type { HasilCariProdukStok } from '@/Tipe/Persediaan';

export type ProdukStokTerpilih = HasilCariProdukStok['Data'][number];

/** URL pencarian produk berstok (DesainF05a D: `GET /kelola/persediaan/produk/cari?kata=&gudang=&batas=`). */
export function BuatUrlCariProdukStok(kata: string, uuidGudang: string | null, batas = BATAS_CARI_PRODUK): string {
    const parameter = new URLSearchParams({ kata });

    if (uuidGudang !== null) {
        parameter.set('gudang', uuidGudang);
    }

    parameter.set('batas', String(batas));

    return `/kelola/persediaan/produk/cari?${parameter.toString()}`;
}

type PropsPemilihProdukStok = {
    label: string;
    /** Lokasi stok untuk kolom "stok saat ini"; null = belum dipilih. */
    uuidGudang: string | null;
    saatPilih: (produk: ProdukStokTerpilih) => void;
    /** Uuid produk yang tidak ditawarkan lagi (sudah ada di dokumen). */
    kecuali?: string[];
    /** Form stok awal: produk yang stok awalnya sudah dicatat di lokasi ini tidak bisa dipilih (StokAwalSudahAda). */
    tolakStokAwalAda?: boolean;
    keterangan?: string;
    galat?: string | undefined;
    disabled?: boolean;
    /** F-04: URL pencarian lain berbentuk sama (misal `/kelola/pembelian/produk/cari` yang juga membawa satuan beli). */
    buatUrl?: (kata: string, uuidGudang: string | null) => string;
    /** Entri cepat barang: id tombol pemicu dan pengalih fokus sesudah memilih (lihat `useEntriCepat`). */
    id?: string;
    alihkanFokusSetelahPilih?: () => boolean;
};

/**
 * Pemilih produk berstok untuk stok awal, kartu stok, dan pembelian (DesainF05a E). Memakai
 * {@link KerangkaPemilihProduk} yang sama dengan `PemilihProduk`, jadi bentuk & perilakunya mengikuti
 * `Komponen/Formulir/PilihanCari` seperti dropdown lain. Server hanya mengembalikan produk yang punya stok
 * (bukan konsinyasi, tidak diarsipkan).
 */
export default function PemilihProdukStok({
    label,
    uuidGudang,
    saatPilih,
    kecuali = [],
    tolakStokAwalAda = false,
    keterangan,
    galat,
    disabled,
    buatUrl = BuatUrlCariProdukStok,
    id,
    alihkanFokusSetelahPilih,
}: PropsPemilihProdukStok) {
    const [kata, AturKata] = useState('');
    const [terbuka, AturTerbuka] = useState(false);
    const [sorot, AturSorot] = useState('');
    const kataCari = useNilaiTertunda(kata.trim(), 300);
    const kueri = useQuery({
        queryKey:
            buatUrl === BuatUrlCariProdukStok
                ? KunciKueri.Persediaan.CariProduk(kataCari, uuidGudang)
                : [...KunciKueri.Persediaan.CariProduk(kataCari, uuidGudang), buatUrl(kataCari, uuidGudang)],
        queryFn: ({ signal }) => AmbilHasilCari<HasilCariProdukStok>(buatUrl(kataCari, uuidGudang), signal),
        enabled: terbuka,
        staleTime: 0,
        // Hasil lama tetap tampil selama hasil baru dimuat, jadi daftar tidak berkedip saat mengetik.
        placeholderData: keepPreviousData,
    });
    const hasil = (kueri.data?.Data ?? []).filter((produk) => !kecuali.includes(produk.Uuid));
    const CekTertolak = (produk: ProdukStokTerpilih) => tolakStokAwalAda && produk.StokAwalSudahAda;

    useSorotPertama(
        hasil.map((produk) => produk.Uuid),
        AturSorot,
    );

    const Buka = (buka: boolean) => {
        AturTerbuka(buka);
        if (!buka) {
            AturKata('');
        }
    };

    const Pilih = (produk: ProdukStokTerpilih) => {
        if (CekTertolak(produk)) {
            return;
        }

        saatPilih(produk);
        Buka(false);
    };
    const SaatPindai = usePilihSetelahPindai({
        kataCari,
        hasil,
        siap: !kueri.isFetching && !kueri.isPlaceholderData,
        Pilih,
        AturKata,
        AturTerbuka,
    });

    let status: string | null = null;

    if (terbuka && kueri.isPending) {
        status = kataCari === '' ? 'Memuat produk…' : 'Mencari produk…';
    } else if (terbuka && kueri.isError) {
        status = 'Pencarian gagal. Periksa koneksi lalu ketik ulang.';
    } else if (terbuka && hasil.length === 0) {
        status =
            kataCari === ''
                ? 'Belum ada produk berstok yang bisa dipilih.'
                : `Tidak ada produk berstok yang cocok dengan "${kataCari}".`;
    } else if (terbuka && kataCari === '' && (kueri.data?.Data.length ?? 0) >= BATAS_CARI_PRODUK) {
        // Daftar dipotong server; tanpa keterangan ini pengguna mengira produknya memang cuma segitu.
        status = `Menampilkan ${String(BATAS_CARI_PRODUK)} produk pertama. Ketik untuk mencari yang lain.`;
    }

    return (
        <KerangkaPemilihProduk
            label={label}
            keterangan={keterangan}
            galat={galat}
            disabled={disabled}
            placeholder="Cari nama, SKU, atau barcode"
            kata={kata}
            saatKata={AturKata}
            terbuka={terbuka}
            saatTerbuka={Buka}
            sorot={sorot}
            saatSorot={AturSorot}
            status={status}
            saatPindai={SaatPindai}
            id={id}
            alihkanFokusTutup={alihkanFokusSetelahPilih}
        >
            {hasil.map((produk) => {
                const tertolak = CekTertolak(produk);

                return (
                    <CommandItem
                        key={produk.Uuid}
                        value={produk.Uuid}
                        data-slot="pilihan-cari-item"
                        data-nilai={produk.Uuid}
                        disabled={tertolak}
                        onSelect={() => Pilih(produk)}
                        className="flex min-h-9 cursor-pointer flex-col items-start gap-0 rounded-kontrol px-3 py-2 text-isi data-[selected=true]:bg-brand-lembut data-[selected=true]:text-teks-utama pointer-coarse:min-h-11"
                    >
                        <span className="font-semibold break-words text-teks-utama">{produk.Nama}</span>
                        <span className="text-keterangan text-teks-sekunder">
                            {produk.Sku ? <span className="font-mono">{produk.Sku}</span> : 'Tanpa SKU'}
                            {' | '}
                            {produk.SaldoDiGudang === null
                                ? `satuan ${produk.SimbolSatuan}`
                                : `stok ${FormatJumlahStok(produk.SaldoDiGudang, produk.SimbolSatuan)}`}
                            {produk.Pelacakan === 'Batch' ? ' | batch' : null}
                            {produk.Pelacakan === 'Seri' ? ' | nomor seri' : null}
                        </span>
                        {tertolak ? (
                            <span className="text-keterangan font-semibold text-teks-sekunder">
                                Stok awal sudah dicatat di lokasi ini
                            </span>
                        ) : null}
                    </CommandItem>
                );
            })}
        </KerangkaPemilihProduk>
    );
}
