import { Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangBerkas from '@/Komponen/Formulir/BidangBerkas';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { BandingkanDesimal } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisJurnalBank, BarisMutasiBank, PropsRekonsiliasiBank, StatusMutasiBank } from '@/Tipe/Akuntansi';

const dasar = '/kelola/akuntansi/rekonsiliasi';

const jenisStatus: Record<StatusMutasiBank, 'peringatan' | 'sukses' | 'netral'> = {
    BelumCocok: 'peringatan',
    Cocok: 'sukses',
    Diabaikan: 'netral',
};

/** Nominal baris jurnal dari sisi akun bank: debit = masuk, kredit = keluar. */
function NominalJurnal(j: BarisJurnalBank): string {
    return BandingkanDesimal(j.Debit, '0') > 0 ? `+${FormatRupiah(j.Debit)}` : `−${FormatRupiah(j.Kredit)}`;
}

function TautanJurnal({ j }: { j: BarisJurnalBank }) {
    return (
        <Link href={`/kelola/akuntansi/jurnal/${j.Uuid}`} className="font-mono text-brand underline">
            {j.Nomor}
        </Link>
    );
}

const kolom: KolomTabel<BarisMutasiBank>[] = [
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
    },
    {
        id: 'Keterangan',
        header: 'Keterangan bank',
        enableSorting: false,
        meta: { label: 'Keterangan bank', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: m } }) => (
            <span className="flex flex-col">
                <span className="break-words">{m.Keterangan}</span>
                {m.Jurnal ? (
                    <span className="text-keterangan text-teks-sekunder">
                        Cocok dengan <TautanJurnal j={m.Jurnal} />
                    </span>
                ) : null}
                {m.AlasanAbaikan ? (
                    <span className="text-keterangan text-teks-sekunder">Diabaikan: {m.AlasanAbaikan}</span>
                ) : null}
                {m.Status === 'BelumCocok' ? (
                    <span className="text-keterangan text-teks-sekunder">
                        {m.Kandidat.length === 0
                            ? 'Belum ada transaksi di buku. Catat di Kas & bank, atau abaikan.'
                            : `${String(m.Kandidat.length)} kemungkinan pasangan di buku`}
                    </span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'Masuk',
        accessorKey: 'Masuk',
        header: 'Masuk',
        meta: { label: 'Uang masuk', angka: true, prioritas: 'penting' },
        cell: ({ row }) => (BandingkanDesimal(row.original.Masuk, '0') > 0 ? FormatRupiah(row.original.Masuk) : '—'),
    },
    {
        id: 'Keluar',
        accessorKey: 'Keluar',
        header: 'Keluar',
        meta: { label: 'Uang keluar', angka: true, prioritas: 'penting' },
        cell: ({ row }) => (BandingkanDesimal(row.original.Keluar, '0') > 0 ? FormatRupiah(row.original.Keluar) : '—'),
    },
    {
        id: 'Saldo',
        header: 'Saldo',
        enableSorting: false,
        meta: { label: 'Saldo rekening', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => (row.original.Saldo === null ? '—' : FormatRupiah(row.original.Saldo)),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: m } }) => <LabelStatus jenis={jenisStatus[m.Status]} teks={m.LabelStatus} />,
    },
];

type Dialog = { jenis: 'cocok' | 'abaikan'; mutasi: BarisMutasiBank };

/**
 * Rekonsiliasi bank (FIN-09): impor rekening koran dari internet banking, cocokkan tiap mutasi dengan transaksi di
 * buku akun bank yang sama, lalu lihat selisih saldo rekening koran dengan saldo buku. Mutasi tanpa pasangan dicatat
 * lewat Kas & bank (biaya admin, bunga, pajak bunga) atau diabaikan dengan alasan.
 */
