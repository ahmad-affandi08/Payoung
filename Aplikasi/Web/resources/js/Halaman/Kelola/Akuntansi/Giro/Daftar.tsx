import { Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, HasilTabel, KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisGiro, PropsDaftarGiro, RingkasanGiro } from '@/Tipe/Akuntansi';

const alamat = '/kelola/akuntansi/giro';

const kolom: KolomTabel<BarisGiro>[] = [
    {
        id: 'NomorGiro',
        header: 'Giro',
        enableSorting: false,
        meta: { label: 'Giro', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: g } }) => (
            <span className="flex flex-col">
                <span className="font-mono font-semibold break-all">{g.NomorGiro}</span>
                <span className="text-keterangan text-teks-sekunder">{g.NamaBank}</span>
            </span>
        ),
    },
    {
        id: 'Arah',
        header: 'Arah',
        enableSorting: false,
        meta: { label: 'Arah', prioritas: 'penting' },
        cell: ({ row: { original: g } }) => (
            <span className="flex flex-col">
                <span>{g.Arah === 'Masuk' ? `Dari ${g.NamaPihak}` : `Ke ${g.NamaPihak}`}</span>
                <span className="font-mono text-keterangan text-teks-sekunder">{g.NomorSumber}</span>
            </span>
        ),
    },
    {
        id: 'TanggalJatuhTempo',
        accessorKey: 'TanggalJatuhTempo',
        header: 'Tanggal efektif',
        meta: { label: 'Tanggal efektif', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.TanggalJatuhTempo),
    },
    {
        id: 'Jumlah',
        accessorKey: 'Jumlah',
        header: 'Jumlah',
        meta: { label: 'Jumlah', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.Jumlah),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: g } }) => (
            <span className="flex flex-col">
                <LabelStatus
                    jenis={g.Status === 'Cair' ? 'sukses' : g.Status === 'Ditolak' ? 'bahaya' : 'peringatan'}
                    teks={g.LabelStatus}
                />
                {g.JurnalCair ? (
                    <Link
                        href={`/kelola/akuntansi/jurnal/${g.JurnalCair.Uuid}`}
                        className="font-mono text-keterangan text-brand underline"
                    >
                        {g.JurnalCair.Nomor}
                    </Link>
                ) : null}
                {g.AlasanTolak ? <span className="text-keterangan text-teks-sekunder">{g.AlasanTolak}</span> : null}
            </span>
        ),
    },
];

function Ringkasan({ ringkasan }: { ringkasan: RingkasanGiro | undefined }) {
    if (ringkasan === undefined) {
        return null;
    }

    return (
        <section aria-label="Ringkasan giro belum cair" className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <div className="min-w-0 rounded-panel border border-garis bg-permukaan px-3 py-2">
                <p className="text-label text-teks-sekunder">Giro masuk belum cair</p>
                <p className="text-subjudul font-semibold tabular-nums">{FormatRupiah(ringkasan.MasukNilai)}</p>
                <p className="text-keterangan text-teks-sekunder">
                    {ringkasan.MasukJumlah.toLocaleString('id-ID')} giro
                </p>
            </div>
            <div className="min-w-0 rounded-panel border border-garis bg-permukaan px-3 py-2">
                <p className="text-label text-teks-sekunder">Giro keluar belum cair</p>
                <p className="text-subjudul font-semibold tabular-nums">{FormatRupiah(ringkasan.KeluarNilai)}</p>
                <p className="text-keterangan text-teks-sekunder">
                    {ringkasan.KeluarJumlah.toLocaleString('id-ID')} giro
                </p>
            </div>
        </section>
    );
}

/**
 * v3.42 (F-12): giro/cek mundur dari pelanggan & ke pemasok. Giro dibuat dari pelunasan piutang atau pembayaran hutang;
 * di sini dicatat cair ke rekening bank, atau ditolak (pelunasan/pembayarannya dibatalkan otomatis).
 */
