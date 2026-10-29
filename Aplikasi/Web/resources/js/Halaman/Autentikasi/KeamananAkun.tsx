import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import TombolGoogle from '@/Komponen/Formulir/TombolGoogle';
import { Button } from '@/Komponen/Ui/button';
import Panel from '@/Komponen/Kelola/Panel';
import { Separator } from '@/Komponen/Ui/separator';
import DialogKodePemulihan from '@/Komponen/Autentikasi/DialogKodePemulihan';
import { useRahasiaSekali } from '@/Komponen/Umpan/DialogRahasia';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';

type PropsKeamananAkun = {
    Akun: {
        Email: string | null;
        EmailTerverifikasi: boolean;
        EmailDiverifikasiPada: string | null;
        /** false = akun buatan Google yang belum punya kata sandi; konfirmasi ganti email butuh kata sandi. */
        BisaGantiEmail: boolean;
    };
    Google: {
        Tersedia: boolean;
        Tertaut: boolean;
        TertautPada: string | null;
        KataSandiOtomatis: boolean;
        MasukDenganGoogle: boolean;
    };
    DuaFaktor: { Aktif: boolean; AktifPada: string | null; SisaKodePemulihan: number; Wajib: boolean };
    Aktivasi: { QrSvg: string; Rahasia: string } | null;
    KodePemulihanBaru: string[] | null;
};

/**
 * Keamanan akun (§20.2, BR-00.5, BR-00.8, D-57): satu halaman untuk semua pengaman akun. Ringkasan di kiri menunjukkan
 * mana yang sudah beres dan mana yang belum, lalu tiap pengaman punya panelnya sendiri: email, kata sandi, Google,
 * verifikasi dua langkah, dan PIN kasir.
 */
export default function HalamanKeamananAkun({
    Akun,
    DuaFaktor,
    Aktivasi,
    KodePemulihanBaru,
    Google,
}: PropsKeamananAkun) {
    const adaGoogle = Google.Tersedia || Google.Tertaut;
    const kodePemulihan = useRahasiaSekali(KodePemulihanBaru);

    return (
        <TataLetakAplikasi judul="Keamanan akun">
            <div className="grid gap-4 lg:grid-cols-[16rem_minmax(0,1fr)] lg:items-start">
                <RingkasanKeamanan akun={Akun} duaFaktor={DuaFaktor} google={Google} adaGoogle={adaGoogle} />
                <div className="flex min-w-0 flex-col gap-4">
                    <div id="email" className="scroll-mt-20">
                        <PanelEmail akun={Akun} />
                    </div>
                    <div id="kata-sandi" className="scroll-mt-20">
                        {/* D-22: ganti kata sandi sendiri tanpa keluar dulu dan memakai "Lupa kata sandi". */}
                        <Panel
                            judul="Kata sandi"
                            keterangan="Setelah kata sandi diganti, perangkat lain yang masuk dengan akun ini keluar otomatis."
                            aksi={
                                Google.KataSandiOtomatis ? (
                                    <LabelStatus jenis="peringatan" teks="Belum diatur" />
                                ) : (
                                    <LabelStatus jenis="sukses" teks="Sudah diatur" />
                                )
                            }
                        >
                            <div>
                                <Button
                                    asChild
                                    variant="outline"
                                    className="h-8 pointer-coarse:h-11 text-label font-semibold"
                                >
                                    <Link href="/ganti-kata-sandi">
                                        {Google.KataSandiOtomatis ? 'Buat kata sandi' : 'Ganti kata sandi'}
                                    </Link>
                                </Button>
                            </div>
                        </Panel>
                    </div>
                    {/* D-57: akun Google yang ditautkan masuk tanpa kode; Masuk dengan Google menggantikan verifikasi dua langkah. */}
                    {adaGoogle ? (
                        <div id="google" className="scroll-mt-20">
                            <PanelGoogle google={Google} />
                        </div>
                    ) : null}
                    <div id="dua-langkah" className="scroll-mt-20">
                        <Panel
                            judul="Verifikasi dua langkah"
                            keterangan="Selain kata sandi, masuk memerlukan kode 6 digit dari aplikasi autentikator di ponsel Anda."
                            aksi={
                                DuaFaktor.Aktif ? (
                                    <LabelStatus jenis="sukses" teks="Aktif" />
                                ) : (
                                    <LabelStatus jenis="netral" teks="Belum aktif" />
                                )
                            }
                        >
                            {DuaFaktor.Aktif && DuaFaktor.AktifPada ? (
                                <p className="text-isi text-teks-sekunder">
                                    Aktif sejak {FormatTanggalWaktu(DuaFaktor.AktifPada)}.
                                </p>
                            ) : null}
                            {DuaFaktor.Wajib && !DuaFaktor.Aktif ? (
                                <Pemberitahuan jenis="peringatan" judul="Wajib untuk peran Anda di paket ini">
                                    Aktifkan verifikasi dua langkah sebelum membuka menu lain di back-office.
                                </Pemberitahuan>
                            ) : null}
                            {Aktivasi ? <FormulirAktivasi aktivasi={Aktivasi} /> : null}
                            {DuaFaktor.Aktif ? (
                                <FormulirNonaktifkan wajib={DuaFaktor.Wajib} sisaKode={DuaFaktor.SisaKodePemulihan} />
                            ) : null}
                        </Panel>
                    </div>
                    {/* F-02b: PIN kasir untuk masuk cepat di aplikasi kasir. */}
                    <div id="pin-kasir" className="scroll-mt-20">
                        <Panel judul="PIN kasir" keterangan="PIN 6 angka untuk masuk cepat di perangkat kasir bersama.">
                            <div>
                                <Button
                                    asChild
                                    variant="outline"
                                    className="h-8 pointer-coarse:h-11 text-label font-semibold"
                                >
                                    <Link href="/kelola/keamanan/pin">Atur PIN kasir</Link>
                                </Button>
                            </div>
                        </Panel>
                    </div>
                </div>
            </div>
            {kodePemulihan.aktif ? (
                <DialogKodePemulihan kode={kodePemulihan.aktif} saatSelesai={kodePemulihan.selesai} />
            ) : null}
        </TataLetakAplikasi>
    );
}

