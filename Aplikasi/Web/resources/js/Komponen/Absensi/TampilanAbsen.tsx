import { Head, Link, router } from '@inertiajs/react';
import { ClockIcon, WifiIcon, WifiOffIcon } from 'lucide-react';

import GarisWaktuRiwayat from '@/Komponen/Absensi/GarisWaktuRiwayat';
import KartuPasangDiHp from '@/Komponen/Absensi/KartuPasangDiHp';
import PanelAbsen from '@/Komponen/Absensi/PanelAbsen';
import PanelDaftarWajah from '@/Komponen/Absensi/PanelDaftarWajah';
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
 * Tampilan: kepala merek dengan jam hidup & salam, kartu aksi tumpang-tindih di bawahnya (tombol absen bulat besar,
 * lama bekerja berjalan), lalu riwayat sebagai garis waktu berdurasi.
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

    return (
        <div className="min-h-dvh bg-latar">
            <Head title={`Absen | ${NamaToko}`}>
                <link
                    rel="manifest"
                    href={`${jalur}/manifest`}
                    crossOrigin={Berlogin ? 'use-credentials' : undefined}
                />
                <meta name="apple-mobile-web-app-capable" content="yes" />
                <meta name="apple-mobile-web-app-title" content="Absen" />
            </Head>

            <header className="bg-brand-gelap px-4 pt-6 pb-20 text-permukaan">
                <div className="mx-auto flex w-full max-w-md flex-col gap-5">
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
                    </div>
                    <div className="flex items-end justify-between gap-3">
                        <p
                            className="text-sorotan-hp font-semibold tabular-nums text-permukaan"
                            aria-label="Jam sekarang"
                        >
                            {formatJamDetik.format(sekarang)}
                        </p>
                        <p className="pb-1 text-right text-keterangan text-brand-gelap-teks">
                            {formatHariPanjang.format(sekarang)}
                        </p>
                    </div>
                </div>
            </header>

            <main className="mx-auto -mt-12 flex w-full max-w-md flex-col gap-5 px-4 pb-8 tepi-bawah-aman">
                {!daring ? (
                    <Pemberitahuan jenis="peringatan">
                        <span className="inline-flex items-center gap-2">
                            <WifiOffIcon aria-hidden="true" className="size-4 shrink-0" />
                            Tidak ada koneksi internet. Absen butuh internet karena lokasi dan wajah diperiksa server.
                        </span>
                    </Pemberitahuan>
                ) : null}

                {Wajah?.Status === 'Disetujui' ? (
                    <PanelAbsen
                        AbsensiTerbuka={props.AbsensiTerbuka}
                        AlamatModelWajah={props.AlamatModelWajah}
                        SimpanFoto={props.SimpanFoto}
                        alamatDasar={AlamatDasar}
                        daring={daring}
                        sekarang={sekarang}
                        mesin={mesin}
                    />
                ) : Wajah?.Status === 'Menunggu' ? (
                    <section className="flex flex-col items-center gap-3 rounded-panel border border-garis bg-permukaan p-6 text-center">
                        <span className="grid size-14 place-items-center rounded-full bg-info-lembut text-info">
                            <ClockIcon aria-hidden="true" className="size-7" />
                        </span>
                        <p className="text-isi text-teks-utama">
                            Wajah Anda sudah terdaftar dan menunggu persetujuan pengelola. Setelah disetujui, buka lagi
                            halaman ini untuk absen.
                        </p>
                    </section>
                ) : (
                    <PanelDaftarWajah
                        Wajah={props.Wajah}
                        AlamatModelWajah={props.AlamatModelWajah}
                        JumlahFotoDaftar={props.JumlahFotoDaftar}
                        alamatDasar={AlamatDasar}
                        daring={daring}
                    />
                )}

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
            </main>
        </div>
    );
}
