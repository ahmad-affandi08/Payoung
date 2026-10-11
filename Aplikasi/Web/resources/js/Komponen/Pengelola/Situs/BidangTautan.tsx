import { useId } from 'react';

import {
    BuatKelasKontrol,
    GabungDijelaskanOleh,
    GalatBidang,
    KerangkaBidang,
    KeteranganBidang,
    LabelBidang,
} from '@/Komponen/Formulir/BagianBidang';
import { Input } from '@/Komponen/Ui/input';

/** Saran tautan: pintasan (diterjemahkan server) dan halaman bawaan situs. */
export const SARAN_TAUTAN = [
    { Nilai: '@daftar', Label: 'Halaman daftar (Coba gratis)' },
    { Nilai: '@masuk', Label: 'Halaman masuk' },
    { Nilai: '@whatsapp', Label: 'Chat WhatsApp (nomor di Pengaturan)' },
    { Nilai: '@unduh-android', Label: 'Unduh aplikasi Android' },
    { Nilai: '@unduh-ios', Label: 'Unduh aplikasi iOS' },
    { Nilai: '@unduh-windows', Label: 'Unduh aplikasi Windows' },
    { Nilai: '@solusi', Label: 'Menu Solusi (dropdown sektor, hanya untuk Menu atas)' },
    { Nilai: '/', Label: 'Beranda' },
    { Nilai: '/fitur', Label: 'Fitur' },
    { Nilai: '/harga', Label: 'Harga' },
    { Nilai: '/kontak', Label: 'Kontak' },
    { Nilai: '/tentang', Label: 'Tentang kami' },
    { Nilai: '/solusi/kafe-resto', Label: 'Solusi kafe & resto' },
    { Nilai: '/solusi/toko-retail', Label: 'Solusi toko & retail' },
    { Nilai: '/solusi/jasa', Label: 'Solusi jasa' },
    { Nilai: '/kompatibilitas-perangkat', Label: 'Perangkat kompatibel' },
    { Nilai: '/legal/syarat-ketentuan', Label: 'Syarat & ketentuan' },
    { Nilai: '/legal/kebijakan-privasi', Label: 'Kebijakan privasi' },
];

const ID_DAFTAR_SARAN = 'saran-tautan-situs';

/** Daftar saran bersama untuk semua `BidangTautan` di satu halaman (pasang sekali). */
export function DaftarSaranTautan() {
    return (
        <datalist id={ID_DAFTAR_SARAN}>
            {SARAN_TAUTAN.map((s) => (
                <option key={s.Nilai} value={s.Nilai}>
                    {s.Label}
                </option>
            ))}
        </datalist>
    );
}

type PropsBidangTautan = {
    label: string;
    nilai: string;
    saatBerubah: (nilai: string) => void;
    galat?: string | undefined;
    keterangan?: string;
    required?: boolean;
    tersembunyiLabel?: boolean;
};

/** Isian tautan situs dengan saran pintasan & halaman (D-21). */
export default function BidangTautan({
    label,
    nilai,
    saatBerubah,
    galat,
    keterangan = 'Jalur situs (/harga), https://…, mailto:, tel:, atau pintasan seperti @daftar.',
    required,
    tersembunyiLabel = false,
}: PropsBidangTautan) {
    const id = useId();

    return (
        <KerangkaBidang galat={galat}>
            <LabelBidang htmlFor={id} tersembunyi={tersembunyiLabel}>
                {label}
            </LabelBidang>
            <Input
                id={id}
                list={ID_DAFTAR_SARAN}
                value={nilai}
                onChange={(p) => saatBerubah(p.target.value)}
                inputMode="url"
                autoComplete="off"
                required={required}
                aria-invalid={galat ? true : undefined}
                aria-describedby={GabungDijelaskanOleh(keterangan && `${id}-keterangan`, galat && `${id}-galat`)}
                className={BuatKelasKontrol(galat, 'font-mono')}
            />
            {keterangan ? <KeteranganBidang id={`${id}-keterangan`}>{keterangan}</KeteranganBidang> : null}
            {galat ? <GalatBidang id={`${id}-galat`}>{galat}</GalatBidang> : null}
        </KerangkaBidang>
    );
}
