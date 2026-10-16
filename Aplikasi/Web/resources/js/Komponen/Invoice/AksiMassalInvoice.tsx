import { router } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import { AlamatInvoice } from '@/Komponen/Invoice/BagianInvoice';
import type { KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { BarisDaftarInvoice } from '@/Tipe/Invoice';

/** Batas server `ProsesInvoiceMassal::MAKS`. */
export const MaksInvoiceMassal = 50;

type Aksi = 'terbitkan' | 'kirim' | 'ingatkan';

/**
 * Aksi untuk invoice terpilih (F-21): terbitkan draf, kirim ke WhatsApp, atau kirim pengingat. Tiap invoice diproses
 * sendiri-sendiri di server; yang tidak bisa (tanpa nomor HP, periode terkunci, sudah lunas) dilewati dan alasannya
 * dirangkum, sehingga satu masalah tidak menggagalkan sisanya.
 */
export default function AksiMassalInvoice({ konteks }: { konteks: KonteksAksiMassal<BarisDaftarInvoice> }) {
    const [memproses, AturMemproses] = useState<Aksi | null>(null);
    const draf = konteks.terpilih.filter((i) => i.Status === 'Draf');
    const terbit = konteks.terpilih.filter((i) => i.Status === 'Terbit');
    const bersisa = terbit.filter((i) => i.Sisa !== null && i.Sisa !== '0.00');
    const terlaluBanyak = konteks.terpilih.length > MaksInvoiceMassal;

    const Proses = (aksi: Aksi, daftar: BarisDaftarInvoice[]) =>
        router.post(
            `${AlamatInvoice}/massal/${aksi}`,
            { Uuid: daftar.map((i) => i.Uuid) },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(aksi),
                onFinish: () => AturMemproses(null),
                onSuccess: () => konteks.bersihkan(),
            },
        );

    return (
        <div className="flex flex-wrap items-center gap-2">
            {terlaluBanyak ? (
                <p className="w-full text-keterangan text-bahaya">
                    Maksimal {MaksInvoiceMassal} invoice sekali proses. Kurangi pilihan.
                </p>
            ) : null}
            <Tombol
                varian="sekunder"
                disabled={draf.length === 0 || terlaluBanyak}
                memproses={memproses === 'terbitkan'}
                onClick={() => Proses('terbitkan', draf)}
            >
                Terbitkan draf ({draf.length.toLocaleString('id-ID')})
            </Tombol>
            <Tombol
                varian="sekunder"
                disabled={terbit.length === 0 || terlaluBanyak}
                memproses={memproses === 'kirim'}
                onClick={() => Proses('kirim', terbit)}
            >
                Kirim lewat WhatsApp ({terbit.length.toLocaleString('id-ID')})
            </Tombol>
            <Tombol
                varian="sekunder"
                disabled={bersisa.length === 0 || terlaluBanyak}
                memproses={memproses === 'ingatkan'}
                onClick={() => Proses('ingatkan', bersisa)}
            >
                Kirim pengingat ({bersisa.length.toLocaleString('id-ID')})
            </Tombol>
        </div>
    );
}
