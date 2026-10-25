import { router } from '@inertiajs/react';
import {
    CheckCircle2Icon,
    LogInIcon,
    LogOutIcon,
    MapPinIcon,
    ScanFaceIcon,
    SunIcon,
    TriangleAlertIcon,
    WifiIcon,
    WifiOffIcon,
} from 'lucide-react';
import { useRef, useState } from 'react';

import FormMintaKoreksi from '@/Komponen/Absensi/FormMintaKoreksi';
import PemindaiWajah, { type HasilFoto } from '@/Komponen/Absensi/PemindaiWajah';
import Tombol from '@/Komponen/Formulir/Tombol';
import { Spinner } from '@/Komponen/Ui/spinner';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { ALAMAT_ABSEN } from '@/Fitur/Absensi/Alamat';
import { FormatJam, SelisihDetik } from '@/Fitur/Absensi/FormatAbsensi';
import { AmbilLokasi, type Lokasi } from '@/Fitur/Absensi/Lokasi';
import type { StatusMesin } from '@/Fitur/Absensi/MesinWajah';
import { FormatDurasi } from '@/Pustaka/FormatWaktu';
import { KirimJson } from '@/Pustaka/PermintaanJson';
import { BuatUlid } from '@/Pustaka/Ulid';
import type { PropsAbsenSaya } from '@/Tipe/Karyawan';

type PropsPanelAbsen = Pick<PropsAbsenSaya, 'AbsensiTerbuka' | 'AlamatModelWajah' | 'SimpanFoto'> & {
    /** Tautan pribadi (D-198) memakai alamatnya sendiri; bawaannya halaman berlogin. */
    alamatDasar?: string;
    daring: boolean;
    sekarang: Date;
    /** Kemajuan menyiapkan model wajah di latar belakang (dimulai saat halaman dibuka). */
    mesin: StatusMesin;
};

/** Teks penanda kesiapan pemindai wajah untuk karyawan. */
function TeksMesin(mesin: StatusMesin): string {
    switch (mesin.Tahap) {
        case 'siap':
            return 'Pemindai wajah siap';
        case 'galat':
            return 'Pemindai gagal dimuat';
        case 'belum':
            return 'Pemindai wajah dicek';
        default:
            return `Menyiapkan pemindai ${String(mesin.Persen)}%`;
    }
}

/**
 * Satu tombol besar Masuk/Pulang (D-180). Tiap absen: lokasi GPS dicari bersamaan dengan pindai wajah; server menolak
 * bila wajah tidak cocok, sedangkan lokasi yang janggal tetap tercatat dan ditandai "perlu dicek". Bila terus gagal,
 * karyawan bisa meminta koreksi ke manajer.
 */
