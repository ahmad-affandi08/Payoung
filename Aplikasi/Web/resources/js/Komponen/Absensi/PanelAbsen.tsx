import { router } from '@inertiajs/react';
import { CheckCircle2Icon, LogInIcon, SunIcon } from 'lucide-react';
import { useRef, useState } from 'react';

import FormMintaKoreksi from '@/Komponen/Absensi/FormMintaKoreksi';
import GeserAbsen from '@/Komponen/Absensi/GeserAbsen';
import IlustrasiAbsen, { type KeadaanIlustrasiAbsen } from '@/Komponen/Absensi/IlustrasiAbsen';
import PemindaiWajah, { type HasilFoto } from '@/Komponen/Absensi/PemindaiWajah';
import Tombol from '@/Komponen/Formulir/Tombol';
import { Spinner } from '@/Komponen/Ui/spinner';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { ALAMAT_ABSEN } from '@/Fitur/Absensi/Alamat';
import { FormatJam, SelisihDetik } from '@/Fitur/Absensi/FormatAbsensi';
import { AmbilLokasi, type Lokasi } from '@/Fitur/Absensi/Lokasi';
import { FormatDurasi } from '@/Pustaka/FormatWaktu';
import { KirimJson } from '@/Pustaka/PermintaanJson';
import { BuatUlid } from '@/Pustaka/Ulid';
import type { PropsAbsenSaya } from '@/Tipe/Karyawan';

type PropsPanelAbsen = Pick<PropsAbsenSaya, 'AbsensiTerbuka' | 'AlamatModelWajah' | 'SimpanFoto'> & {
    /** Tautan pribadi (D-198) memakai alamatnya sendiri; bawaannya halaman berlogin. */
    alamatDasar?: string;
    daring: boolean;
    sekarang: Date;
};

/**
 * Inti halaman Absen di atas latar gelap (D-180, D-337): absen masuk lewat tombol apricot berlingkar, absen pulang lewat
 * tombol geser supaya tidak tertekan tidak sengaja, dengan ilustrasi gerai yang berganti menurut keadaan. Tiap absen:
 * lokasi GPS dicari bersamaan dengan pindai wajah. Server menolak bila wajah tidak cocok, sedangkan lokasi yang janggal
 * tetap tercatat dan ditandai "perlu dicek". Bila terus gagal, karyawan bisa meminta koreksi ke manajer.
 */
export default function PanelAbsen({
    AbsensiTerbuka,
    AlamatModelWajah,
    SimpanFoto,
    alamatDasar = ALAMAT_ABSEN,
    daring,
    sekarang,
}: PropsPanelAbsen) {
    const [pindai, AturPindai] = useState(false);
    const [langkah, AturLangkah] = useState<string | null>(null);
    const [galat, AturGalat] = useState<string | null>(null);
    const [berhasil, AturBerhasil] = useState<string | null>(null);
    const [berhasilPulang, AturBerhasilPulang] = useState(false);
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
        AturBerhasilPulang(false);
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
            AturBerhasilPulang(keluar);
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
    const keadaanIlustrasi: KeadaanIlustrasiAbsen = berhasilPulang ? 'selesai' : keluar ? 'bekerja' : 'belum';

    return (
        <section className="flex flex-col gap-5 text-permukaan" aria-live="polite" aria-label="Absensi">
            <div className="flex flex-col items-center gap-2 text-center">
                {AbsensiTerbuka ? (
                    <>
                        <span className="inline-flex items-center rounded-full bg-sukses-lembut px-3 py-1 text-keterangan font-semibold text-sukses">
                            Sedang bekerja
                        </span>
                        <p className="text-sorotan-besar-hp font-semibold tabular-nums text-permukaan">
                            {FormatDurasi(SelisihDetik(AbsensiTerbuka.MasukPada, sekarang))}
                        </p>
                        <p className="text-isi text-brand-gelap-teks">
                            Masuk pukul {FormatJam(AbsensiTerbuka.MasukPada)}
                            {AbsensiTerbuka.NamaOutlet ? ` di ${AbsensiTerbuka.NamaOutlet}` : ''}
                        </p>
                    </>
                ) : (
                    <>
                        <span className="inline-flex items-center rounded-full bg-brand-gelap-sorot px-3 py-1 text-keterangan font-semibold text-brand-gelap-teks">
                            Belum absen masuk
                        </span>
                        <p className="inline-flex items-center gap-2 text-isi text-brand-gelap-teks">
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
            {galat || koreksiTerkirim || mintaKoreksi ? (
                <div className="flex flex-col gap-3 rounded-panel bg-permukaan p-3 text-teks-utama">
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
                </div>
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
            ) : keluar ? (
                <GeserAbsen
                    nama="Absen pulang"
                    petunjuk="Geser untuk absen pulang"
                    disabled={!daring}
                    sibuk={sibuk}
                    langkah={langkah}
                    saatSelesai={Mulai}
                />
            ) : (
                <div className="relative mx-auto grid size-52 place-items-center">
                    <span
                        aria-hidden="true"
                        className="absolute inset-0 rounded-full bg-aksen/15 motion-safe:animate-pulse"
                    />
                    <span aria-hidden="true" className="absolute inset-6 rounded-full bg-aksen/30" />
                    <button
                        type="button"
                        disabled={!daring || sibuk}
                        aria-busy={sibuk || undefined}
                        onClick={Mulai}
                        className="relative flex size-32 flex-col items-center justify-center gap-1.5 rounded-full bg-aksen text-brand-gelap transition-transform duration-150 select-none focus-visible:ring-4 focus-visible:ring-aksen/50 focus-visible:outline-none enabled:active:scale-95 disabled:opacity-60"
                    >
                        {sibuk ? (
                            <Spinner role={undefined} aria-label={undefined} aria-hidden="true" className="size-8" />
                        ) : (
                            <LogInIcon aria-hidden="true" className="size-8" />
                        )}
                        <span className="px-3 text-center text-label font-semibold">{langkah ?? 'Absen masuk'}</span>
                    </button>
                </div>
            )}

            {pindai ? null : (
                <>
                    <p className="text-center text-keterangan text-brand-gelap-teks">
                        {keluar
                            ? 'Setelah digeser, hadapkan wajah ke kamera.'
                            : 'Ketuk, lalu hadapkan wajah ke kamera.'}
                    </p>
                    <IlustrasiAbsen keadaan={keadaanIlustrasi} />
                </>
            )}
        </section>
    );
}
