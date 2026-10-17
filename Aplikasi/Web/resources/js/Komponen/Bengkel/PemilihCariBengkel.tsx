import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import {
    AmbilHasilCari,
    KerangkaPemilihProduk,
    useNilaiTertunda,
    usePilihSetelahPindai,
    useSorotPertama,
} from '@/Komponen/Katalog/PemilihProduk';
import { CommandItem } from '@/Komponen/Ui/command';
import { KunciKueri } from '@/Pustaka/KunciKueri';

type PropsPemilihCariBengkel<T> = {
    /** Pembeda key kueri (`pelanggan`, `kendaraan`, `jasa`, `sparepart`). */
    sumber: string;
    label: string;
    placeholder: string;
    buatUrl: (kata: string) => string;
    ambilId: (item: T) => string;
    ambilJudul: (item: T) => string;
    ambilKeterangan?: (item: T) => string | null;
    saatPilih: (item: T) => void;
    nilaiTerpilih?: string | undefined;
    pesanKosong: string;
    galat?: string | undefined;
    keterangan?: string | undefined;
    wajib?: boolean;
    disabled?: boolean;
    /**
     * Tombol pindai kamera di samping pemicu: `true` untuk pemilih produk (sparepart), `'Seri'` untuk kendaraan/perangkat
     * (dicari lewat nomor polisi atau IMEI); pelanggan dan jasa tidak memakainya.
     */
    pindaiKamera?: boolean | 'Seri';
};

/**
 * Pemilih cari-server formulir bengkel (§9.10): dropdown dengan kotak cari di dalamnya (kerangka yang sama dengan
 * pemilih produk & `PilihanCari`), data dari server karena pelanggan, kendaraan, dan produk bisa ribuan. Membuka
 * dropdown tanpa mengetik menampilkan daftar awal; mengetik menyaring di server (debounce 300 ms).
 */
export default function PemilihCariBengkel<T>({
    sumber,
    label,
    placeholder,
    buatUrl,
    ambilId,
    ambilJudul,
    ambilKeterangan,
    saatPilih,
    nilaiTerpilih,
    pesanKosong,
    galat,
    keterangan,
    wajib,
    disabled,
    pindaiKamera = false,
}: PropsPemilihCariBengkel<T>) {
    const [kata, AturKata] = useState('');
    const [terbuka, AturTerbuka] = useState(false);
    const [sorot, AturSorot] = useState('');
    const kataCari = useNilaiTertunda(kata.trim(), 300);
    const url = buatUrl(kataCari);
    const kueri = useQuery({
        queryKey: KunciKueri.Bengkel.Cari(sumber, url),
        queryFn: ({ signal }) => AmbilHasilCari<{ Data: T[] }>(url, signal),
        enabled: terbuka,
        staleTime: 0,
        placeholderData: keepPreviousData,
    });
    const hasil = kueri.data?.Data ?? [];

    useSorotPertama(hasil.map(ambilId), AturSorot);

    const Buka = (buka: boolean) => {
        AturTerbuka(buka);

        if (!buka) {
            AturKata('');
        }
    };

    const Pilih = (item: T) => {
        saatPilih(item);
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
        status = 'Memuat…';
    } else if (terbuka && kueri.isError) {
        status = 'Pencarian gagal. Periksa koneksi lalu ketik ulang.';
    } else if (terbuka && hasil.length === 0) {
        status = kataCari === '' ? pesanKosong : `Tidak ada yang cocok dengan "${kataCari}".`;
    }

    return (
        <KerangkaPemilihProduk
            label={label}
            galat={galat}
            keterangan={keterangan}
            placeholder={placeholder}
            wajib={wajib}
            disabled={disabled}
            nilaiTerpilih={nilaiTerpilih}
            kata={kata}
            saatKata={AturKata}
            terbuka={terbuka}
            saatTerbuka={Buka}
            sorot={sorot}
            saatSorot={AturSorot}
            status={status}
            saatPindai={pindaiKamera !== false ? SaatPindai : undefined}
            jenisKodePindai={pindaiKamera === 'Seri' ? 'Seri' : 'Produk'}
        >
            {hasil.map((item) => {
                const keteranganItem = ambilKeterangan?.(item) ?? null;

                return (
                    <CommandItem
                        key={ambilId(item)}
                        value={ambilId(item)}
                        onSelect={() => {
                            saatPilih(item);
                            Buka(false);
                        }}
                        className="min-h-9 cursor-pointer flex-col items-start gap-0 rounded-kontrol px-2 py-1.5 text-isi text-teks-utama data-[selected=true]:bg-brand-lembut data-[selected=true]:text-teks-utama pointer-coarse:min-h-11"
                    >
                        <span className="break-words">{ambilJudul(item)}</span>
                        {keteranganItem ? (
                            <span className="text-keterangan text-teks-sekunder">{keteranganItem}</span>
                        ) : null}
                    </CommandItem>
                );
            })}
        </KerangkaPemilihProduk>
    );
}
