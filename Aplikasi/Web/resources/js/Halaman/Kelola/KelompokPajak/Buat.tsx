import { router, usePage } from '@inertiajs/react';

import KartuFormulir from '@/Komponen/Formulir/KartuFormulir';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import FormKelompokPajak from '@/Komponen/Katalog/FormKelompokPajak';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsBuatKelompokPajak } from '@/Tipe/Katalog';

const isianForm = ['Nama', 'Kategori', 'Pajak'];

/** F-03 halaman "Tambah kelompok pajak" (E.8). Setelah disimpan, server mengarahkan kembali ke daftar kelompok pajak. */
export default function HalamanBuatKelompokPajak(propsHalaman: PropsBuatKelompokPajak) {
    const { props } = usePage<PropsBersamaAplikasi>();

    return (
        <TataLetakAplikasi judul="Tambah kelompok pajak">
            <DaftarGalatServer
                galat={props.errors}
                kecuali={Object.keys(props.errors).filter((k) => isianForm.some((i) => k.startsWith(i)))}
            />
            <KartuFormulir keterangan="Setiap produk memakai satu kelompok pajak. PBJT makanan dan minuman serta PPN tidak boleh bersamaan pada satu produk.">
                <FormKelompokPajak
                    kelompok={null}
                    props={propsHalaman}
                    saatBatal={() => router.visit('/kelola/kelompok-pajak')}
                />
            </KartuFormulir>
        </TataLetakAplikasi>
    );
}
