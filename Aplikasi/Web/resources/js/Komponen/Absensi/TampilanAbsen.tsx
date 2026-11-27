import { Head, Link, router } from '@inertiajs/react';
import { ClockIcon, WifiIcon, WifiOffIcon } from 'lucide-react';

import GarisWaktuRiwayat from '@/Komponen/Absensi/GarisWaktuRiwayat';
import IlustrasiAbsen from '@/Komponen/Absensi/IlustrasiAbsen';
import KartuPasangDiHp from '@/Komponen/Absensi/KartuPasangDiHp';
import PanelAbsen from '@/Komponen/Absensi/PanelAbsen';
import PanelDaftarWajah from '@/Komponen/Absensi/PanelDaftarWajah';
import PanelKesiapanAbsen from '@/Komponen/Absensi/PanelKesiapanAbsen';
import PanelJadwalSaya from '@/Komponen/Absensi/PanelJadwalSaya';
import Tombol from '@/Komponen/Formulir/Tombol';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { formatHariPanjang, formatJamDetik, SalamWaktu } from '@/Fitur/Absensi/FormatAbsensi';
import { useSekarang, useStatusDaring } from '@/Fitur/Absensi/useKondisiPerangkat';
import { useMesinWajah } from '@/Fitur/Absensi/useMesinWajah';
import { JalurHalamanAbsen, usePasangPwa } from '@/Fitur/Absensi/usePasangPwa';
import type { PropsAbsenSaya } from '@/Tipe/Karyawan';

/**
 * Tampilan halaman Absen (F-18 bagian 4, D-37, D-180, D-198), dipakai halaman berlogin `/kelola/absen` dan tautan
 * pribadi `/{slug}/absen/{token}`: karyawan masuk dengan akunnya atau membuka tautannya, mendaftarkan wajah sekali, lalu
 * absen masuk/pulang dengan satu tombol. Mobile-first, satu tugas per layar, tanpa menu dashboard. Wajah didaftarkan
 * sekali (persetujuan UU PDP, langsung aktif); tiap absen: lokasi GPS + pindai wajah. Lingkaran kamera hijau bila wajah cocok
 * (merah bila tidak) dan baru saat itu tombol Ambil foto aktif (D-186); server mengambil keputusan akhir dan mencatat lokasi.
 *
 * Bisa dipasang ke layar utama HP sebagai PWA (D-211): manifest + service worker per halaman, kartu "Pasang di HP".
 *
 * Tampilan (D-337): layar gelap penuh dengan salam dan jam besar, tombol absen apricot berlingkar (absen pulang lewat
 * tombol geser) dan ilustrasi gerai yang berganti menurut keadaan. Di bawahnya lembar terang berisi kesiapan perangkat,
 * jadwal, dan riwayat berdurasi.
 */
