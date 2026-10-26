import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import { Card, CardContent, CardHeader, CardTitle } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BidangPengaturanGerbang, OpsiPenyediaGerbang, PropsGerbangPembayaran } from '@/Tipe/Pembayaran';

const alamat = '/kelola/pembayaran/gerbang';

const jenisStatusUji = { BelumDiuji: 'peringatan', Berhasil: 'sukses', Gagal: 'bahaya' } as const;

type IsianGerbang = {
    Penyedia: string;
    Lingkungan: string;
    Pengaturan: Record<string, string>;
    Kredensial: Record<string, string>;
};

/** Isian awal bidang pengaturan: nilai tersimpan (penyedia sama), nilai bawaan penyedia, atau opsi pertama. */
export function IsiAwalPengaturan(
    bidang: BidangPengaturanGerbang[],
    tersimpan: Record<string, string | number> | null,
): Record<string, string> {
    return Object.fromEntries(
        bidang.map((b) => [b.Kunci, String(tersimpan?.[b.Kunci] ?? b.Bawaan ?? b.Opsi?.[0] ?? '')]),
    );
}

/** Audit kemudahan pakai #24: langkah menghubungkan QRIS tanpa istilah teknis, ditandai selesai menurut status. */
export function LangkahQris(gerbang: PropsGerbangPembayaran['Gerbang']): { Teks: string; Selesai: boolean }[] {
    return [
        {
            Teks: 'Daftar akun merchant DOKU, lalu salin Client ID dan Secret key dari dasbor DOKU.',
            Selesai: gerbang !== null,
        },
        {
            Teks: 'Tempel Client ID dan Secret key di bawah, lalu klik "Simpan akun merchant".',
            Selesai: gerbang !== null,
        },
        {
            Teks: 'Klik "Uji koneksi" untuk memastikan kunci benar.',
            Selesai: gerbang?.StatusUji === 'Berhasil' || gerbang?.Aktif === true,
        },
        {
            Teks: 'Klik "Aktifkan gerbang", lalu salin URL webhook ke dasbor DOKU agar pembayaran terkonfirmasi otomatis.',
            Selesai: gerbang?.Aktif === true,
        },
    ];
}

function KredensialKosong(penyedia: OpsiPenyediaGerbang | undefined): Record<string, string> {
    return Object.fromEntries((penyedia?.BidangKredensial ?? []).map((b) => [b.Kunci, '']));
}

/**
 * Gerbang pembayaran QRIS dinamis milik toko (F-08, PRD v2.06): toko memakai akun merchant sendiri sehingga dana
 * pelanggan langsung masuk ke rekening toko. Penyedianya DOKU (satu-satunya yang tersedia): isi kredensial dari dasbor
 * DOKU, uji koneksi, aktifkan, lalu salin URL webhook ke dasbor DOKU. Pilihan penyedia baru tampil bila server
 * menyediakan lebih dari satu penyedia.
 */