type BarisRingkasan = { id: string; label: string; jenis: 'sukses' | 'peringatan' | 'netral'; teks: string };

function RingkasanKeamanan({
    akun,
    duaFaktor,
    google,
    adaGoogle,
}: {
    akun: PropsKeamananAkun['Akun'];
    duaFaktor: PropsKeamananAkun['DuaFaktor'];
    google: PropsKeamananAkun['Google'];
    adaGoogle: boolean;
}) {
    const baris: BarisRingkasan[] = [
        {
            id: 'email',
            label: 'Email akun',
            jenis: akun.EmailTerverifikasi ? 'sukses' : 'peringatan',
            teks: akun.EmailTerverifikasi ? 'Terverifikasi' : 'Belum terverifikasi',
        },
        {
            id: 'kata-sandi',
            label: 'Kata sandi',
            jenis: google.KataSandiOtomatis ? 'peringatan' : 'sukses',
            teks: google.KataSandiOtomatis ? 'Belum diatur' : 'Sudah diatur',
        },
        ...(adaGoogle
            ? [
                  {
                      id: 'google',
                      label: 'Akun Google',
                      jenis: google.Tertaut ? 'sukses' : 'netral',
                      teks: google.Tertaut ? 'Tertaut' : 'Belum ditautkan',
                  } satisfies BarisRingkasan,
              ]
            : []),
        {
            id: 'dua-langkah',
            label: 'Verifikasi dua langkah',
            jenis: duaFaktor.Aktif ? 'sukses' : duaFaktor.Wajib ? 'peringatan' : 'netral',
            teks: duaFaktor.Aktif ? 'Aktif' : duaFaktor.Wajib ? 'Wajib diaktifkan' : 'Belum aktif',
        },
        { id: 'pin-kasir', label: 'PIN kasir', jenis: 'netral', teks: 'Opsional' },
    ];
    // PIN kasir opsional, jadi tidak ikut dihitung.
    const hitung = baris.filter((b) => b.id !== 'pin-kasir');
    const beres = hitung.filter((b) => b.jenis === 'sukses').length;

    return (
        <nav aria-label="Ringkasan keamanan" className="lg:sticky lg:top-20">
            <Panel judul="Ringkasan" keterangan={`${beres} dari ${hitung.length} pengaman sudah beres.`}>
                <ul className="flex flex-col divide-y divide-garis">
                    {baris.map((b) => (
                        <li key={b.id}>
                            <a
                                href={`#${b.id}`}
                                className="flex min-h-11 items-center justify-between gap-2 py-2 text-isi text-teks-utama hover:text-brand"
                            >
                                <span>{b.label}</span>
                                <LabelStatus jenis={b.jenis} teks={b.teks} />
                            </a>
                        </li>
                    ))}
                </ul>
            </Panel>
        </nav>
    );
}