export default function PanelAbsen({
    AbsensiTerbuka,
    AlamatModelWajah,
    SimpanFoto,
    alamatDasar = ALAMAT_ABSEN,
    daring,
    sekarang,
    mesin,
}: PropsPanelAbsen) {
    const [pindai, AturPindai] = useState(false);
    const [langkah, AturLangkah] = useState<string | null>(null);
    const [galat, AturGalat] = useState<string | null>(null);
    const [berhasil, AturBerhasil] = useState<string | null>(null);
    const [mintaKoreksi, AturMintaKoreksi] = useState(false);
    const [koreksiTerkirim, AturKoreksiTerkirim] = useState(false);
    const lokasi = useRef<Promise<Lokasi> | null>(null);
    // Satu Uuid per absen masuk, dipakai ulang saat mencoba lagi (koneksi putus setelah server mencatat tidak
    // menghasilkan galat "sudah absen"), dan baru diganti setelah berhasil.
    const uuidMasuk = useRef<string | null>(null);
    const keluar = AbsensiTerbuka !== null;

    const Mulai = () => {
        AturGalat(null);
        AturBerhasil(null);
        AturKoreksiTerkirim(false);
        // Lokasi dicari bersamaan dengan pindai wajah supaya karyawan tidak menunggu dua kali.
        lokasi.current = AmbilLokasi();
        lokasi.current.catch(() => undefined);
        AturPindai(true);
    };

    const Kirim = async ([foto]: HasilFoto[]) => {
        AturPindai(false);

        if (!foto || !lokasi.current) {
            return;
        }

        try {
            AturLangkah('Mencari lokasi…');
            const posisi = await lokasi.current;
            AturLangkah('Mengirim absen…');
            const jawaban = await KirimJson<{ PerluDicek?: boolean }>(`${alamatDasar}/${keluar ? 'keluar' : 'masuk'}`, {
                Uuid: keluar ? AbsensiTerbuka.Uuid : (uuidMasuk.current ??= BuatUlid()),
                ...posisi,
                SidikWajah: foto.Sidik,
                // Foto hanya bila aturan menyimpannya; wajah tetap dicocokkan lewat sidik.
                ...(SimpanFoto ? { Swafoto: foto.Swafoto } : {}),
            });
            uuidMasuk.current = null;
            AturMintaKoreksi(false);
            AturBerhasil(
                `${keluar ? 'Absen pulang tercatat. Terima kasih!' : 'Absen masuk tercatat. Selamat bekerja!'}${
                    jawaban?.PerluDicek
                        ? ' Lokasi Anda terdeteksi jauh dari outlet, jadi pengelola akan memeriksanya.'
                        : ''
                }`,
            );
            router.reload({ only: ['AbsensiTerbuka', 'Riwayat'] });
        } catch (kesalahan) {
            AturGalat(kesalahan instanceof Error ? kesalahan.message : 'Absen gagal. Coba lagi.');
        } finally {
            AturLangkah(null);
        }
    };

    const CekKecocokan = async (sidik: number[]): Promise<boolean> =>
        (await KirimJson<{ Cocok: boolean }>(`${alamatDasar}/cek-wajah`, { SidikWajah: sidik })).Cocok;

    const sibuk = langkah !== null;

    return (
        <section
            className="flex flex-col gap-5 rounded-panel border border-garis bg-permukaan p-5"
            aria-live="polite"
            aria-label="Absensi"
        >
            <div className="flex flex-col items-center gap-2 text-center">
                {AbsensiTerbuka ? (
                    <>
                        <LabelStatus jenis="sukses" teks="Sedang bekerja" />
                        <p className="text-sorotan-besar-hp font-semibold tabular-nums text-teks-utama">
                            {FormatDurasi(SelisihDetik(AbsensiTerbuka.MasukPada, sekarang))}
                        </p>
                        <p className="text-isi text-teks-sekunder">
                            Masuk pukul {FormatJam(AbsensiTerbuka.MasukPada)}
                            {AbsensiTerbuka.NamaOutlet ? ` di ${AbsensiTerbuka.NamaOutlet}` : ''}
                        </p>
                    </>
                ) : (
                    <>
                        <LabelStatus jenis="netral" teks="Belum absen masuk" />
                        <p className="inline-flex items-center gap-2 text-isi text-teks-sekunder">
                            <SunIcon aria-hidden="true" className="size-4" />
                            Siap mulai kerja hari ini?
                        </p>
                    </>
                )}
            </div>

            {berhasil ? (
                <div
                    className="flex animate-in items-center gap-3 rounded-panel bg-sukses-lembut p-3 text-sukses duration-300 zoom-in-95 fade-in"
                    role="status"
                >
                    <CheckCircle2Icon aria-hidden="true" className="size-6 shrink-0" />
                    <p className="text-isi font-semibold">{berhasil}</p>
                </div>
            ) : null}
            {galat ? <Pemberitahuan jenis="bahaya">{galat}</Pemberitahuan> : null}
            {koreksiTerkirim ? (
                <Pemberitahuan jenis="sukses">
                    Permintaan terkirim ke manajer. Jam absen Anda akan dicatat setelah diperiksa.
                </Pemberitahuan>
            ) : null}
            {galat && !mintaKoreksi && !koreksiTerkirim ? (
                <Tombol varian="sekunder" onClick={() => AturMintaKoreksi(true)}>
                    Minta koreksi ke manajer
                </Tombol>
            ) : null}
            {mintaKoreksi ? (
                <FormMintaKoreksi
                    jenis={keluar ? 'Keluar' : 'Masuk'}
                    saatSelesai={() => {
                        AturMintaKoreksi(false);
                        AturGalat(null);
                        AturKoreksiTerkirim(true);
                    }}
                    saatBatal={() => AturMintaKoreksi(false)}
                    alamatDasar={alamatDasar}
                />
            ) : null}

            {pindai ? (
                <PemindaiWajah
                    alamatModel={AlamatModelWajah}
                    mode="absen"
                    judul={keluar ? 'Absen pulang' : 'Absen masuk'}
                    cekKecocokan={CekKecocokan}
                    jumlah={1}
                    saatSelesai={(hasil) => void Kirim(hasil)}
                    saatBatal={() => AturPindai(false)}
                    saatGalat={(pesan) => {
                        AturPindai(false);
                        AturGalat(pesan);
                    }}
                />
            ) : (
                <>
                    <button
                        type="button"
                        disabled={!daring || sibuk}
                        aria-busy={sibuk || undefined}
                        onClick={Mulai}
                        className={`mx-auto flex size-40 flex-col items-center justify-center gap-2 rounded-full text-brand-teks ring-8 transition-transform duration-150 select-none focus-visible:ring-offset-2 active:scale-95 disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100 ${
                            keluar ? 'bg-brand-gelap ring-brand-gelap-gulir' : 'bg-brand ring-brand-lembut-sorot'
                        }`}
                    >
                        {sibuk ? (
                            <Spinner role={undefined} aria-label={undefined} aria-hidden="true" className="size-8" />
                        ) : keluar ? (
                            <LogOutIcon aria-hidden="true" className="size-9" />
                        ) : (
                            <LogInIcon aria-hidden="true" className="size-9" />
                        )}
                        <span className="px-3 text-center text-subjudul font-semibold">
                            {langkah ?? (keluar ? 'Absen pulang' : 'Absen masuk')}
                        </span>
                    </button>

                    <ul className="grid grid-cols-3 gap-2 text-center text-keterangan text-teks-sekunder">
                        <li className="flex flex-col items-center gap-1 rounded-panel bg-permukaan-redup px-2 py-2.5">
                            {daring ? (
                                <WifiIcon aria-hidden="true" className="size-5 text-sukses" />
                            ) : (
                                <WifiOffIcon aria-hidden="true" className="size-5 text-bahaya" />
                            )}
                            {daring ? 'Internet siap' : 'Tanpa internet'}
                        </li>
                        <li className="flex flex-col items-center gap-1 rounded-panel bg-permukaan-redup px-2 py-2.5">
                            <MapPinIcon aria-hidden="true" className="size-5 text-brand" />
                            Lokasi dicatat
                        </li>
                        <li
                            className="flex flex-col items-center gap-1 rounded-panel bg-permukaan-redup px-2 py-2.5"
                            role="status"
                        >
                            {mesin.Tahap === 'siap' ? (
                                <ScanFaceIcon aria-hidden="true" className="size-5 text-sukses" />
                            ) : mesin.Tahap === 'galat' ? (
                                <TriangleAlertIcon aria-hidden="true" className="size-5 text-bahaya" />
                            ) : (
                                <ScanFaceIcon aria-hidden="true" className="size-5 text-brand" />
                            )}
                            {TeksMesin(mesin)}
                        </li>
                    </ul>
                    <ol className="flex flex-col gap-2 rounded-panel bg-permukaan-redup p-3 text-keterangan text-teks-sekunder">
                        {[
                            'Ketuk tombol Absen, lalu izinkan kamera dan lokasi bila diminta.',
                            'Hadapkan wajah ke lingkaran. Hijau berarti wajah cocok, merah berarti tidak cocok.',
                            'Saat lingkaran hijau, ketuk Ambil foto. Absen langsung tercatat.',
                        ].map((teks, indeks) => (
                            <li key={teks} className="flex items-start gap-2">
                                <span className="grid size-5 shrink-0 place-items-center rounded-full bg-brand-lembut text-keterangan font-semibold text-brand">
                                    {indeks + 1}
                                </span>
                                {teks}
                            </li>
                        ))}
                    </ol>
                </>
            )}
        </section>
    );
}
