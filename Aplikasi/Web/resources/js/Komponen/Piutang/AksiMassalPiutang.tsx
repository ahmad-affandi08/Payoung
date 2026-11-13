import { router } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import { AlamatPiutang } from '@/Komponen/Piutang/BagianPiutang';
import type { KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import { useWhatsappTersedia } from '@/Pustaka/Whatsapp';

/** Batas server `AntrekanPengingatPiutangMassal::MAKS`. */
export const MaksPengingatMassal = 100;

type BarisDenganPelanggan = { Uuid: string; UuidPelanggan: string | null };

/**
 * Aksi untuk piutang terpilih (F-12, D-23 D): kirim pengingat WhatsApp sekaligus. Piutang tanpa pelanggan tidak
 * dikirim (tidak ada nomor); server melewati yang baru diingatkan atau tanpa nomor sah dan merangkum alasannya.
 */
export default function AksiMassalPiutang({ konteks }: { konteks: KonteksAksiMassal<BarisDenganPelanggan> }) {
    const whatsappTersedia = useWhatsappTersedia();
    const [memproses, AturMemproses] = useState(false);
    const dapatDiingatkan = konteks.terpilih.filter((p) => p.UuidPelanggan !== null);
    const terlaluBanyak = dapatDiingatkan.length > MaksPengingatMassal;

    // D-299: seluruh aksi ini memakai WhatsApp, jadi hilang bila layanannya tidak tersedia.
    if (!whatsappTersedia) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            {terlaluBanyak ? (
                <p className="w-full text-keterangan text-bahaya">
                    Maksimal {MaksPengingatMassal} piutang sekali kirim. Kurangi pilihan.
                </p>
            ) : null}
            <Tombol
                varian="sekunder"
                disabled={dapatDiingatkan.length === 0 || terlaluBanyak}
                memproses={memproses}
                onClick={() =>
                    router.post(
                        `${AlamatPiutang}/pengingat-massal`,
                        { Uuid: dapatDiingatkan.map((p) => p.Uuid) },
                        {
                            preserveScroll: true,
                            onStart: () => AturMemproses(true),
                            onFinish: () => AturMemproses(false),
                            onSuccess: () => konteks.bersihkan(),
                        },
                    )
                }
            >
                Kirim pengingat WhatsApp ({dapatDiingatkan.length.toLocaleString('id-ID')})
            </Tombol>
            {dapatDiingatkan.length < konteks.terpilih.length ? (
                <p className="text-keterangan text-teks-sekunder">
                    {(konteks.terpilih.length - dapatDiingatkan.length).toLocaleString('id-ID')} piutang tanpa pelanggan
                    tidak ikut dikirim.
                </p>
            ) : null}
        </div>
    );
}
