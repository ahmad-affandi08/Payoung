import { router, usePage } from '@inertiajs/react';

import KartuFormulir from '@/Komponen/Formulir/KartuFormulir';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import FormKelompokPilihan from '@/Komponen/Katalog/FormKelompokPilihan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsBuatKelompokPilihan } from '@/Tipe/Katalog';

const isianForm = ['Nama', 'MinimalPilih', 'MaksimalPilih', 'Urutan', 'Pilihan'];

/** F-03 halaman "Tambah kelompok pilihan" (E.9). Setelah disimpan, server mengarahkan kembali ke daftar kelompok pilihan. */
export default function HalamanBuatKelompokPilihan({ Izin }: PropsBuatKelompokPilihan) {
    const { props } = usePage<PropsBersamaAplikasi>();

    return (
        <TataLetakAplikasi judul="Tambah kelompok pilihan">
            <DaftarGalatServer
                galat={props.errors}
                kecuali={Object.keys(props.errors).filter((k) => isianForm.some((i) => k.startsWith(i)))}
            />
            <KartuFormulir keterangan="Pilihan yang ditanyakan kasir saat menjual, misal Level gula atau Topping.">
                <FormKelompokPilihan
                    kelompok={null}
                    bolehUbahHarga={Izin.UbahHarga}
                    saatBatal={() => router.visit('/kelola/kelompok-pilihan')}
                />
            </KartuFormulir>
        </TataLetakAplikasi>
    );
}
