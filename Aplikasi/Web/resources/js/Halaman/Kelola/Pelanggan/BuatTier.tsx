import { router, usePage } from '@inertiajs/react';

import KartuFormulir from '@/Komponen/Formulir/KartuFormulir';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import FormulirTier, { AlamatTier, TierKosong } from '@/Komponen/Pelanggan/FormulirTier';
import PesanFiturLoyalti from '@/Komponen/Pelanggan/PesanFiturLoyalti';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsBuatTierPelanggan } from '@/Tipe/Pelanggan';

/** F-16b halaman "Tambah tier". Setelah disimpan, server mengarahkan kembali ke daftar tier. */
export default function HalamanBuatTierPelanggan({ FiturAktif }: PropsBuatTierPelanggan) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const isianForm = Object.keys(TierKosong);

    return (
        <TataLetakAplikasi judul="Tambah tier">
            {FiturAktif ? null : <PesanFiturLoyalti />}
            <DaftarGalatServer
                galat={props.errors}
                kecuali={Object.keys(props.errors).filter((k) => isianForm.includes(k))}
            />
            <KartuFormulir keterangan="Tier dinilai ulang tiap malam dari total belanja. Kode tier dipakai di Daftar harga untuk harga khusus.">
                <FormulirTier uuid={null} awal={TierKosong} saatBatal={() => router.visit(AlamatTier)} />
            </KartuFormulir>
        </TataLetakAplikasi>
    );
}
