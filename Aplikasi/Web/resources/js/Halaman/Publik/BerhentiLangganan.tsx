import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import KerangkaPelanggan, { PanelHasil } from '@/Komponen/Publik/KerangkaPelanggan';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';

type PropsBerhentiLangganan = {
    Ditemukan: boolean;
    NamaToko: string | null;
    SudahBerhenti: boolean;
    /** Alamat bertanda tangan untuk POST; null setelah berhasil. */
    AlamatKirim: string | null;
};

/** CRM-07: pelanggan berhenti menerima pesan promosi dari satu toko (UU PDP, hak menarik persetujuan). */
export default function HalamanBerhentiLangganan({
    Ditemukan,
    NamaToko,
    SudahBerhenti,
    AlamatKirim,
}: PropsBerhentiLangganan) {
    const [memproses, AturMemproses] = useState(false);

    return (
        <KerangkaPelanggan toko={NamaToko}>
            <Head title="Berhenti berlangganan" />
            {!Ditemukan ? (
                <PanelHasil jenis="netral" judul="Tautan tidak berlaku">
                    Tautan ini rusak atau sudah tidak dikenal. Hubungi toko yang mengirim pesan untuk berhenti
                    berlangganan.
                </PanelHasil>
            ) : SudahBerhenti ? (
                <PanelHasil jenis="sukses" judul="Anda sudah berhenti berlangganan">
                    {NamaToko} tidak akan mengirim pesan promosi lagi kepada Anda. Struk dan pemberitahuan transaksi
                    tetap dikirim seperti biasa.
                </PanelHasil>
            ) : (
                <section className="flex flex-col gap-4 rounded-panel border border-garis bg-permukaan p-6">
                    <JudulHalaman>Berhenti menerima promosi dari {NamaToko}?</JudulHalaman>
                    <p className="text-teks-sekunder">
                        Anda tidak akan menerima pesan promosi WhatsApp atau email dari {NamaToko}. Struk dan
                        pemberitahuan transaksi tetap dikirim. Anda bisa berlangganan lagi lewat kasir toko.
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
