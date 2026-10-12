import { Head, Link, router, useForm } from '@inertiajs/react';
import { ExternalLinkIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';

type Outlet = {
    Id: number;
    Uuid: string;
    Nama: string;
    TokoOnlineAktif: boolean;
    AmbilSendiriAktif: boolean;
    KirimAktif: boolean;
};
type Zona = {
    Uuid: string;
    UuidOutlet: string;
    Nama: string;
    KodePos: string[];
    Ongkir: string;
    GratisMulai: string | null;
    EstimasiHariMin: number;
    EstimasiHariMaks: number;
    Urutan: number;
    Aktif: boolean;
};
type Kurir = {
    Uuid: string;
    Nama: string;
    NoHp: string | null;
    Jenis: string;
    NamaPenyedia: string | null;
    Status: string;
    /** v3.49: tautan rahasia portal kurir; null = belum dibuat / dicabut. */
    TautanPortal: string | null;
};
type Pengiriman = {
    Uuid: string;
    UuidKurir: string | null;
    NamaPenyedia: string | null;
    NomorResi: string | null;
    Status: string;
    NamaPenerima: string | null;
    Alasan: string | null;
    /** v3.49: foto bukti serah terima dari portal kurir. */
    AdaBukti: boolean;
};
type Pesanan = {
    Uuid: string;
    Nomor: string;
    NamaPelanggan: string;
    NoHp: string;
    /** F-17 bagian 3: pembeli yang masuk dengan WhatsApp → pelanggan toko yang sama dengan pelanggan kasir. */
    Pelanggan: { Uuid: string; Nama: string } | null;
    Alamat: string | null;
    Kelurahan: string | null;
    Kecamatan: string | null;
    Kota: string | null;
    Provinsi: string | null;
    KodePos: string | null;
    JenisPemenuhan: string;
    MetodePembayaran: string;
    Subtotal: string;
    Ongkir: string;
    Total: string;
    Status: string;
    Catatan: string | null;
    IdPenjualan: number | null;
    DibuatPada: string | null;
    DibayarPada: string | null;
    JumlahDibayar: string | null;
    UangMukaTerpakai: string;
    SisaUangMuka: string;
    DikembalikanPada: string | null;
    Baris: { NamaProduk: string; Jumlah: string; TotalBaris: string }[];
    Pengiriman: Pengiriman | null;
};
type Opsi = { Nilai: string; Label: string };
type Props = {
    TautanPublik: string;
    /** D-110: QR (SVG) tautan toko untuk dibagikan atau dicetak. */
    QrSvg: string;
    AkunPembeliTersedia: boolean;
    Pengaturan: {
        Aktif: boolean;
        BayarSaatAmbilAktif: boolean;
        CodAktif: boolean;
        QrisAktif: boolean;
        AkunPelangganAktif: boolean;
        NotifikasiWhatsappAktif: boolean;
        MinimalPesanan: string;
        MenitKedaluwarsa: number;
        PesanTutup: string | null;
    };
    Outlet: Outlet[];
    Zona: Zona[];
    Kurir: Kurir[];
    Pesanan: Pesanan[];
    OpsiStatusPesanan: Opsi[];
    OpsiStatusPengiriman: Opsi[];
    OpsiAkun: { Uuid: string; Kode: string; Nama: string }[];
    IzinRefund: boolean;
};

const kelasKotak = 'rounded-xl border border-garis bg-permukaan p-4 shadow-sm';

/** D-110: cara pembeli tahu toko online: tautan, QR, kirim lewat WhatsApp, dan poster cetak. */
function PanelBagikan({ props }: { props: Props }) {
    const [tersalin, AturTersalin] = useState(false);
    const siap = props.Pengaturan.Aktif && props.Outlet.some((o) => o.TokoOnlineAktif);
    const pesan = `Sekarang bisa pesan online di toko kami. Pilih menu dan pesan di sini: ${props.TautanPublik}`;
    const Salin = () => {
        void navigator.clipboard
            ?.writeText(props.TautanPublik)
            .then(() => AturTersalin(true))
            .catch(() => AturTersalin(false));
    };

    return (
        <section
            aria-label="Bagikan toko"
            className={`${kelasKotak} grid gap-4 sm:grid-cols-[auto_1fr] sm:items-center`}
        >
            <img
                src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(props.QrSvg)}`}
                alt="QR toko online"
                className="size-40 justify-self-center rounded-panel border border-garis bg-permukaan p-2"
            />
            <div className="flex min-w-0 flex-col gap-3">
                <div>
                    <h2 className="text-subjudul font-semibold">Bagikan toko online Anda</h2>
                    <p className="text-keterangan text-teks-sekunder">
                        Pembeli tidak akan menemukan toko ini sendiri. Bagikan tautan atau QR ini lewat WhatsApp, bio
                        Instagram, dan tempel posternya di kasir, pintu, atau meja. Struk digital pelanggan juga
                        otomatis memuat tautan ini.
                    </p>
                </div>
                {siap ? null : (
                    <Pemberitahuan jenis="peringatan">
                        Toko belum menerima pesanan. Nyalakan &quot;Toko menerima pesanan&quot; dan &quot;Outlet tampil
                        online&quot; di bawah, lalu simpan.
                    </Pemberitahuan>
                )}
                <code className="min-w-0 rounded-kontrol border border-garis bg-latar px-2 py-1 font-mono text-keterangan break-all text-teks-utama">
                    {props.TautanPublik}
                </code>
                <div className="flex flex-wrap gap-2">
                    <Tombol varian="sekunder" onClick={Salin}>
                        {tersalin ? 'Tautan tersalin' : 'Salin tautan'}
                    </Tombol>
                    <Tombol
                        varian="sekunder"
                        onClick={() =>
                            window.open(
                                `https://wa.me/?text=${encodeURIComponent(pesan)}`,
                                '_blank',
                                'noopener,noreferrer',
                            )
                        }
                    >
                        Kirim lewat WhatsApp
                    </Tombol>
                    <Button asChild variant="outline" className="font-semibold">
                        <a
                            href={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(props.QrSvg)}`}
                            download="qr-toko-online.svg"
                        >
                            Unduh QR
                        </a>
                    </Button>
                    <Button asChild className="font-semibold">
                        <a href="/kelola/toko-online/poster" target="_blank" rel="noreferrer">
                            Cetak poster
                        </a>
                    </Button>
                </div>
            </div>
        </section>
    );
}

function FormPengaturan({ props }: { props: Props }) {
    const awal = props.Outlet[0];
    const form = useForm({
        Outlet: awal?.Uuid ?? '',
        ...props.Pengaturan,
        TokoOnlineAktif: awal?.TokoOnlineAktif ?? false,
        AmbilSendiriAktif: awal?.AmbilSendiriAktif ?? true,
        KirimAktif: awal?.KirimAktif ?? false,
        PesanTutup: props.Pengaturan.PesanTutup ?? '',
    });
    const PilihOutlet = (uuid: string) => {
        const o = props.Outlet.find((x) => x.Uuid === uuid);
        form.setData((d) => ({
            ...d,
            Outlet: uuid,
            TokoOnlineAktif: o?.TokoOnlineAktif ?? false,
            AmbilSendiriAktif: o?.AmbilSendiriAktif ?? true,
            KirimAktif: o?.KirimAktif ?? false,
        }));
    };
    return (
        <form
            className={`${kelasKotak} grid gap-4 sm:grid-cols-2`}
            onSubmit={(e) => {
                e.preventDefault();
                form.put('/kelola/toko-online/pengaturan', { preserveScroll: true });
            }}
        >
            <div className="sm:col-span-2">
                <h2 className="text-subjudul font-semibold">Pengaturan toko</h2>
                <p className="text-keterangan text-teks-sekunder">
                    Aktifkan kanal per outlet dan tentukan cara bayar bagian pertama.
                </p>
            </div>
            <BidangOutlet
                nilai={form.data.Outlet}
                opsi={props.Outlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                saatBerubah={PilihOutlet}
            />
            <BidangTeks
                label="Minimal pesanan"
                nilai={form.data.MinimalPesanan}
                saatBerubah={(v) => form.setData('MinimalPesanan', v)}
                inputMode="decimal"
            />
            <div className="flex flex-col gap-1">
                <KotakCentang
                    label="Toko menerima pesanan"
                    nilai={form.data.Aktif}
                    saatBerubah={(v) => form.setData('Aktif', v)}
                />
                <KotakCentang
                    label="Outlet tampil online"
                    nilai={form.data.TokoOnlineAktif}
                    saatBerubah={(v) => form.setData('TokoOnlineAktif', v)}
                />
                <KotakCentang
                    label="Boleh ambil sendiri"
                    nilai={form.data.AmbilSendiriAktif}
                    saatBerubah={(v) => form.setData('AmbilSendiriAktif', v)}
                />
            </div>
            <div className="flex flex-col gap-1">
                <KotakCentang
                    label="Boleh dikirim"
                    nilai={form.data.KirimAktif}
                    saatBerubah={(v) => form.setData('KirimAktif', v)}
                />
                <KotakCentang
                    label="Bayar saat ambil"
                    nilai={form.data.BayarSaatAmbilAktif}
                    saatBerubah={(v) => form.setData('BayarSaatAmbilAktif', v)}
                />
                <KotakCentang
                    label="Bayar di tempat (COD)"
                    nilai={form.data.CodAktif}
                    saatBerubah={(v) => form.setData('CodAktif', v)}
                />
                <KotakCentang
                    label="Bayar sekarang lewat QRIS"
                    nilai={form.data.QrisAktif}
                    saatBerubah={(v) => form.setData('QrisAktif', v)}
                />
            </div>
            <div className="flex flex-col gap-1 sm:col-span-2">
                <KotakCentang
                    label="Kabari pembeli lewat WhatsApp saat status pesanan berubah (dikonfirmasi, siap diambil, dikirim, ditolak)"
                    nilai={form.data.NotifikasiWhatsappAktif}
                    saatBerubah={(v) => form.setData('NotifikasiWhatsappAktif', v)}
                />
                <KotakCentang
                    label="Pembeli bisa masuk dengan kode WhatsApp (riwayat belanja, poin, harga member)"
                    nilai={form.data.AkunPelangganAktif}
                    saatBerubah={(v) => form.setData('AkunPelangganAktif', v)}
                />
                <p className="text-keterangan text-teks-sekunder">
                    {props.AkunPembeliTersedia
                        ? 'Pembeli yang masuk tercatat sebagai pelanggan toko, sama dengan pelanggan di kasir. Tanpa masuk tetap bisa memesan.'
                        : 'Belum aktif: WhatsApp platform belum tersambung, jadi tombol Masuk belum tampil di toko. Pesanan tamu tetap berjalan.'}
                </p>
            </div>
            <div className="sm:col-span-2">
                <BidangTeks
                    label="Pesan saat toko tutup"
                    nilai={form.data.PesanTutup}
                    saatBerubah={(v) => form.setData('PesanTutup', v)}
                />
            </div>
            <div className="flex flex-col gap-2 sm:col-span-2">
                {Object.values(form.errors).length > 0 ? (
                    <div role="alert" className="rounded-kontrol border-l-4 border-bahaya bg-permukaan px-3 py-2">
                        {Object.entries(form.errors).map(([kunci, pesan]) => (
                            <p key={kunci} className="text-isi text-bahaya">
                                {pesan}
                            </p>
                        ))}
                    </div>
                ) : null}
                <div>
                    <Tombol type="submit" memproses={form.processing}>
                        Simpan pengaturan
                    </Tombol>
                </div>
            </div>
        </form>
    );
}

function FormZona({ outlet }: { outlet: Outlet[] }) {
    const form = useForm({
        Outlet: '',
        Nama: '',
        KodePos: [] as string[],
        Ongkir: '0',
        GratisMulai: '',
        EstimasiHariMin: 0,
        EstimasiHariMaks: 0,
        Urutan: 0,
        Aktif: true,
    });
    const [kode, AturKode] = useState('');
    return (
        <form
            className={kelasKotak}
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({
                    ...d,
                    KodePos: kode.split(/[\s,;]+/).filter(Boolean),
                    GratisMulai: d.GratisMulai || null,
                }));
                form.post('/kelola/toko-online/zona', {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset();
                        AturKode('');
                    },
                });
            }}
        >
            <h2 className="mb-3 text-subjudul font-semibold">Tambah zona ongkir</h2>
            <div className="grid gap-3 sm:grid-cols-2">
                <BidangOutlet
                    nilai={form.data.Outlet}
                    opsi={outlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                    saatBerubah={(nilai) => form.setData('Outlet', nilai)}
                />
                <BidangTeks label="Nama zona" nilai={form.data.Nama} saatBerubah={(v) => form.setData('Nama', v)} />
                <div className="sm:col-span-2">
                    <BidangTeks
                        label="Kode pos"
                        nilai={kode}
                        saatBerubah={AturKode}
                        keterangan="Pisahkan dengan koma atau spasi. Satu kode pos hanya boleh masuk satu zona aktif."
                    />
                </div>
                <BidangTeks
                    label="Ongkir"
                    nilai={form.data.Ongkir}
                    saatBerubah={(v) => form.setData('Ongkir', v)}
                    inputMode="decimal"
                />
                <BidangTeks
                    label="Gratis mulai"
                    nilai={form.data.GratisMulai}
                    saatBerubah={(v) => form.setData('GratisMulai', v)}
                    inputMode="decimal"
                />
                <BidangTeks
                    label="Estimasi hari minimal"
                    nilai={String(form.data.EstimasiHariMin)}
                    saatBerubah={(v) => form.setData('EstimasiHariMin', Number(v))}
                    inputMode="numeric"
                />
                <BidangTeks
                    label="Estimasi hari maksimal"
                    nilai={String(form.data.EstimasiHariMaks)}
                    saatBerubah={(v) => form.setData('EstimasiHariMaks', Number(v))}
                    inputMode="numeric"
                />
            </div>
            <div className="mt-3">
                <Tombol type="submit" memproses={form.processing}>
                    Tambah zona
                </Tombol>
            </div>
        </form>
    );
}

function FormKurir() {
    const form = useForm({ Nama: '', NoHp: '', Jenis: 'Internal', NamaPenyedia: '', Status: 'Aktif' });
    return (
        <form
            className={kelasKotak}
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/kelola/pengiriman/kurir', { preserveScroll: true, onSuccess: () => form.reset() });
            }}
        >
            <h2 className="mb-3 text-subjudul font-semibold">Tambah kurir</h2>
            <div className="grid gap-3 sm:grid-cols-2">
                <BidangTeks label="Nama kurir" nilai={form.data.Nama} saatBerubah={(v) => form.setData('Nama', v)} />
                <BidangTeks
                    label="Nomor HP"
                    nilai={form.data.NoHp}
                    saatBerubah={(v) => form.setData('NoHp', v)}
                    inputMode="tel"
                />
                <BidangPilihan
                    label="Jenis"
                    nilai={form.data.Jenis}
                    opsi={[
                        { Nilai: 'Internal', Label: 'Kurir internal' },
                        { Nilai: 'PihakKetiga', Label: 'Pihak ketiga' },
                    ]}
                    saatBerubah={(nilai) => form.setData('Jenis', nilai)}
                />
                <BidangTeks
                    label="Nama penyedia"
                    nilai={form.data.NamaPenyedia}
                    saatBerubah={(v) => form.setData('NamaPenyedia', v)}
                />
            </div>
            <div className="mt-3">
                <Tombol type="submit" memproses={form.processing}>
                    Tambah kurir
                </Tombol>
            </div>
        </form>
    );
}

/**
 * F-17 bagian 2 (J-17.2): uangnya dipindahkan sendiri oleh toko; formulir ini hanya membukukannya, jadi labelnya
 * "Catat pengembalian", bukan "Kembalikan uang".
 */
function FormKembalikanUang({ p, opsiAkun }: { p: Pesanan; opsiAkun: { Uuid: string; Kode: string; Nama: string }[] }) {
    const form = useForm({ UuidAkun: opsiAkun[0]?.Uuid ?? '', Alasan: '' });

    return (
        <form
            className="mt-3 flex flex-col gap-3 border-t border-garis pt-3"
            noValidate
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/kelola/toko-online/pesanan/${p.Uuid}/kembalikan-uang`, { preserveScroll: true });
            }}
        >
            <p className="text-keterangan text-teks-sekunder">
                Uang pelanggan {FormatRupiah(p.SisaUangMuka)} masih ditahan. Transfer dulu ke pelanggan, lalu catat di
                sini supaya bukunya cocok.
            </p>
            <BidangPilihan
                label="Uang keluar dari"
                nilai={form.data.UuidAkun}
                opsi={opsiAkun.map((a) => ({ Nilai: a.Uuid, Label: `${a.Kode} | ${a.Nama}` }))}
                saatBerubah={(v) => form.setData('UuidAkun', v)}
                required
            />
            <BidangTeks
                label="Alasan"
                nilai={form.data.Alasan}
                saatBerubah={(v) => form.setData('Alasan', v)}
                required
            />
            <div>
                <Tombol type="submit" varian="sekunder" memproses={form.processing}>
                    Catat pengembalian
                </Tombol>
            </div>
        </form>
    );
}

