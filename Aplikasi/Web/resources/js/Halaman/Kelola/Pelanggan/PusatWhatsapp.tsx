import { Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarTab from '@/Komponen/Katalog/DaftarTab';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import DialogPindaiQr from '@/Komponen/Whatsapp/DialogPindaiQr';
import PanelSaldoWhatsapp from '@/Komponen/Whatsapp/PanelSaldoWhatsapp';
import { Switch } from '@/Komponen/Ui/switch';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type {
    NomorPengirimWhatsapp,
    PenerimaLaporanWhatsapp,
    PesanOtomatisWhatsapp,
    PropsHalamanPusatWhatsapp,
    RiwayatPesanWhatsapp,
    StatusNomorWhatsapp,
} from '@/Tipe/Whatsapp';

const jenisStatus: Record<StatusNomorWhatsapp, 'sukses' | 'peringatan' | 'bahaya'> = {
    Terhubung: 'sukses',
    MenungguPindai: 'peringatan',
    Terputus: 'bahaya',
};

/**
 * D-299: Pusat WhatsApp. Tahap 1: nomor pengirim per outlet. Tenant mendaftarkan nomor, memindai QR dengan WhatsApp di
 * ponsel, dan memilih untuk apa nomor itu dipakai. Tidak ada token, ID nomor, atau nama templat yang diisi tenant.
 */
type KunciTab = 'Nomor' | 'Pesan' | 'Laporan' | 'Saldo' | 'Riwayat';

export default function HalamanPusatWhatsapp({
    Saldo,
    PesanOtomatis,
    Penerima,
    MaksPenerima,
    Riwayat,
    Nomor,
    Outlet,
    OpsiFungsi,
    MaksPerOutlet,
    SesiSiap,
}: PropsHalamanPusatWhatsapp) {
    const [tambah, AturTambah] = useState(false);
    const [pindai, AturPindai] = useState<NomorPengirimWhatsapp | null>(null);
    const [ubah, AturUbah] = useState<NomorPengirimWhatsapp | null>(null);
    const [hapus, AturHapus] = useState<NomorPengirimWhatsapp | null>(null);
    const [pindaiBaru, AturPindaiBaru] = useState(false);
    const [tab, AturTab] = useState<KunciTab>(() =>
        typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('tab') === 'saldo'
            ? 'Saldo'
            : 'Nomor',
    );

    // Setelah nomor ditambah, langsung buka dialog pindai untuk nomor yang menunggu.
    const menungguPindai = pindaiBaru ? Nomor.find((n) => n.Status === 'MenungguPindai') : undefined;
    const nomorPindai = pindai ?? menungguPindai ?? null;

    const kolom: KolomTabel<NomorPengirimWhatsapp>[] = [
        {
            id: 'Label',
            accessorKey: 'Label',
            header: 'Nomor',
            meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: n } }) => (
                <>
                    <span className="block text-teks-utama">{n.Label}</span>
                    <span className="block text-label text-teks-sekunder tabular-nums">{n.NomorTelepon}</span>
                </>
            ),
        },
        {
            id: 'NamaOutlet',
            accessorKey: 'NamaOutlet',
            header: 'Outlet',
            meta: { label: 'Outlet', prioritas: 'penting' },
        },
        {
            id: 'LabelFungsi',
            accessorKey: 'LabelFungsi',
            header: 'Dipakai untuk',
            meta: { label: 'Dipakai untuk', prioritas: 'penting' },
        },
        {
            id: 'Status',
            accessorFn: (n) => n.LabelStatus,
            header: 'Status',
            meta: { label: 'Status', prioritas: 'utama' },
            cell: ({ row: { original: n } }) => (
                <>
                    <LabelStatus jenis={jenisStatus[n.Status]} teks={n.LabelStatus} />
                    {n.Status === 'Terhubung' && !n.Aktif ? (
                        <span className="mt-1 block text-label text-teks-sekunder">Dimatikan</span>
                    ) : null}
                    {n.PesanStatus !== null ? (
                        <span className="mt-1 block text-label text-teks-sekunder">{n.PesanStatus}</span>
                    ) : null}
                </>
            ),
        },
    ];

    const Sakelar = (n: NomorPengirimWhatsapp) =>
        router.put(
            `/kelola/pelanggan/whatsapp/nomor/${n.Uuid}`,
            { Label: n.Label, Fungsi: n.Fungsi, Aktif: !n.Aktif },
            { preserveScroll: true },
        );

    return (
        <TataLetakAplikasi judul="Pusat WhatsApp">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Daftarkan nomor WhatsApp toko Anda, lalu pindai kodenya sekali dengan ponsel. Pesan ke pelanggan dan
                laporan ke karyawan keluar lewat nomor itu. Satu outlet bisa punya sampai {MaksPerOutlet} nomor.
            </p>

            <DaftarTab<KunciTab>
                label="Bagian Pusat WhatsApp"
                aktif={tab}
                saatPilih={AturTab}
                tab={[
                    { Kunci: 'Nomor', Label: 'Nomor pengirim' },
                    { Kunci: 'Pesan', Label: 'Pesan otomatis' },
                    { Kunci: 'Laporan', Label: 'Laporan karyawan' },
                    { Kunci: 'Saldo', Label: 'Saldo' },
                    { Kunci: 'Riwayat', Label: 'Riwayat' },
                ]}
                panel={{
                    Nomor: (
                        <>
                            {!SesiSiap ? (
                                <Panel judul="Layanan nomor pengirim belum siap">
                                    <p className="text-isi text-teks-sekunder">
                                        Payoung masih menyiapkan layanan ini. Pesan tetap keluar lewat nomor Payoung
                                        selama kuota paket masih ada. Hubungi dukungan bila ini berlangsung lama.
                                    </p>
                                </Panel>
                            ) : null}

                            <AksiHalaman>
                                <Tombol onClick={() => AturTambah(true)} disabled={!SesiSiap || Outlet.length === 0}>
                                    Tambah nomor
                                </Tombol>
                            </AksiHalaman>

                            {tambah ? (
                                <FormNomor
                                    outlet={Outlet}
                                    opsiFungsi={OpsiFungsi}
                                    saatSelesai={() => AturTambah(false)}
                                    saatDibuat={() => AturPindaiBaru(true)}
                                />
                            ) : null}
                            {ubah ? (
                                <FormUbah nomor={ubah} opsiFungsi={OpsiFungsi} saatSelesai={() => AturUbah(null)} />
                            ) : null}
                            {nomorPindai ? (
                                <DialogPindaiQr
                                    nomor={nomorPindai}
                                    saatTutup={() => {
                                        AturPindai(null);
                                        AturPindaiBaru(false);
                                    }}
                                />
                            ) : null}
                            {hapus ? <KonfirmasiHapus nomor={hapus} saatSelesai={() => AturHapus(null)} /> : null}

                            <TabelData
                                id="pusat-whatsapp-nomor"
                                label="Nomor pengirim WhatsApp"
                                kolom={kolom}
                                sumber={{ mode: 'lokal', data: Nomor }}
                                ambilIdBaris={(n) => n.Uuid}
                                cari="Cari nomor atau outlet"
                                labelBaris={(n) => `nomor ${n.Label}`}
                                aksiBaris={(n) => (
                                    <ItemAksiBaris
                                        aksi={[
                                            n.Status === 'Terhubung'
                                                ? {
                                                      label: n.Aktif ? 'Matikan' : 'Nyalakan',
                                                      saatPilih: () => Sakelar(n),
                                                  }
                                                : { label: 'Pindai QR', saatPilih: () => AturPindai(n) },
                                            { label: 'Ubah', saatPilih: () => AturUbah(n) },
                                            { label: 'Hapus', bahaya: true, saatPilih: () => AturHapus(n) },
                                        ]}
                                    />
                                )}
                                kosong={{
                                    ilustrasi: true,
                                    judul: 'Belum ada nomor pengirim. Tambah nomor WhatsApp toko lalu pindai kodenya.',
                                }}
                            />

                            <p className="text-label text-teks-sekunder">
                                Pakai akun WhatsApp sendiri dari penyedia lain?{' '}
                                <Link className="font-semibold text-brand underline" href="/kelola/pengaturan/whatsapp">
                                    Pengaturan lanjutan
                                </Link>
                            </p>
                        </>
                    ),
                    Pesan: <PanelPesanOtomatis pesan={PesanOtomatis} />,
                    Laporan: (
                        <PanelLaporan penerima={Penerima} outlet={Outlet} maks={MaksPenerima} pesan={PesanOtomatis} />
                    ),
                    Saldo: <PanelSaldoWhatsapp saldo={Saldo} outlet={Outlet} />,
                    Riwayat: <PanelRiwayat riwayat={Riwayat} />,
                }}
            />
        </TataLetakAplikasi>
    );
}

