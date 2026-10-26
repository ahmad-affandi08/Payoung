import { router, usePage } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import TabDeposit from '@/Komponen/Pelanggan/TabDeposit';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { ItemAksiBaris, type AksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import DialogRahasia, { NilaiRahasia, useRahasiaSekali } from '@/Komponen/Umpan/DialogRahasia';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { BandingkanDesimal } from '@/Pustaka/HitungDesimal';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import { BuatUlid } from '@/Pustaka/Ulid';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisGiftCard, PropsGiftCard, RincianGiftCard, StatusGiftCard } from '@/Tipe/Pelanggan';

const alamat = '/kelola/pelanggan/gift-card';

type JenisDialog = 'jual' | 'perpanjang' | 'hanguskan' | 'kembalikan';

function JenisLabel(status: StatusGiftCard): 'sukses' | 'netral' | 'peringatan' | 'bahaya' {
    return status === 'Aktif'
        ? 'sukses'
        : status === 'Habis'
          ? 'netral'
          : status === 'Hangus'
            ? 'peringatan'
            : 'netral';
}

async function AmbilRincian(uuid: string, sinyal: AbortSignal): Promise<RincianGiftCard> {
    const respons = await fetch(`${alamat}/${uuid}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: sinyal,
    });

    if (!respons.ok) {
        throw new Error(`Riwayat gift card gagal dimuat (${String(respons.status)})`);
    }

    return (await respons.json()) as RincianGiftCard;
}

const kolom: KolomTabel<BarisGiftCard>[] = [
    {
        id: 'KodeSamar',
        header: 'Kartu',
        enableSorting: false,
        meta: { label: 'Kartu', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: g } }) => (
            <span className="flex flex-col">
                <span className="font-mono font-semibold break-all">{g.KodeSamar}</span>
                {g.NamaPemegang ? <span className="text-keterangan text-teks-sekunder">{g.NamaPemegang}</span> : null}
            </span>
        ),
    },
    {
        id: 'DiterbitkanPada',
        accessorKey: 'DiterbitkanPada',
        header: 'Terbit',
        meta: { label: 'Terbit', prioritas: 'rendah', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.DiterbitkanPada),
    },
    {
        id: 'NilaiAwal',
        accessorKey: 'NilaiAwal',
        header: 'Nilai awal',
        meta: { label: 'Nilai awal', prioritas: 'penting', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.NilaiAwal),
    },
    {
        id: 'Saldo',
        accessorKey: 'Saldo',
        header: 'Saldo',
        meta: { label: 'Saldo', prioritas: 'utama', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.Saldo),
    },
    {
        id: 'BerlakuSampai',
        accessorKey: 'BerlakuSampai',
        header: 'Berlaku sampai',
        meta: { label: 'Berlaku sampai', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) =>
            row.original.BerlakuSampai ? (
                FormatTanggal(row.original.BerlakuSampai)
            ) : (
                <span className="text-teks-sekunder">Tanpa batas</span>
            ),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: g } }) => <LabelStatus jenis={JenisLabel(g.Status)} teks={g.LabelStatus} />,
    },
];

const saring: DefinisiSaring[] = [
    {
        id: 'Status',
        label: 'Status',
        jenis: 'pilihanBanyak',
        opsi: [
            { nilai: 'Aktif', label: 'Aktif' },
            { nilai: 'Habis', label: 'Habis dipakai' },
            { nilai: 'Hangus', label: 'Hangus' },
            { nilai: 'Dibatalkan', label: 'Dibatalkan' },
        ],
    },
];

/**
 * CRM-06 (D-153): gift card — kartu bernilai berkode yang dijual di sini dan dipakai membayar di kasir semua outlet.
 * Kode lengkap hanya tampil sekali saat kartu dijual; daftar menampilkan samarannya.
 */
export default function HalamanGiftCard({
    GiftCard,
    Izin,
    Ringkasan,
    Pengaturan,
    AkunKasBank,
    KartuBaru,
}: PropsGiftCard) {
    const { props, url } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const rahasia = useRahasiaSekali(KartuBaru);
    const [dialog, AturDialog] = useState<{ jenis: JenisDialog; kartu: BarisGiftCard | null } | null>(null);
    const [rincian, AturRincian] = useState<string | null>(() =>
        new URLSearchParams(url.split('?')[1] ?? '').get('rincian'),
    );
    const [memproses, AturMemproses] = useState(false);
    const opsiKirim = {
        preserveScroll: true,
        onStart: () => AturMemproses(true),
        onFinish: () => AturMemproses(false),
        onSuccess: () => AturDialog(null),
    };

    const AksiBarisKartu = (g: BarisGiftCard) => {
        const aksi: AksiBaris[] = [{ label: 'Lihat riwayat', saatPilih: () => AturRincian(g.Uuid) }];

        if (Izin.Kelola && (g.Status === 'Aktif' || g.Status === 'Habis')) {
            aksi.push({ label: 'Ubah masa berlaku', saatPilih: () => AturDialog({ jenis: 'perpanjang', kartu: g }) });
        }

        if (Izin.Kelola && g.Status === 'Aktif' && BandingkanDesimal(g.Saldo, '0') > 0) {
            if (BandingkanDesimal(g.Saldo, g.NilaiAwal) === 0) {
                aksi.push({
                    label: 'Batalkan & kembalikan uang',
                    bahaya: true,
                    saatPilih: () => AturDialog({ jenis: 'kembalikan', kartu: g }),
                });
            }

            aksi.push({
                label: 'Hanguskan sisa saldo',
                bahaya: true,
                saatPilih: () => AturDialog({ jenis: 'hanguskan', kartu: g }),
            });
        }

        return <ItemAksiBaris aksi={aksi} />;
    };

    return (
        <TataLetakAplikasi judul="Deposit & gift card">
            <TabDeposit aktif="gift-card" />
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Kartu bernilai yang dibeli lalu dipakai pemegangnya membayar di kasir semua outlet. Uang kartu adalah
                kewajiban sampai dipakai. Kartu tidak bisa diuangkan, kecuali dibatalkan sebelum terpakai.
            </p>
            <DaftarGalatServer galat={galat} />
            {rahasia.aktif ? (
                <DialogRahasia
                    judul={`Gift card senilai ${FormatRupiah(rahasia.aktif.Nilai)} dijual`}
                    peringatan="Kode ini tidak bisa dilihat lagi. Catat atau cetak sekarang dan berikan kepada pembeli. Kartu hanya dipakai dengan kode ini."
                    saatSelesai={rahasia.selesai}
                >
                    <NilaiRahasia nilai={rahasia.aktif.Kode} jenis="kode" besar />
                    <p>
                        {rahasia.aktif.BerlakuSampai
                            ? `Berlaku sampai ${FormatTanggal(rahasia.aktif.BerlakuSampai)}.`
                            : 'Tanpa batas masa berlaku.'}
                    </p>
                </DialogRahasia>
            ) : null}
            <Panel>
                <dl className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt className="text-label text-teks-sekunder">Kartu aktif</dt>
                        <dd className="text-subjudul font-semibold text-teks-utama tabular-nums">
                            {Ringkasan.KartuAktif.toLocaleString('id-ID')}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-label text-teks-sekunder">Saldo beredar</dt>
                        <dd className="text-subjudul font-semibold text-teks-utama tabular-nums">
                            {FormatRupiah(Ringkasan.SaldoBeredar)}
                        </dd>
                    </div>
                </dl>
            </Panel>
            <AksiHalaman>
                {Izin.Kelola && Pengaturan.Berlaku ? (
                    <Tombol onClick={() => AturDialog({ jenis: 'jual', kartu: null })}>Jual gift card</Tombol>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="pelanggan-gift-card"
                label="Daftar gift card"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: GiftCard }}
                ambilIdBaris={(g) => g.Uuid}
                urutBawaan="-DiterbitkanPada"
                cari="Cari kode lengkap atau nama pemegang"
                saring={saring}
                labelBaris={(g) => `gift card ${g.KodeSamar}`}
                aksiBaris={AksiBarisKartu}
                kosong={{ ilustrasi: true, judul: 'Belum ada gift card. Jual kartu pertama untuk memulai.' }}
            />

            {dialog?.jenis === 'jual' ? (
                <FormJual
                    pengaturan={Pengaturan}
                    akun={AkunKasBank}
                    galat={galat}
                    memproses={memproses}
                    saatTutup={() => AturDialog(null)}
                    saatKirim={(isi) => router.post(alamat, isi, opsiKirim)}
                />
            ) : null}
            {dialog?.jenis === 'perpanjang' && dialog.kartu ? (
                <FormPerpanjang
                    kartu={dialog.kartu}
                    galat={galat}
                    memproses={memproses}
                    saatTutup={() => AturDialog(null)}
                    saatKirim={(isi) => router.post(`${alamat}/${dialog.kartu?.Uuid ?? ''}/perpanjang`, isi, opsiKirim)}
                />
            ) : null}
            {dialog?.jenis === 'hanguskan' && dialog.kartu ? (
                <KonfirmasiTutup
                    kartu={dialog.kartu}
                    jenis="hanguskan"
                    akun={AkunKasBank}
                    galat={galat}
                    memproses={memproses}
                    saatBatal={() => AturDialog(null)}
                    saatKirim={(isi) => router.post(`${alamat}/${dialog.kartu?.Uuid ?? ''}/hanguskan`, isi, opsiKirim)}
                />
            ) : null}
            {dialog?.jenis === 'kembalikan' && dialog.kartu ? (
                <KonfirmasiTutup
                    kartu={dialog.kartu}
                    jenis="kembalikan"
                    akun={AkunKasBank}
                    galat={galat}
                    memproses={memproses}
                    saatBatal={() => AturDialog(null)}
                    saatKirim={(isi) => router.post(`${alamat}/${dialog.kartu?.Uuid ?? ''}/kembalikan`, isi, opsiKirim)}
                />
            ) : null}
            {rincian ? <DialogRincian uuid={rincian} saatTutup={() => AturRincian(null)} /> : null}
        </TataLetakAplikasi>
    );
}

type PropsFormJual = {
    pengaturan: PropsGiftCard['Pengaturan'];
    akun: PropsGiftCard['AkunKasBank'];
    galat: Record<string, string>;
    memproses: boolean;
    saatTutup: () => void;
    saatKirim: (isi: Record<string, string | number | null>) => void;
};

function FormJual({ pengaturan, akun, galat, memproses, saatTutup, saatKirim }: PropsFormJual) {
    // Uuid dibuat sekali per dialog: klik ganda atau kirim ulang tidak menerbitkan dua kartu.
    const uuid = useMemo(() => BuatUlid(), []);
    const [isi, AturIsi] = useState({
        Nilai: '',
        UuidAkun: akun[0]?.Uuid ?? '',
        NamaPemegang: '',
        MasaBerlakuHari: String(pengaturan.MasaBerlakuBawaanHari),
        Catatan: '',
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        saatKirim({
            Uuid: uuid,
            Nilai: isi.Nilai,
            UuidAkun: isi.UuidAkun,
            NamaPemegang: isi.NamaPemegang === '' ? null : isi.NamaPemegang,
            MasaBerlakuHari: isi.MasaBerlakuHari === '' ? null : Number.parseInt(isi.MasaBerlakuHari, 10),
            Catatan: isi.Catatan === '' ? null : isi.Catatan,
        });
    };

    return (
        <DialogFormulir judul="Jual gift card" jenis="panel" galatUmum={galat.Umum} saatTutup={saatTutup}>
            <form onSubmit={Kirim} className="flex flex-col gap-4" aria-label="Formulir jual gift card" noValidate>
                <BidangUang
                    label="Nilai kartu"
                    nilai={isi.Nilai}
                    saatBerubah={(nilai) => AturIsi({ ...isi, Nilai: nilai })}
                    galat={galat.Nilai}
                    keterangan={`Rupiah bulat antara ${FormatRupiah(pengaturan.MinimalNilai)} dan ${FormatRupiah(pengaturan.MaksimalNilai)}.`}
                    required
                />
                <BidangPilihan
                    label="Uang diterima di akun"
                    nilai={isi.UuidAkun}
                    kosong="Pilih akun kas/bank"
                    opsi={akun.map((a) => ({ Nilai: a.Uuid, Label: a.Nama, Keterangan: a.Kode }))}
                    saatBerubah={(nilai) => AturIsi({ ...isi, UuidAkun: nilai })}
                    galat={galat.UuidAkun}
                    required
                />
                <BidangTeks
                    label="Nama pemegang (opsional)"
                    nilai={isi.NamaPemegang}
                    saatBerubah={(nilai) => AturIsi({ ...isi, NamaPemegang: nilai })}
                    galat={galat.NamaPemegang}
                    keterangan="Untuk memudahkan mencari kartu. Siapa pun yang tahu kodenya bisa memakai kartu ini."
                    maxLength={100}
                />
                <BidangTeks
                    label="Masa berlaku (hari)"
                    nilai={isi.MasaBerlakuHari}
                    saatBerubah={(nilai) => AturIsi({ ...isi, MasaBerlakuHari: nilai.replace(/\D/g, '') })}
                    galat={galat.MasaBerlakuHari}
                    keterangan="Kosongkan untuk kartu tanpa batas masa berlaku."
                    inputMode="numeric"
                    maxLength={4}
                />
                <BidangTeks
                    label="Catatan (opsional)"
                    nilai={isi.Catatan}
                    saatBerubah={(nilai) => AturIsi({ ...isi, Catatan: nilai })}
                    galat={galat.Catatan}
                    maxLength={255}
                />
                <div className="flex flex-wrap justify-end gap-2">
                    <Tombol type="button" varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                    <Tombol type="submit" memproses={memproses}>
                        Jual dan tampilkan kode
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

type PropsFormPerpanjang = {
    kartu: BarisGiftCard;
    galat: Record<string, string>;
    memproses: boolean;
    saatTutup: () => void;
    saatKirim: (isi: { BerlakuSampai: string | null }) => void;
};

function FormPerpanjang({ kartu, galat, memproses, saatTutup, saatKirim }: PropsFormPerpanjang) {
    const [tanggal, AturTanggal] = useState(kartu.BerlakuSampai ?? '');

    return (
        <DialogFormulir judul={`Masa berlaku ${kartu.KodeSamar}`} galatUmum={galat.Umum} saatTutup={saatTutup}>
            <form
                onSubmit={(peristiwa) => {
                    peristiwa.preventDefault();
                    saatKirim({ BerlakuSampai: tanggal === '' ? null : tanggal });
                }}
                className="flex flex-col gap-4"
                aria-label="Formulir masa berlaku gift card"
                noValidate
            >
                <PemilihTanggal
                    label="Berlaku sampai"
                    nilai={tanggal}
                    saatBerubah={AturTanggal}
                    galat={galat.BerlakuSampai}
                    keterangan="Kosongkan untuk kartu tanpa batas masa berlaku."
                />
                <div className="flex flex-wrap justify-end gap-2">
                    <Tombol type="button" varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                    <Tombol type="submit" memproses={memproses}>
                        Simpan masa berlaku
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

type PropsKonfirmasiTutup = {
    kartu: BarisGiftCard;
    jenis: 'hanguskan' | 'kembalikan';
    akun: PropsGiftCard['AkunKasBank'];
    galat: Record<string, string>;
    memproses: boolean;
    saatBatal: () => void;
    saatKirim: (isi: { Alasan: string; UuidAkun?: string }) => void;
};

function KonfirmasiTutup({ kartu, jenis, akun, galat, memproses, saatBatal, saatKirim }: PropsKonfirmasiTutup) {
    const [alasan, AturAlasan] = useState('');
    const [uuidAkun, AturUuidAkun] = useState(akun[0]?.Uuid ?? '');
    const kembalikan = jenis === 'kembalikan';

    return (
        <DialogKonfirmasi
            judul={kembalikan ? `Batalkan ${kartu.KodeSamar}?` : `Hanguskan sisa saldo ${kartu.KodeSamar}?`}
            labelAksi={kembalikan ? 'Batalkan dan kembalikan uang' : 'Hanguskan sisa saldo'}
            memproses={memproses}
            nonaktif={alasan.trim().length < 5 || (kembalikan && uuidAkun === '')}
            saatKonfirmasi={() => saatKirim(kembalikan ? { Alasan: alasan, UuidAkun: uuidAkun } : { Alasan: alasan })}
            saatBatal={saatBatal}
        >
            <span className="flex flex-col gap-3">
                <span>
                    {kembalikan
                        ? `Kartu belum pernah dipakai. ${FormatRupiah(kartu.Saldo)} dikembalikan dari akun kas/bank yang dipilih dan kartu tidak bisa dipakai lagi.`
                        : `Sisa saldo ${FormatRupiah(kartu.Saldo)} diakui sebagai pendapatan lain dan kartu tidak bisa dipakai lagi. Tindakan ini tidak bisa dibatalkan.`}
                </span>
                {kembalikan ? (
                    <BidangPilihan
                        label="Dikembalikan dari akun"
                        nilai={uuidAkun}
                        kosong="Pilih akun kas/bank"
                        opsi={akun.map((a) => ({ Nilai: a.Uuid, Label: a.Nama, Keterangan: a.Kode }))}
                        saatBerubah={AturUuidAkun}
                        galat={galat.UuidAkun}
                        required
                    />
                ) : null}
                <BidangTeks
                    label="Alasan"
                    nilai={alasan}
                    saatBerubah={AturAlasan}
                    galat={galat.Alasan}
                    maxLength={255}
                    required
                />
            </span>
        </DialogKonfirmasi>
    );
}

function DialogRincian({ uuid, saatTutup }: { uuid: string; saatTutup: () => void }) {
    const data = useQuery({
        queryKey: KunciKueri.GiftCard.Rincian(uuid),
        queryFn: ({ signal }) => AmbilRincian(uuid, signal),
    });
    const kartu = data.data?.Kartu;

    return (
        <DialogFormulir
            judul={kartu ? `Riwayat ${kartu.KodeSamar}` : 'Riwayat gift card'}
            jenis="panel"
            saatTutup={saatTutup}
        >
            {data.isPending ? <p className="text-isi text-teks-sekunder">Memuat riwayat…</p> : null}
            {data.isError ? (
                <p role="alert" className="text-isi text-bahaya">
                    Riwayat gift card gagal dimuat. Tutup lalu buka lagi.
                </p>
            ) : null}
            {kartu && data.data ? (
                <div className="flex flex-col gap-3">
                    <p className="text-isi text-teks-utama">
                        Nilai awal {FormatRupiah(kartu.NilaiAwal)} | Saldo {FormatRupiah(kartu.Saldo)} |{' '}
                        {kartu.LabelStatus}
                    </p>
                    <ul className="flex flex-col divide-y divide-garis" aria-label="Riwayat buku gift card">
                        {data.data.Riwayat.map((r, indeks) => (
                            <li
                                key={`${r.Jenis}-${String(indeks)}`}
                                className="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2"
                            >
                                <span className="font-semibold text-teks-utama">{r.LabelJenis}</span>
                                <span className="text-keterangan text-teks-sekunder">{FormatTanggal(r.Tanggal)}</span>
                                {r.NomorSumber ? (
                                    <span className="font-mono text-keterangan break-all text-teks-sekunder">
                                        {r.NomorSumber}
                                    </span>
                                ) : null}
                                <span className="ml-auto tabular-nums text-teks-utama">
                                    {FormatRupiah(r.Jumlah)} → {FormatRupiah(r.SaldoSetelah)}
                                </span>
                                {r.Keterangan ? (
                                    <span className="basis-full text-keterangan break-words text-teks-sekunder">
                                        {r.Keterangan}
                                    </span>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
        </DialogFormulir>
    );
}
