import { ScanFaceIcon } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import { AmbilPetunjukWajah } from '@/Fitur/Absensi/SidikWajah';

export type HasilFoto = { Sidik: number[]; Swafoto: string };

/**
 * Kamera depan + pemindaian wajah berulang (±4 kali per detik) sampai [jumlah] foto lolos: satu wajah, wajah asli &
 * hidup, sudah berkedip. Antar-foto ada jeda singkat supaya fotonya tidak identik.
 */
export default function PemindaiWajah({
    alamatModel,
    jumlah,
    saatSelesai,
    saatBatal,
    saatGalat,
}: {
    alamatModel: string;
    jumlah: number;
    saatSelesai: (hasil: HasilFoto[]) => void;
    saatBatal: () => void;
    saatGalat: (pesan: string) => void;
}) {
    const video = useRef<HTMLVideoElement>(null);
    const [petunjuk, AturPetunjuk] = useState('Menyiapkan kamera & model wajah…');
    const [terkumpul, AturTerkumpul] = useState(0);
    const selesai = useRef(false);

    const Hentikan = useCallback(() => {
        selesai.current = true;
        const aliran = video.current?.srcObject;

        if (aliran instanceof MediaStream) {
            aliran.getTracks().forEach((trek) => trek.stop());
        }
    }, []);

    useEffect(() => {
        selesai.current = false;
        const hasil: HasilFoto[] = [];

        const Jalankan = async () => {
            const { MuatMesinWajah, PindaiWajah, AmbilSwafoto } = await import('@/Fitur/Absensi/MesinWajah');
            let aliran: MediaStream;

            try {
                aliran = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'user', width: { ideal: 640 } },
                    audio: false,
                });
            } catch {
                saatGalat(
                    'Kamera tidak bisa dibuka. Izinkan akses kamera untuk situs ini di pengaturan browser, lalu coba lagi.',
                );

                return;
            }

            if (!video.current || selesai.current) {
                aliran.getTracks().forEach((trek) => trek.stop());

                return;
            }

            video.current.srcObject = aliran;
            await video.current.play().catch(() => undefined);

            let human;

            try {
                human = await MuatMesinWajah(alamatModel);
            } catch {
                Hentikan();
                saatGalat('Model wajah gagal dimuat. Periksa koneksi internet, lalu coba lagi.');

                return;
            }

            let kedip = false;

            while (!selesai.current && video.current) {
                const pindai = await PindaiWajah(human, video.current, kedip);
                kedip = pindai.Kedip;
                AturPetunjuk(AmbilPetunjukWajah(pindai.Penilaian));

                if (pindai.Penilaian.Lolos && pindai.Sidik) {
                    hasil.push({ Sidik: pindai.Sidik, Swafoto: AmbilSwafoto(video.current) });
                    AturTerkumpul(hasil.length);

                    if (hasil.length >= jumlah) {
                        Hentikan();
                        saatSelesai(hasil);

                        return;
                    }

                    // Foto berikutnya: kedip lagi & jeda supaya posisi wajah sedikit berbeda.
                    kedip = false;
                    AturPetunjuk('Foto tersimpan. Gerakkan kepala sedikit, lalu kedip lagi.');
                    await new Promise((selesaiTunggu) => setTimeout(selesaiTunggu, 900));
                }

                await new Promise((selesaiTunggu) => setTimeout(selesaiTunggu, 250));
            }
        };

        void Jalankan();

        return Hentikan;
        // Pemindaian dimulai sekali per tampilan panel.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div className="flex flex-col items-center gap-4">
            <div className="relative aspect-square w-full max-w-72 overflow-hidden rounded-full border-4 border-brand bg-permukaan-redup ring-8 ring-brand-lembut">
                <video
                    ref={video}
                    className="size-full -scale-x-100 object-cover"
                    playsInline
                    muted
                    aria-label="Pratinjau kamera depan"
                />
            </div>
            <p
                className="flex min-h-12 w-full items-center justify-center gap-2 rounded-panel bg-brand-lembut px-3 py-2 text-center text-isi font-semibold text-teks-utama"
                aria-live="assertive"
            >
                <ScanFaceIcon aria-hidden="true" className="size-5 shrink-0 text-brand" />
                {petunjuk}
            </p>
            {jumlah > 1 ? (
                <div className="flex flex-col items-center gap-2">
                    <div className="flex gap-2" aria-hidden="true">
                        {Array.from({ length: jumlah }, (_, indeks) => (
                            <span
                                key={indeks}
                                className={`h-2 w-10 rounded-full ${indeks < terkumpul ? 'bg-sukses' : 'bg-garis'}`}
                            />
                        ))}
                    </div>
                    <p className="text-keterangan text-teks-sekunder">
                        Foto {Math.min(terkumpul + 1, jumlah)} dari {jumlah}
                    </p>
                </div>
            ) : null}
            <Tombol
                varian="sekunder"
                onClick={() => {
                    Hentikan();
                    saatBatal();
                }}
            >
                Batal
            </Tombol>
        </div>
    );
}
