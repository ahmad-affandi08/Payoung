import { router, usePage } from '@inertiajs/react';

import KartuFormulir from '@/Komponen/Formulir/KartuFormulir';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import FormDaftarHarga, { DaftarHargaKosong } from '@/Komponen/Katalog/FormDaftarHarga';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsBuatDaftarHarga } from '@/Tipe/Katalog';

/** F-03 halaman "Buat daftar harga". Setelah disimpan, server mengarahkan ke detail daftar harga untuk mengisi harga. */
export default function HalamanBuatDaftarHarga({ Outlet, Kanal, OpsiTier, ZonaWaktu }: PropsBuatDaftarHarga) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const isianForm = Object.keys(DaftarHargaKosong);

    return (
        <TataLetakAplikasi judul="Buat daftar harga">
            <DaftarGalatServer
                galat={props.errors}
                kecuali={Object.keys(props.errors).filter((k) => isianForm.some((i) => k.startsWith(i)))}
            />
            <KartuFormulir keterangan="Atur outlet, kanal, tingkat pelanggan, dan periode berlakunya. Harga per produk diisi setelah daftar dibuat.">
                <FormDaftarHarga
                    uuid={null}
                    awal={DaftarHargaKosong}
                    outlet={Outlet}
                    kanal={Kanal}
                    tier={OpsiTier ?? []}
                    zonaWaktu={ZonaWaktu}
                    saatBatal={() => router.visit('/kelola/daftar-harga')}
                />
            </KartuFormulir>
        </TataLetakAplikasi>
    );
}