export default function HalamanRekonsiliasiBank({
    Mutasi,
    Akun,
    OpsiAkun,
    Ringkasan,
    BukuBelumCocok,
    OpsiStatus,
    HasilImpor,
    Izin,
}: PropsRekonsiliasiBank) {
    const alamat = `${dasar}/${Akun.Uuid}`;
    const [dialog, AturDialog] = useState<Dialog | null>(null);
    const [mencocokkan, AturMencocokkan] = useState(false);
    const unggah = useForm<{ Berkas: File[] }>({ Berkas: [] });
    const saring: DefinisiSaring[] = [
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((s) => ({ nilai: s.Nilai, label: s.Label })),
        },
        { id: 'Tanggal', label: 'Tanggal', jenis: 'rentangTanggal' },
    ];
    const Unggah = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        unggah.transform((d) => ({ Berkas: d.Berkas[0] ?? null }));
        unggah.post(`${alamat}/impor`, { forceFormData: true, preserveScroll: true, onSuccess: () => unggah.reset() });
    };
    const Putuskan = (mutasi: BarisMutasiBank, data: Record<string, string>, selesai?: () => void) =>
        router.post(`${dasar}/mutasi/${mutasi.Uuid}`, data, {
            preserveScroll: true,
            ...(selesai ? { onSuccess: selesai } : {}),
        });
    const CocokkanOtomatis = () => {
        AturMencocokkan(true);
        router.post(
            `${alamat}/cocokkan-otomatis`,
            {},
            { preserveScroll: true, onFinish: () => AturMencocokkan(false) },
        );
    };

    return (
        <TataLetakAplikasi
            judul="Rekonsiliasi bank"
            jejak={[{ label: 'Kas & bank', href: '/kelola/akuntansi/kas-bank' }]}
        >
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Unggah rekening koran dari internet banking (CSV atau Excel), lalu cocokkan setiap mutasi dengan
                transaksi di buku. Yang tidak punya pasangan biasanya biaya admin, bunga, atau transaksi yang belum
                dicatat.
            </p>
            <div className="max-w-md">
                <BidangPilihan
                    label="Akun kas/bank"
                    nilai={Akun.Uuid}
                    opsi={OpsiAkun.map((a) => ({ Nilai: a.Uuid, Label: `${a.Kode} ${a.Nama}` }))}
                    saatBerubah={(nilai) => router.visit(`${dasar}/${nilai}`)}
                />
            </div>

            <Panel judul="Ringkasan">
                <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-isi sm:grid-cols-4">
                    <div className="flex flex-col gap-1">
                        <dt className="text-teks-sekunder">
                            Saldo rekening koran
                            {Ringkasan.TanggalTerakhir ? ` (${FormatTanggal(Ringkasan.TanggalTerakhir)})` : ''}
                        </dt>
                        <dd className="tabular-nums">
                            {Ringkasan.SaldoRekeningKoran === null ? '—' : FormatRupiah(Ringkasan.SaldoRekeningKoran)}
                        </dd>
                    </div>
                    <div className="flex flex-col gap-1">
                        <dt className="text-teks-sekunder">Saldo buku per tanggal itu</dt>
                        <dd className="tabular-nums">
                            {Ringkasan.SaldoBuku === null ? '—' : FormatRupiah(Ringkasan.SaldoBuku)}
                        </dd>
                    </div>
                    <div className="flex flex-col gap-1">
                        <dt className="text-teks-sekunder">Selisih</dt>
                        <dd className="font-semibold tabular-nums">
                            {Ringkasan.Selisih === null ? '—' : FormatRupiah(Ringkasan.Selisih)}
                            {Ringkasan.Selisih !== null && BandingkanDesimal(Ringkasan.Selisih, '0') === 0
                                ? ' (sesuai)'
                                : ''}
                        </dd>
                    </div>
                    <div className="flex flex-col gap-1">
                        <dt className="text-teks-sekunder">Mutasi</dt>
                        <dd>
                            {Ringkasan.BelumCocok} belum cocok | {Ringkasan.Cocok} cocok | {Ringkasan.Diabaikan}{' '}
                            diabaikan
                        </dd>
                    </div>
                </dl>
            </Panel>

            {Izin.Kelola ? (
                <Panel judul="Unggah rekening koran">
                    <form onSubmit={Unggah} className="flex flex-col gap-3" noValidate>
                        <BidangBerkas
                            label="Berkas (.csv atau .xlsx)"
                            berkas={unggah.data.Berkas}
                            ekstensi={['csv', 'xlsx']}
                            maksimal={1}
                            ukuranMaksimalKb={5120}
                            saatBerubah={(daftar) => unggah.setData('Berkas', daftar)}
                            galat={unggah.errors.Berkas}
                        />
                        <div>
                            <Tombol
                                type="submit"
                                memproses={unggah.processing}
                                disabled={unggah.data.Berkas.length === 0}
                            >
                                Impor mutasi
                            </Tombol>
                        </div>
                    </form>
                    {HasilImpor && HasilImpor.Bermasalah.length > 0 ? (
                        <div className="mt-3">
                            <Pemberitahuan jenis="peringatan">
                                {`${String(HasilImpor.Bermasalah.length)} baris tidak bisa dibaca: `}
                                {HasilImpor.Bermasalah.map((b) => `baris ${String(b.Baris)} (${b.Pesan})`).join('; ')}
                            </Pemberitahuan>
                        </div>
                    ) : null}
                </Panel>
            ) : (
                <PesanHanyaLihat izin="akuntansi.kelola" objek="rekonsiliasi bank" />
            )}

            {Izin.Kelola && Ringkasan.BelumCocok > 0 ? (
                <AksiHalaman>
                    <Tombol varian="sekunder" onClick={CocokkanOtomatis} memproses={mencocokkan}>
                        Cocokkan otomatis
                    </Tombol>
                </AksiHalaman>
            ) : null}

            <TabelData
                id="akuntansi-rekonsiliasi-mutasi"
                label="Mutasi rekening koran"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Mutasi }}
                ambilIdBaris={(m) => m.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari keterangan bank"
                saring={saring}
                labelBaris={(m) => `mutasi ${FormatTanggal(m.Tanggal)} ${m.Keterangan}`}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (m: BarisMutasiBank) => (
                              <ItemAksiBaris
                                  aksi={
                                      m.Status === 'BelumCocok'
                                          ? [
                                                ...(m.Kandidat.length > 0
                                                    ? [
                                                          {
                                                              label: 'Cocokkan dengan buku',
                                                              saatPilih: () =>
                                                                  AturDialog({ jenis: 'cocok', mutasi: m }),
                                                          },
                                                      ]
                                                    : []),
                                                {
                                                    label: 'Abaikan mutasi',
                                                    saatPilih: () => AturDialog({ jenis: 'abaikan', mutasi: m }),
                                                },
                                            ]
                                          : [
                                                {
                                                    label: 'Batalkan keputusan',
                                                    saatPilih: () => Putuskan(m, { Keputusan: 'Batal' }),
                                                },
                                            ]
                                  }
                              />
                          ),
                      }
                    : {})}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada mutasi. Unggah rekening koran dari internet banking untuk mulai mencocokkan.',
                }}
            />

            <Panel judul="Di buku, belum ada di rekening koran">
                {BukuBelumCocok.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">
                        Semua transaksi buku di rentang rekening koran sudah punya pasangan.
                    </p>
                ) : (
                    <ul className="flex flex-col divide-y divide-garis text-isi">
                        {BukuBelumCocok.map((j) => (
                            <li
                                key={`${j.Uuid}-${j.Debit}-${j.Kredit}`}
                                className="flex flex-wrap items-baseline gap-x-3 py-2"
                            >
                                <span className="whitespace-nowrap text-teks-sekunder">{FormatTanggal(j.Tanggal)}</span>
                                <TautanJurnal j={j} />
                                <span className="min-w-0 flex-1 break-words">{j.Keterangan}</span>
                                <span className="tabular-nums">{NominalJurnal(j)}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            {dialog?.jenis === 'cocok' ? (
                <DialogFormulir
                    judul="Cocokkan dengan buku"
                    keterangan={`${FormatTanggal(dialog.mutasi.Tanggal)} | ${dialog.mutasi.Keterangan}`}
                    saatTutup={() => AturDialog(null)}
                >
                    <ul className="flex flex-col gap-2">
                        {dialog.mutasi.Kandidat.map((j) => (
                            <li
                                key={j.Uuid}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-panel border border-garis p-3"
                            >
                                <span className="flex min-w-0 flex-col">
                                    <span className="font-mono">{j.Nomor}</span>
                                    <span className="break-words text-teks-sekunder">
                                        {FormatTanggal(j.Tanggal)} | {j.Keterangan}
                                    </span>
                                </span>
                                <span className="flex items-center gap-3">
                                    <span className="tabular-nums">{NominalJurnal(j)}</span>
                                    <Tombol
                                        onClick={() =>
                                            Putuskan(dialog.mutasi, { Keputusan: 'Cocok', Jurnal: j.Uuid }, () =>
                                                AturDialog(null),
                                            )
                                        }
                                    >
                                        Pilih
                                    </Tombol>
                                </span>
                            </li>
                        ))}
                    </ul>
                </DialogFormulir>
            ) : null}
            {dialog?.jenis === 'abaikan' ? (
                <FormAbaikan mutasi={dialog.mutasi} saatSelesai={() => AturDialog(null)} />
            ) : null}
        </TataLetakAplikasi>
    );
}

function FormAbaikan({ mutasi, saatSelesai }: { mutasi: BarisMutasiBank; saatSelesai: () => void }) {
    const formulir = useForm({ Keputusan: 'Abaikan', Alasan: '' });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${dasar}/mutasi/${mutasi.Uuid}`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul="Abaikan mutasi"
            keterangan={`${FormatTanggal(mutasi.Tanggal)} | ${mutasi.Keterangan}`}
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeksPanjang
                    label="Alasan"
                    nilai={formulir.data.Alasan}
                    saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                    maksimal={255}
                    baris={2}
                    galat={formulir.errors.Alasan}
                    required
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Abaikan mutasi
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