export default function HalamanGerbangPembayaran({
    AktivasiQrisTersedia = false,
    Gerbang,
    DaftarPenyedia,
    DaftarLingkungan,
}: PropsGerbangPembayaran) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galatUmum = (props.errors as Record<string, string | undefined>).Umum;
    const penyediaAwal = Gerbang?.Penyedia ?? DaftarPenyedia[0]?.Nilai ?? '';
    const formulir = useForm<IsianGerbang>({
        Penyedia: penyediaAwal,
        Lingkungan: Gerbang?.Lingkungan ?? DaftarLingkungan[0]?.Nilai ?? 'Sandbox',
        Pengaturan: IsiAwalPengaturan(
            DaftarPenyedia.find((p) => p.Nilai === penyediaAwal)?.BidangPengaturan ?? [],
            Gerbang?.Pengaturan ?? null,
        ),
        Kredensial: KredensialKosong(DaftarPenyedia.find((p) => p.Nilai === penyediaAwal)),
    });
    const [memproses, AturMemproses] = useState<'uji' | 'status' | null>(null);
    const [tersalin, AturTersalin] = useState(false);
    const penyedia = DaftarPenyedia.find((p) => p.Nilai === formulir.data.Penyedia);
    // Kredensial tersimpan hanya berlaku untuk penyedia yang sama (ganti penyedia = isi ulang).
    const penyediaTersimpan = Gerbang !== null && formulir.data.Penyedia === Gerbang.Penyedia;
    const galat = formulir.errors as Record<string, string | undefined>;

    const GantiPenyedia = (nilai: string) => {
        const baru = DaftarPenyedia.find((p) => p.Nilai === nilai);
        if (!baru) {
            return;
        }
        const sama = Gerbang !== null && nilai === Gerbang.Penyedia;
        formulir.setData('Penyedia', nilai);
        formulir.setData('Pengaturan', IsiAwalPengaturan(baru.BidangPengaturan, sama ? Gerbang.Pengaturan : null));
        formulir.setData('Kredensial', KredensialKosong(baru));
    };

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(alamat, {
            preserveScroll: true,
            onSuccess: () => formulir.setData('Kredensial', KredensialKosong(penyedia)),
        });
    };

    const Jalankan = (aksi: 'uji' | 'aktifkan' | 'nonaktifkan') => {
        router.post(
            `${alamat}/${aksi}`,
            {},
            {
                preserveScroll: true,
                onStart: () => AturMemproses(aksi === 'uji' ? 'uji' : 'status'),
                onFinish: () => AturMemproses(null),
            },
        );
    };

    const Salin = (teks: string) => {
        void navigator.clipboard
            ?.writeText(teks)
            .then(() => AturTersalin(true))
            .catch(() => AturTersalin(false));
    };

    return (
        <TataLetakAplikasi judul="Gerbang pembayaran">
            <div className="grid gap-4">
                <Pemberitahuan jenis="info" judul="Hubungkan QRIS dalam 4 langkah">
                    <ol className="mt-1 grid list-decimal gap-1 pl-5" aria-label="Langkah menghubungkan QRIS">
                        {LangkahQris(Gerbang).map((langkah) => (
                            <li key={langkah.Teks} className={langkah.Selesai ? 'text-teks-sekunder line-through' : ''}>
                                {langkah.Teks}
                                {langkah.Selesai ? ' (selesai)' : ''}
                            </li>
                        ))}
                    </ol>
                    <p className="mt-2">
                        Uang pelanggan langsung masuk ke rekening toko Anda, tidak lewat Payoung. Pilihan bayar
                        &quot;QRIS&quot; di kasir dibuat otomatis saat QRIS diaktifkan. Biaya MDR mengikuti ketentuan
                        Bank Indonesia dan ditagih penyedia ke toko.
                    </p>
                </Pemberitahuan>
                {galatUmum ? <Pemberitahuan jenis="bahaya">{galatUmum}</Pemberitahuan> : null}
                {AktivasiQrisTersedia ? (
                    <Pemberitahuan jenis="info" judul="Belum punya akun merchant DOKU?">
                        Payoung bisa mendaftarkannya untuk Anda. Cukup foto KTP, foto selfie, foto tempat usaha, dan
                        nomor rekening toko.{' '}
                        <Link href="/kelola/pembayaran/aktivasi-qris" className="font-semibold underline">
                            Aktifkan QRIS otomatis
                        </Link>
                    </Pemberitahuan>
                ) : null}

                {Gerbang ? (
                    <Card className="rounded-panel shadow-none">
                        <CardHeader>
                            <CardTitle className="text-subjudul text-teks-utama">Status gerbang</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-isi font-semibold text-teks-utama">{Gerbang.LabelPenyedia}</span>
                                <LabelStatus jenis={jenisStatusUji[Gerbang.StatusUji]} teks={Gerbang.LabelStatusUji} />
                                <LabelStatus
                                    jenis={Gerbang.Aktif ? 'sukses' : 'netral'}
                                    teks={Gerbang.Aktif ? 'Aktif' : 'Nonaktif'}
                                />
                            </div>
                            {!Gerbang.PenyediaDiizinkan ? (
                                <Pemberitahuan jenis="peringatan">
                                    Penyedia ini sedang tidak tersedia dari platform. Kasir tidak bisa membuat QRIS
                                    dinamis baru. Hubungi dukungan Payoung.
                                </Pemberitahuan>
                            ) : null}
                            {Gerbang.PesanUji ? (
                                <p className="text-keterangan text-teks-sekunder">
                                    Hasil uji terakhir
                                    {Gerbang.DiujiPada ? ` (${FormatTanggalWaktu(Gerbang.DiujiPada)})` : ''}:{' '}
                                    {Gerbang.PesanUji}
                                </p>
                            ) : null}
                            <div className="grid gap-1">
                                <span className="text-label font-semibold text-teks-utama">URL webhook</span>
                                <p className="text-keterangan text-teks-sekunder">
                                    Salin ke pengaturan notifikasi/callback di dasbor {Gerbang.LabelPenyedia} agar
                                    pembayaran langsung terkonfirmasi di kasir.
                                </p>
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                                    <code className="min-w-0 break-all rounded-kontrol border border-garis bg-latar px-2 py-1 font-mono text-keterangan text-teks-utama">
                                        {Gerbang.UrlWebhook}
                                    </code>
                                    <Tombol varian="sekunder" onClick={() => Salin(Gerbang.UrlWebhook)}>
                                        {tersalin ? 'Tersalin' : 'Salin URL'}
                                    </Tombol>
                                </div>
                                <p className="text-keterangan text-teks-sekunder">
                                    Notifikasi sah terakhir:{' '}
                                    {Gerbang.WebhookDiterimaPada
                                        ? FormatTanggalWaktu(Gerbang.WebhookDiterimaPada)
                                        : 'belum ada'}
                                    {Gerbang.WebhookDitolakPada
                                        ? ` | Ditolak (tanda tangan salah) terakhir: ${FormatTanggalWaktu(Gerbang.WebhookDitolakPada)}`
                                        : ''}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Tombol
                                    varian="sekunder"
                                    memproses={memproses === 'uji'}
                                    disabled={memproses !== null || formulir.isDirty}
                                    onClick={() => Jalankan('uji')}
                                >
                                    Uji koneksi
                                </Tombol>
                                {Gerbang.Aktif ? (
                                    <Tombol
                                        varian="bahaya"
                                        memproses={memproses === 'status'}
                                        disabled={memproses !== null}
                                        onClick={() => Jalankan('nonaktifkan')}
                                    >
                                        Nonaktifkan gerbang
                                    </Tombol>
                                ) : (
                                    <Tombol
                                        memproses={memproses === 'status'}
                                        disabled={
                                            memproses !== null ||
                                            formulir.isDirty ||
                                            Gerbang.StatusUji !== 'Berhasil' ||
                                            !Gerbang.PenyediaDiizinkan
                                        }
                                        onClick={() => Jalankan('aktifkan')}
                                    >
                                        Aktifkan gerbang
                                    </Tombol>
                                )}
                            </div>
                            {!Gerbang.Aktif && Gerbang.StatusUji !== 'Berhasil' ? (
                                <p className="text-keterangan text-teks-sekunder">
                                    Gerbang bisa diaktifkan setelah uji koneksi berhasil.
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>
                ) : null}

                <Card className="rounded-panel shadow-none">
                    <CardHeader>
                        <CardTitle className="text-subjudul text-teks-utama">
                            {Gerbang ? 'Ubah akun merchant' : 'Hubungkan akun merchant'}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {DaftarPenyedia.length === 0 ? (
                            <Pemberitahuan jenis="peringatan">
                                Belum ada penyedia gerbang pembayaran yang tersedia. Hubungi dukungan Payoung.
                            </Pemberitahuan>
                        ) : (
                            <form onSubmit={Simpan} className="grid gap-3 sm:grid-cols-2" noValidate>
                                {DaftarPenyedia.length > 1 ? (
                                    <BidangPilihan
                                        label="Penyedia"
                                        nilai={formulir.data.Penyedia}
                                        opsi={DaftarPenyedia.map((p) => ({ Nilai: p.Nilai, Label: p.Label }))}
                                        saatBerubah={GantiPenyedia}
                                        galat={galat.Penyedia}
                                        required
                                    />
                                ) : null}
                                <BidangPilihan
                                    label="Lingkungan"
                                    nilai={formulir.data.Lingkungan}
                                    opsi={DaftarLingkungan}
                                    saatBerubah={(nilai) => formulir.setData('Lingkungan', nilai)}
                                    galat={galat.Lingkungan}
                                    required
                                />
                                {penyedia?.Keterangan ? (
                                    <div className="sm:col-span-2">
                                        <Pemberitahuan jenis="info">{penyedia.Keterangan}</Pemberitahuan>
                                    </div>
                                ) : null}
                                {(penyedia?.BidangPengaturan ?? []).map((bidang) =>
                                    bidang.Jenis === 'Pilihan' ? (
                                        <BidangPilihan
                                            key={bidang.Kunci}
                                            label={bidang.Label}
                                            nilai={formulir.data.Pengaturan[bidang.Kunci] ?? ''}
                                            opsi={(bidang.Opsi ?? []).map((opsi) => ({ Nilai: opsi, Label: opsi }))}
                                            saatBerubah={(nilai) =>
                                                formulir.setData('Pengaturan', {
                                                    ...formulir.data.Pengaturan,
                                                    [bidang.Kunci]: nilai,
                                                })
                                            }
                                            galat={galat[`Pengaturan.${bidang.Kunci}`]}
                                            required={bidang.Wajib}
                                        />
                                    ) : (
                                        <BidangTeks
                                            key={bidang.Kunci}
                                            label={bidang.Label}
                                            {...(bidang.Keterangan ? { keterangan: bidang.Keterangan } : {})}
                                            jenis={bidang.Jenis === 'Email' ? 'email' : 'text'}
                                            inputMode={bidang.Jenis === 'Angka' ? 'numeric' : undefined}
                                            nilai={formulir.data.Pengaturan[bidang.Kunci] ?? ''}
                                            saatBerubah={(nilai) =>
                                                formulir.setData('Pengaturan', {
                                                    ...formulir.data.Pengaturan,
                                                    [bidang.Kunci]: nilai,
                                                })
                                            }
                                            galat={galat[`Pengaturan.${bidang.Kunci}`]}
                                            required={bidang.Wajib}
                                        />
                                    ),
                                )}
                                {(penyedia?.BidangKredensial ?? []).map((bidang) => (
                                    <BidangTeks
                                        key={bidang.Kunci}
                                        label={bidang.Label}
                                        jenis="password"
                                        autoComplete="new-password"
                                        keterangan={
                                            penyediaTersimpan && Gerbang.PetunjukKredensial[bidang.Kunci]
                                                ? `Tersimpan ${Gerbang.PetunjukKredensial[bidang.Kunci]}. Kosongkan bila tidak diganti.`
                                                : 'Salin dari dasbor penyedia.'
                                        }
                                        nilai={formulir.data.Kredensial[bidang.Kunci] ?? ''}
                                        saatBerubah={(nilai) =>
                                            formulir.setData('Kredensial', {
                                                ...formulir.data.Kredensial,
                                                [bidang.Kunci]: nilai,
                                            })
                                        }
                                        galat={galat[`Kredensial.${bidang.Kunci}`]}
                                        required={bidang.Wajib && !penyediaTersimpan}
                                    />
                                ))}
                                <p className="text-keterangan text-teks-sekunder sm:col-span-2">
                                    Kredensial disimpan terenkripsi dan tidak pernah ditampilkan ulang. Setelah
                                    disimpan, gerbang menjadi nonaktif sampai uji koneksi berhasil.
                                </p>
                                <div className="sm:col-span-2">
                                    <Tombol type="submit" memproses={formulir.processing}>
                                        Simpan akun merchant
                                    </Tombol>
                                </div>
                            </form>
                        )}
                    </CardContent>
                </Card>
            </div>
        </TataLetakAplikasi>
    );
}