export default function TampilanAbsen(props: PropsAbsenSaya) {
    const { NamaToko, NamaKaryawan, AlamatDasar, Berlogin, BisaDashboard, Wajah, Riwayat } = props;
    const daring = useStatusDaring();
    const sekarang = useSekarang();
    // Model wajah disiapkan di latar belakang begitu halaman terbuka, jadi pemindai langsung siap saat tombol diketuk.
    const mesin = useMesinWajah(props.AlamatModelWajah, daring && Wajah?.Status !== 'Menunggu');
    // PWA (D-211): bisa dipasang ke layar utama HP; manifest versi berlogin butuh cookie sesi.
    const pasang = usePasangPwa(AlamatDasar);
    const jalur = JalurHalamanAbsen(AlamatDasar);

    const jam = formatJamDetik.format(sekarang);
    const disetujui = Wajah?.Status === 'Disetujui';

    return (
        <div className="flex min-h-dvh flex-col bg-brand-gelap">
            <Head title={`Absen | ${NamaToko}`}>
                <link
                    rel="manifest"
                    href={`${jalur}/manifest`}
                    crossOrigin={Berlogin ? 'use-credentials' : undefined}
                />
                <meta name="apple-mobile-web-app-capable" content="yes" />
                <meta name="apple-mobile-web-app-title" content="Absen" />
            </Head>

            <div className="mx-auto flex w-full max-w-md flex-col gap-5 px-5 pt-6 pb-8 text-permukaan">
                <div className="flex items-center justify-between gap-3">
                    <p className="min-w-0 truncate text-label font-semibold text-brand-gelap-teks">{NamaToko}</p>
                    <span
                        className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brand-gelap-sorot px-2.5 py-1 text-keterangan font-semibold text-permukaan"
                        role="status"
                    >
                        {daring ? (
                            <WifiIcon aria-hidden="true" className="size-3.5" />
                        ) : (
                            <WifiOffIcon aria-hidden="true" className="size-3.5" />
                        )}
                        {daring ? 'Tersambung' : 'Luring'}
                    </span>
                </div>

                <div className="flex flex-col gap-1">
                    <p className="text-isi text-brand-gelap-teks">{SalamWaktu(sekarang)},</p>
                    <JudulHalaman className="text-permukaan">{NamaKaryawan}</JudulHalaman>
                    <p className="flex items-baseline gap-1.5 font-semibold tabular-nums" aria-label="Jam sekarang">
                        <span className="text-sorotan-besar text-permukaan">{jam.slice(0, 5)}</span>
                        <span className="text-subjudul text-brand-gelap-teks">{jam.slice(5)}</span>
                    </p>
                    <p className="text-isi text-brand-gelap-teks">{formatHariPanjang.format(sekarang)}</p>
                </div>

                {!daring ? (
                    <div className="rounded-panel bg-permukaan p-1">
                        <Pemberitahuan jenis="peringatan">
                            <span className="inline-flex items-center gap-2">
                                <WifiOffIcon aria-hidden="true" className="size-4 shrink-0" />
                                Tidak ada koneksi internet. Absen butuh internet karena lokasi dan wajah diperiksa
                                server.
                            </span>
                        </Pemberitahuan>
                    </div>
                ) : null}

                {disetujui ? (
                    <PanelAbsen
                        AbsensiTerbuka={props.AbsensiTerbuka}
                        AlamatModelWajah={props.AlamatModelWajah}
                        SimpanFoto={props.SimpanFoto}
                        alamatDasar={AlamatDasar}
                        daring={daring}
                        sekarang={sekarang}
                    />
                ) : (
                    <section className="flex flex-col items-center gap-3 text-center" aria-label="Absensi">
                        <span className="inline-flex items-center rounded-full bg-brand-gelap-sorot px-3 py-1 text-keterangan font-semibold text-brand-gelap-teks">
                            {Wajah?.Status === 'Menunggu' ? 'Wajah sedang diperiksa' : 'Daftarkan wajah dulu'}
                        </span>
                        <IlustrasiAbsen keadaan="wajah" />
                    </section>
                )}
            </div>

            <main className="flex-1 rounded-t-3xl bg-latar">
                <div className="mx-auto flex w-full max-w-md flex-col gap-5 px-4 pt-6 pb-8 tepi-bawah-aman">
                    {disetujui ? <PanelKesiapanAbsen daring={daring} mesin={mesin} /> : null}

                    {Wajah?.Status === 'Menunggu' ? (
                        <section className="flex flex-col items-center gap-3 rounded-panel border border-garis bg-permukaan p-6 text-center">
                            <span className="grid size-14 place-items-center rounded-full bg-info-lembut text-info">
                                <ClockIcon aria-hidden="true" className="size-7" />
                            </span>
                            <p className="text-isi text-teks-utama">
                                Wajah Anda sudah terdaftar dan menunggu persetujuan pengelola. Setelah disetujui, buka
                                lagi halaman ini untuk absen.
                            </p>
                        </section>
                    ) : !disetujui ? (
                        <PanelDaftarWajah
                            Wajah={props.Wajah}
                            AlamatModelWajah={props.AlamatModelWajah}
                            JumlahFotoDaftar={props.JumlahFotoDaftar}
                            alamatDasar={AlamatDasar}
                            daring={daring}
                        />
                    ) : null}

                    {Berlogin && props.JadwalSaya ? (
                        <PanelJadwalSaya jadwal={props.JadwalSaya} alamatDasar={AlamatDasar} />
                    ) : null}

                    <KartuPasangDiHp keadaan={pasang} />

                    {Riwayat.length > 0 ? <GarisWaktuRiwayat riwayat={Riwayat} sekarang={sekarang} /> : null}

                    <div className="flex flex-wrap justify-center gap-2">
                        {BisaDashboard ? (
                            <Link
                                href="/kelola"
                                className="inline-flex h-8 items-center rounded-md border border-garis px-4 text-label font-semibold text-teks-utama pointer-coarse:h-11"
                            >
                                Buka dashboard
                            </Link>
                        ) : null}
                        {Berlogin ? (
                            <Tombol varian="sekunder" onClick={() => router.post('/keluar')}>
                                Keluar
                            </Tombol>
                        ) : null}
                    </div>
                </div>
            </main>
        </div>
    );
}
