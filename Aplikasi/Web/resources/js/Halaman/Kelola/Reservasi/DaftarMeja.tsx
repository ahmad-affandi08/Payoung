import { Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';

import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihJam from '@/Komponen/Tanggal/PemilihJam';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { UbahIsoKeWaktuLokal } from '@/Pustaka/Tanggal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisReservasiMeja, PropsDaftarReservasiMeja, StatusReservasiMeja } from '@/Tipe/Reservasi';

const alamat = '/kelola/reservasi-meja';

const JenisLabelStatus: Record<StatusReservasiMeja, 'netral' | 'sukses' | 'peringatan' | 'bahaya'> = {
    Menunggu: 'peringatan',
    Dikonfirmasi: 'netral',
    Hadir: 'sukses',
    Selesai: 'sukses',
    Dibatalkan: 'bahaya',
    TidakDatang: 'bahaya',
};

const LabelAksiStatus: Partial<Record<StatusReservasiMeja, string>> = {
    Dikonfirmasi: 'Konfirmasi',
    Hadir: 'Tamu sudah datang',
    Selesai: 'Tandai selesai',
    TidakDatang: 'Tamu tidak datang',
};

/** Status yang mengakhiri reservasi dan karena itu menyelesaikan sisa uang muka (dialog sendiri). */
const StatusAkhir: StatusReservasiMeja[] = ['Dibatalkan', 'TidakDatang', 'Selesai'];

const OpsiDurasi = [60, 90, 120, 150, 180, 240].map((m) => ({
    Nilai: String(m),
    Label: m % 60 === 0 ? `${String(m / 60)} jam` : `${String(Math.floor(m / 60))} jam ${String(m % 60)} menit`,
}));

/** Tanggal & jam reservasi dalam zona waktu peramban (toko dan pengguna di Indonesia). */
export function FormatWaktuReservasiMeja(mulai: string, selesai: string): string {
    const m = new Date(mulai);
    const s = new Date(selesai);
    const tanggal = m.toLocaleDateString('id-ID', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
    const FormatJam = (d: Date) => d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false });

    return `${tanggal} | ${FormatJam(m)}–${FormatJam(s)}`;
}

function AdaUangMuka(r: BarisReservasiMeja): boolean {
    return r.UangMuka.Dicatat !== '0.00';
}

function AdaSisaUangMuka(r: BarisReservasiMeja): boolean {
    return r.UangMuka.Sisa !== '0.00';
}

const kolom: KolomTabel<BarisReservasiMeja>[] = [
    {
        id: 'MulaiPada',
        accessorKey: 'MulaiPada',
        header: 'Waktu',
        meta: { label: 'Waktu', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: r } }) => (
            <span className="flex flex-col gap-0.5">
                <span className="font-semibold whitespace-nowrap text-teks-utama tabular-nums">
                    {FormatWaktuReservasiMeja(r.MulaiPada, r.SelesaiPada)}
                </span>
                <span className="font-mono text-keterangan text-teks-sekunder">{r.Nomor}</span>
            </span>
        ),
    },
    {
        id: 'Pemesan',
        header: 'Pemesan',
        enableSorting: false,
        meta: { label: 'Pemesan', prioritas: 'penting' },
        cell: ({ row: { original: r } }) => (
            <span className="flex flex-col gap-0.5 break-words">
                <span className="text-teks-utama">{r.NamaPemesan}</span>
                <span className="text-keterangan text-teks-sekunder tabular-nums">{r.NoHp}</span>
            </span>
        ),
    },
    {
        id: 'Meja',
        header: 'Meja',
        enableSorting: false,
        meta: { label: 'Meja', prioritas: 'penting' },
        cell: ({ row: { original: r } }) => (
            <span className="flex flex-col gap-0.5 break-words">
                <span>{r.Meja === null ? 'Belum ditentukan' : `Meja ${r.Meja.Nama}`}</span>
                <span className="text-keterangan text-teks-sekunder">
                    {[`${String(r.JumlahTamu)} tamu`, r.Area?.Nama].filter(Boolean).join(' | ')}
                </span>
            </span>
        ),
    },
    {
        id: 'UangMuka',
        header: 'Uang muka',
        enableSorting: false,
        meta: { label: 'Uang muka', prioritas: 'penting', angka: true },
        cell: ({ row: { original: r } }) =>
            AdaUangMuka(r) ? (
                <span className="flex flex-col items-end gap-0.5 tabular-nums">
                    <span className="font-semibold">{FormatRupiah(r.UangMuka.Dicatat)}</span>
                    <span className="text-keterangan text-teks-sekunder">
                        {AdaSisaUangMuka(r) ? `Sisa ${FormatRupiah(r.UangMuka.Sisa)}` : 'Sudah diselesaikan'}
                    </span>
                </span>
            ) : (
                <span className="text-teks-sekunder">Tanpa uang muka</span>
            ),
    },
    {
        id: 'Outlet',
        header: 'Outlet',
        enableSorting: false,
        meta: { label: 'Outlet', prioritas: 'rendah' },
        cell: ({ row }) => row.original.Outlet.Nama,
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: r } }) => (
            <span className="flex flex-col items-start gap-1">
                <LabelStatus jenis={JenisLabelStatus[r.Status]} teks={r.LabelStatus} />
                {r.AlasanBatal ? <span className="text-keterangan text-teks-sekunder">{r.AlasanBatal}</span> : null}
            </span>
        ),
    },
];

