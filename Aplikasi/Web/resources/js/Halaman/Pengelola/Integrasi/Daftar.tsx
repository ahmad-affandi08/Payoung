import { router, useForm, usePage } from '@inertiajs/react';
import { useId, useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/Komponen/Ui/alert-dialog';
import { Button } from '@/Komponen/Ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Komponen/Ui/card';
import { Label } from '@/Komponen/Ui/label';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/Komponen/Ui/sheet';
import { Switch } from '@/Komponen/Ui/switch';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, PunyaIzin, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

type BidangPengaturan = {
    Kunci: string;
    Label: string;
    Jenis: 'Teks' | 'Angka' | 'Email' | 'Url' | 'Pilihan';
    Wajib: boolean;
    Opsi?: string[];
    Bawaan?: string | number;
    Keterangan?: string;
};

type BidangKredensial = { Kunci: string; Label: string; Wajib: boolean };

/** v2.04: penyedia yang bisa dipilih untuk satu jenis integrasi. */
type OpsiPenyedia = {
    Nilai: string;
    Label: string;
    Keterangan: string;
    Resmi: boolean;
    BidangPengaturan: BidangPengaturan[];
    BidangKredensial: BidangKredensial[];
};

type SlotIntegrasi = {
    Jenis: string;
    LabelJenis: string;
    Lingkungan: 'Staging' | 'Produksi';
    LingkunganServer: boolean;
    Penyedia: { Nilai: string; Label: string };
    BidangPengaturan: BidangPengaturan[];
    BidangKredensial: BidangKredensial[];
    /** Kosong/tidak ada = hanya penyedia terpasang (data lama). */
    DaftarPenyedia?: OpsiPenyedia[];
    Konfigurasi: {
        Uuid: string;
        Pengaturan: Record<string, string | number>;
        PetunjukKredensial: Record<string, string>;
        Aktif: boolean;
        Status: 'BelumDiuji' | 'Terhubung' | 'Gagal';
        LabelStatus: string;
        TerakhirDiujiPada: string | null;
        HasilUji: { Berhasil: boolean; Pesan: string; DurasiMs: number } | null;
        KredensialDiubahPada: string;
        RotasiSetiapHari: number;
        PerluRotasi: boolean;
        /** D-159: kunci publik RSA SNAP (bukan rahasia); kunci pribadinya tidak pernah dikirim ke peramban. */
        KunciPublikSnap?: string | null;
    } | null;
};

const jenisLabelStatus = { BelumDiuji: 'peringatan', Terhubung: 'sukses', Gagal: 'bahaya' } as const;

/** v2.06: penyedia gerbang pembayaran yang boleh dipilih tenant + pemakaian (tanpa kredensial tenant). */
type GerbangTenant = {
    Penyedia: string;
    Label: string;
    Diizinkan: boolean;
    JumlahTenant: number;
    JumlahAktif: number;
    JumlahUjiGagal: number;
    WebhookDiterima24Jam: number;
    WebhookDitolak24Jam: number;
};

/**
 * Konfigurasi integrasi platform (P-05): email (banyak penyedia SMTP), CAPTCHA, penyimpanan objek, gerbang pembayaran
 * QRIS dinamis, dan WhatsApp (resmi & tidak resmi). Penyedia dipilih per jenis & lingkungan (v2.04).
 */
export default function HalamanIntegrasi({
    Integrasi,
    GerbangTenant = [],
}: {
    Integrasi: SlotIntegrasi[];
    GerbangTenant?: GerbangTenant[];
}) {
    const { props } = usePage<PropsBersamaPengelola>();
    const bolehKelola = PunyaIzin(props.Pengguna, IzinPengelola.IntegrasiKelola);
    const daftarJenis = [...new Set(Integrasi.map((slot) => slot.Jenis))];

    return (
        <TataLetakPengelola judul="Integrasi">
            <p className="text-isi text-teks-sekunder">
                Kredensial disimpan terenkripsi dan tidak pernah ditampilkan ulang. Setiap perubahan harus diuji sebelum
                integrasi bisa diaktifkan. Server ini memakai konfigurasi yang bertanda &ldquo;Dipakai server
                ini&rdquo;.
            </p>
            {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}
            {GerbangTenant.length > 0 ? <BagianGerbangTenant daftar={GerbangTenant} bolehKelola={bolehKelola} /> : null}
            {daftarJenis.map((jenis) => {
                const slot = Integrasi.filter((baris) => baris.Jenis === jenis);

                return (
                    <section key={jenis} className="flex flex-col gap-3">
                        <h2 className="text-subjudul font-semibold text-teks-utama">{slot[0]?.LabelJenis}</h2>
                        <div className="grid gap-4 lg:grid-cols-2">
                            {slot.map((baris) => (
                                <KartuIntegrasi key={baris.Lingkungan} slot={baris} bolehKelola={bolehKelola} />
                            ))}
                        </div>
                    </section>
                );
            })}
        </TataLetakPengelola>
    );
}

/**
 * v2.06: gerbang pembayaran QRIS dinamis diatur tiap toko dengan akun merchant sendiri (dana langsung ke rekening toko).
 * Platform hanya menentukan penyedia yang boleh dipilih. Melarang penyedia wajib beralasan; toko yang memakainya tidak
 * bisa membuat tagihan QRIS baru. Kredensial toko tidak pernah tampil di sini.
 */
function BagianGerbangTenant({ daftar, bolehKelola }: { daftar: GerbangTenant[]; bolehKelola: boolean }) {
    const [larang, AturLarang] = useState<GerbangTenant | null>(null);
    const [alasan, AturAlasan] = useState('');
    const [memproses, AturMemproses] = useState<string | null>(null);
    const { props } = usePage<PropsBersamaPengelola>();
    const Ubah = (baris: GerbangTenant, diizinkan: boolean, alasanLarang = '') => {
        router.post(
            `/integrasi/gerbang-pembayaran/${baris.Penyedia}`,
            { Diizinkan: diizinkan, Alasan: alasanLarang },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(baris.Penyedia),
                onFinish: () => AturMemproses(null),
                onSuccess: () => {
                    AturLarang(null);
                    AturAlasan('');
                },
            },
        );
    };

    return (
        <section className="flex flex-col gap-3">
            <h2 className="text-subjudul font-semibold text-teks-utama">Gerbang pembayaran untuk toko</h2>
            <p className="text-keterangan text-teks-sekunder">
                Setiap toko menghubungkan akun merchant miliknya sendiri di back-office, sehingga dana pelanggan
                langsung masuk ke rekening toko. Di sini hanya diatur penyedia yang boleh dipilih toko.
            </p>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {daftar.map((baris) => (
                    <Card key={baris.Penyedia} className="gap-2 py-4 rounded-panel shadow-none">
                        <CardHeader className="flex flex-wrap items-center gap-2 px-4">
                            <CardTitle>
                                <h3 className="text-label font-semibold text-teks-utama">{baris.Label}</h3>
                            </CardTitle>
                            <LabelStatus
                                jenis={baris.Diizinkan ? 'sukses' : 'netral'}
                                teks={baris.Diizinkan ? 'Bisa dipilih toko' : 'Dilarang'}
                            />
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2 px-4">
                            <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-keterangan">
                                <dt className="text-teks-sekunder">Toko terhubung</dt>
                                <dd className="text-right tabular-nums text-teks-utama">{baris.JumlahTenant}</dd>
                                <dt className="text-teks-sekunder">Aktif</dt>
                                <dd className="text-right tabular-nums text-teks-utama">{baris.JumlahAktif}</dd>
                                <dt className="text-teks-sekunder">Uji gagal</dt>
                                <dd className="text-right tabular-nums text-teks-utama">{baris.JumlahUjiGagal}</dd>
                                <dt className="text-teks-sekunder">Webhook sah 24 jam</dt>
                                <dd className="text-right tabular-nums text-teks-utama">
                                    {baris.WebhookDiterima24Jam}
                                </dd>
                                <dt className="text-teks-sekunder">Webhook ditolak 24 jam</dt>
                                <dd className="text-right tabular-nums text-teks-utama">{baris.WebhookDitolak24Jam}</dd>
                            </dl>
                            {bolehKelola ? (
                                baris.Diizinkan ? (
                                    <Tombol
                                        varian="sekunder"
                                        disabled={memproses !== null}
                                        onClick={() => {
                                            AturLarang(baris);
                                            AturAlasan('');
                                        }}
                                    >
                                        Larang {baris.Label}
                                    </Tombol>
                                ) : (
                                    <Tombol
                                        varian="sekunder"
                                        memproses={memproses === baris.Penyedia}
                                        disabled={memproses !== null}
                                        onClick={() => Ubah(baris, true)}
                                    >
                                        Izinkan {baris.Label}
                                    </Tombol>
                                )
                            ) : null}
                        </CardContent>
                    </Card>
                ))}
            </div>
            {larang ? (
                <Sheet
                    open
                    onOpenChange={(terbuka) => {
                        if (!terbuka) {
                            AturLarang(null);
                        }
                    }}
                >
                    <SheetContent className="w-full overflow-y-auto sm:max-w-md">
                        <SheetHeader>
                            <SheetTitle className="text-subjudul text-teks-utama">Larang {larang.Label}</SheetTitle>
                            <SheetDescription>
                                {larang.JumlahAktif > 0
                                    ? `${larang.JumlahAktif} toko aktif memakai penyedia ini dan tidak akan bisa membuat tagihan QRIS baru.`
                                    : 'Toko tidak bisa lagi memilih penyedia ini.'}
                            </SheetDescription>
                        </SheetHeader>
                        <form
                            className="grid gap-3 px-4"
                            noValidate
                            onSubmit={(peristiwa) => {
                                peristiwa.preventDefault();
                                Ubah(larang, false, alasan);
                            }}
                        >
                            <BidangTeks
                                label="Alasan"
                                nilai={alasan}
                                saatBerubah={AturAlasan}
                                galat={props.errors.Alasan}
                                required
                            />
                            <SheetFooter className="flex-row px-0">
                                <Tombol type="submit" varian="bahaya" memproses={memproses === larang.Penyedia}>
                                    Larang penyedia
                                </Tombol>
                                <Tombol varian="sekunder" onClick={() => AturLarang(null)}>
                                    Batal
                                </Tombol>
                            </SheetFooter>
                        </form>
                    </SheetContent>
                </Sheet>
            ) : null}
        </section>
    );
}

