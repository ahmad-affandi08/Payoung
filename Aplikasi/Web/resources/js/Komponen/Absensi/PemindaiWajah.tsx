import { CameraIcon, CheckCircle2Icon, ScanFaceIcon, TriangleAlertIcon, XCircleIcon, XIcon } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import { Progress } from '@/Komponen/Ui/progress';
import { Spinner } from '@/Komponen/Ui/spinner';
import { AmbilSwafoto, MuatMesinWajah, PindaiWajah } from '@/Fitur/Absensi/MesinWajah';
import {
    AmbilPetunjukPosisi,
    AmbilTampilanKeadaan,
    BolehAmbilFoto,
    PenstabilKeadaan,
    TentukanKeadaan,
    type AlasanWajahGagal,
    type KeadaanPemindai,
    type PenilaianWajah,
} from '@/Fitur/Absensi/SidikWajah';
import { useMesinWajah } from '@/Fitur/Absensi/useMesinWajah';

export type HasilFoto = { Sidik: number[]; Swafoto: string };

type PropsPemindai = {
    alamatModel: string;
    /** `daftar`: merekam wajah baru (hijau bila wajah terbaca jelas). `absen`: hijau hanya bila cocok dengan wajah terdaftar. */
    mode: 'daftar' | 'absen';
    judul: string;
    /** Jumlah foto yang dikumpulkan sebelum [saatSelesai]; 1 untuk absen. */
    jumlah: number;
    /** Wajib pada mode `absen`: jawaban server apakah sidik ini cocok dengan wajah terdaftar. */
    cekKecocokan?: (sidik: number[]) => Promise<boolean>;
    saatSelesai: (hasil: HasilFoto[]) => void;
    saatBatal: () => void;
    saatGalat: (pesan: string) => void;
};

/** Jeda antar-pemindaian (ms): cukup rapat untuk umpan balik terasa langsung, cukup longgar untuk HP biasa. */
const JEDA_PINDAI_MS = 90;
/** Seberapa sering sidik yang sama diperiksa ke server (ms), dan berapa lama jawabannya masih dianggap sahih. */
const JEDA_CEK_MS = 700;
const MASA_BERLAKU_CEK_MS = 3500;
/** Jeda sesudah satu foto pendaftaran, supaya foto berikutnya tidak identik. */
const JEDA_ANTAR_FOTO_MS = 1300;

const PETUNJUK_POSE = [
    'Hadap lurus ke kamera.',
    'Miringkan kepala sedikit ke kiri.',
    'Miringkan kepala sedikit ke kanan.',
];

/** Warna lingkaran & petunjuk per nada; selalu disertai ikon dan teks (warna bukan satu-satunya penanda). */
const kelasNada = {
    netral: { cincin: 'border-garis-input', petunjuk: 'bg-permukaan-redup text-teks-utama' },
    sukses: { cincin: 'border-sukses ring-sukses-lembut', petunjuk: 'bg-sukses-lembut text-sukses' },
    bahaya: { cincin: 'border-bahaya ring-bahaya-lembut', petunjuk: 'bg-bahaya-lembut text-bahaya' },
    peringatan: {
        cincin: 'border-peringatan ring-peringatan-lembut',
        petunjuk: 'bg-peringatan-lembut text-peringatan',
    },
} as const;

type Kecocokan = { Hasil: 'cocok' | 'tidak-cocok'; Sidik: number[]; Waktu: number };

const Tunggu = (ms: number) => new Promise<void>((selesai) => setTimeout(selesai, ms));

/**
 * Kamera depan layar penuh dengan umpan balik langsung (D-186). Lingkaran **hijau** dan tombol "Ambil foto" aktif hanya
 * bila wajah memenuhi syarat (mode absen: cocok dengan wajah terdaftar menurut server); **merah** dan tombol mati bila
 * wajah tidak cocok atau tidak terbaca sebagai wajah asli; kuning saat posisi perlu diatur atau sedang diperiksa.
 * Foto tidak diambil otomatis: karyawan sendiri yang mengetuk tombol, sehingga bingkainya sesuai yang ia lihat.
 */
