import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import {
    AmbilHasilCari,
    KerangkaPemilihProduk,
    useNilaiTertunda,
    useSorotPertama,
} from '@/Komponen/Katalog/PemilihProduk';
import { CommandItem } from '@/Komponen/Ui/command';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import type { PembeliFakturPajak } from '@/Tipe/Penjualan';

const alamatCari = '/kelola/penjualan/faktur-pajak/pelanggan';

export function BuatUrlCariPembeli(kata: string): string {
    return `${alamatCari}?${new URLSearchParams({ kata }).toString()}`;
}

/** "NPWP ••••1234" atau pesan bahwa identitas pajaknya belum ada. */
export function KeteranganIdentitas(p: PembeliFakturPajak): string {
    return p.JenisIdentitas === null || p.IdentitasTersamar === null
        ? 'Belum ada NPWP/NIK. Lengkapi di data pelanggan.'
        : `${p.JenisIdentitas} ${p.IdentitasTersamar}`;
}

/**
 * Pemilih pembeli Faktur Pajak per transaksi kasir (TAX-07): dropdown dengan kotak cari, daftar dari server. Pelanggan
 * tanpa NPWP/NIK sah tetap tampil tetapi tidak bisa dipilih, supaya operator tahu harus melengkapinya dulu (bukan
 * mengira pelanggannya tidak ada) dan tidak mengirim permintaan yang pasti ditolak.
 */
export default function PemilihPembeliFakturPajak({
    uuidTerpilih,
    namaTerpilih,
    saatPilih,
    galat,
}: {
    uuidTerpilih: string;
    namaTerpilih: string;
    saatPilih: (uuid: string, nama: string) => void;
    galat?: string | undefined;
}) {
    const [kata, AturKata] = useState('');
    const [terbuka, AturTerbuka] = useState(false);
    const [sorot, AturSorot] = useState('');
    const kataCari = useNilaiTertunda(kata.trim(), 300);
    const kueri = useQuery({
        queryKey: KunciKueri.Penjualan.CariPembeliFakturPajak(kataCari),
        queryFn: ({ signal }) => AmbilHasilCari<{ Data: PembeliFakturPajak[] }>(BuatUrlCariPembeli(kataCari), signal),
        enabled: terbuka,
        staleTime: 0,
        placeholderData: keepPreviousData,
    });
    const hasil = kueri.data?.Data ?? [];

    useSorotPertama(
        hasil.filter((p) => p.JenisIdentitas !== null).map((p) => p.Uuid),
        AturSorot,
    );

    const Buka = (buka: boolean) => {
        AturTerbuka(buka);

        if (!buka) {
            AturKata('');
        }
    };

    let status: string | null = null;

    if (terbuka && kueri.isPending) {
        status = 'Memuat pelanggan…';
    } else if (terbuka && kueri.isError) {
        status = 'Pencarian gagal. Periksa koneksi lalu ketik ulang.';
    } else if (terbuka && hasil.length === 0) {
        status =
            kataCari === ''
                ? 'Belum ada pelanggan aktif. Tambahkan dulu di Pelanggan.'
                : `Tidak ada pelanggan yang cocok dengan "${kataCari}".`;
    } else if (terbuka && kataCari === '' && hasil.length >= 20) {
        status = 'Menampilkan 20 pelanggan pertama. Ketik nama atau nomor untuk mencari yang lain.';
    }

    return (
        <KerangkaPemilihProduk
            label="Pembeli"
            galat={galat}
            placeholder="Pilih pelanggan ber-NPWP/NIK"
            wajib
            nilaiTerpilih={uuidTerpilih === '' ? undefined : namaTerpilih}
            kata={kata}
            saatKata={AturKata}
            terbuka={terbuka}
            saatTerbuka={Buka}
            sorot={sorot}
            saatSorot={AturSorot}
            status={status}
        >
            {hasil.map((p) => (
                <CommandItem
                    key={p.Uuid}
                    value={p.Uuid}
                    disabled={p.JenisIdentitas === null}
                    onSelect={() => {
                        saatPilih(p.Uuid, p.Nama);
                        Buka(false);
                    }}
                    className="min-h-9 cursor-pointer flex-col items-start gap-0 rounded-kontrol px-2 py-1.5 text-isi text-teks-utama data-[selected=true]:bg-brand-lembut data-[selected=true]:text-teks-utama pointer-coarse:min-h-11"
                >
                    <span className={p.Uuid === uuidTerpilih ? 'font-semibold break-words' : 'break-words'}>
                        {p.Nama}
                    </span>
                    <span className="text-keterangan text-teks-sekunder">{KeteranganIdentitas(p)}</span>
                </CommandItem>
            ))}
        </KerangkaPemilihProduk>
    );
}
