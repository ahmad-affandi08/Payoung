import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import PanelKalibrasiWajah from '@/Komponen/Karyawan/PanelKalibrasiWajah';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihJam from '@/Komponen/Tanggal/PemilihJam';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { TulisTanggal } from '@/Pustaka/Tanggal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type {
    BarisAbsensi,
    OpsiUuidNama,
    PermintaanKoreksiAbsensi,
    PropsAbsensi,
    StatusKehadiran,
} from '@/Tipe/Karyawan';

const alamat = '/kelola/karyawan/absensi';

/** Durasi menit → `7 j 45 m`. */
export function FormatDurasiMenit(menit: number | null): string {
    if (menit === null) {
        return '—';
    }

    const jam = Math.floor(menit / 60);
    const sisa = menit % 60;

    return jam === 0 ? `${sisa} m` : `${jam} j ${sisa} m`;
}

function JenisStatus(status: StatusKehadiran): 'sukses' | 'peringatan' | 'bahaya' | 'netral' {
    return status === 'TepatWaktu'
        ? 'sukses'
        : status === 'Terlambat' || status === 'PulangCepat' || status === 'Lembur'
          ? 'peringatan'
          : status === 'BelumKeluar'
            ? 'bahaya'
            : 'netral';
}

function TautanSwafoto({ a, jenis }: { a: BarisAbsensi; jenis: 'masuk' | 'keluar' }) {
    const ada = jenis === 'masuk' ? a.AdaSwafotoMasuk : a.AdaSwafotoKeluar;

    return ada ? (
        <a
            href={`${alamat}/${a.Uuid}/swafoto/${jenis}`}
            target="_blank"
            rel="noreferrer"
            className="text-keterangan text-brand underline"
        >
            Swafoto {jenis}
        </a>
    ) : null;
}

const kolom: KolomTabel<BarisAbsensi>[] = [
    {
        id: 'TanggalBisnis',
        accessorKey: 'TanggalBisnis',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.TanggalBisnis),
    },
    {
        id: 'Karyawan',
        header: 'Karyawan',
        enableSorting: false,
        meta: { label: 'Karyawan', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: a } }) => (
            <span className="flex flex-col">
                <span className="font-semibold break-words">{a.NamaKaryawan ?? '—'}</span>
                {a.NamaOutlet ? <span className="text-keterangan text-teks-sekunder">{a.NamaOutlet}</span> : null}
            </span>
        ),
    },
    {
        id: 'MasukPada',
        accessorKey: 'MasukPada',
        header: 'Masuk',
        meta: { label: 'Masuk', prioritas: 'penting' },
        cell: ({ row: { original: a } }) => (
            <span className="flex flex-col">
                <span className="font-mono tabular-nums">{a.JamMasuk}</span>
                <TautanSwafoto a={a} jenis="masuk" />
            </span>
        ),
    },
    {
        id: 'Keluar',
        header: 'Keluar',
        enableSorting: false,
        meta: { label: 'Keluar', prioritas: 'penting' },
        cell: ({ row: { original: a } }) => (
            <span className="flex flex-col">
                <span className="font-mono tabular-nums">
                    {a.JamKeluar ?? '—'}
                    {a.KeluarBeda ? ' (+1 hari)' : ''}
                </span>
                <TautanSwafoto a={a} jenis="keluar" />
            </span>
        ),
    },
    {
        id: 'Durasi',
        header: 'Durasi',
        enableSorting: false,
        meta: { label: 'Durasi', prioritas: 'rendah', angka: true },
        cell: ({ row }) => FormatDurasiMenit(row.original.DurasiMenit),
    },
    {
        id: 'Jadwal',
        header: 'Jadwal',
        enableSorting: false,
        meta: { label: 'Jadwal', prioritas: 'rendah' },
        cell: ({ row: { original: a } }) => (
            <span className="flex flex-col">
                <span className="font-mono tabular-nums">{a.Jadwal ?? '—'}</span>
                {a.TerlambatMenit > 0 ? (
                    <span className="text-keterangan text-teks-sekunder">Terlambat {a.TerlambatMenit} menit</span>
                ) : null}
                {a.PulangCepatMenit > 0 ? (
                    <span className="text-keterangan text-teks-sekunder">Pulang cepat {a.PulangCepatMenit} menit</span>
                ) : null}
                {a.LemburMenit > 0 ? (
                    <span className="text-keterangan text-teks-sekunder">Lembur {a.LemburMenit} menit</span>
                ) : null}
                {a.DiluarJadwal ? (
                    <span className="text-keterangan text-teks-sekunder">Di luar jadwal (perlu ditinjau)</span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: a } }) => (
            <span className="flex flex-col items-start gap-1">
                <LabelStatus jenis={JenisStatus(a.Status)} teks={a.LabelStatus} />
                {a.PerluDicek ? (
                    <span className="flex flex-col items-start gap-1">
                        <LabelStatus jenis="peringatan" teks="Perlu dicek" />
                        {a.CatatanPeriksa ? (
                            <span className="text-keterangan text-teks-sekunder">{a.CatatanPeriksa}</span>
                        ) : null}
                    </span>
                ) : null}
                {a.Sumber === 'Web' ? (
                    <span className="text-keterangan text-teks-sekunder">
                        Dari HP | {a.JarakMasukMeter ?? '—'} m dari outlet
                        {a.JarakKeluarMeter !== null ? ` (keluar ${String(a.JarakKeluarMeter)} m)` : ''} | wajah{' '}
                        {a.KemiripanWajahMasuk ?? '—'}
                    </span>
                ) : null}
                {a.Sumber === 'Manual' || a.Dikoreksi ? (
                    <span className="text-keterangan text-teks-sekunder" title={a.AlasanKoreksi ?? undefined}>
                        {a.Sumber === 'Manual' ? 'Dicatat manual' : 'Dikoreksi'}
                        {a.AlasanKoreksi ? `: ${a.AlasanKoreksi}` : ''}
                    </span>
                ) : null}
            </span>
        ),
    },
];