export default function PemindaiWajah({
    alamatModel,
    mode,
    judul,
    jumlah,
    cekKecocokan,
    saatSelesai,
    saatBatal,
    saatGalat,
}: PropsPemindai) {
    const video = useRef<HTMLVideoElement>(null);
    const selesai = useRef(false);
    const penstabil = useRef(new PenstabilKeadaan());
    const keadaanRef = useRef<KeadaanPemindai>('menyiapkan');
    const sidikSegar = useRef<{ Sidik: number[]; Waktu: number } | null>(null);
    const kecocokan = useRef<Kecocokan | null>(null);
    const hasil = useRef<HasilFoto[]>([]);
    const sedangJeda = useRef(false);
    const [keadaan, AturKeadaan] = useState<KeadaanPemindai>('menyiapkan');
    const [alasan, AturAlasan] = useState<AlasanWajahGagal | null>(null);
    const [terkumpul, AturTerkumpul] = useState(0);
    const [jeda, AturJeda] = useState(false);
    const [kameraSiap, AturKameraSiap] = useState(false);
    const [galatCek, AturGalatCek] = useState(false);
    const mesin = useMesinWajah(alamatModel, true);

    const Hentikan = useCallback(() => {
        selesai.current = true;
        const aliran = video.current?.srcObject;

        if (aliran !== null && aliran !== undefined && 'getTracks' in aliran) {
            aliran.getTracks().forEach((trek) => trek.stop());
        }
    }, []);

    useEffect(() => {
        selesai.current = false;
        let cekBerjalan = false;
        let cekTerakhir = 0;
        let gagalBerturut = 0;
        const sebelumnya = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        const JalankanCek = (sidik: number[]) => {
            if (mode !== 'absen' || cekKecocokan === undefined || cekBerjalan) {
                return;
            }

            cekBerjalan = true;
            cekTerakhir = Date.now();
            cekKecocokan(sidik)
                .then((cocok) => {
                    gagalBerturut = 0;
                    AturGalatCek(false);
                    kecocokan.current = { Hasil: cocok ? 'cocok' : 'tidak-cocok', Sidik: sidik, Waktu: Date.now() };
                })
                .catch(() => {
                    gagalBerturut += 1;
                    AturGalatCek(gagalBerturut >= 3);
                })
                .finally(() => {
                    cekBerjalan = false;
                });
        };

        const Jalankan = async () => {
            // Kamera dan model disiapkan bersamaan; model biasanya sudah siap karena dimuat sejak halaman dibuka.
            const janjiMesin = MuatMesinWajah(alamatModel);
            janjiMesin.catch(() => undefined);
            let aliran: MediaStream;

            try {
                aliran = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
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

            try {
                await video.current.play();
            } catch {
                // Pemutaran otomatis ditolak browser: video tetap tampil begitu pengguna menyentuh layar.
            }

            AturKameraSiap(true);

            let human;

            try {
                human = await janjiMesin;
            } catch {
                Hentikan();
                saatGalat('Model wajah gagal dimuat. Periksa koneksi internet, lalu coba lagi.');

                return;
            }

            penstabil.current.Atur('tidak-ada-wajah');
            keadaanRef.current = 'tidak-ada-wajah';
            AturKeadaan('tidak-ada-wajah');
            let denganSidik = false;

            while (!selesai.current && video.current) {
                if (sedangJeda.current) {
                    await Tunggu(100);
                    continue;
                }

                const mulai = Date.now();
                const pindai = await PindaiWajah(human, video.current, denganSidik);

                if (selesai.current || sedangJeda.current) {
                    continue;
                }

                // Wajah lolos tetapi sidik belum ada (bingkai pertama): belum boleh dianggap siap.
                const penilaian: PenilaianWajah =
                    pindai.Penilaian.Lolos && pindai.Sidik === null
                        ? { Lolos: false, Alasan: 'SidikKosong' }
                        : pindai.Penilaian;
                const kualitasBaik = penilaian.Lolos || (!penilaian.Lolos && penilaian.Alasan === 'SidikKosong');
                denganSidik = kualitasBaik;

                if (!kualitasBaik) {
                    sidikSegar.current = null;
                    kecocokan.current = null;
                }

                if (penilaian.Lolos && pindai.Sidik !== null) {
                    sidikSegar.current = { Sidik: pindai.Sidik, Waktu: Date.now() };

                    if (Date.now() - cekTerakhir >= JEDA_CEK_MS) {
                        JalankanCek(pindai.Sidik);
                    }
                }

                const cek = kecocokan.current;
                const jawaban: 'belum' | 'cocok' | 'tidak-cocok' | null =
                    mode === 'daftar'
                        ? null
                        : cek !== null && Date.now() - cek.Waktu < MASA_BERLAKU_CEK_MS
                          ? cek.Hasil
                          : 'belum';
                const stabil = penstabil.current.Masukkan(TentukanKeadaan(penilaian, jawaban));
                keadaanRef.current = stabil;
                AturKeadaan(stabil);
                AturAlasan(!penilaian.Lolos ? penilaian.Alasan : null);

                await Tunggu(Math.max(20, JEDA_PINDAI_MS - (Date.now() - mulai)));
            }
        };

        void Jalankan();

        return () => {
            document.body.style.overflow = sebelumnya;
            Hentikan();
        };
        // Pemindaian dimulai sekali per tampilan panel.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const AmbilFoto = () => {
        const kamera = video.current;

        if (!kamera || !BolehAmbilFoto(keadaanRef.current) || sedangJeda.current) {
            return;
        }

        const sumber =
            mode === 'absen'
                ? kecocokan.current !== null &&
                  kecocokan.current.Hasil === 'cocok' &&
                  Date.now() - kecocokan.current.Waktu < MASA_BERLAKU_CEK_MS
                    ? kecocokan.current
                    : null
                : sidikSegar.current !== null && Date.now() - sidikSegar.current.Waktu < MASA_BERLAKU_CEK_MS
                  ? sidikSegar.current
                  : null;

        if (sumber === null) {
            return;
        }

        hasil.current.push({ Sidik: sumber.Sidik, Swafoto: AmbilSwafoto(kamera) });
        AturTerkumpul(hasil.current.length);

        if (hasil.current.length >= jumlah) {
            Hentikan();
            saatSelesai(hasil.current);

            return;
        }

        // Foto berikutnya: jeda sebentar dan minta wajah diperiksa ulang dari awal.
        sedangJeda.current = true;
        AturJeda(true);
        sidikSegar.current = null;
        kecocokan.current = null;
        penstabil.current.Atur('memeriksa');
        keadaanRef.current = 'memeriksa';
        AturKeadaan('memeriksa');
        setTimeout(() => {
            sedangJeda.current = false;
            AturJeda(false);
        }, JEDA_ANTAR_FOTO_MS);
    };

    const menyiapkan = keadaan === 'menyiapkan';
    const tampilan = AmbilTampilanKeadaan(keadaan, mode);
    const teksPetunjuk =
        keadaan === 'atur-posisi' && alasan !== null
            ? AmbilPetunjukPosisi(alasan)
            : jeda
              ? `Foto ${String(terkumpul)} tersimpan. ${PETUNJUK_POSE[Math.min(terkumpul, PETUNJUK_POSE.length - 1)] ?? ''}`
              : tampilan.teks;
    const kelas = kelasNada[tampilan.nada];
    const boleh = BolehAmbilFoto(keadaan) && !jeda;
    const Ikon =
        tampilan.nada === 'sukses'
            ? CheckCircle2Icon
            : tampilan.nada === 'bahaya'
              ? XCircleIcon
              : tampilan.nada === 'peringatan'
                ? TriangleAlertIcon
                : ScanFaceIcon;

    return (
        <div
            className="fixed inset-0 z-50 flex animate-in flex-col bg-permukaan duration-200 fade-in"
            role="dialog"
            aria-modal="true"
            aria-label={judul}
        >
            <header className="flex items-center justify-between gap-3 border-b border-garis px-4 py-3">
                <p className="min-w-0 truncate text-subjudul font-semibold text-teks-utama">{judul}</p>
                <Tombol
                    varian="sekunder"
                    onClick={() => {
                        Hentikan();
                        saatBatal();
                    }}
                >
                    <XIcon aria-hidden="true" className="size-4" />
                    Batal
                </Tombol>
            </header>

            <div className="mx-auto flex w-full max-w-md flex-1 flex-col items-center justify-center gap-5 overflow-y-auto px-4 py-4">
                <div
                    className={`relative aspect-square w-full max-w-72 overflow-hidden rounded-full border-8 bg-permukaan-redup ring-8 transition-colors duration-200 ${kelas.cincin} ${
                        keadaan === 'memeriksa' ? 'motion-safe:animate-pulse' : ''
                    }`}
                    data-keadaan={keadaan}
                >
                    <video
                        ref={video}
                        className="size-full -scale-x-100 object-cover"
                        playsInline
                        muted
                        aria-label="Pratinjau kamera depan"
                    />
                    {!kameraSiap ? (
                        <div className="absolute inset-0 grid place-items-center bg-permukaan-redup">
                            <Spinner role={undefined} aria-label={undefined} aria-hidden="true" className="size-8" />
                        </div>
                    ) : null}
                </div>

                {menyiapkan ? (
                    <div className="flex w-full flex-col gap-2 text-center" role="status">
                        <p className="text-isi font-semibold text-teks-utama">
                            {kameraSiap ? 'Menyiapkan pemindai wajah…' : 'Membuka kamera…'}
                        </p>
                        <Progress
                            value={kameraSiap ? mesin.Persen : 5}
                            aria-label="Kemajuan menyiapkan pemindai wajah"
                        />
                        <p className="text-keterangan text-teks-sekunder">
                            Pemindai disiapkan sekali, kunjungan berikutnya jauh lebih cepat.
                        </p>
                    </div>
                ) : (
                    <p
                        className={`flex min-h-12 w-full items-center justify-center gap-2 rounded-panel px-3 py-2 text-center text-isi font-semibold transition-colors duration-200 ${kelas.petunjuk}`}
                        role="status"
                        aria-live="polite"
                    >
                        {keadaan === 'memeriksa' ? (
                            <Spinner role={undefined} aria-label={undefined} aria-hidden="true" className="size-5" />
                        ) : (
                            <Ikon aria-hidden="true" className="size-5 shrink-0" />
                        )}
                        {teksPetunjuk}
                    </p>
                )}

                {galatCek ? (
                    <p className="text-center text-keterangan text-bahaya" role="alert">
                        Pemeriksaan wajah ke server gagal. Periksa koneksi internet Anda.
                    </p>
                ) : null}

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
                            {terkumpul < jumlah && !jeda
                                ? ` | ${PETUNJUK_POSE[Math.min(terkumpul, PETUNJUK_POSE.length - 1)] ?? ''}`
                                : ''}
                        </p>
                    </div>
                ) : null}
            </div>

            <footer className="border-t border-garis px-4 pt-3 pb-4 tepi-bawah-aman">
                <div className="mx-auto w-full max-w-md">
                    <Tombol ukuran="besar" disabled={!boleh} onClick={AmbilFoto}>
                        <CameraIcon aria-hidden="true" className="size-5" />
                        Ambil foto
                    </Tombol>
                </div>
            </footer>
        </div>
    );
}