function KartuIntegrasi({ slot, bolehKelola }: { slot: SlotIntegrasi; bolehKelola: boolean }) {
    const [sunting, AturSunting] = useState(false);
    const [alasan, AturAlasan] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const konfigurasi = slot.Konfigurasi;
    const produksi = slot.Lingkungan === 'Produksi';
    const { props } = usePage<PropsBersamaPengelola>();
    const [kartuTerakhir, AturKartuTerakhir] = useState(false);
    // Galat alasan (BR-P05.2) hanya ditampilkan di kartu yang baru saja dikirim.
    const galatAlasan = kartuTerakhir ? props.errors.Alasan : undefined;
    const opsiKirim = {
        preserveScroll: true,
        onStart: () => AturMemproses(true),
        onFinish: () => AturMemproses(false),
        onSuccess: () => {
            AturAlasan('');
            AturKartuTerakhir(false);
        },
    };
    const idSaklar = useId();
    const UbahStatus = (aksi: 'aktifkan' | 'nonaktifkan') => {
        if (konfigurasi) {
            AturKartuTerakhir(true);
            router.post(`/integrasi/${konfigurasi.Uuid}/${aksi}`, { Alasan: alasan }, opsiKirim);
        }
    };

    return (
        <Card className="gap-3 py-5 rounded-panel shadow-none">
            <CardHeader className="flex flex-wrap items-center gap-2 px-5">
                <CardTitle>
                    <h3 className="text-label font-semibold text-teks-utama">{slot.Lingkungan}</h3>
                </CardTitle>
                {slot.LingkunganServer ? <LabelStatus jenis="netral" teks="Dipakai server ini" /> : null}
                {konfigurasi ? (
                    <>
                        <LabelStatus jenis={jenisLabelStatus[konfigurasi.Status]} teks={konfigurasi.LabelStatus} />
                        <LabelStatus
                            jenis={konfigurasi.Aktif ? 'sukses' : 'netral'}
                            teks={konfigurasi.Aktif ? 'Aktif' : 'Nonaktif'}
                        />
                        {konfigurasi.PerluRotasi ? <LabelStatus jenis="peringatan" teks="Perlu rotasi kunci" /> : null}
                    </>
                ) : (
                    <LabelStatus jenis="netral" teks="Belum diatur" />
                )}
            </CardHeader>
            <CardContent className="flex flex-col gap-3 px-5">
                {konfigurasi ? (
                    <dl className="grid grid-cols-1 gap-x-4 gap-y-1 text-keterangan sm:grid-cols-2">
                        <div className="flex flex-col sm:col-span-2">
                            <dt className="text-teks-sekunder">Penyedia</dt>
                            <dd className="text-teks-utama">{slot.Penyedia.Label}</dd>
                        </div>
                        {slot.BidangPengaturan.map((bidang) => (
                            <div key={bidang.Kunci} className="flex flex-col">
                                <dt className="text-teks-sekunder">{bidang.Label}</dt>
                                <dd className="break-all text-teks-utama">
                                    {bidang.Opsi && konfigurasi.Pengaturan[bidang.Kunci] !== undefined
                                        ? LabelOpsi(String(konfigurasi.Pengaturan[bidang.Kunci]))
                                        : String(konfigurasi.Pengaturan[bidang.Kunci] ?? '-')}
                                </dd>
                            </div>
                        ))}
                        {slot.BidangKredensial.map((bidang) => (
                            <div key={bidang.Kunci} className="flex flex-col">
                                <dt className="text-teks-sekunder">{bidang.Label}</dt>
                                <dd className="font-mono text-teks-utama">
                                    {konfigurasi.PetunjukKredensial[bidang.Kunci] ?? '••••'}
                                </dd>
                            </div>
                        ))}
                        {slot.Jenis === 'GerbangBilling' ? (
                            <div className="flex flex-col gap-1 sm:col-span-2">
                                <dt className="text-teks-sekunder">
                                    Kunci publik SNAP (daftarkan di DOKU › API Keys › Merchant Public Key)
                                </dt>
                                <dd>
                                    {konfigurasi.KunciPublikSnap ? (
                                        <pre
                                            tabIndex={0}
                                            aria-label="Kunci publik SNAP"
                                            className="max-h-40 overflow-auto rounded-panel border border-garis bg-permukaan p-2 font-mono text-keterangan break-all whitespace-pre-wrap text-teks-utama"
                                        >
                                            {konfigurasi.KunciPublikSnap}
                                        </pre>
                                    ) : (
                                        <span className="text-teks-utama">Belum dibuat</span>
                                    )}
                                </dd>
                            </div>
                        ) : null}
                        <div className="flex flex-col sm:col-span-2">
                            <dt className="text-teks-sekunder">Tes koneksi terakhir</dt>
                            <dd className="text-teks-utama">
                                {konfigurasi.HasilUji && konfigurasi.TerakhirDiujiPada
                                    ? `${FormatTanggalWaktu(konfigurasi.TerakhirDiujiPada)} | ${konfigurasi.HasilUji.Pesan}`
                                    : 'Belum pernah diuji'}
                            </dd>
                        </div>
                        <div className="flex flex-col sm:col-span-2">
                            <dt className="text-teks-sekunder">Kredensial terakhir diganti</dt>
                            <dd className="text-teks-utama">
                                {FormatTanggalWaktu(konfigurasi.KredensialDiubahPada)} | rotasi setiap{' '}
                                {konfigurasi.RotasiSetiapHari} hari
                            </dd>
                        </div>
                    </dl>
                ) : (
                    <p className="text-keterangan text-teks-sekunder">
                        Belum ada konfigurasi {slot.Lingkungan.toLowerCase()} untuk {slot.LabelJenis.toLowerCase()}.
                    </p>
                )}

                {bolehKelola && !sunting ? (
                    <div className="flex flex-col gap-3">
                        {konfigurasi && produksi ? (
                            <BidangTeks
                                label="Alasan (wajib untuk produksi)"
                                nilai={alasan}
                                saatBerubah={AturAlasan}
                                keterangan="Dipakai saat mengaktifkan atau menonaktifkan."
                                galat={galatAlasan}
                                required
                            />
                        ) : null}
                        <div className="flex flex-wrap gap-2">
                            <Tombol varian="sekunder" onClick={() => AturSunting(true)}>
                                {konfigurasi ? 'Ubah konfigurasi' : 'Atur konfigurasi'}
                            </Tombol>
                            {konfigurasi ? (
                                <Tombol
                                    varian="sekunder"
                                    memproses={memproses}
                                    onClick={() => router.post(`/integrasi/${konfigurasi.Uuid}/uji`, {}, opsiKirim)}
                                >
                                    Uji koneksi
                                </Tombol>
                            ) : null}
                            {konfigurasi && slot.Jenis === 'GerbangBilling' ? (
                                <KunciSnap
                                    uuid={konfigurasi.Uuid}
                                    kunciPublik={konfigurasi.KunciPublikSnap ?? null}
                                    memproses={memproses}
                                    opsiKirim={opsiKirim}
                                />
                            ) : null}
                        </div>
                        {konfigurasi ? (
                            <div className="flex items-center gap-2">
                                <Switch
                                    id={idSaklar}
                                    checked={konfigurasi.Aktif}
                                    disabled={memproses}
                                    aria-busy={memproses || undefined}
                                    onCheckedChange={(aktif) => UbahStatus(aktif ? 'aktifkan' : 'nonaktifkan')}
                                />
                                <Label htmlFor={idSaklar} className="text-label font-semibold text-teks-utama">
                                    Integrasi {slot.Lingkungan.toLowerCase()} aktif
                                </Label>
                            </div>
                        ) : null}
                    </div>
                ) : null}
            </CardContent>
            {sunting ? <FormIntegrasi slot={slot} saatSelesai={() => AturSunting(false)} /> : null}
        </Card>
    );
}

