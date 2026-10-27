import { Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { BandingkanDesimal } from '@/Pustaka/HitungDesimal';
import { TulisTanggal } from '@/Pustaka/Tanggal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisJadwalPenyusutan, OpsiAkunAset, PropsDetailAsetTetap } from '@/Tipe/Akuntansi';

import { FormatMasaManfaat } from './Daftar';

const alamat = '/kelola/akuntansi/aset-tetap';

/** `2026-10` → "Okt 2026". */
export function FormatPeriode(periode: string): string {
    const [tahun = '', bulan = '1'] = periode.split('-');
    const nama =
        ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][Number(bulan) - 1] ?? '';

    return `${nama} ${tahun}`;
}

const kolomJadwal: KolomTabel<BarisJadwalPenyusutan>[] = [
    {
        id: 'Periode',
        accessorKey: 'Periode',
        header: 'Bulan',
        meta: { label: 'Bulan', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatPeriode(row.original.Periode),
    },
    {
        id: 'Jumlah',
        accessorKey: 'Jumlah',
        header: 'Penyusutan',
        meta: { label: 'Penyusutan', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.Jumlah),
    },
    {
        id: 'Akumulasi',
        accessorKey: 'Akumulasi',
        header: 'Akumulasi',
        meta: { label: 'Akumulasi', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatRupiah(row.original.Akumulasi),
    },
    {
        id: 'NilaiBuku',
        accessorKey: 'NilaiBuku',
        header: 'Nilai buku',
        meta: { label: 'Nilai buku', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.NilaiBuku),
    },
    {
        id: 'Dijurnal',
        header: 'Jurnal',
        enableSorting: false,
        meta: { label: 'Jurnal', prioritas: 'penting' },
        cell: ({ row: { original: b } }) =>
            b.Jurnal ? (
                <Link href={`/kelola/akuntansi/jurnal/${b.Jurnal.Uuid}`} className="font-mono text-brand underline">
                    {b.Jurnal.Nomor}
                </Link>
            ) : (
                <span className="text-teks-sekunder">Belum</span>
            ),
    },
];

function Nilai({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex flex-col gap-1">
            <dt className="text-teks-sekunder">{label}</dt>
            <dd className="text-teks-utama">{children}</dd>
        </div>
    );
}

/**
 * Rincian aset tetap (FIN-10): nilai buku hari ini, jadwal penyusutan lengkap (bulan yang sudah dijurnal bertaut ke
 * jurnalnya), dan aksi pelepasan (dijual/dibuang) atau pembatalan (salah catat, sebelum disusutkan).
 */
export default function HalamanDetailAsetTetap({ Aset, Jadwal, OpsiAkun, Izin }: PropsDetailAsetTetap) {
    const [dialog, AturDialog] = useState<'lepas' | 'batal' | null>(null);
    const aktif = Aset.Status === 'Aktif';
    const Tutup = () => AturDialog(null);

    return (
        <TataLetakAplikasi judul={`${Aset.Nomor} ${Aset.Nama}`} jejak={[{ label: 'Aset tetap', href: alamat }]}>
            {Izin.Kelola && aktif ? (
                <AksiHalaman
                    langkah={[{ label: 'Jual atau hapus aset', saatKlik: () => AturDialog('lepas') }]}
                    lainnya={
                        Aset.BisaDibatalkan ? [{ label: 'Batalkan aset', saatKlik: () => AturDialog('batal') }] : []
                    }
                />
            ) : null}

            <Panel>
                <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-isi sm:grid-cols-4">
                    <Nilai label="Status">
                        <LabelStatus
                            jenis={aktif ? 'sukses' : Aset.Status === 'Dilepas' ? 'netral' : 'bahaya'}
                            teks={Aset.LabelStatus}
                        />
                    </Nilai>
                    <Nilai label="Kelompok">{Aset.LabelKelompok}</Nilai>
                    <Nilai label="Masa manfaat">{FormatMasaManfaat(Aset.UmurBulan)}</Nilai>
                    <Nilai label="Diperoleh">{FormatTanggal(Aset.TanggalPerolehan)}</Nilai>
                    <Nilai label="Harga perolehan">
                        <span className="tabular-nums">{FormatRupiah(Aset.HargaPerolehan)}</span>
                    </Nilai>
                    <Nilai label="Akumulasi penyusutan">
                        <span className="tabular-nums">{FormatRupiah(Aset.Akumulasi)}</span>
                    </Nilai>
                    <Nilai label="Nilai buku">
                        <span className="font-semibold tabular-nums">{FormatRupiah(Aset.NilaiBuku)}</span>
                    </Nilai>
                    <Nilai label="Nilai sisa">
                        <span className="tabular-nums">{FormatRupiah(Aset.NilaiSisa)}</span>
                    </Nilai>
                    <Nilai label="Asal">{Aset.SumberDana === 'SaldoAwal' ? 'Saldo awal' : 'Dibeli (kas/bank)'}</Nilai>
                    {BandingkanDesimal(Aset.AkumulasiAwal, '0') > 0 ? (
                        <Nilai label="Akumulasi awal">
                            <span className="tabular-nums">{FormatRupiah(Aset.AkumulasiAwal)}</span>
                        </Nilai>
                    ) : null}
                    <Nilai label="Outlet">{Aset.NamaOutlet ?? 'Semua outlet'}</Nilai>
                    <Nilai label="Jurnal perolehan">
                        {Aset.JurnalPerolehan ? (
                            <Link
                                href={`/kelola/akuntansi/jurnal/${Aset.JurnalPerolehan.Uuid}`}
                                className="font-mono text-brand underline"
                            >
                                {Aset.JurnalPerolehan.Nomor}
                            </Link>
                        ) : (
                            '—'
                        )}
                    </Nilai>
                    {Aset.TanggalPelepasan ? (
                        <>
                            <Nilai label="Dilepas">{FormatTanggal(Aset.TanggalPelepasan)}</Nilai>
                            <Nilai label="Nilai jual">
                                <span className="tabular-nums">{FormatRupiah(Aset.NilaiPelepasan ?? '0')}</span>
                            </Nilai>
                            <Nilai label="Jurnal pelepasan">
                                {Aset.JurnalPelepasan ? (
                                    <Link
                                        href={`/kelola/akuntansi/jurnal/${Aset.JurnalPelepasan.Uuid}`}
                                        className="font-mono text-brand underline"
                                    >
                                        {Aset.JurnalPelepasan.Nomor}
                                    </Link>
                                ) : (
                                    '—'
                                )}
                            </Nilai>
                        </>
                    ) : null}
                </dl>
                {Aset.Catatan ? <p className="mt-3 text-isi text-teks-sekunder">{Aset.Catatan}</p> : null}
                {Aset.AlasanBatal ? <p className="mt-3 text-isi text-bahaya">Dibatalkan: {Aset.AlasanBatal}</p> : null}
            </Panel>

            <h2 className="text-subjudul font-semibold text-teks-utama">Jadwal penyusutan</h2>
            <TabelData
                id="akuntansi-aset-tetap-jadwal"
                label="Jadwal penyusutan"
                kolom={kolomJadwal}
                sumber={{ mode: 'lokal', data: Jadwal }}
                ambilIdBaris={(b) => b.Periode}
                kosong={{
                    judul: Aset.UmurBulan === 0 ? 'Tanah tidak disusutkan.' : 'Tidak ada nilai yang disusutkan.',
                }}
            />

            {dialog === 'lepas' ? (
                <FormLepas
                    uuid={Aset.Uuid}
                    opsiAkun={OpsiAkun}
                    tanggalPerolehan={Aset.TanggalPerolehan}
                    saatSelesai={Tutup}
                />
            ) : null}
            {dialog === 'batal' ? <FormBatal uuid={Aset.Uuid} saatSelesai={Tutup} /> : null}
        </TataLetakAplikasi>
    );
}

function FormLepas({
    uuid,
    opsiAkun,
    tanggalPerolehan,
    saatSelesai,
}: {
    uuid: string;
    opsiAkun: OpsiAkunAset[];
    tanggalPerolehan: string;
    saatSelesai: () => void;
}) {
    const hariIni = TulisTanggal(new Date());
    const formulir = useForm({ Tanggal: hariIni, NilaiJual: '0', AkunKasBank: opsiAkun[0]?.Uuid ?? '', Catatan: '' });
    const dijual = BandingkanDesimal(formulir.data.NilaiJual || '0', '0') > 0;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${alamat}/${uuid}/lepas`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul="Jual atau hapus aset"
            keterangan="Penyusutan disusul dulu sampai bulan pelepasan. Selisih nilai jual dengan nilai buku dicatat sebagai laba atau rugi pelepasan."
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <PemilihTanggal
                    label="Tanggal"
                    nilai={formulir.data.Tanggal}
                    saatBerubah={(nilai) => formulir.setData('Tanggal', nilai)}
                    galat={formulir.errors.Tanggal}
                    min={tanggalPerolehan}
                    max={hariIni}
                    required
                />
                <BidangUang
                    label="Nilai jual (0 bila dibuang/rusak)"
                    nilai={formulir.data.NilaiJual}
                    saatBerubah={(nilai) => formulir.setData('NilaiJual', nilai)}
                    galat={formulir.errors.NilaiJual}
                    required
                />
                {dijual ? (
                    <BidangPilihan
                        label="Diterima di"
                        nilai={formulir.data.AkunKasBank}
                        opsi={opsiAkun.map((a) => ({ Nilai: a.Uuid, Label: `${a.Kode} ${a.Nama}` }))}
                        saatBerubah={(nilai) => formulir.setData('AkunKasBank', nilai)}
                        galat={formulir.errors.AkunKasBank}
                        required
                    />
                ) : null}
                <div className="sm:col-span-2">
                    <BidangTeksPanjang
                        label="Catatan (opsional)"
                        nilai={formulir.data.Catatan}
                        saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                        maksimal={500}
                        baris={2}
                        galat={formulir.errors.Catatan}
                    />
                </div>
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan pelepasan
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

function FormBatal({ uuid, saatSelesai }: { uuid: string; saatSelesai: () => void }) {
    const formulir = useForm({ Alasan: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${alamat}/${uuid}/batalkan`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul="Batalkan aset"
            keterangan="Untuk aset yang salah catat. Jurnal perolehannya dibalik hari ini."
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeksPanjang
                    label="Alasan membatalkan"
                    nilai={formulir.data.Alasan}
                    saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                    maksimal={500}
                    baris={3}
                    galat={formulir.errors.Alasan}
                    required
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" varian="bahaya" memproses={formulir.processing}>
                        Batalkan aset
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Kembali
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
