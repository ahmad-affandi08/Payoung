import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import KerangkaPelanggan, { PanelHasil } from '@/Komponen/Publik/KerangkaPelanggan';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';

type PropsBerhentiProspek = {
    Ditemukan: boolean;
    SudahBerhenti: boolean;
    /** Alamat bertanda tangan untuk POST; null setelah berhasil. */
    AlamatKirim: string | null;
};

/** D-216: penerima kampanye email Payoung berhenti berlangganan dari tautan di email (UU PDP). */
export default function HalamanBerhentiProspek({ Ditemukan, SudahBerhenti, AlamatKirim }: PropsBerhentiProspek) {
    const [memproses, AturMemproses] = useState(false);

    return (
        <KerangkaPelanggan toko="Payoung">
            <Head title="Berhenti menerima email" />
            {!Ditemukan ? (
                <PanelHasil jenis="netral" judul="Tautan tidak berlaku">
                    Tautan ini rusak atau sudah tidak dikenal. Balas email yang Anda terima dan minta kami berhenti
                    mengirim, maka kami akan menghentikannya.
                </PanelHasil>
            ) : SudahBerhenti ? (
                <PanelHasil jenis="sukses" judul="Anda sudah berhenti berlangganan">
                    Payoung tidak akan mengirim email perkenalan atau promosi lagi ke alamat ini.
                </PanelHasil>
            ) : (
                <section className="flex flex-col gap-4 rounded-panel border border-garis bg-permukaan p-6">
                    <JudulHalaman>Berhenti menerima email dari Payoung?</JudulHalaman>
                    <p className="text-teks-sekunder">
                        Alamat email Anda tidak akan dikirimi email perkenalan atau promosi lagi dari Payoung. Ini tidak
                        memengaruhi akun Payoung yang mungkin sudah Anda punya.
                    </p>
                    <div>
                        <Tombol
                            memproses={memproses}
                            onClick={() =>
                                AlamatKirim &&
                                router.post(
                                    AlamatKirim,
                                    {},
                                    { onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
                                )
                            }
                        >
                            Berhenti berlangganan
                        </Tombol>
                    </div>
                </section>
            )}
        </KerangkaPelanggan>
    );
}