/** BR-00.5: email akun, status verifikasi, dan ganti email lewat tautan konfirmasi yang dikirim ke alamat baru. */
function PanelEmail({ akun }: { akun: PropsKeamananAkun['Akun'] }) {
    const kirimUlang = useForm({});
    const formulir = useForm({ Email: '', KataSandi: '' });

    const KirimUlangVerifikasi = () => kirimUlang.post('/verifikasi-email/kirim-ulang', { preserveScroll: true });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/kelola/keamanan/email', {
            preserveScroll: true,
            onSuccess: () => formulir.reset('Email', 'KataSandi'),
            onFinish: () => formulir.reset('KataSandi'),
        });
    };

    return (
        <Panel
            judul="Email akun"
            keterangan="Email dipakai untuk masuk, tautan lupa kata sandi, dan pengingat tagihan langganan."
            aksi={
                akun.EmailTerverifikasi ? (
                    <LabelStatus jenis="sukses" teks="Terverifikasi" />
                ) : (
                    <LabelStatus jenis="peringatan" teks="Belum terverifikasi" />
                )
            }
        >
            <p className="text-isi text-teks-utama">
                <span className="font-semibold break-all">{akun.Email ?? '-'}</span>
                {akun.EmailTerverifikasi && akun.EmailDiverifikasiPada
                    ? ` | diverifikasi ${FormatTanggalWaktu(akun.EmailDiverifikasiPada)}`
                    : null}
            </p>
            {!akun.EmailTerverifikasi ? (
                <Pemberitahuan jenis="peringatan" judul="Email belum terverifikasi">
                    <p>
                        Buka tautan verifikasi yang kami kirim ke email ini. Belum menerimanya, atau emailnya salah
                        ketik? Kirim ulang tautan, atau ganti email di bawah.
                    </p>
                    <div className="mt-2">
                        <Tombol varian="sekunder" memproses={kirimUlang.processing} onClick={KirimUlangVerifikasi}>
                            Kirim ulang tautan
                        </Tombol>
                    </div>
                </Pemberitahuan>
            ) : null}
            <Separator className="bg-garis" />
            <h3 className="text-label font-semibold text-teks-utama">Ganti email</h3>
            {akun.BisaGantiEmail ? (
                <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                    <BidangTeks
                        label="Email baru"
                        jenis="email"
                        autoComplete="email"
                        keterangan="Kami kirim tautan konfirmasi ke alamat ini. Email akun baru berganti setelah tautannya dibuka."
                        nilai={formulir.data.Email}
                        saatBerubah={(nilai) => formulir.setData('Email', nilai)}
                        galat={formulir.errors.Email}
                        required
                    />
                    <BidangTeks
                        label="Kata sandi saat ini"
                        jenis="password"
                        autoComplete="current-password"
                        nilai={formulir.data.KataSandi}
                        saatBerubah={(nilai) => formulir.setData('KataSandi', nilai)}
                        galat={formulir.errors.KataSandi}
                        required
                    />
                    <div>
                        <Tombol type="submit" memproses={formulir.processing}>
                            Kirim tautan konfirmasi
                        </Tombol>
                    </div>
                </form>
            ) : (
                <Pemberitahuan jenis="info" judul="Atur kata sandi dulu">
                    <p>
                        Akun ini dibuat lewat Google dan belum punya kata sandi. Atur kata sandi supaya bisa mengganti
                        email.
                    </p>
                    <div className="mt-2">
                        <Button asChild variant="outline" className="h-8 pointer-coarse:h-11 text-label font-semibold">
                            <Link href="/ganti-kata-sandi">Atur kata sandi dulu</Link>
                        </Button>
                    </div>
                </Pemberitahuan>
            )}
        </Panel>
    );
}

