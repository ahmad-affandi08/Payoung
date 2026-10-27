import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import PemilihSlot from '@/Komponen/Reservasi/PemilihSlot';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type {
    BarisReservasi,
    LayananReservasi,
    PengaturanReservasi,
    PropsDaftarReservasi,
    StatusReservasi,
} from '@/Tipe/Reservasi';

const alamat = '/kelola/reservasi';

const JenisLabelStatus: Record<StatusReservasi, 'netral' | 'sukses' | 'peringatan' | 'bahaya'> = {
    Menunggu: 'peringatan',
    Dikonfirmasi: 'netral',
    Hadir: 'sukses',
    Selesai: 'sukses',
    Batal: 'bahaya',
    TidakDatang: 'bahaya',
};

const LabelAksiStatus: Partial<Record<StatusReservasi, string>> = {
    Dikonfirmasi: 'Konfirmasi',
    Hadir: 'Pelanggan sudah datang',
    Selesai: 'Tandai selesai',
    TidakDatang: 'Tidak datang',
};

/** Tanggal & jam reservasi dalam zona waktu peramban (toko & pengguna di Indonesia). */
export function FormatWaktuReservasi(mulai: string, selesai: string): string {
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

const kolom: KolomTabel<BarisReservasi>[] = [
    {
        id: 'MulaiPada',
        accessorKey: 'MulaiPada',
        header: 'Waktu',
        meta: { label: 'Waktu', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: r } }) => (
            <span className="flex flex-col gap-0.5">
                <span className="font-semibold whitespace-nowrap text-teks-utama tabular-nums">
                    {FormatWaktuReservasi(r.MulaiPada, r.SelesaiPada)}
                </span>
                <span className="font-mono text-keterangan text-teks-sekunder">{r.Nomor}</span>
            </span>
        ),
    },
    {
        id: 'Pelanggan',
        header: 'Pelanggan',
        enableSorting: false,
        meta: { label: 'Pelanggan', prioritas: 'penting' },
        cell: ({ row: { original: r } }) => (
            <span className="flex flex-col gap-0.5 break-words">
                <span className="text-teks-utama">{r.NamaPelanggan}</span>
                <span className="text-keterangan text-teks-sekunder tabular-nums">{r.NoHp}</span>
            </span>
        ),
    },
    {
        id: 'Layanan',
        header: 'Layanan',
        enableSorting: false,
        meta: { label: 'Layanan', prioritas: 'penting' },
        cell: ({ row: { original: r } }) => (
            <span className="flex flex-col gap-0.5 break-words">
                <span>{r.Layanan}</span>
                <span className="text-keterangan text-teks-sekunder">{r.Staf?.Nama ?? 'Staf belum ditentukan'}</span>
            </span>
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
    {
        id: 'Sumber',
        header: 'Sumber',
        enableSorting: false,
        meta: { label: 'Sumber', prioritas: 'rendah' },
        cell: ({ row }) => row.original.LabelSumber,
    },
];

function OpsiLayanan(layanan: LayananReservasi[]) {
    return layanan.map((l) => ({
        Nilai: l.Uuid,
        Label: l.Nama,
        Keterangan: `${String(l.DurasiMenit)} menit${l.Harga ? ` | ${FormatRupiah(l.Harga)}` : ''}`,
    }));
}

type PropsDialogCatat = Pick<PropsDaftarReservasi, 'OpsiOutlet' | 'OpsiStaf' | 'OpsiLayanan' | 'HariIni'> & {
    saatTutup: () => void;
};

/** Catat reservasi dari telepon/WhatsApp/datang langsung. */
function DialogCatat({ OpsiOutlet, OpsiStaf, OpsiLayanan: layanan, HariIni, saatTutup }: PropsDialogCatat) {
    const formulir = useForm({
        Outlet: '',
        UuidLayanan: '',
        Tanggal: HariIni,
        UuidStaf: '',
        Jam: '',
        NamaPelanggan: '',
        NoHp: '',
        Catatan: '',
    });
    const d = formulir.data;
    const Kirim = (e: FormEvent) => {
        e.preventDefault();
        formulir.post(alamat, { preserveScroll: true, onSuccess: saatTutup });
    };

    return (
        <DialogFormulir
            judul="Catat reservasi"
            lebar="lebar"
            saatTutup={saatTutup}
            galatUmum={(formulir.errors as Record<string, string | undefined>).Umum}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <BidangOutlet
                    nilai={d.Outlet}
                    opsi={OpsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                    saatBerubah={(nilai) => formulir.setData({ ...d, Outlet: nilai, Jam: '' })}
                    galat={formulir.errors.Outlet}
                    sembunyiBilaTunggal
                />
                <BidangPilihan
                    label="Layanan"
                    nilai={d.UuidLayanan}
                    opsi={OpsiLayanan(layanan)}
                    saatBerubah={(nilai) => formulir.setData({ ...d, UuidLayanan: nilai, Jam: '' })}
                    galat={formulir.errors.UuidLayanan}
                    required
                />
                <PemilihTanggal
                    label="Tanggal"
                    nilai={d.Tanggal}
                    min={HariIni}
                    saatBerubah={(nilai) => formulir.setData({ ...d, Tanggal: nilai, Jam: '' })}
                    galat={formulir.errors.Tanggal}
                    required
                />
                <BidangPilihan
                    label="Staf"
                    nilai={d.UuidStaf}
                    opsi={[
                        { Nilai: '', Label: 'Siapa saja yang kosong' },
                        ...OpsiStaf.map((s) => ({ Nilai: s.Uuid, Label: s.Nama })),
                    ]}
                    saatBerubah={(nilai) => formulir.setData({ ...d, UuidStaf: nilai, Jam: '' })}
                    galat={formulir.errors.UuidStaf}
                />
                <div className="sm:col-span-2">
                    <PemilihSlot
                        alamat={`${alamat}/slot`}
                        outlet={d.Outlet}
                        layanan={d.UuidLayanan}
                        tanggal={d.Tanggal}
                        staf={d.UuidStaf}
                        nilai={d.Jam}
                        saatPilih={(jam) => formulir.setData('Jam', jam)}
                        galat={formulir.errors.Jam}
                    />
                </div>
                <BidangTeks
                    label="Nama pelanggan"
                    nilai={d.NamaPelanggan}
                    saatBerubah={(nilai) => formulir.setData('NamaPelanggan', nilai)}
                    galat={formulir.errors.NamaPelanggan}
                    required
                />
                <BidangTeks
                    label="Nomor HP (WhatsApp)"
                    nilai={d.NoHp}
                    saatBerubah={(nilai) => formulir.setData('NoHp', nilai)}
                    galat={formulir.errors.NoHp}
                    inputMode="tel"
                    required
                />
                <div className="sm:col-span-2">
                    <BidangTeks
                        label="Catatan (opsional)"
                        nilai={d.Catatan}
                        saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                        galat={formulir.errors.Catatan}
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

/** Pindah jadwal reservasi yang belum datang. */
function DialogJadwalUlang({
    reservasi,
    layanan,
    OpsiStaf,
    HariIni,
    saatTutup,
}: {
    reservasi: BarisReservasi;
    layanan: LayananReservasi | undefined;
    OpsiStaf: PropsDaftarReservasi['OpsiStaf'];
    HariIni: string;
    saatTutup: () => void;
}) {
    const formulir = useForm({ Tanggal: HariIni, Jam: '', UuidStaf: reservasi.Staf?.Uuid ?? '' });
    const d = formulir.data;

    return (
        <DialogFormulir
            judul={`Pindah jadwal ${reservasi.Nomor}`}
            keterangan={`${reservasi.NamaPelanggan} | ${reservasi.Layanan}`}
            saatTutup={saatTutup}
            galatUmum={(formulir.errors as Record<string, string | undefined>).Umum}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    formulir.post(`${alamat}/${reservasi.Uuid}/jadwal-ulang`, {
                        preserveScroll: true,
                        onSuccess: saatTutup,
                    });
                }}
                className="flex flex-col gap-4"
                noValidate
            >
                <PemilihTanggal
                    label="Tanggal baru"
                    nilai={d.Tanggal}
                    min={HariIni}
                    saatBerubah={(nilai) => formulir.setData({ ...d, Tanggal: nilai, Jam: '' })}
                    galat={formulir.errors.Tanggal}
                    required
                />
                <BidangPilihan
                    label="Staf"
                    nilai={d.UuidStaf}
                    opsi={[
                        { Nilai: '', Label: 'Siapa saja yang kosong' },
                        ...OpsiStaf.map((s) => ({ Nilai: s.Uuid, Label: s.Nama })),
                    ]}
                    saatBerubah={(nilai) => formulir.setData({ ...d, UuidStaf: nilai, Jam: '' })}
                />
                {layanan && reservasi.Outlet.Uuid ? (
                    <PemilihSlot
                        alamat={`${alamat}/slot`}
                        outlet={reservasi.Outlet.Uuid}
                        layanan={layanan.Uuid}
                        tanggal={d.Tanggal}
                        staf={d.UuidStaf}
                        nilai={d.Jam}
                        saatPilih={(jam) => formulir.setData('Jam', jam)}
                        galat={formulir.errors.Jam}
                    />
                ) : (
                    <p className="text-keterangan text-bahaya">Layanan ini sudah tidak bisa direservasi.</p>
                )}
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing} disabled={d.Jam === ''}>
                        Pindahkan jadwal
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

function DialogBatal({ reservasi, saatTutup }: { reservasi: BarisReservasi; saatTutup: () => void }) {
    const formulir = useForm({ Status: 'Batal', Alasan: '' });

    return (
        <DialogFormulir
            judul={`Batalkan ${reservasi.Nomor}?`}
            keterangan={`${reservasi.NamaPelanggan} | ${reservasi.Layanan}. Jam ini kembali tersedia untuk pelanggan lain.`}
            jenis="konfirmasi"
            saatTutup={saatTutup}
            galatUmum={(formulir.errors as Record<string, string | undefined>).Umum}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    formulir.post(`${alamat}/${reservasi.Uuid}/status`, { preserveScroll: true, onSuccess: saatTutup });
                }}
                className="flex flex-col gap-4"
                noValidate
            >
                <BidangTeks
                    label="Alasan pembatalan"
                    nilai={formulir.data.Alasan}
                    saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                    galat={formulir.errors.Alasan}
                    required
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" varian="bahaya" memproses={formulir.processing}>
                        Batalkan reservasi
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Kembali
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

const OpsiInterval = [10, 15, 20, 30, 45, 60].map((m) => ({ Nilai: String(m), Label: `Tiap ${String(m)} menit` }));
const OpsiJeda = [0, 5, 10, 15, 30].map((m) => ({
    Nilai: String(m),
    Label: m === 0 ? 'Tanpa jeda' : `${String(m)} menit`,
}));
const OpsiMinimal = [0, 30, 60, 120, 180, 1440].map((m) => ({
    Nilai: String(m),
    Label: m === 0 ? 'Kapan saja' : m >= 1440 ? '1 hari sebelumnya' : `${String(m / 60)} jam sebelumnya`,
}));
const OpsiHariKeDepan = [7, 14, 30, 60, 90].map((h) => ({ Nilai: String(h), Label: `${String(h)} hari ke depan` }));

function DialogPengaturan({ pengaturan, saatTutup }: { pengaturan: PengaturanReservasi; saatTutup: () => void }) {
    const formulir = useForm<PengaturanReservasi>({ ...pengaturan });
    const d = formulir.data;
    const AturAngka = (kunci: keyof PengaturanReservasi) => (nilai: string) => formulir.setData(kunci, Number(nilai));

    return (
        <DialogFormulir
            judul="Pengaturan reservasi"
            lebar="lebar"
            saatTutup={saatTutup}
            galatUmum={(formulir.errors as Record<string, string | undefined>).Umum}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    formulir.put(`${alamat}/pengaturan`, { preserveScroll: true, onSuccess: saatTutup });
                }}
                className="grid gap-4 sm:grid-cols-2"
                noValidate
            >
                <div className="flex flex-col gap-3 sm:col-span-2">
                    <KotakCentang
                        label="Terima reservasi online dari halaman publik"
                        nilai={d.OnlineAktif}
                        saatBerubah={(nilai) => formulir.setData('OnlineAktif', nilai)}
                    />
                    <KotakCentang
                        label="Reservasi online langsung dikonfirmasi"
                        nilai={d.KonfirmasiOtomatis}
                        saatBerubah={(nilai) => formulir.setData('KonfirmasiOtomatis', nilai)}
                    />
                    <KotakCentang
                        label="Kirim pengingat WhatsApp sehari sebelumnya"
                        nilai={d.PengingatAktif}
                        saatBerubah={(nilai) => formulir.setData('PengingatAktif', nilai)}
                    />
                </div>
                <BidangPilihan
                    label="Pilihan jam mulai"
                    nilai={String(d.IntervalSlotMenit)}
                    opsi={OpsiInterval}
                    saatBerubah={AturAngka('IntervalSlotMenit')}
                    galat={formulir.errors.IntervalSlotMenit}
                />
                <BidangPilihan
                    label="Jeda antar layanan"
                    nilai={String(d.JedaMenit)}
                    opsi={OpsiJeda}
                    saatBerubah={AturAngka('JedaMenit')}
                    galat={formulir.errors.JedaMenit}
                />
                <BidangPilihan
                    label="Pesan online paling cepat"
                    nilai={String(d.MinimalMenitSebelum)}
                    opsi={OpsiMinimal}
                    saatBerubah={AturAngka('MinimalMenitSebelum')}
                    galat={formulir.errors.MinimalMenitSebelum}
                />
                <BidangPilihan
                    label="Pesan online paling jauh"
                    nilai={String(d.BatasHariKeDepan)}
                    opsi={OpsiHariKeDepan}
                    saatBerubah={AturAngka('BatasHariKeDepan')}
                    galat={formulir.errors.BatasHariKeDepan}
                />
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan pengaturan
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
    | { jenis: 'catat' }
    | { jenis: 'pengaturan' }
    | { jenis: 'jadwal'; reservasi: BarisReservasi }
    | { jenis: 'batal'; reservasi: BarisReservasi };

/**
 * F-07 mode service: reservasi layanan jasa (salon, barbershop, spa, klinik kecantikan). Jam kosong dihitung dari
 * jadwal kerja staf dan durasi layanan; staf tidak bisa dipesan dua kali pada jam yang sama.
 */
export default function HalamanDaftarReservasi(props: PropsDaftarReservasi) {
    const {
        Reservasi,
        OpsiStatus,
        OpsiOutlet,
        OpsiStaf,
        OpsiLayanan: layanan,
        Pengaturan,
        HariIni,
        TautanPublik,
        Izin,
    } = props;
    const [dialog, AturDialog] = useState<Dialog | null>(null);
    const Tutup = () => AturDialog(null);
    const UbahStatus = (r: BarisReservasi, status: StatusReservasi) =>
        router.post(`${alamat}/${r.Uuid}/status`, { Status: status }, { preserveScroll: true });
    const saring: DefinisiSaring[] = [
        { id: 'Tanggal', label: 'Tanggal', jenis: 'rentangTanggal' },
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        { id: 'Staf', label: 'Staf', jenis: 'pilihan', opsi: OpsiStaf.map((s) => ({ nilai: s.Uuid, label: s.Nama })) },
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
        <TataLetakAplikasi judul="Reservasi">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Reservasi layanan per staf. Jam kosong mengikuti jadwal kerja staf dan durasi layanan.{' '}
                {Pengaturan.OnlineAktif ? (
                    <>
                        Pelanggan bisa memesan sendiri di{' '}
                        <a
                            href={TautanPublik}
                            className="font-semibold break-all text-brand underline"
                            target="_blank"
                            rel="noreferrer"
                        >
                            {TautanPublik}
                        </a>
                        .
                    </>
                ) : (
                    'Reservasi online belum aktif.'
                )}
            </p>
            {layanan.length === 0 ? (
                <p className="max-w-3xl text-keterangan text-teks-sekunder">
                    Belum ada layanan yang bisa direservasi. Isi &quot;Durasi layanan&quot; pada produk berjenis Jasa.
                </p>
            ) : null}
            <AksiHalaman
                utama={
                    <Tombol onClick={() => AturDialog({ jenis: 'catat' })} disabled={layanan.length === 0}>
                        Catat reservasi
                    </Tombol>
                }
                lainnya={
                    Izin.Pengaturan
                        ? [{ label: 'Pengaturan', saatKlik: () => AturDialog({ jenis: 'pengaturan' }) }]
                        : []
                }
            />
            <TabelData
                id="reservasi"
                label="Daftar reservasi"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Reservasi }}
                ambilIdBaris={(r) => r.Uuid}
                urutBawaan="MulaiPada"
                cari="Cari nomor, nama, atau nomor HP"
                saring={saring}
                aksiBaris={(r: BarisReservasi) => (
                    <>
                        {r.StatusBerikutnya.filter((s) => s !== 'Batal').map((s) => (
                            <DropdownMenuItem key={s} onSelect={() => UbahStatus(r, s)}>
                                {LabelAksiStatus[s] ?? s}
                            </DropdownMenuItem>
                        ))}
                        {r.Status === 'Menunggu' || r.Status === 'Dikonfirmasi' ? (
                            <DropdownMenuItem onSelect={() => AturDialog({ jenis: 'jadwal', reservasi: r })}>
                                Pindah jadwal
                            </DropdownMenuItem>
                        ) : null}
                        {r.StatusBerikutnya.includes('Batal') ? (
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => AturDialog({ jenis: 'batal', reservasi: r })}
                            >
                                Batalkan
                            </DropdownMenuItem>
                        ) : null}
                    </>
                )}
                kosong={{ ilustrasi: true, judul: 'Belum ada reservasi.' }}
            />
            {dialog?.jenis === 'catat' ? (
                <DialogCatat
                    OpsiOutlet={OpsiOutlet}
                    OpsiStaf={OpsiStaf}
                    OpsiLayanan={layanan}
                    HariIni={HariIni}
                    saatTutup={Tutup}
                />
            ) : null}
            {dialog?.jenis === 'pengaturan' ? <DialogPengaturan pengaturan={Pengaturan} saatTutup={Tutup} /> : null}
            {dialog?.jenis === 'jadwal' ? (
                <DialogJadwalUlang
                    reservasi={dialog.reservasi}
                    layanan={layanan.find((l) => l.Nama === dialog.reservasi.Layanan)}
                    OpsiStaf={OpsiStaf}
                    HariIni={HariIni}
                    saatTutup={Tutup}
                />
            ) : null}
            {dialog?.jenis === 'batal' ? <DialogBatal reservasi={dialog.reservasi} saatTutup={Tutup} /> : null}
        </TataLetakAplikasi>
    );
}
