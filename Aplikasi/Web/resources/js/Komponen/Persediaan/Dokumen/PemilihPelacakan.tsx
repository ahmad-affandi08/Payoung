import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import PilihanCari from '@/Komponen/Formulir/PilihanCari';
import { GalatBidang } from '@/Komponen/Formulir/BagianBidang';
import TombolPindaiKamera from '@/Komponen/Katalog/TombolPindaiKamera';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import type { PelacakanTersedia } from '@/Tipe/DokumenPersediaan';
import type { PelacakanProduk } from '@/Tipe/Persediaan';

/** URL batch & nomor seri tersedia (F-05b: `GET /kelola/persediaan/pelacakan?produk=&gudang=`). */
export function BuatUrlPelacakan(uuidProduk: string, uuidGudang: string): string {
    return `/kelola/persediaan/pelacakan?${new URLSearchParams({ produk: uuidProduk, gudang: uuidGudang }).toString()}`;
}

async function AmbilPelacakan(url: string, sinyal: AbortSignal): Promise<PelacakanTersedia> {
    const respons = await fetch(url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: sinyal,
    });

    if (!respons.ok) {
        throw new Error(`Batch/nomor seri gagal dimuat (${String(respons.status)})`);
    }

    return (await respons.json()) as PelacakanTersedia;
}

type PropsPemilihPelacakan = {
    /** Nama produk untuk label aksesibel. */
    nama: string;
    pelacakan: Exclude<PelacakanProduk, 'Tidak'>;
    uuidProduk: string;
    uuidGudang: string;
    simbolSatuan: string;
    nilai: string | null;
    /** Uuid terpilih + teks tampilan (nomor batch / nomor seri). */
    saatBerubah: (uuid: string, teks: string) => void;
    galat?: string | undefined;
    disabled?: boolean;
};

/** Pilihan batch bersisa atau nomor seri tersedia di satu lokasi stok, untuk baris keluar (transfer, penyesuaian). */
export default function PemilihPelacakan({
    nama,
    pelacakan,
    uuidProduk,
    uuidGudang,
    simbolSatuan,
    nilai,
    saatBerubah,
    galat,
    disabled,
}: PropsPemilihPelacakan) {
    const [pesanPindai, AturPesanPindai] = useState<string | null>(null);
    const kueri = useQuery({
        queryKey: KunciKueri.Persediaan.Pelacakan(uuidProduk, uuidGudang),
        queryFn: ({ signal }) => AmbilPelacakan(BuatUrlPelacakan(uuidProduk, uuidGudang), signal),
        enabled: uuidProduk !== '' && uuidGudang !== '',
        staleTime: 30_000,
    });
    const label = pelacakan === 'Batch' ? `Batch ${nama}` : `Nomor seri ${nama}`;
    const opsi =
        pelacakan === 'Batch'
            ? (kueri.data?.Batch ?? []).map((b) => ({
                  Nilai: b.Uuid,
                  Label: b.NomorBatch,
                  Keterangan: `Sisa ${FormatJumlahStok(b.JumlahSisa, simbolSatuan)}${b.TanggalKedaluwarsa ? ` | kedaluwarsa ${FormatTanggal(b.TanggalKedaluwarsa)}` : ''}`,
              }))
            : (kueri.data?.Seri ?? []).map((s) => ({ Nilai: s.Uuid, Label: s.Nomor }));

    // Nomor seri bisa dipilih dengan memindai labelnya: dicocokkan ke nomor yang tersedia di lokasi ini.
    const PilihDariPindai = (terbaca: string) => {
        const kode = terbaca.trim().toLowerCase();
        const cocok = opsi.find((o) => o.Label.toLowerCase() === kode);

        if (cocok === undefined) {
            AturPesanPindai(`${terbaca.trim()} tidak tersedia di lokasi ini.`);

            return;
        }

        saatBerubah(cocok.Nilai, cocok.Label);
        AturPesanPindai(null);
    };

    return (
        <div className="flex flex-col gap-1">
            <div className="flex items-end gap-2">
                <div className="min-w-0 flex-1">
                    <PilihanCari
                        label={label}
                        aria-label={label}
                        nilai={nilai ?? ''}
                        opsi={opsi}
                        placeholder={
                            kueri.isPending ? 'Memuat…' : pelacakan === 'Batch' ? 'Pilih batch' : 'Pilih nomor seri'
                        }
                        disabled={disabled === true || kueri.isPending}
                        galat={galat}
                        required
                        saatBerubah={(uuid) => saatBerubah(uuid, opsi.find((o) => o.Nilai === uuid)?.Label ?? '')}
                    />
                </div>
                {pelacakan === 'Seri' ? (
                    <TombolPindaiKamera
                        ikonSaja
                        jenisKode="Seri"
                        label={`Pindai nomor seri ${nama}`}
                        disabled={disabled === true || kueri.isPending}
                        saatTerbaca={PilihDariPindai}
                    />
                ) : null}
            </div>
            {pesanPindai ? <GalatBidang>{pesanPindai}</GalatBidang> : null}
            {kueri.isError ? <GalatBidang>Batch/nomor seri gagal dimuat. Muat ulang halaman.</GalatBidang> : null}
            {kueri.isSuccess && opsi.length === 0 ? (
                <p className="text-keterangan text-teks-sekunder">
                    {pelacakan === 'Batch'
                        ? 'Tidak ada batch bersisa di lokasi ini.'
                        : 'Tidak ada nomor seri tersedia di lokasi ini.'}
                </p>
            ) : null}
        </div>
    );
}
