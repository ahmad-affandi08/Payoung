import { router } from '@inertiajs/react';
import { useState } from 'react';

import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import type { KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';

/** Batas server `UbahStatusTiketDukunganMassal::MAKS`. */
export const MaksTiketMassal = 100;

/**
 * Aksi untuk tiket terpilih di antrean konsol (P-09): tandai selesai, atau tutup dengan satu alasan untuk semuanya.
 * Tiket yang tidak boleh berubah (sudah final) dilewati server dan alasannya dirangkum di dialog hasil.
 */
export default function AksiMassalTiket<T extends { Uuid: string; Status: string }>({
    konteks,
}: {
    konteks: KonteksAksiMassal<T>;
}) {
    const [tutup, AturTutup] = useState(false);
    const [alasan, AturAlasan] = useState('');
    const [galat, AturGalat] = useState<Record<string, string>>({});
    const [memproses, AturMemproses] = useState<'Selesai' | 'Ditutup' | null>(null);
    const uuid = konteks.terpilih.map((t) => t.Uuid);
    const terlaluBanyak = uuid.length > MaksTiketMassal;
    // Selesai hanya untuk tiket yang masih berjalan, tutup untuk semua yang belum ditutup.
    const adaBerjalan = konteks.terpilih.some((t) => ['Baru', 'Ditangani', 'MenungguPelanggan'].includes(t.Status));
    const adaBelumDitutup = konteks.terpilih.some((t) => t.Status !== 'Ditutup');

    const Kirim = (status: 'Selesai' | 'Ditutup') =>
        router.put(
            '/dukungan/tiket/status-massal',
            { Status: status, Alasan: status === 'Ditutup' ? alasan : null, Uuid: uuid },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(status),
                onFinish: () => AturMemproses(null),
                onError: (errors) => AturGalat(errors),
                onSuccess: () => {
                    AturTutup(false);
                    konteks.bersihkan();
                },
            },
        );

    return (
        <div className="flex flex-wrap items-center gap-2">
            {terlaluBanyak ? (
                <p className="w-full text-keterangan text-bahaya">
                    Maksimal {MaksTiketMassal} tiket sekali proses. Kurangi pilihan.
                </p>
            ) : null}
            {adaBerjalan ? (
                <Tombol
                    varian="sekunder"
                    disabled={memproses !== null || terlaluBanyak}
                    memproses={memproses === 'Selesai'}
                    onClick={() => Kirim('Selesai')}
                >
                    Tandai selesai
                </Tombol>
            ) : null}
            {adaBelumDitutup ? (
                <Tombol varian="bahaya" disabled={memproses !== null || terlaluBanyak} onClick={() => AturTutup(true)}>
                    Tutup tiket…
                </Tombol>
            ) : null}
            {tutup ? (
                <DialogFormulir
                    judul={`Tutup ${uuid.length.toLocaleString('id-ID')} tiket`}
                    keterangan="Tiket yang ditutup tidak bisa dibuka lagi. Alasan yang sama dicatat di semua tiket terpilih dan tampil sebagai catatan internal."
                    galatUmum={galat.Umum}
                    saatTutup={() => AturTutup(false)}
                >
                    <form
                        noValidate
                        className="flex flex-col gap-3"
                        onSubmit={(peristiwa) => {
                            peristiwa.preventDefault();
                            Kirim('Ditutup');
                        }}
                    >
                        <BidangTeksPanjang
                            label="Alasan menutup tiket"
                            nilai={alasan}
                            saatBerubah={AturAlasan}
                            galat={galat.Alasan}
                            baris={3}
                            maksimal={500}
                            required
                        />
                        <DialogFooter className="sm:justify-start">
                            <Tombol
                                type="submit"
                                varian="bahaya"
                                memproses={memproses === 'Ditutup'}
                                disabled={alasan.trim() === ''}
                            >
                                Tutup tiket
                            </Tombol>
                            <Tombol varian="sekunder" onClick={() => AturTutup(false)}>
                                Batal
                            </Tombol>
                        </DialogFooter>
                    </form>
                </DialogFormulir>
            ) : null}
        </div>
    );
}