/** `0830` → `08:30` (ketikan tanpa titik dua); lainnya apa adanya. */
export function RapikanJamKetik(teks: string): string {
    const bersih = teks.trim();

    return /^\d{4}$/.test(bersih) ? `${bersih.slice(0, 2)}:${bersih.slice(2)}` : bersih;
}

type Dialog = { jenis: 'tambah'; permintaan?: PermintaanKoreksiAbsensi } | { jenis: 'koreksi'; absensi: BarisAbsensi };

/**
 * F-18 EMP-03: rekap absensi dari aplikasi kasir: jam masuk/keluar waktu outlet, durasi, keterlambatan, swafoto.
 * v3.34: pengelola (`karyawan.kelola`) mencatat absensi yang terlewat dan mengoreksi jam, dengan alasan yang tercatat.
 */
export default function HalamanAbsensi({
    Absensi,
    OpsiKaryawan,
    OpsiOutlet,
    BolehKoreksi,
    PermintaanKoreksi = [],
}: PropsAbsensi) {
    const [dialog, AturDialog] = useState<Dialog | null>(null);
    const Tutup = () => AturDialog(null);
    const saring: DefinisiSaring[] = [
        { id: 'TanggalBisnis', label: 'Tanggal', jenis: 'rentangTanggal' },
        {
            id: 'Karyawan',
            label: 'Karyawan',
            jenis: 'pilihanBanyak',
            opsi: OpsiKaryawan.map((k) => ({ nilai: k.Uuid, label: k.Nama })),
        },
        {
            id: 'Outlet',
            label: 'Outlet',
            jenis: 'pilihanBanyak',
            opsi: OpsiOutlet.map((o) => ({ nilai: o.Uuid, label: o.Nama })),
        },
        {
            id: 'BelumKeluar',
            label: 'Belum absen keluar',
            jenis: 'pilihan',
            opsi: [{ nilai: '1', label: 'Ya' }],
        },
        {
            id: 'DiluarJadwal',
            label: 'Di luar jadwal',
            jenis: 'pilihan',
            opsi: [{ nilai: '1', label: 'Ya' }],
        },
        {
            id: 'PerluDicek',
            label: 'Perlu dicek',
            jenis: 'pilihan',
            opsi: [{ nilai: '1', label: 'Ya' }],
        },
    ];

    return (
        <TataLetakAplikasi judul="Absensi">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Absen masuk & pulang karyawan dari HP (login, lokasi, dan pencocokan wajah). Jam memakai zona waktu
                outlet. Terlambat dihitung dari jadwal kerja. Absen yang lokasinya janggal tetap tercatat dan ditandai
                Perlu dicek.
            </p>
            {BolehKoreksi ? (
                <AksiHalaman>
                    <Tombol onClick={() => AturDialog({ jenis: 'tambah' })}>Catat absensi terlewat</Tombol>
                </AksiHalaman>
            ) : null}
            {BolehKoreksi && PermintaanKoreksi.length > 0 ? (
                <PanelPermintaanKoreksi
                    daftar={PermintaanKoreksi}
                    saatCatat={(permintaan) => AturDialog({ jenis: 'tambah', permintaan })}
                />
            ) : null}
            <TabelData
                id="karyawan-absensi"
                label="Rekap absensi"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Absensi }}
                ambilIdBaris={(a) => a.Uuid}
                urutBawaan="-MasukPada"
                cari="Cari nama karyawan"
                saring={saring}
                {...(BolehKoreksi
                    ? {
                          aksiBaris: (a: BarisAbsensi) => (
                              <>
                                  <DropdownMenuItem onSelect={() => AturDialog({ jenis: 'koreksi', absensi: a })}>
                                      Koreksi jam
                                  </DropdownMenuItem>
                                  {a.PerluDicek ? (
                                      <DropdownMenuItem
                                          onSelect={() =>
                                              router.post(`${alamat}/${a.Uuid}/dicek`, {}, { preserveScroll: true })
                                          }
                                      >
                                          Tandai sudah dicek
                                      </DropdownMenuItem>
                                  ) : null}
                              </>
                          ),
                      }
                    : {})}
                labelBaris={(a) => `absensi ${a.NamaKaryawan ?? ''} ${FormatTanggal(a.TanggalBisnis)}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada absensi. Karyawan absen dari aplikasi kasir di outlet.' }}
            />
            {BolehKoreksi ? <PanelKalibrasiWajah /> : null}
            {dialog !== null ? (
                <FormAbsensi
                    absensi={dialog.jenis === 'koreksi' ? dialog.absensi : null}
                    permintaan={dialog.jenis === 'tambah' ? (dialog.permintaan ?? null) : null}
                    opsiKaryawan={OpsiKaryawan}
                    opsiOutlet={OpsiOutlet}
                    saatSelesai={Tutup}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}

function KeOpsi(daftar: OpsiUuidNama[]) {
    return daftar.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }));
}

/** Satu formulir untuk tambah (absensi null) dan koreksi; tanggal = tanggal bisnis, jam = jam dinding outlet. */
function FormAbsensi({
    absensi,
    permintaan,
    opsiKaryawan,
    opsiOutlet,
    saatSelesai,
}: {
    absensi: BarisAbsensi | null;
    /** D-180: absensi ini menjawab permintaan koreksi karyawan; permintaannya ikut ditutup. */
    permintaan: PermintaanKoreksiAbsensi | null;
    opsiKaryawan: OpsiUuidNama[];
    opsiOutlet: OpsiUuidNama[];
    saatSelesai: () => void;
}) {
    const hariIni = TulisTanggal(new Date());
    const formulir = useForm({
        Outlet: permintaan?.UuidOutlet ?? '',
        Karyawan: permintaan?.UuidKaryawan ?? '',
        UuidPermintaan: permintaan?.Uuid ?? '',
        Tanggal: absensi?.TanggalBisnis ?? hariIni,
        JamMasuk: absensi?.JamMasuk ?? '',
        JamKeluar: absensi?.JamKeluar ?? '',
        KeluarHariBerikutnya: absensi?.KeluarBeda ?? false,
        Alasan: '',
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.transform((d) => ({
            ...d,
            JamKeluar: d.JamKeluar === '' ? null : d.JamKeluar,
            UuidPermintaan: d.UuidPermintaan === '' ? null : d.UuidPermintaan,
        }));

        if (absensi === null) {
            formulir.post(alamat, { preserveScroll: true, onSuccess: saatSelesai });
        } else {
            formulir.put(`${alamat}/${absensi.Uuid}`, { preserveScroll: true, onSuccess: saatSelesai });
        }
    };

    const Jam = (kolom: 'JamMasuk' | 'JamKeluar', label: string, wajib: boolean) => (
        <PemilihJam
            label={label}
            nilai={formulir.data[kolom]}
            saatBerubah={(nilai) => formulir.setData(kolom, nilai)}
            galat={formulir.errors[kolom]}
            keterangan="Jam outlet. Ketik (misal 0830) atau pilih."
            tombolSekarang
            required={wajib}
        />
    );

    return (
        <DialogFormulir
            judul={absensi === null ? 'Catat absensi terlewat' : `Koreksi absensi ${absensi.NamaKaryawan ?? ''}`}
            keterangan={
                absensi === null
                    ? 'Untuk karyawan yang lupa absen di kasir. Tercatat sebagai absensi manual atas nama Anda.'
                    : `${FormatTanggal(absensi.TanggalBisnis)}, ${absensi.NamaOutlet ?? ''}. Jam lama tetap tersimpan di log audit.`
            }
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                {absensi === null ? (
                    <>
                        <BidangOutlet
                            nilai={formulir.data.Outlet}
                            opsi={KeOpsi(opsiOutlet)}
                            saatBerubah={(nilai) => formulir.setData('Outlet', nilai)}
                            galat={formulir.errors.Outlet}
                        />
                        <BidangPilihan
                            label="Karyawan"
                            nilai={formulir.data.Karyawan}
                            opsi={KeOpsi(opsiKaryawan)}
                            saatBerubah={(nilai) => formulir.setData('Karyawan', nilai)}
                            galat={formulir.errors.Karyawan}
                            required
                        />
                    </>
                ) : null}
                <PemilihTanggal
                    label="Tanggal masuk"
                    nilai={formulir.data.Tanggal}
                    saatBerubah={(nilai) => formulir.setData('Tanggal', nilai)}
                    galat={formulir.errors.Tanggal}
                    max={hariIni}
                    required
                />
                {Jam('JamMasuk', 'Jam masuk', true)}
                {Jam('JamKeluar', 'Jam keluar (kosongkan bila belum)', false)}
                <div className="flex items-end pb-2">
                    <KotakCentang
                        label="Keluar keesokan harinya (shift malam)"
                        nilai={formulir.data.KeluarHariBerikutnya}
                        saatBerubah={(nilai) => formulir.setData('KeluarHariBerikutnya', nilai)}
                    />
                </div>
                <div className="sm:col-span-2">
                    <BidangTeks
                        label="Alasan"
                        nilai={formulir.data.Alasan}
                        saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                        galat={formulir.errors.Alasan}
                        keterangan="Misal: lupa absen keluar, HP kasir mati. Minimal 5 huruf."
                        maxLength={255}
                        required
                    />
                </div>
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        {absensi === null ? 'Simpan absensi' : 'Simpan koreksi'}
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

/** D-180: karyawan yang gagal absen meminta manajer mencatat jamnya; manajer mencatat absensinya atau menutup permintaan. */
function PanelPermintaanKoreksi({
    daftar,
    saatCatat,
}: {
    daftar: PermintaanKoreksiAbsensi[];
    saatCatat: (permintaan: PermintaanKoreksiAbsensi) => void;
}) {
    const Tutup = (uuid: string, status: 'Selesai' | 'Ditolak') =>
        router.post(`${alamat}/permintaan-koreksi/${uuid}/tutup`, { Status: status }, { preserveScroll: true });

    return (
        <Panel
            judul="Permintaan koreksi absensi"
            keterangan="Karyawan ini gagal absen dan meminta Anda mencatat jamnya. Periksa alasannya, catat absensinya, atau tutup permintaan."
        >
            <ul className="flex flex-col gap-3" aria-label="Permintaan koreksi absensi">
                {daftar.map((p) => (
                    <li
                        key={p.Uuid}
                        className="flex flex-wrap items-start justify-between gap-3 rounded-panel border border-garis p-3"
                    >
                        <span className="flex min-w-0 flex-col gap-0.5">
                            <span className="text-isi font-semibold break-words text-teks-utama">
                                {p.NamaKaryawan} | absen {p.Jenis === 'Masuk' ? 'masuk' : 'pulang'}
                            </span>
                            <span className="text-keterangan break-words text-teks-sekunder">{p.Alasan}</span>
                            <span className="text-keterangan text-teks-sekunder">
                                {FormatTanggalWaktu(p.DiajukanPada)}
                                {p.NamaOutlet ? ` | ${p.NamaOutlet}` : ''}
                            </span>
                        </span>
                        <span className="flex flex-wrap gap-2">
                            <Tombol onClick={() => saatCatat(p)}>Catat absensi</Tombol>
                            <Tombol varian="sekunder" onClick={() => Tutup(p.Uuid, 'Selesai')}>
                                Tutup
                            </Tombol>
                            <Tombol varian="sekunder" onClick={() => Tutup(p.Uuid, 'Ditolak')}>
                                Tolak
                            </Tombol>
                        </span>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}
