import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { GalatPermintaan, KirimJson } from '@/Pustaka/PermintaanJson';
import type { HasilQrNomor, HasilStatusNomor, NomorPengirimWhatsapp } from '@/Tipe/Whatsapp';

/** QR WhatsApp kedaluwarsa dalam hitungan detik, jadi diminta ulang tiap 25 detik selama dialog terbuka. */
const JEDA_QR_MS = 25_000;
const JEDA_STATUS_MS = 3_000;

type PropsDialogPindaiQr = { nomor: NomorPengirimWhatsapp; saatTutup: () => void };

/**
 * D-299: dialog pindai QR. Meminta QR ke server, menampilkannya, lalu memeriksa status tiap 3 detik. Begitu nomor
 * terhubung dialog menutup sendiri dan daftar dimuat ulang. Tidak ada token atau kode yang diketik tenant.
 */
export default function DialogPindaiQr({ nomor, saatTutup }: PropsDialogPindaiQr) {
    const [kodeQr, AturKodeQr] = useState<string | null>(null);
    const [pesan, AturPesan] = useState<string | null>(null);
    const [terhubung, AturTerhubung] = useState(false);
    const [memuat, AturMemuat] = useState(true);
    const aktif = useRef(true);
    const dasar = `/kelola/pelanggan/whatsapp/nomor/${nomor.Uuid}`;

    const Selesai = useCallback(() => {
        AturTerhubung(true);
        router.reload({ only: ['Nomor'] });
    }, []);

    const MintaQr = useCallback(async () => {
        try {
            const hasil = await KirimJson<HasilQrNomor>(`${dasar}/qr`);

            if (!aktif.current) {
                return;
            }

            if (hasil.Terhubung) {
                Selesai();

                return;
            }

            AturKodeQr(hasil.KodeQr);
            AturPesan(hasil.KodeQr === null ? hasil.Pesan : null);
        } catch (galat) {
            if (aktif.current) {
                AturPesan(
                    galat instanceof GalatPermintaan ? galat.message : 'QR belum bisa diambil. Coba lagi sebentar.',
                );
            }
        } finally {
            if (aktif.current) {
                AturMemuat(false);
            }
        }
    }, [dasar, Selesai]);

    useEffect(() => {
        aktif.current = true;
        const awal = window.setTimeout(() => void MintaQr(), 0);
        const qr = window.setInterval(() => void MintaQr(), JEDA_QR_MS);
        const status = window.setInterval(() => {
            void KirimJson<HasilStatusNomor>(`${dasar}/status`)
                .then((hasil) => {
                    if (aktif.current && hasil.Terhubung) {
                        Selesai();
                    }
                })
                .catch(() => undefined);
        }, JEDA_STATUS_MS);

        return () => {
            aktif.current = false;
            window.clearTimeout(awal);
            window.clearInterval(qr);
            window.clearInterval(status);
        };
    }, [dasar, MintaQr, Selesai]);

    return (
        <DialogFormulir judul={`Sambungkan ${nomor.Label}`} saatTutup={saatTutup}>
            {terhubung ? (
                <div className="flex flex-col gap-4">
                    <p className="text-isi text-teks-utama">
                        Nomor {nomor.NomorTelepon} sudah terhubung dan siap mengirim pesan.
                    </p>
                    <Tombol onClick={saatTutup}>Selesai</Tombol>
                </div>
            ) : (
                <div className="flex flex-col gap-4">
                    <ol className="list-decimal space-y-1 pl-5 text-isi text-teks-sekunder">
                        <li>Buka WhatsApp di ponsel dengan nomor {nomor.NomorTelepon}.</li>
                        <li>Ketuk Perangkat tertaut, lalu Tautkan perangkat.</li>
                        <li>Arahkan kamera ponsel ke kode di bawah.</li>
                    </ol>
                    <div className="mx-auto flex size-64 items-center justify-center rounded-panel border border-garis bg-permukaan p-2">
                        {kodeQr !== null ? (
                            <img
                                src={kodeQr}
                                alt={`Kode QR untuk menyambungkan ${nomor.NomorTelepon}`}
                                className="size-full object-contain"
                            />
                        ) : (
                            <span className="text-isi text-teks-sekunder" role="status">
                                {memuat ? 'Menyiapkan kode QR…' : (pesan ?? 'Kode QR belum tersedia.')}
                            </span>
                        )}
                    </div>
                    {pesan !== null && kodeQr !== null ? (
                        <p className="text-label text-teks-sekunder">{pesan}</p>
                    ) : null}
                    <p className="text-label text-teks-sekunder">
                        Kode diperbarui otomatis. Halaman ini menutup sendiri begitu nomor terhubung.
                    </p>
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Tutup
                    </Tombol>
                </div>
            )}
        </DialogFormulir>
    );
}