type PropsDialogReservasi = Pick<PropsDaftarReservasiMeja, 'OpsiOutlet' | 'PilihanMeja' | 'HariIni'> & {
    reservasi: BarisReservasiMeja | null;
    saatTutup: () => void;
};

/** Catat reservasi baru (telepon/WhatsApp/datang langsung) atau ubah yang belum datang. */
function DialogReservasi({ reservasi, OpsiOutlet, PilihanMeja, HariIni, saatTutup }: PropsDialogReservasi) {
    const awal = reservasi === null ? '' : UbahIsoKeWaktuLokal(reservasi.MulaiPada);
    const formulir = useForm({
        Outlet: reservasi?.Outlet.Uuid ?? (OpsiOutlet.length === 1 ? (OpsiOutlet[0]?.Uuid ?? '') : ''),
        UuidMeja: reservasi?.Meja?.Uuid ?? '',
        NamaPemesan: reservasi?.NamaPemesan ?? '',
        NoHp: reservasi?.NoHp ?? '',
        JumlahTamu: reservasi === null ? '2' : String(reservasi.JumlahTamu),
        Tanggal: awal === '' ? HariIni : awal.slice(0, 10),
        Jam: awal === '' ? '' : awal.slice(11, 16),
        DurasiMenit: reservasi === null ? '120' : String(reservasi.DurasiMenit),
        Catatan: reservasi?.Catatan ?? '',
    });
    const d = formulir.data;
    const galat = formulir.errors as Record<string, string | undefined>;
    const opsiMeja = useMemo(
        () => [
            { Nilai: '', Label: 'Tentukan nanti', Keterangan: 'Meja dipilih sebelum tamu datang' },
            ...PilihanMeja.Meja.filter((m) => d.Outlet === '' || m.UuidOutlet === d.Outlet).map((m) => ({
                Nilai: m.Uuid,
                Label: `Meja ${m.Nama}`,
                Keterangan: [`${String(m.Kapasitas)} kursi`, m.NamaArea].filter(Boolean).join(' | '),
            })),
        ],
        [PilihanMeja.Meja, d.Outlet],
    );
    const Kirim = (e: FormEvent) => {
        e.preventDefault();
        const pilihan = { preserveScroll: true, onSuccess: saatTutup };

        if (reservasi === null) {
            formulir.post(alamat, pilihan);
        } else {
            formulir.put(`${alamat}/${reservasi.Uuid}`, pilihan);
        }
    };

    return (
        <DialogFormulir
            judul={reservasi === null ? 'Catat reservasi meja' : `Ubah ${reservasi.Nomor}`}
            lebar="lebar"
            saatTutup={saatTutup}
            galatUmum={galat.Umum}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <BidangOutlet
                    nilai={d.Outlet}
                    opsi={OpsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                    saatBerubah={(nilai) => formulir.setData({ ...d, Outlet: nilai, UuidMeja: '' })}
                    galat={galat.Outlet}
                    sembunyiBilaTunggal
                />
                <BidangTeks
                    label="Nama pemesan"
                    nilai={d.NamaPemesan}
                    saatBerubah={(nilai) => formulir.setData('NamaPemesan', nilai)}
                    galat={galat.NamaPemesan}
                    required
                    maxLength={100}
                />
                <BidangTeks
                    label="Nomor HP (WhatsApp)"
                    nilai={d.NoHp}
                    saatBerubah={(nilai) => formulir.setData('NoHp', nilai)}
                    galat={galat.NoHp}
                    inputMode="tel"
                    required
                />
                <BidangJumlah
                    label="Jumlah tamu"
                    nilai={d.JumlahTamu}
                    saatBerubah={(nilai) => formulir.setData('JumlahTamu', nilai)}
                    galat={galat.JumlahTamu}
                    desimal={0}
                    digitBulat={3}
                    akhiran="orang"
                    required
                />
                <PemilihTanggal
                    label="Tanggal"
                    nilai={d.Tanggal}
                    min={HariIni}
                    saatBerubah={(nilai) => formulir.setData('Tanggal', nilai)}
                    galat={galat.Tanggal}
                    required
                />
                <PemilihJam
                    label="Jam datang"
                    nilai={d.Jam}
                    saatBerubah={(nilai) => formulir.setData('Jam', nilai)}
                    galat={galat.Jam}
                    contoh="19:00"
                    langkahMenit={15}
                    required
                />
                <BidangPilihan
                    label="Lama pemakaian meja"
                    nilai={d.DurasiMenit}
                    opsi={OpsiDurasi}
                    saatBerubah={(nilai) => formulir.setData('DurasiMenit', nilai)}
                    galat={galat.DurasiMenit}
                />
                <BidangPilihan
                    label="Meja"
                    nilai={d.UuidMeja}
                    opsi={opsiMeja}
                    saatBerubah={(nilai) => formulir.setData('UuidMeja', nilai)}
                    galat={galat.UuidMeja}
                />
                <div className="sm:col-span-2">
                    <BidangTeks
                        label="Catatan (opsional)"
                        nilai={d.Catatan}
                        saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                        galat={galat.Catatan}
                        maxLength={255}
                    />
                </div>
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing} disabled={d.Jam === ''}>
                        Simpan reservasi
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

/** Catat uang muka (DP) yang diterima dari tamu: dibukukan sebagai kewajiban sampai dipakai membayar tagihan. */
function DialogUangMuka({
    reservasi,
    AkunKasBank,
    saatTutup,
}: {
    reservasi: BarisReservasiMeja;
    AkunKasBank: PropsDaftarReservasiMeja['AkunKasBank'];
    saatTutup: () => void;
}) {
    const formulir = useForm({
        JumlahDp: '',
        UuidAkun: AkunKasBank.length === 1 ? (AkunKasBank[0]?.Uuid ?? '') : '',
    });
    const galat = formulir.errors as Record<string, string | undefined>;

    return (
        <DialogFormulir
            judul={`Catat uang muka ${reservasi.Nomor}`}
            keterangan={`${reservasi.NamaPemesan} | ${reservasi.JumlahTamu} tamu. Uang muka dipotong dari tagihan saat tamu membayar di kasir.`}
            saatTutup={saatTutup}
            galatUmum={galat.Umum}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    formulir.post(`${alamat}/${reservasi.Uuid}/uang-muka`, {
                        preserveScroll: true,
                        onSuccess: saatTutup,
                    });
                }}
                className="flex flex-col gap-4"
                noValidate
            >
                <BidangUang
                    label="Uang muka"
                    nilai={formulir.data.JumlahDp}
                    saatBerubah={(nilai) => formulir.setData('JumlahDp', nilai)}
                    galat={galat.JumlahDp}
                    required
                />
                <BidangPilihan
                    label="Diterima di"
                    nilai={formulir.data.UuidAkun}
                    opsi={AkunKasBank.map((a) => ({ Nilai: a.Uuid, Label: a.Nama }))}
                    saatBerubah={(nilai) => formulir.setData('UuidAkun', nilai)}
                    galat={galat.UuidAkun}
                    required
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing} disabled={formulir.data.JumlahDp === ''}>
                        Catat uang muka
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

const OpsiNasibUangMuka = [
    { Nilai: 'Dikembalikan', Label: 'Kembalikan ke tamu', Keterangan: 'Uang keluar dari kas/bank' },
    { Nilai: 'Hangus', Label: 'Hanguskan', Keterangan: 'Menjadi pendapatan lain' },
];

/**
 * Mengakhiri reservasi (batal, tidak datang, atau selesai tanpa dibayar lewat kasir). Bila masih ada sisa uang muka,
 * nasibnya wajib dipilih di sini supaya tidak ada DP yang menggantung di kewajiban.
 */
function DialogAkhiri({
    reservasi,
    tujuan,
    AkunKasBank,
    saatTutup,
}: {
    reservasi: BarisReservasiMeja;
    tujuan: StatusReservasiMeja;
    AkunKasBank: PropsDaftarReservasiMeja['AkunKasBank'];
    saatTutup: () => void;
}) {
    const adaSisa = AdaSisaUangMuka(reservasi);
    const formulir = useForm({
        Status: tujuan,
        Alasan: '',
        CaraDp: adaSisa ? 'Dikembalikan' : '',
        UuidAkun: AkunKasBank.length === 1 ? (AkunKasBank[0]?.Uuid ?? '') : '',
    });
    const d = formulir.data;
    const galat = formulir.errors as Record<string, string | undefined>;
    const batal = tujuan === 'Dibatalkan';
    const judul = batal
        ? `Batalkan ${reservasi.Nomor}?`
        : tujuan === 'TidakDatang'
          ? `Tamu ${reservasi.NamaPemesan} tidak datang?`
          : `Tandai ${reservasi.Nomor} selesai?`;

    return (
        <DialogFormulir
            judul={judul}
            keterangan={`${reservasi.NamaPemesan} | ${reservasi.JumlahTamu} tamu${batal ? '. Meja kembali tersedia untuk tamu lain.' : ''}`}
            jenis="konfirmasi"
            saatTutup={saatTutup}
            galatUmum={galat.Umum}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    formulir.post(`${alamat}/${reservasi.Uuid}/status`, { preserveScroll: true, onSuccess: saatTutup });
                }}
                className="flex flex-col gap-4"
                noValidate
            >
                {batal ? (
                    <BidangTeks
                        label="Alasan pembatalan"
                        nilai={d.Alasan}
                        saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                        galat={galat.Alasan}
                        required
                        maxLength={255}
                    />
                ) : null}
                {adaSisa ? (
                    <>
                        <p className="text-isi text-teks-utama">
                            Masih ada uang muka{' '}
                            <span className="font-semibold">{FormatRupiah(reservasi.UangMuka.Sisa)}</span>. Pilih
                            nasibnya sekarang.
                        </p>
                        <BidangPilihan
                            label="Uang muka"
                            nilai={d.CaraDp}
                            opsi={OpsiNasibUangMuka}
                            saatBerubah={(nilai) => formulir.setData('CaraDp', nilai)}
                            galat={galat.CaraDp}
                            required
                        />
                        {d.CaraDp === 'Dikembalikan' ? (
                            <BidangPilihan
                                label="Dikembalikan dari"
                                nilai={d.UuidAkun}
                                opsi={AkunKasBank.map((a) => ({ Nilai: a.Uuid, Label: a.Nama }))}
                                saatBerubah={(nilai) => formulir.setData('UuidAkun', nilai)}
                                galat={galat.UuidAkun}
                                required
                            />
                        ) : null}
                    </>
                ) : null}
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" varian={batal ? 'bahaya' : 'utama'} memproses={formulir.processing}>
                        {batal ? 'Batalkan reservasi' : 'Simpan'}
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Kembali
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

/** Menyelesaikan sisa uang muka reservasi yang sudah dibayar sebagian (sisa DP setelah tagihan lebih kecil dari DP). */
function DialogSelesaikanUangMuka({
    reservasi,
    AkunKasBank,
    saatTutup,
}: {
    reservasi: BarisReservasiMeja;
    AkunKasBank: PropsDaftarReservasiMeja['AkunKasBank'];
    saatTutup: () => void;
}) {
    const formulir = useForm({
        Cara: 'Dikembalikan',
        Alasan: '',
        UuidAkun: AkunKasBank.length === 1 ? (AkunKasBank[0]?.Uuid ?? '') : '',
    });
    const d = formulir.data;
    const galat = formulir.errors as Record<string, string | undefined>;

    return (
        <DialogFormulir
            judul={`Sisa uang muka ${reservasi.Nomor}`}
            keterangan={`Sisa ${FormatRupiah(reservasi.UangMuka.Sisa)} dari uang muka ${FormatRupiah(reservasi.UangMuka.Dicatat)}.`}
            saatTutup={saatTutup}
            galatUmum={galat.Umum}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    formulir.post(`${alamat}/${reservasi.Uuid}/uang-muka/selesai`, {
                        preserveScroll: true,
                        onSuccess: saatTutup,
                    });
                }}
                className="flex flex-col gap-4"
                noValidate
            >
                <BidangPilihan
                    label="Nasib sisa uang muka"
                    nilai={d.Cara}
                    opsi={OpsiNasibUangMuka}
                    saatBerubah={(nilai) => formulir.setData('Cara', nilai)}
                    galat={galat.Cara}
                    required
                />
                {d.Cara === 'Dikembalikan' ? (
                    <BidangPilihan
                        label="Dikembalikan dari"
                        nilai={d.UuidAkun}
                        opsi={AkunKasBank.map((a) => ({ Nilai: a.Uuid, Label: a.Nama }))}
                        saatBerubah={(nilai) => formulir.setData('UuidAkun', nilai)}
                        galat={galat.UuidAkun}
                        required
                    />
                ) : null}
                <BidangTeks
                    label="Alasan"
                    nilai={d.Alasan}
                    saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                    galat={galat.Alasan}
                    required
                    maxLength={255}
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Selesaikan uang muka
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

type Dialog =
    | { jenis: 'reservasi'; reservasi: BarisReservasiMeja | null }
    | { jenis: 'uangMuka'; reservasi: BarisReservasiMeja }
    | { jenis: 'akhiri'; reservasi: BarisReservasiMeja; tujuan: StatusReservasiMeja }
    | { jenis: 'selesaikanUangMuka'; reservasi: BarisReservasiMeja };

/**
 * FnB reservasi meja + uang muka (D-145). Meja yang sudah dipesan tidak bisa dipesan lagi pada jam yang beririsan;
 * uang muka dibukukan sebagai kewajiban dan dipotong dari tagihan saat tamu membayar di kasir.
 */
export default function HalamanDaftarReservasiMeja(props: PropsDaftarReservasiMeja) {
    const { Reservasi, OpsiStatus, OpsiOutlet, PilihanMeja, AkunKasBank, HariIni } = props;
    const [dialog, AturDialog] = useState<Dialog | null>(null);
    const Tutup = () => AturDialog(null);
    const UbahStatus = (r: BarisReservasiMeja, status: StatusReservasiMeja) =>
        router.post(`${alamat}/${r.Uuid}/status`, { Status: status }, { preserveScroll: true });
    const saring: DefinisiSaring[] = [
        { id: 'Tanggal', label: 'Tanggal', jenis: 'rentangTanggal' },
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        ...(OpsiOutlet.length > 1
            ? [
                  {
                      id: 'Outlet',
                      label: 'Outlet',
                      jenis: 'pilihan' as const,
                      opsi: OpsiOutlet.map((o) => ({ nilai: o.Uuid, label: o.Nama })),
                  },
              ]
            : []),
    ];

    return (
        <TataLetakAplikasi judul="Reservasi meja">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Catat tamu yang memesan meja lebih dulu. Satu meja tidak bisa dipesan dua kali pada jam yang beririsan.
                Uang muka (DP) dipotong dari tagihan saat tamu membayar di kasir. Reservasi layanan per staf ada di{' '}
                <Link href="/kelola/reservasi" className="font-semibold text-brand underline">
                    Reservasi layanan
                </Link>
                .
            </p>
            <AksiHalaman>
                <Tombol onClick={() => AturDialog({ jenis: 'reservasi', reservasi: null })}>Catat reservasi</Tombol>
            </AksiHalaman>
            <TabelData
                id="reservasi-meja"
                label="Daftar reservasi meja"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Reservasi }}
                ambilIdBaris={(r) => r.Uuid}
                urutBawaan="MulaiPada"
                cari="Cari nomor, nama, atau nomor HP"
                saring={saring}
                aksiBaris={(r: BarisReservasiMeja) => (
                    <>
                        {r.StatusBerikutnya.filter((s) => !StatusAkhir.includes(s)).map((s) => (
                            <DropdownMenuItem key={s} onSelect={() => UbahStatus(r, s)}>
                                {LabelAksiStatus[s] ?? s}
                            </DropdownMenuItem>
                        ))}
                        {r.BolehDiubah ? (
                            <DropdownMenuItem onSelect={() => AturDialog({ jenis: 'reservasi', reservasi: r })}>
                                Ubah reservasi
                            </DropdownMenuItem>
                        ) : null}
                        {r.BolehCatatDp ? (
                            <DropdownMenuItem onSelect={() => AturDialog({ jenis: 'uangMuka', reservasi: r })}>
                                Catat uang muka
                            </DropdownMenuItem>
                        ) : null}
                        {AdaSisaUangMuka(r) && r.StatusBerikutnya.length === 0 ? (
                            <DropdownMenuItem
                                onSelect={() => AturDialog({ jenis: 'selesaikanUangMuka', reservasi: r })}
                            >
                                Selesaikan sisa uang muka
                            </DropdownMenuItem>
                        ) : null}
                        {r.StatusBerikutnya.filter((s) => s === 'Selesai' || s === 'TidakDatang').map((s) => (
                            <DropdownMenuItem
                                key={s}
                                onSelect={() => AturDialog({ jenis: 'akhiri', reservasi: r, tujuan: s })}
                            >
                                {LabelAksiStatus[s] ?? s}
                            </DropdownMenuItem>
                        ))}
                        {r.StatusBerikutnya.includes('Dibatalkan') ? (
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => AturDialog({ jenis: 'akhiri', reservasi: r, tujuan: 'Dibatalkan' })}
                            >
                                Batalkan
                            </DropdownMenuItem>
                        ) : null}
                    </>
                )}
                kosong={{ ilustrasi: true, judul: 'Belum ada reservasi meja.' }}
            />
            {dialog?.jenis === 'reservasi' ? (
                <DialogReservasi
                    reservasi={dialog.reservasi}
                    OpsiOutlet={OpsiOutlet}
                    PilihanMeja={PilihanMeja}
                    HariIni={HariIni}
                    saatTutup={Tutup}
                />
            ) : null}
            {dialog?.jenis === 'uangMuka' ? (
                <DialogUangMuka reservasi={dialog.reservasi} AkunKasBank={AkunKasBank} saatTutup={Tutup} />
            ) : null}
            {dialog?.jenis === 'akhiri' ? (
                <DialogAkhiri
                    reservasi={dialog.reservasi}
                    tujuan={dialog.tujuan}
                    AkunKasBank={AkunKasBank}
                    saatTutup={Tutup}
                />
            ) : null}
            {dialog?.jenis === 'selesaikanUangMuka' ? (
                <DialogSelesaikanUangMuka reservasi={dialog.reservasi} AkunKasBank={AkunKasBank} saatTutup={Tutup} />
            ) : null}
        </TataLetakAplikasi>
    );
}
