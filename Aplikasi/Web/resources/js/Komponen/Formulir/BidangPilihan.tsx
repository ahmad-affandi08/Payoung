import { useId } from 'react';

import { GalatBidang, KerangkaBidang, LabelBidang } from './BagianBidang';
import type { AksiTambahPilihan } from './BarisTambahPilihan';
import PilihanCari, { type OpsiPilihan } from './PilihanCari';

type PropsBidangPilihan = {
    label: string;
    nilai: string;
    opsi: OpsiPilihan[];
    saatBerubah: (nilai: string) => void;
    galat?: string | undefined;
    kosong?: string;
    disabled?: boolean;
    /** Wajib diisi: label otomatis diberi tanda * merah. */
    required?: boolean;
    /** Label hanya untuk pembaca layar, misal di dalam baris tabel yang judul kolomnya sudah ada. */
    labelTersembunyi?: boolean;
    /** Baris aksi di dasar daftar, mis. "Tambah pemasok baru" (D-273). */
    aksiTambah?: AksiTambahPilihan | undefined;
};

/** Pilihan tunggal dengan kotak cari (`PilihanCari`), label & galat terhubung (PRD §17.6). */
export default function BidangPilihan({
    label,
    nilai,
    opsi,
    saatBerubah,
    galat,
    kosong,
    disabled,
    required,
    labelTersembunyi = false,
    aksiTambah,
}: PropsBidangPilihan) {
    const id = useId();

    return (
        <KerangkaBidang galat={galat}>
            <LabelBidang htmlFor={id} tersembunyi={labelTersembunyi}>
                {label}
            </LabelBidang>
            <PilihanCari
                id={id}
                label={label}
                nilai={nilai}
                opsi={opsi}
                saatBerubah={saatBerubah}
                kosong={kosong}
                galat={galat}
                disabled={disabled}
                required={required}
                aksiTambah={aksiTambah}
                aria-label={labelTersembunyi ? label : undefined}
                aria-describedby={galat ? `${id}-galat` : undefined}
            />
            {galat ? <GalatBidang id={`${id}-galat`}>{galat}</GalatBidang> : null}
        </KerangkaBidang>
    );
}