/**
 * D-159: pasangan kunci RSA untuk token B2B DOKU SNAP. Kunci pribadi dibuat dan disimpan server (terenkripsi); yang
 * tampil dan bisa disalin hanya kunci publiknya. Mengganti kunci memutus SNAP sampai kunci publik baru didaftarkan ulang.
 */
function KunciSnap({
    uuid,
    kunciPublik,
    memproses,
    opsiKirim,
}: {
    uuid: string;
    kunciPublik: string | null;
    memproses: boolean;
    opsiKirim: Record<string, unknown>;
}) {
    const [tersalin, AturTersalin] = useState(false);
    const Buat = () => router.post(`/integrasi/${uuid}/kunci-snap`, {}, opsiKirim);
    const Salin = async () => {
        try {
            await navigator.clipboard.writeText(kunciPublik ?? '');
            AturTersalin(true);
        } catch {
            AturTersalin(false);
        }
    };

    return (
        <>
            {kunciPublik === null ? (
                <Tombol varian="sekunder" memproses={memproses} onClick={Buat}>
                    Buat kunci SNAP
                </Tombol>
            ) : (
                <>
                    <Tombol varian="sekunder" onClick={() => void Salin()}>
                        {tersalin ? 'Kunci publik tersalin' : 'Salin kunci publik'}
                    </Tombol>
                    <AlertDialog>
                        <AlertDialogTrigger asChild>
                            <Button type="button" variant="outline" disabled={memproses}>
                                Buat ulang kunci SNAP
                            </Button>
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>Buat ulang kunci SNAP?</AlertDialogTitle>
                                <AlertDialogDescription>
                                    Pembayaran SNAP (QRIS, virtual account, e-wallet) berhenti sampai kunci publik yang
                                    baru didaftarkan di dasbor DOKU. DOKU Checkout tidak terpengaruh.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Jangan ganti</AlertDialogCancel>
                                <AlertDialogAction variant="destructive" onClick={Buat}>
                                    Buat ulang kunci
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                </>
            )}
        </>
    );
}

