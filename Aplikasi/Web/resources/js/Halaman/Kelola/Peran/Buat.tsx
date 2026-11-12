import { router, usePage } from '@inertiajs/react';

import KartuFormulir from '@/Komponen/Formulir/KartuFormulir';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import FormPeran, { type IsianPeran } from '@/Komponen/Kelola/FormPeran';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsBuatPeran } from '@/Tipe/Organisasi';

const peranKosong: IsianPeran = { Nama: '', Keterangan: '', Izin: [] };

/** Halaman "Buat peran" kustom (PRD §19.1). Setelah disimpan, server mengarahkan kembali ke daftar peran. */
export default function HalamanBuatPeran({ DaftarIzin }: PropsBuatPeran) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const isianForm = Object.keys(peranKosong);

    return (
        <TataLetakAplikasi judul="Buat peran">
            <DaftarGalatServer
                galat={props.errors}
                kecuali={Object.keys(props.errors).filter((k) => isianForm.some((i) => k.startsWith(i)))}
            />
            <KartuFormulir keterangan="Peran kustom menggabungkan izin pilihan Anda. Izin khusus Pemilik tidak bisa diberikan.">
                <FormPeran
                    uuid={null}
                    awal={peranKosong}
                    daftarIzin={DaftarIzin}
                    saatBatal={() => router.visit('/kelola/peran')}
                />
            </KartuFormulir>
        </TataLetakAplikasi>
    );
}
