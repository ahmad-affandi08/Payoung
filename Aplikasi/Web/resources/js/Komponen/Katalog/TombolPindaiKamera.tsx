import { ScanBarcode } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { Spinner } from '@/Komponen/Ui/spinner';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { DaftarKamera, MulaiPemindaian, PesanGalatKamera } from '@/Pustaka/PemindaiKamera';

type PropsTombolPindaiKamera = {
    /** Dipanggil sekali dengan isi barcode yang terbaca; dialog menutup sendiri sesudahnya. */
    saatTerbaca: (barcode: string) => void;
    disabled?: boolean | undefined;
    /** Nama tombol; tambahkan konteks bila ada beberapa tombol di satu halaman (mis. "Pindai kamera PCS"). */
    label?: string;
};

/**
 * F-03 (BR-03.1): tombol "Pindai kamera" + dialog pratinjau kamera. Hasilnya diperlakukan sama dengan barcode yang
 * diketik atau dipindai pemindai USB, jadi validasi dan duplikat tetap diputuskan pemanggilnya.
 */
export default function TombolPindaiKamera({
    saatTerbaca,
    disabled = false,
    label = 'Pindai kamera',
}: PropsTombolPindaiKamera) {
    const [buka, AturBuka] = useState(false);
    const [video, AturVideo] = useState<HTMLVideoElement | null>(null);
    const [memuat, AturMemuat] = useState(true);
    const [pesan, AturPesan] = useState<string | null>(null);
    const [kamera, AturKamera] = useState<MediaDeviceInfo[]>([]);
    const [idKamera, AturIdKamera] = useState<string | null>(null);
    const [idAktif, AturIdAktif] = useState<string | null>(null);
    const saatTerbacaTerbaru = useRef(saatTerbaca);

    useEffect(() => {
        saatTerbacaTerbaru.current = saatTerbaca;
    }, [saatTerbaca]);

    useEffect(() => {
        if (!buka || video === null) {
            return;
        }

        let selesai = false;
        let pemindai: Awaited<ReturnType<typeof MulaiPemindaian>> | null = null;

        MulaiPemindaian({
            video,
            idPerangkat: idKamera,
            saatTerbaca: (barcode) => {
                if (selesai) {
                    return;
                }

                selesai = true;
                pemindai?.Berhenti();
                navigator.vibrate?.(60);
                AturBuka(false);
                saatTerbacaTerbaru.current(barcode);
            },
        })
            .then(async (aktif) => {
                if (selesai) {
                    aktif.Berhenti();

                    return;
                }

                pemindai = aktif;
                AturIdAktif(aktif.IdPerangkat);
                AturMemuat(false);
                AturKamera(await DaftarKamera());
            })
            .catch((galat: unknown) => {
                if (!selesai) {
                    AturMemuat(false);
                    AturPesan(PesanGalatKamera(galat));
                }
            });

        return () => {
            selesai = true;
            pemindai?.Berhenti();
        };
    }, [buka, video, idKamera]);

    const Buka = () => {
        AturMemuat(true);
        AturPesan(null);
        AturBuka(true);
    };

    const Tutup = () => {
        AturBuka(false);
        AturIdKamera(null);
        AturIdAktif(null);
        AturKamera([]);
    };

    const GantiKamera = () => {
        const indeks = kamera.findIndex((item) => item.deviceId === idAktif);
        const berikut = kamera[(indeks + 1) % kamera.length];

        if (berikut) {
            AturMemuat(true);
            AturPesan(null);
            AturIdKamera(berikut.deviceId);
        }
    };

    return (
        <>
            <Tombol varian="sekunder" onClick={Buka} disabled={disabled}>
                <ScanBarcode className="size-4" aria-hidden />
                {label}
            </Tombol>
            {buka ? (
                <DialogFormulir
                    judul="Pindai barcode dengan kamera"
                    keterangan="Arahkan kamera ke barcode barang sampai terbaca sendiri. Jaga barcode tetap datar dan terang."
                    saatTutup={Tutup}
                >
                    <div className="flex flex-col gap-3">
                        {pesan ? <Pemberitahuan jenis="bahaya">{pesan}</Pemberitahuan> : null}
                        <div className="relative overflow-hidden rounded-kontrol border border-garis bg-muted">
                            <video
                                ref={AturVideo}
                                muted
                                playsInline
                                aria-label="Pratinjau kamera"
                                className="aspect-video w-full object-cover"
                            />
                            {memuat ? (
                                <div
                                    role="status"
                                    className="absolute inset-0 flex items-center justify-center gap-2 text-isi text-teks-sekunder"
                                >
                                    <Spinner role={undefined} aria-label={undefined} aria-hidden="true" />
                                    Membuka kamera…
                                </div>
                            ) : null}
                            {!memuat && !pesan ? (
                                <div
                                    aria-hidden="true"
                                    className="pointer-events-none absolute inset-x-[10%] top-[30%] h-2/5 rounded-kontrol border-2 border-primary"
                                />
                            ) : null}
                        </div>
                        <DialogFooter className="sm:justify-start">
                            {kamera.length > 1 ? (
                                <Tombol varian="sekunder" onClick={GantiKamera}>
                                    Ganti kamera
                                </Tombol>
                            ) : null}
                            <Tombol varian="sekunder" onClick={Tutup}>
                                Batal pindai
                            </Tombol>
                        </DialogFooter>
                    </div>
                </DialogFormulir>
            ) : null}
        </>
    );
}