function UbahStatus({
    p,
    opsiPesanan,
    opsiPengiriman,
    kurir,
}: {
    p: Pesanan;
    opsiPesanan: Opsi[];
    opsiPengiriman: Opsi[];
    kurir: Kurir[];
}) {
    const [status, AturStatus] = useState(p.Status);
    const [statusKirim, AturStatusKirim] = useState(p.Pengiriman?.Status ?? 'SiapKemas');
    const [uuidKurir, AturUuidKurir] = useState(p.Pengiriman?.UuidKurir ?? '');
    const [resi, AturResi] = useState(p.Pengiriman?.NomorResi ?? '');
    const [penyedia, AturPenyedia] = useState(p.Pengiriman?.NamaPenyedia ?? '');
    const [penerima, AturPenerima] = useState(p.Pengiriman?.NamaPenerima ?? '');
    return (
        <div className="mt-3 flex flex-wrap items-end gap-2 border-t border-garis pt-3">
            <BidangPilihan label="Status pesanan" nilai={status} opsi={opsiPesanan} saatBerubah={AturStatus} />
            <Tombol
                varian="sekunder"
                onClick={() =>
                    router.post(
                        `/kelola/toko-online/pesanan/${p.Uuid}/status`,
                        { Status: status },
                        { preserveScroll: true },
                    )
                }
            >
                Ubah status
            </Tombol>
            {p.Pengiriman ? (
                <>
                    <BidangPilihan
                        label="Status kirim"
                        nilai={statusKirim}
                        opsi={opsiPengiriman}
                        saatBerubah={AturStatusKirim}
                    />
                    <BidangPilihan
                        label="Kurir"
                        nilai={uuidKurir}
                        opsi={kurir.filter((k) => k.Status === 'Aktif').map((k) => ({ Nilai: k.Uuid, Label: k.Nama }))}
                        kosong="Belum ditentukan"
                        saatBerubah={AturUuidKurir}
                    />
                    <label className="flex flex-col gap-1 text-label">
                        Nomor resi
                        <input
                            className="h-9 rounded-md border border-garis-input bg-latar px-2"
                            value={resi}
                            onChange={(e) => AturResi(e.target.value)}
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-label">
                        Penyedia eksternal
                        <input
                            className="h-9 rounded-md border border-garis-input bg-latar px-2"
                            value={penyedia}
                            onChange={(e) => AturPenyedia(e.target.value)}
                        />
                    </label>
                    <label className="flex flex-col gap-1 text-label">
                        Nama penerima
                        <input
                            className="h-9 rounded-md border border-garis-input bg-latar px-2"
                            value={penerima}
                            onChange={(e) => AturPenerima(e.target.value)}
                        />
                    </label>
                    <Tombol
                        varian="sekunder"
                        onClick={() =>
                            router.post(
                                `/kelola/pengiriman/${p.Pengiriman?.Uuid ?? ''}/status`,
                                {
                                    Status: statusKirim,
                                    Kurir: uuidKurir || null,
                                    NamaPenyedia: penyedia || null,
                                    NomorResi: resi || null,
                                    NamaPenerima: penerima || null,
                                },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Ubah pengiriman
                    </Tombol>
                    {p.Pengiriman.AdaBukti ? (
                        <a
                            className="self-center text-label text-brand underline"
                            href={`/kelola/pengiriman/${p.Pengiriman.Uuid}/bukti`}
                            target="_blank"
                            rel="noreferrer"
                        >
                            Lihat foto bukti
                        </a>
                    ) : null}
                </>
            ) : null}
        </div>
    );
}

/**
 * v3.49: tautan portal kurir. Kurir membuka tautan ini di HP tanpa akun; membuat ulang atau mencabut mematikan tautan
 * lama. Tautan bersifat rahasia — kirim hanya ke kurirnya sendiri.
 */
function TautanPortalKurir({ kurir }: { kurir: Kurir }) {
    const [tersalin, AturTersalin] = useState(false);
    const alamat = `/kelola/pengiriman/kurir/${kurir.Uuid}/tautan-portal`;

    return (
        <div className="mt-2 flex flex-col gap-2 border-t border-garis pt-2">
            <p className="text-keterangan text-teks-sekunder">
                {kurir.TautanPortal
                    ? 'Portal kurir aktif. Kirim tautan ini hanya ke kurirnya; siapa pun yang memegangnya bisa melihat alamat pengiriman.'
                    : 'Kurir bisa menandai berangkat, diterima (dengan foto), atau gagal dari HP lewat tautan portal.'}
            </p>
            {kurir.TautanPortal ? (
                <code className="break-all rounded-md bg-latar p-2 font-mono text-keterangan">
                    {kurir.TautanPortal}
                </code>
            ) : null}
            <div className="flex flex-wrap gap-2">
                {kurir.TautanPortal ? (
                    <>
                        <Tombol
                            varian="sekunder"
                            onClick={() => {
                                void navigator.clipboard
                                    .writeText(kurir.TautanPortal ?? '')
                                    .then(() => AturTersalin(true));
                            }}
                        >
                            {tersalin ? 'Tautan tersalin' : 'Salin tautan'}
                        </Tombol>
                        <Tombol varian="sekunder" onClick={() => router.post(alamat, {}, { preserveScroll: true })}>
                            Buat tautan baru
                        </Tombol>
                        <Tombol varian="bahaya" onClick={() => router.delete(alamat, { preserveScroll: true })}>
                            Cabut tautan
                        </Tombol>
                    </>
                ) : (
                    <Tombol varian="sekunder" onClick={() => router.post(alamat, {}, { preserveScroll: true })}>
                        Buat tautan portal
                    </Tombol>
                )}
            </div>
        </div>
    );
}

export default function DaftarTokoOnline(props: Props) {
    const namaOutlet = useMemo(() => new Map(props.Outlet.map((o) => [o.Uuid, o.Nama])), [props.Outlet]);
    return (
        <TataLetakAplikasi judul="Toko online & pengiriman">
            <Head title="Toko online & pengiriman" />
            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-teks-sekunder">
                            Kelola kanal publik, pesanan masuk, zona ongkir, dan kurir.
                        </p>
                    </div>
                    <a
                        href={props.TautanPublik}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-2 text-label font-semibold text-brand hover:underline"
                    >
                        Buka toko <ExternalLinkIcon className="size-4" />
                    </a>
                </div>
                <PanelBagikan props={props} />
                <FormPengaturan props={props} />
                <div className="grid gap-4 xl:grid-cols-2">
                    <FormZona outlet={props.Outlet} />
                    <FormKurir />
                </div>
                <section className={kelasKotak}>
                    <h2 className="mb-3 text-subjudul font-semibold">Zona dan kurir aktif</h2>
                    <div className="grid gap-4 md:grid-cols-2">
                        <ul className="space-y-2">
                            {props.Zona.map((z) => (
                                <li key={z.Uuid} className="rounded-md border border-garis p-3">
                                    <div className="flex justify-between gap-2">
                                        <strong>{z.Nama}</strong>
                                        <LabelStatus
                                            jenis={z.Aktif ? 'sukses' : 'netral'}
                                            teks={z.Aktif ? 'Aktif' : 'Nonaktif'}
                                        />
                                    </div>
                                    <p className="text-keterangan text-teks-sekunder">
                                        {namaOutlet.get(z.UuidOutlet)} | {z.KodePos.join(', ')}
                                    </p>
                                    <p>
                                        {FormatRupiah(z.Ongkir)}
                                        {z.GratisMulai ? ` | gratis mulai ${FormatRupiah(z.GratisMulai)}` : ''}
                                    </p>
                                    <div className="mt-2">
                                        <Tombol
                                            varian="sekunder"
                                            onClick={() =>
                                                router.put(
                                                    `/kelola/toko-online/zona/${z.Uuid}`,
                                                    { ...z, Outlet: z.UuidOutlet, Aktif: !z.Aktif },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {z.Aktif ? 'Nonaktifkan zona' : 'Aktifkan zona'}
                                        </Tombol>
                                    </div>
                                </li>
                            ))}
                        </ul>
                        <ul className="space-y-2">
                            {props.Kurir.map((k) => (
                                <li key={k.Uuid} className="rounded-md border border-garis p-3">
                                    <div className="flex justify-between">
                                        <strong>{k.Nama}</strong>
                                        <LabelStatus
                                            jenis={k.Status === 'Aktif' ? 'sukses' : 'netral'}
                                            teks={k.Status}
                                        />
                                    </div>
                                    <p className="text-keterangan text-teks-sekunder">
                                        {k.Jenis === 'Internal' ? 'Internal' : (k.NamaPenyedia ?? 'Pihak ketiga')}
                                        {k.NoHp ? ` | ${k.NoHp}` : ''}
                                    </p>
                                    <div className="mt-2">
                                        <Tombol
                                            varian="sekunder"
                                            onClick={() =>
                                                router.put(
                                                    `/kelola/pengiriman/kurir/${k.Uuid}`,
                                                    { ...k, Status: k.Status === 'Aktif' ? 'Diarsipkan' : 'Aktif' },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {k.Status === 'Aktif' ? 'Arsipkan kurir' : 'Aktifkan kurir'}
                                        </Tombol>
                                    </div>
                                    {k.Status === 'Aktif' ? <TautanPortalKurir kurir={k} /> : null}
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>
                <section className="flex flex-col gap-3">
                    <h2 className="text-subjudul font-semibold">Pesanan terbaru</h2>
                    {props.Pesanan.length === 0 ? (
                        <div className={kelasKotak}>Belum ada pesanan online.</div>
                    ) : (
                        props.Pesanan.map((p) => (
                            <article key={p.Uuid} className={kelasKotak}>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <strong className="font-mono">{p.Nomor}</strong>
                                        <p>
                                            {p.NamaPelanggan} | {p.NoHp}
                                        </p>
                                        {p.Pelanggan ? (
                                            <p className="text-keterangan">
                                                Pelanggan terdaftar:{' '}
                                                <Link
                                                    href={`/kelola/pelanggan/${p.Pelanggan.Uuid}`}
                                                    className="text-brand underline"
                                                >
                                                    {p.Pelanggan.Nama}
                                                </Link>
                                            </p>
                                        ) : null}
                                        <p className="text-keterangan text-teks-sekunder">
                                            {p.DibuatPada ? FormatTanggalWaktu(p.DibuatPada) : '-'} |{' '}
                                            {p.JenisPemenuhan === 'Kirim' ? 'Dikirim' : 'Ambil sendiri'}
                                        </p>
                                    </div>
                                    <div className="text-right">
                                        <LabelStatus
                                            jenis={
                                                p.Status === 'Selesai'
                                                    ? 'sukses'
                                                    : p.Status === 'Ditolak' || p.Status === 'Dibatalkan'
                                                      ? 'bahaya'
                                                      : 'peringatan'
                                            }
                                            teks={
                                                props.OpsiStatusPesanan.find((o) => o.Nilai === p.Status)?.Label ??
                                                p.Status
                                            }
                                        />
                                        <p className="mt-1 text-subjudul font-semibold">{FormatRupiah(p.Total)}</p>
                                    </div>
                                </div>
                                <ul className="mt-3 list-inside list-disc text-isi text-teks-sekunder">
                                    {p.Baris.map((b, i) => (
                                        <li key={`${p.Uuid}-${String(i)}`}>
                                            {b.Jumlah} × {b.NamaProduk} | {FormatRupiah(b.TotalBaris)}
                                        </li>
                                    ))}
                                </ul>
                                {p.JenisPemenuhan === 'Kirim' ? (
                                    <p className="mt-2 text-isi">
                                        Kirim ke: {p.Alamat}, {p.Kelurahan}, {p.Kecamatan}, {p.Kota}, {p.Provinsi}{' '}
                                        {p.KodePos}
                                    </p>
                                ) : null}
                                {p.Catatan ? <p className="mt-2 text-keterangan">Catatan: {p.Catatan}</p> : null}
                                {p.DibayarPada ? (
                                    <p className="mt-2 text-isi">
                                        Dibayar di muka {FormatRupiah(p.JumlahDibayar ?? '0')} pada{' '}
                                        {FormatTanggalWaktu(p.DibayarPada)}
                                        {Number(p.SisaUangMuka) > 0
                                            ? ` | sisa uang muka ${FormatRupiah(p.SisaUangMuka)}`
                                            : ' | sudah dipakai penjualan'}
                                        {p.DikembalikanPada
                                            ? ` | dikembalikan ${FormatTanggalWaktu(p.DikembalikanPada)}`
                                            : ''}
                                    </p>
                                ) : null}
                                {props.IzinRefund && Number(p.SisaUangMuka) > 0 && !p.DikembalikanPada ? (
                                    <FormKembalikanUang p={p} opsiAkun={props.OpsiAkun} />
                                ) : null}
                                <UbahStatus
                                    p={p}
                                    opsiPesanan={props.OpsiStatusPesanan}
                                    opsiPengiriman={props.OpsiStatusPengiriman}
                                    kurir={props.Kurir}
                                />
                            </article>
                        ))
                    )}
                </section>
            </div>
        </TataLetakAplikasi>
    );
}
