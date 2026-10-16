import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import {
    AmbilHasilCari,
    KerangkaPemilihProduk,
    useNilaiTertunda,
    useSorotPertama,
} from '@/Komponen/Katalog/PemilihProduk';
import { CommandItem } from '@/Komponen/Ui/command';
import { FormatRupiah } from '@/Pustaka/Format';
import { KunciKueri } from '@/Pustaka/KunciKueri';

export type HasilPelangganGrosir = {
    Uuid: string;
    Nama: string;
    NoHp: string | null;
    LimitKredit: string | null;
    SisaPiutang?: string | null;
};

const alamatCari = '/kelola/grosir/pelanggan/cari';

export function BuatUrlCariPelanggan(kata: string, alamat = alamatCari): string {
    return `${alamat}?${new URLSearchParams({ kata }).toString()}`;
}

/** "Limit Rp 50.000.000 | sisa piutang Rp 2.000.000" atau "tanpa limit kredit". */
export function KeteranganKredit(p: HasilPelangganGrosir): string {
    const nomor = p.NoHp ?? 'Tanpa nomor';

    if (p.LimitKredit === null) {
        return `${nomor} | tanpa limit kredit`;
    }

    const piutang = p.SisaPiutang && p.SisaPiutang !== '0.00' ? ` | piutang ${FormatRupiah(p.SisaPiutang)}` : '';

    return `${nomor} | limit ${FormatRupiah(p.LimitKredit)}${piutang}`;
}

/**
 * Pemilih pelanggan pesanan grosir: dropdown dengan kotak cari di dalamnya (kerangka yang sama dengan pemilih produk dan
 * `PilihanCari`), bukan kotak teks dengan tombol Cari. Daftar datang dari server (pelanggan bisa ribuan): membuka
 * dropdown tanpa mengetik menampilkan pelanggan pertama urut nama, mengetik menyaring nama atau nomor HP. Limit kredit &
 * sisa piutang tampil di tiap pilihan, karena itulah yang menentukan apakah pesanan besar lolos BR-12.6 — lebih baik
 * operator tahu sebelum menyusun barisnya daripada ditolak di akhir.
 */
export default function PemilihPelangganGrosir({
    uuidTerpilih,
    namaTerpilih,
    saatPilih,
    galat,
    alamat = alamatCari,
}: {
    uuidTerpilih: string;
    namaTerpilih: string;
    saatPilih: (uuid: string, nama: string) => void;
    galat?: string | undefined;
    /** Titik akhir pencarian; bawaan pencarian pelanggan grosir, invoice memakai rutenya sendiri (F-21). */
    alamat?: string;
}) {
    const [kata, AturKata] = useState('');
    const [terbuka, AturTerbuka] = useState(false);
    const [sorot, AturSorot] = useState('');
    const kataCari = useNilaiTertunda(kata.trim(), 300);
    const kueri = useQuery({
        queryKey:
            alamat === alamatCari
                ? KunciKueri.Grosir.CariPelanggan(kataCari)
                : [...KunciKueri.Grosir.CariPelanggan(kataCari), alamat],
        queryFn: ({ signal }) =>
            AmbilHasilCari<{ Data: HasilPelangganGrosir[] }>(BuatUrlCariPelanggan(kataCari, alamat), signal),
        enabled: terbuka,
        staleTime: 0,
        // Hasil lama tetap tampil selama hasil baru dimuat, jadi daftar tidak berkedip saat mengetik.
        placeholderData: keepPreviousData,
    });
    const hasil = kueri.data?.Data ?? [];

    useSorotPertama(
        hasil.map((p) => p.Uuid),
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
        // Daftar dipotong server; tanpa keterangan ini pengguna mengira pelanggannya memang cuma segitu.
        status = 'Menampilkan 20 pelanggan pertama. Ketik nama atau nomor untuk mencari yang lain.';
    }

    return (
        <KerangkaPemilihProduk
            label="Pelanggan"
            galat={galat}
            placeholder="Pilih pelanggan"
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
                    onSelect={() => {
                        saatPilih(p.Uuid, p.Nama);
                        Buka(false);
                    }}
                    className="min-h-9 cursor-pointer flex-col items-start gap-0 rounded-kontrol px-2 py-1.5 text-isi text-teks-utama data-[selected=true]:bg-brand-lembut data-[selected=true]:text-teks-utama pointer-coarse:min-h-11"
                >
                    <span className={p.Uuid === uuidTerpilih ? 'font-semibold break-words' : 'break-words'}>
                        {p.Nama}
                    </span>
                    <span className="text-keterangan text-teks-sekunder">{KeteranganKredit(p)}</span>
                </CommandItem>
            ))}
        </KerangkaPemilihProduk>
    );
}