function FormNomor({
    outlet,
    opsiFungsi,
    saatSelesai,
    saatDibuat,
}: {
    outlet: PropsHalamanPusatWhatsapp['Outlet'];
    opsiFungsi: PropsHalamanPusatWhatsapp['OpsiFungsi'];
    saatSelesai: () => void;
    saatDibuat: () => void;
}) {
    const formulir = useForm({ UuidOutlet: '', Label: '', Fungsi: 'Umum', NomorTelepon: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/kelola/pelanggan/whatsapp/nomor', {
            preserveScroll: true,
            onSuccess: () => {
                saatDibuat();
                saatSelesai();
            },
        });
    };

    return (
        <DialogFormulir judul="Tambah nomor pengirim" saatTutup={saatSelesai}>
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangOutlet
                    nilai={formulir.data.UuidOutlet}
                    opsi={outlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                    saatBerubah={(nilai) => formulir.setData('UuidOutlet', nilai)}
                    galat={formulir.errors.UuidOutlet}
                />
                <BidangTeks
                    label="Nama nomor"
                    nilai={formulir.data.Label}
                    saatBerubah={(nilai) => formulir.setData('Label', nilai)}
                    galat={formulir.errors.Label}
                    keterangan='Misal "Kasir utama" atau "Admin toko"'
                    maxLength={60}
                    required
                />
                <BidangTeks
                    label="Nomor WhatsApp"
                    inputMode="tel"
                    nilai={formulir.data.NomorTelepon}
                    saatBerubah={(nilai) => formulir.setData('NomorTelepon', nilai)}
                    galat={formulir.errors.NomorTelepon}
                    keterangan="Nomor yang terpasang di ponsel toko, misal 0812 3456 7890."
                    maxLength={20}
                    required
                />
                <BidangPilihan
                    label="Dipakai untuk"
                    nilai={formulir.data.Fungsi}
                    opsi={opsiFungsi}
                    saatBerubah={(nilai) => formulir.setData('Fungsi', nilai)}
                    galat={formulir.errors.Fungsi}
                    required
                />
                <div className="flex flex-wrap gap-2">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan dan lanjut pindai
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

function FormUbah({
    nomor,
    opsiFungsi,
    saatSelesai,
}: {
    nomor: NomorPengirimWhatsapp;
    opsiFungsi: PropsHalamanPusatWhatsapp['OpsiFungsi'];
    saatSelesai: () => void;
}) {
    const formulir = useForm({ Label: nomor.Label, Fungsi: nomor.Fungsi as string, Aktif: nomor.Aktif });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.put(`/kelola/pelanggan/whatsapp/nomor/${nomor.Uuid}`, {
            preserveScroll: true,
            onSuccess: saatSelesai,
        });
    };

    return (
        <DialogFormulir judul={`Ubah ${nomor.Label}`} saatTutup={saatSelesai}>
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeks
                    label="Nama nomor"
                    nilai={formulir.data.Label}
                    saatBerubah={(nilai) => formulir.setData('Label', nilai)}
                    galat={formulir.errors.Label}
                    maxLength={60}
                    required
                />
                <BidangPilihan
                    label="Dipakai untuk"
                    nilai={formulir.data.Fungsi}
                    opsi={opsiFungsi}
                    saatBerubah={(nilai) => formulir.setData('Fungsi', nilai)}
                    galat={formulir.errors.Fungsi}
                    required
                />
                <div className="flex flex-wrap gap-2">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan perubahan
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

function KonfirmasiHapus({ nomor, saatSelesai }: { nomor: NomorPengirimWhatsapp; saatSelesai: () => void }) {
    const [memproses, AturMemproses] = useState(false);

    return (
        <DialogKonfirmasi
            judul={`Hapus nomor ${nomor.Label}?`}
            labelAksi="Hapus nomor"
            memproses={memproses}
            saatBatal={saatSelesai}
            saatKonfirmasi={() =>
                router.delete(`/kelola/pelanggan/whatsapp/nomor/${nomor.Uuid}`, {
                    preserveScroll: true,
                    onStart: () => AturMemproses(true),
                    onFinish: () => AturMemproses(false),
                    onSuccess: saatSelesai,
                })
            }
        >
            <p>Nomor ini diputus dari Payoung dan tidak lagi mengirim pesan. Anda bisa mendaftarkannya lagi nanti.</p>
        </DialogKonfirmasi>
    );
}

function PanelPesanOtomatis({ pesan }: { pesan: PesanOtomatisWhatsapp[] }) {
    const pelanggan = pesan.filter((p) => p.Kelompok === 'Pelanggan');
    const Sakelar = (p: PesanOtomatisWhatsapp, aktif: boolean) =>
        router.put(`/kelola/pelanggan/whatsapp/pesan-otomatis/${p.Kunci}`, { Aktif: aktif }, { preserveScroll: true });

    return (
        <div className="flex flex-col gap-4">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Pilih pesan yang dikirim otomatis ke pelanggan. Isi pesan sudah disiapkan Payoung, Anda tinggal
                menyalakannya. Pesan struk, pengingat piutang, reservasi, dan status pesanan online diatur di halamannya
                masing-masing.
            </p>
            <Panel judul="Pesan ke pelanggan">
                <ul className="divide-y divide-garis">
                    {pelanggan.map((p) => (
                        <li key={p.Kunci} className="flex items-center justify-between gap-4 py-3">
                            <div>
                                <p className="text-isi text-teks-utama">{p.Label}</p>
                                <p className="text-label text-teks-sekunder">{p.Keterangan}</p>
                            </div>
                            <Switch
                                checked={p.Aktif}
                                aria-label={p.Label}
                                onCheckedChange={(aktif) => Sakelar(p, aktif)}
                            />
                        </li>
                    ))}
                </ul>
            </Panel>
        </div>
    );
}

function PanelLaporan({
    penerima,
    outlet,
    maks,
    pesan,
}: {
    penerima: PenerimaLaporanWhatsapp[];
    outlet: PropsHalamanPusatWhatsapp['Outlet'];
    maks: number;
    pesan: PesanOtomatisWhatsapp[];
}) {
    const [tambah, AturTambah] = useState(false);
    const [hapus, AturHapus] = useState<PenerimaLaporanWhatsapp | null>(null);
    const laporan = pesan.filter((p) => p.Kelompok === 'Karyawan');
    const Sakelar = (p: PesanOtomatisWhatsapp, aktif: boolean) =>
        router.put(`/kelola/pelanggan/whatsapp/pesan-otomatis/${p.Kunci}`, { Aktif: aktif }, { preserveScroll: true });
    const kolom: KolomTabel<PenerimaLaporanWhatsapp>[] = [
        {
            id: 'Nama',
            accessorKey: 'Nama',
            header: 'Penerima',
            meta: { label: 'Penerima', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: p } }) => (
                <>
                    <span className="block text-teks-utama">{p.Nama}</span>
                    <span className="block text-label text-teks-sekunder tabular-nums">{p.NomorTelepon}</span>
                </>
            ),
        },
        {
            id: 'NamaOutlet',
            accessorKey: 'NamaOutlet',
            header: 'Outlet',
            meta: { label: 'Outlet', prioritas: 'penting' },
        },
    ];

    return (
        <div className="flex flex-col gap-4">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Laporan otomatis dikirim ke nomor WhatsApp pengelola atau pemilik toko. Satu outlet bisa punya sampai{' '}
                {maks} penerima.
            </p>
            <Panel judul="Laporan yang dikirim">
                <ul className="divide-y divide-garis">
                    {laporan.map((p) => (
                        <li key={p.Kunci} className="flex items-center justify-between gap-4 py-3">
                            <div>
                                <p className="text-isi text-teks-utama">{p.Label}</p>
                                <p className="text-label text-teks-sekunder">{p.Keterangan}</p>
                            </div>
                            <Switch
                                checked={p.Aktif}
                                aria-label={p.Label}
                                onCheckedChange={(aktif) => Sakelar(p, aktif)}
                            />
                        </li>
                    ))}
                </ul>
            </Panel>
            <AksiHalaman>
                <Tombol onClick={() => AturTambah(true)} disabled={outlet.length === 0}>
                    Tambah penerima
                </Tombol>
            </AksiHalaman>
            {tambah ? <FormPenerima outlet={outlet} saatSelesai={() => AturTambah(false)} /> : null}
            {hapus ? <KonfirmasiHapusPenerima penerima={hapus} saatSelesai={() => AturHapus(null)} /> : null}
            <TabelData
                id="pusat-whatsapp-penerima"
                label="Penerima laporan WhatsApp"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: penerima }}
                ambilIdBaris={(p) => p.Uuid}
                cari="Cari penerima atau outlet"
                labelBaris={(p) => `penerima ${p.Nama}`}
                aksiBaris={(p) => (
                    <ItemAksiBaris aksi={[{ label: 'Hapus', bahaya: true, saatPilih: () => AturHapus(p) }]} />
                )}
                kosong={{ ilustrasi: true, judul: 'Belum ada penerima. Tambahkan nomor pengelola atau pemilik toko.' }}
            />
        </div>
    );
}

