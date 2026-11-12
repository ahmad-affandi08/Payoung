import { router, usePage } from '@inertiajs/react';

import KartuFormulir from '@/Komponen/Formulir/KartuFormulir';
import {
    AlamatAturanKomisi,
    IsianAturanKomisi,
    IsiFormulirAturanKomisi,
} from '@/Komponen/Karyawan/FormulirAturanKomisi';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsBuatAturanKomisi } from '@/Tipe/Karyawan';

/** F-18 EMP-04 halaman "Tambah aturan komisi". Setelah disimpan, server mengarahkan kembali ke daftar aturan. */
export default function HalamanBuatAturanKomisi({ OpsiKategori }: PropsBuatAturanKomisi) {
    const { props } = usePage<PropsBersamaAplikasi>();

    return (
        <TataLetakAplikasi judul="Tambah aturan komisi">
            <DaftarGalatServer galat={props.errors} kecuali={IsianAturanKomisi} />
            <KartuFormulir keterangan="Aturan paling spesifik dipakai (produk, lalu kategori, lalu semua). Berlaku untuk penjualan berikutnya.">
                <IsiFormulirAturanKomisi
                    aturan={null}
                    opsiKategori={OpsiKategori}
                    saatBatal={() => router.visit(AlamatAturanKomisi)}
                />
            </KartuFormulir>
        </TataLetakAplikasi>
    );
}