type IsianIntegrasi = {
    Jenis: string;
    Lingkungan: string;
    Penyedia: string;
    Pengaturan: Record<string, string>;
    Kredensial: Record<string, string>;
    RotasiSetiapHari: string;
    Alasan: string;
};

/** Isian awal bidang pengaturan: nilai tersimpan (penyedia sama), nilai bawaan penyedia, atau opsi pertama. */
function IsiAwalPengaturan(bidang: BidangPengaturan[], tersimpan: Record<string, string | number> | null) {
    return Object.fromEntries(
        bidang.map((b) => [b.Kunci, String(tersimpan?.[b.Kunci] ?? b.Bawaan ?? b.Opsi?.[0] ?? '')]),
    );
}

function LabelOpsi(opsi: string) {
    return opsi.length <= 3 ? opsi.toUpperCase() : opsi;
}

function FormIntegrasi({ slot, saatSelesai }: { slot: SlotIntegrasi; saatSelesai: () => void }) {
    const konfigurasi = slot.Konfigurasi;
    const cadangan: OpsiPenyedia = {
        Nilai: slot.Penyedia.Nilai,
        Label: slot.Penyedia.Label,
        Keterangan: '',
        Resmi: true,
        BidangPengaturan: slot.BidangPengaturan,
        BidangKredensial: slot.BidangKredensial,
    };
    const daftarPenyedia = slot.DaftarPenyedia?.length ? slot.DaftarPenyedia : [cadangan];
    const formulir = useForm<IsianIntegrasi>({
        Jenis: slot.Jenis,
        Lingkungan: slot.Lingkungan,
        Penyedia: slot.Penyedia.Nilai,
        Pengaturan: IsiAwalPengaturan(slot.BidangPengaturan, konfigurasi?.Pengaturan ?? null),
        Kredensial: Object.fromEntries(slot.BidangKredensial.map((bidang) => [bidang.Kunci, ''])),
        RotasiSetiapHari: String(konfigurasi?.RotasiSetiapHari ?? 90),
        Alasan: '',
    });
    const penyedia = daftarPenyedia.find((p) => p.Nilai === formulir.data.Penyedia) ?? cadangan;
    // Kredensial tersimpan hanya berlaku untuk penyedia yang sama (ganti penyedia = isi ulang).
    const penyediaTersimpan = konfigurasi !== null && penyedia.Nilai === slot.Penyedia.Nilai;
    const GantiPenyedia = (nilai: string) => {
        const baru = daftarPenyedia.find((p) => p.Nilai === nilai);
        if (!baru) {
            return;
        }
        const sama = konfigurasi !== null && nilai === slot.Penyedia.Nilai;
        formulir.setData('Penyedia', nilai);
        formulir.setData('Pengaturan', IsiAwalPengaturan(baru.BidangPengaturan, sama ? konfigurasi.Pengaturan : null));
        formulir.setData('Kredensial', Object.fromEntries(baru.BidangKredensial.map((bidang) => [bidang.Kunci, ''])));
    };
    const galat = formulir.errors as Record<string, string | undefined>;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/integrasi', { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <Sheet
            open
            onOpenChange={(terbuka) => {
                if (!terbuka) {
                    saatSelesai();
                }
            }}
        >
            <SheetContent className="w-full overflow-y-auto sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle className="text-subjudul text-teks-utama">
                        {konfigurasi ? 'Ubah konfigurasi' : 'Atur konfigurasi'} {slot.LabelJenis} | {slot.Lingkungan}
                    </SheetTitle>
                    <SheetDescription>
                        Pilih penyedia lalu isi bidangnya. Kredensial disimpan terenkripsi dan tidak pernah ditampilkan
                        ulang.
                    </SheetDescription>
                </SheetHeader>
                {galat.Umum ? (
                    <div className="px-4">
                        <Pemberitahuan jenis="bahaya">{galat.Umum}</Pemberitahuan>
                    </div>
                ) : null}
                <form onSubmit={Kirim} className="grid gap-3 px-4 sm:grid-cols-2" noValidate>
                    <div className="sm:col-span-2">
                        <BidangPilihan
                            label="Penyedia"
                            nilai={formulir.data.Penyedia}
                            opsi={daftarPenyedia.map((p) => ({ Nilai: p.Nilai, Label: p.Label }))}
                            saatBerubah={GantiPenyedia}
                            galat={galat.Penyedia}
                            required
                        />
                    </div>
                    {penyedia.Keterangan ? (
                        <div className="sm:col-span-2">
                            <Pemberitahuan jenis={penyedia.Resmi ? 'info' : 'peringatan'}>
                                {penyedia.Keterangan}
                            </Pemberitahuan>
                        </div>
                    ) : null}
                    {penyedia.BidangPengaturan.map((bidang) =>
                        bidang.Jenis === 'Pilihan' ? (
                            <BidangPilihan
                                key={bidang.Kunci}
                                label={bidang.Label}
                                nilai={formulir.data.Pengaturan[bidang.Kunci] ?? ''}
                                opsi={(bidang.Opsi ?? []).map((opsi) => ({ Nilai: opsi, Label: LabelOpsi(opsi) }))}
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
                    {penyedia.BidangKredensial.map((bidang) => (
                        <BidangTeks
                            key={bidang.Kunci}
                            label={bidang.Label}
                            jenis="password"
                            autoComplete="new-password"
                            keterangan={
                                penyediaTersimpan && konfigurasi.PetunjukKredensial[bidang.Kunci]
                                    ? `Tersimpan ${konfigurasi.PetunjukKredensial[bidang.Kunci]}. Kosongkan bila tidak diganti.`
                                    : bidang.Wajib
                                      ? 'Wajib diisi.'
                                      : 'Opsional.'
                            }
                            nilai={formulir.data.Kredensial[bidang.Kunci] ?? ''}
                            saatBerubah={(nilai) =>
                                formulir.setData('Kredensial', { ...formulir.data.Kredensial, [bidang.Kunci]: nilai })
                            }
                            galat={galat[`Kredensial.${bidang.Kunci}`]}
                            required={bidang.Wajib && !penyediaTersimpan}
                        />
                    ))}
                    <BidangTeks
                        label="Rotasi kunci setiap (hari)"
                        inputMode="numeric"
                        nilai={formulir.data.RotasiSetiapHari}
                        saatBerubah={(nilai) => formulir.setData('RotasiSetiapHari', nilai)}
                        galat={formulir.errors.RotasiSetiapHari}
                        required
                    />
                    {slot.Lingkungan === 'Produksi' ? (
                        <BidangTeks
                            label="Alasan perubahan"
                            nilai={formulir.data.Alasan}
                            saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                            galat={formulir.errors.Alasan}
                            required
                        />
                    ) : null}
                    <p className="text-keterangan text-teks-sekunder sm:col-span-2">
                        Setelah disimpan, integrasi menjadi nonaktif sampai tes koneksi berhasil.
                    </p>
                    <SheetFooter className="flex-row px-0 sm:col-span-2">
                        <Tombol type="submit" memproses={formulir.processing}>
                            Simpan konfigurasi
                        </Tombol>
                        <Tombol varian="sekunder" onClick={saatSelesai}>
                            Batal
                        </Tombol>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