function PanelGoogle({ google }: { google: PropsKeamananAkun['Google'] }) {
    const formulir = useForm({});

    return (
        <Panel
            judul="Masuk dengan Google"
            keterangan="Akun Google yang ditautkan bisa dipakai masuk tanpa kata sandi dan tanpa kode verifikasi dua langkah."
            aksi={
                google.Tertaut ? (
                    <LabelStatus jenis="sukses" teks="Tertaut" />
                ) : (
                    <LabelStatus jenis="netral" teks="Belum ditautkan" />
                )
            }
        >
            <p className="text-label font-semibold text-teks-utama">
                Status:{' '}
                {google.Tertaut
                    ? `Tertaut${google.TertautPada ? ` sejak ${FormatTanggalWaktu(google.TertautPada)}` : ''}`
                    : 'Belum ditautkan'}
            </p>
            {google.Tertaut ? (
                <>
                    {google.KataSandiOtomatis ? (
                        <Pemberitahuan jenis="info" judul="Kata sandi belum diatur">
                            Akun ini dibuat lewat Google, jadi belum punya kata sandi. Atur kata sandi dulu supaya Anda
                            tetap bisa masuk bila ingin melepas Google.
                        </Pemberitahuan>
                    ) : null}
                    <div className="flex flex-wrap gap-2">
                        {google.KataSandiOtomatis ? (
                            <Button
                                asChild
                                variant="outline"
                                className="h-8 pointer-coarse:h-11 text-label font-semibold"
                            >
                                <Link href="/ganti-kata-sandi">Atur kata sandi</Link>
                            </Button>
                        ) : (
                            <Tombol
                                varian="bahaya"
                                memproses={formulir.processing}
                                onClick={() => formulir.delete('/kelola/keamanan/google')}
                            >
                                Lepas tautan Google
                            </Tombol>
                        )}
                    </div>
                </>
            ) : (
                <div>
                    <TombolGoogle href="/masuk/google?tujuan=tautkan">Tautkan akun Google</TombolGoogle>
                </div>
            )}
        </Panel>
    );
}

function FormulirAktivasi({ aktivasi }: { aktivasi: { QrSvg: string; Rahasia: string } }) {
    const formulir = useForm({ Kode: '' });
    const sumberQr = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(aktivasi.QrSvg)}`;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/kelola/keamanan/dua-faktor', { onFinish: () => formulir.reset('Kode') });
    };

    return (
        <div className="flex flex-col gap-4">
            <ol className="flex list-decimal flex-col gap-1 pl-5 text-isi text-teks-utama">
                <li>Pasang aplikasi autentikator, misalnya Google Authenticator atau Aegis.</li>
                <li>Pindai kode QR di bawah dengan aplikasi tersebut.</li>
                <li>Masukkan 6 digit kode yang muncul di aplikasi.</li>
            </ol>
            <img src={sumberQr} alt="Kode QR verifikasi dua langkah" width={192} height={192} className="self-start" />
            <p className="text-keterangan text-teks-sekunder">
                Tidak bisa memindai? Masukkan kunci ini secara manual:
                <span className="mt-1 block font-mono text-label text-teks-utama">{aktivasi.Rahasia}</span>
            </p>
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeks
                    label="Kode 6 digit"
                    kode
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    maxLength={6}
                    nilai={formulir.data.Kode}
                    saatBerubah={(nilai) => formulir.setData('Kode', nilai)}
                    galat={formulir.errors.Kode}
                    required
                />
                <Tombol type="submit" memproses={formulir.processing}>
                    Aktifkan verifikasi dua langkah
                </Tombol>
            </form>
        </div>
    );
}

function FormulirNonaktifkan({ wajib, sisaKode }: { wajib: boolean; sisaKode: number }) {
    const formulir = useForm({ KataSandi: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.delete('/kelola/keamanan/dua-faktor', { onFinish: () => formulir.reset('KataSandi') });
    };

    return (
        <div className="flex flex-col gap-3">
            <Separator className="bg-garis" />
            <p className="text-isi text-teks-sekunder">
                Sisa kode pemulihan: <span className="tabular-nums">{sisaKode}</span> dari 8.
                {sisaKode <= 2 ? ' Nonaktifkan lalu aktifkan lagi untuk mendapat kode baru.' : null}
            </p>
            {wajib ? (
                <p className="text-isi text-teks-sekunder">
                    Paket langganan usaha tempat Anda bergabung mewajibkan verifikasi dua langkah untuk peran Anda
                    (Owner, Admin, atau Akuntan), jadi tidak bisa dinonaktifkan.
                </p>
            ) : (
                <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                    <BidangTeks
                        label="Kata sandi saat ini"
                        jenis="password"
                        autoComplete="current-password"
                        keterangan="Untuk keamanan, konfirmasi kata sandi sebelum menonaktifkan."
                        nilai={formulir.data.KataSandi}
                        saatBerubah={(nilai) => formulir.setData('KataSandi', nilai)}
                        galat={formulir.errors.KataSandi}
                        required
                    />
                    <Tombol type="submit" varian="bahaya" memproses={formulir.processing}>
                        Nonaktifkan verifikasi dua langkah
                    </Tombol>
                </form>
            )}
        </div>
    );
}
