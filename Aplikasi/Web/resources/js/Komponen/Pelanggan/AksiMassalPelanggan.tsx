import { router } from '@inertiajs/react';
import { useState } from 'react';

import PilihanCari from '@/Komponen/Formulir/PilihanCari';
import Tombol from '@/Komponen/Formulir/Tombol';
import type { KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { BarisPelanggan, OpsiTier } from '@/Tipe/Pelanggan';

type AksiPelanggan = 'Arsipkan' | 'Pulihkan' | 'Tier';

/** Batas server `UbahPelangganMassal::MAKS`. */
export const MaksPelangganMassal = 200;

/** Nilai khusus pilihan "Lepas tier" (UuidTier dikirim null). */
const LEPAS_TIER = '__lepas__';

/**
 * Aksi untuk pelanggan terpilih (F-16a/F-16b): arsipkan, pulihkan, atur atau lepas tier. Yang sudah berstatus tujuan
 * dilewati server. Tier yang diatur dari sini tidak dikunci; kunci tier tetap diatur di detail pelanggan.
 */
export default function AksiMassalPelanggan({
    konteks,
    tier,
}: {
    konteks: KonteksAksiMassal<BarisPelanggan>;
    tier: OpsiTier[];
}) {
    const [uuidTier, AturUuidTier] = useState('');
    const [memproses, AturMemproses] = useState<AksiPelanggan | null>(null);
    const uuid = konteks.terpilih.map((p) => p.Uuid);
    const terlaluBanyak = uuid.length > MaksPelangganMassal;
    const nonaktif = memproses !== null || terlaluBanyak;
    // Hanya aksi yang bisa dikenai ke minimal satu pelanggan terpilih yang ditampilkan.
    const adaDiarsipkan = konteks.terpilih.some((p) => p.Status === 'Diarsipkan');
    const adaAktif = konteks.terpilih.some((p) => p.Status === 'Aktif');

    const Jalankan = (aksi: AksiPelanggan) =>
        router.post(
            '/kelola/pelanggan/massal',
            {
                Aksi: aksi,
                Uuid: uuid,
                UuidTier: aksi === 'Tier' && uuidTier !== LEPAS_TIER ? uuidTier : null,
                TierTetap: false,
            },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(aksi),
                onFinish: () => AturMemproses(null),
                onSuccess: () => konteks.bersihkan(),
            },
        );

    return (
        <div className="flex flex-wrap items-end gap-2">
            {terlaluBanyak ? (
                <p className="w-full text-keterangan text-bahaya">
                    Maksimal {MaksPelangganMassal} pelanggan sekali proses. Kurangi pilihan.
                </p>
            ) : null}
            {tier.length > 0 ? (
                <>
                    <div className="w-56">
                        <PilihanCari
                            label="Tier tujuan"
                            nilai={uuidTier}
                            opsi={[
                                ...tier.map((t) => ({ Nilai: t.Uuid, Label: t.Label })),
                                { Nilai: LEPAS_TIER, Label: 'Lepas tier' },
                            ]}
                            placeholder="Pilih tier"
                            saatBerubah={AturUuidTier}
                            aria-label="Tier tujuan"
                        />
                    </div>
                    <Tombol
                        varian="sekunder"
                        disabled={nonaktif || uuidTier === ''}
                        memproses={memproses === 'Tier'}
                        onClick={() => Jalankan('Tier')}
                    >
                        Atur tier
                    </Tombol>
                </>
            ) : null}
            {adaDiarsipkan ? (
                <Tombol
                    varian="sekunder"
                    disabled={nonaktif}
                    memproses={memproses === 'Pulihkan'}
                    onClick={() => Jalankan('Pulihkan')}
                >
                    Pulihkan
                </Tombol>
            ) : null}
            {adaAktif ? (
                <Tombol
                    varian="bahaya"
                    disabled={nonaktif}
                    memproses={memproses === 'Arsipkan'}
                    onClick={() => Jalankan('Arsipkan')}
                >
                    Arsipkan
                </Tombol>
            ) : null}
        </div>
    );
}