function FormPenerima({
    outlet,
    saatSelesai,
}: {
    outlet: PropsHalamanPusatWhatsapp['Outlet'];
    saatSelesai: () => void;
}) {
    const formulir = useForm({ UuidOutlet: '', Nama: '', NomorTelepon: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/kelola/pelanggan/whatsapp/penerima', { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir judul="Tambah penerima laporan" saatTutup={saatSelesai}>
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangOutlet
                    nilai={formulir.data.UuidOutlet}
                    opsi={outlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                    saatBerubah={(nilai) => formulir.setData('UuidOutlet', nilai)}
                    galat={formulir.errors.UuidOutlet}
                />
                <BidangTeks
                    label="Nama penerima"
                    nilai={formulir.data.Nama}
                    saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                    galat={formulir.errors.Nama}
                    maxLength={60}
                    required
                />
                <BidangTeks
                    label="Nomor WhatsApp"
                    inputMode="tel"
                    nilai={formulir.data.NomorTelepon}
                    saatBerubah={(nilai) => formulir.setData('NomorTelepon', nilai)}
                    galat={formulir.errors.NomorTelepon}
                    keterangan="Misal 0812 3456 7890."
                    maxLength={20}
                    required
                />
                <div className="flex flex-wrap gap-2">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan penerima
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

function KonfirmasiHapusPenerima({
    penerima,
    saatSelesai,
}: {
    penerima: PenerimaLaporanWhatsapp;
    saatSelesai: () => void;
}) {
    const [memproses, AturMemproses] = useState(false);

    return (
        <DialogKonfirmasi
            judul={`Hapus penerima ${penerima.Nama}?`}
            labelAksi="Hapus penerima"
            memproses={memproses}
            saatBatal={saatSelesai}
            saatKonfirmasi={() =>
                router.delete(`/kelola/pelanggan/whatsapp/penerima/${penerima.Uuid}`, {
                    preserveScroll: true,
                    onStart: () => AturMemproses(true),
                    onFinish: () => AturMemproses(false),
                    onSuccess: saatSelesai,
                })
            }
        >
            <p>Nomor ini tidak lagi menerima laporan otomatis dari outlet {penerima.NamaOutlet}.</p>
        </DialogKonfirmasi>
    );
}

function PanelRiwayat({ riwayat }: { riwayat: RiwayatPesanWhatsapp[] }) {
    const kolom: KolomTabel<RiwayatPesanWhatsapp>[] = [
        {
            id: 'DibuatPada',
            accessorKey: 'DibuatPada',
            header: 'Waktu',
            meta: { label: 'Waktu', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
            cell: ({ row }) => (row.original.DibuatPada ? FormatTanggalWaktu(row.original.DibuatPada) : '-'),
        },
        { id: 'Jenis', accessorKey: 'Jenis', header: 'Pesan', meta: { label: 'Pesan', prioritas: 'penting' } },
        {
            id: 'Tujuan',
            accessorKey: 'Tujuan',
            header: 'Tujuan',
            meta: { label: 'Tujuan', prioritas: 'penting', kelasSel: 'tabular-nums' },
        },
        {
            id: 'NamaOutlet',
            accessorKey: 'NamaOutlet',
            header: 'Outlet',
            meta: { label: 'Outlet', prioritas: 'rendah' },
        },
        {
            id: 'Status',
            accessorKey: 'Status',
            header: 'Status',
            meta: { label: 'Status', prioritas: 'utama' },
            cell: ({ row: { original: r } }) => (
                <>
                    <LabelStatus jenis={r.Status === 'Terkirim' ? 'sukses' : 'bahaya'} teks={r.Status} />
                    {r.PesanGalat !== null ? (
                        <span className="mt-1 block text-label text-teks-sekunder">{r.PesanGalat}</span>
                    ) : null}
                </>
            ),
        },
    ];

    return (
        <div className="flex flex-col gap-4">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                50 pesan otomatis terakhir (poin, deposit, dan laporan karyawan). Nomor tujuan disamarkan.
            </p>
            <TabelData
                id="pusat-whatsapp-riwayat"
                label="Riwayat pesan otomatis"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: riwayat }}
                ambilIdBaris={(r) => String(r.Id)}
                cari="Cari jenis pesan"
                labelBaris={(r) => `pesan ${r.Jenis}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada pesan otomatis yang dikirim.' }}
            />
        </div>
    );
}