export default function HalamanDaftarGiro({ Giro, OpsiStatus, OpsiArah, OpsiAkun, HariIni, Izin }: PropsDaftarGiro) {
    const [cair, AturCair] = useState<BarisGiro | null>(null);
    const [tolak, AturTolak] = useState<BarisGiro | null>(null);
    const saring: DefinisiSaring[] = [
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        {
            id: 'Arah',
            label: 'Arah',
            jenis: 'pilihan',
            opsi: OpsiArah.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
    ];

    return (
        <TataLetakAplikasi
            judul="Giro & cek mundur"
            jejak={[{ label: 'Kas & bank', href: '/kelola/akuntansi/kas-bank' }]}
        >
            <AksiHalaman
                keterangan="Giro dicatat saat menerima pelunasan piutang atau membayar hutang dengan cara bayar giro."
                lainnya={[
                    { label: 'Terima pelunasan', href: '/kelola/piutang/pelunasan/buat' },
                    { label: 'Bayar hutang', href: '/kelola/pembelian/pembayaran/buat' },
                ]}
            />
            <TabelData
                id="akuntansi-giro"
                label="Daftar giro"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Giro }}
                ambilIdBaris={(g) => g.Uuid}
                urutBawaan="TanggalJatuhTempo"
                cari="Cari nomor giro, bank, pihak, atau nomor dokumen"
                saring={saring}
                ringkasan={(hasil) => (
                    <Ringkasan
                        ringkasan={
                            (hasil as HasilTabel<BarisGiro, RingkasanGiro> | undefined)?.Ringkasan ?? Giro.Ringkasan
                        }
                    />
                )}
                labelBaris={(g) => `giro ${g.NomorGiro}`}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (g: BarisGiro) =>
                              g.Status === 'Menunggu' ? (
                                  <ItemAksiBaris
                                      aksi={[
                                          { label: 'Catat cair', saatPilih: () => AturCair(g) },
                                          { label: 'Tolak giro', saatPilih: () => AturTolak(g) },
                                      ]}
                                  />
                              ) : null,
                      }
                    : {})}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada giro. Giro muncul di sini saat pelunasan atau pembayaran memakai giro/cek mundur.',
                }}
            />

            {cair ? (
                <FormCair giro={cair} opsiAkun={OpsiAkun} hariIni={HariIni} saatSelesai={() => AturCair(null)} />
            ) : null}
            {tolak ? <FormTolak giro={tolak} saatSelesai={() => AturTolak(null)} /> : null}
        </TataLetakAplikasi>
    );
}

function FormCair({
    giro,
    opsiAkun,
    hariIni,
    saatSelesai,
}: {
    giro: BarisGiro;
    opsiAkun: PropsDaftarGiro['OpsiAkun'];
    hariIni: string;
    saatSelesai: () => void;
}) {
    const formulir = useForm({ UuidAkun: opsiAkun[0]?.Uuid ?? '', Tanggal: hariIni });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${alamat}/${giro.Uuid}/cair`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul={`Catat giro ${giro.NomorGiro} cair`}
            keterangan={`${FormatRupiah(giro.Jumlah)} ${giro.Arah === 'Masuk' ? 'masuk ke' : 'keluar dari'} rekening yang dipilih, paling cepat tanggal ${FormatTanggal(giro.TanggalJatuhTempo)}.`}
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <BidangPilihan
                    label="Rekening"
                    nilai={formulir.data.UuidAkun}
                    opsi={opsiAkun.map((a) => ({ Nilai: a.Uuid, Label: `${a.Kode} ${a.Nama}` }))}
                    saatBerubah={(nilai) => formulir.setData('UuidAkun', nilai)}
                    galat={formulir.errors.UuidAkun}
                    required
                />
                <PemilihTanggal
                    label="Tanggal cair"
                    nilai={formulir.data.Tanggal}
                    min={giro.TanggalJatuhTempo}
                    max={hariIni}
                    saatBerubah={(nilai) => formulir.setData('Tanggal', nilai)}
                    galat={formulir.errors.Tanggal}
                    required
                />
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Catat cair
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

function FormTolak({ giro, saatSelesai }: { giro: BarisGiro; saatSelesai: () => void }) {
    const formulir = useForm({ Alasan: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${alamat}/${giro.Uuid}/tolak`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul={`Tolak giro ${giro.NomorGiro}`}
            keterangan={`${giro.NomorSumber} dibatalkan dan ${giro.Arah === 'Masuk' ? 'piutang pelanggan' : 'hutang ke pemasok'} kembali terbuka.`}
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeksPanjang
                    label="Alasan ditolak"
                    nilai={formulir.data.Alasan}
                    saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                    maksimal={255}
                    baris={3}
                    galat={formulir.errors.Alasan}
                    required
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" varian="bahaya" memproses={formulir.processing}>
                        Tolak giro
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Kembali
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
