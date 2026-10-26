import { Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import { HitungBarisMutasi } from '@/Komponen/Persediaan/AturanFormStokAwal';
import LabelStatusStokAwal from '@/Komponen/Persediaan/LabelStatusStokAwal';
import PanelKesiapanAkun from '@/Komponen/Persediaan/PanelKesiapanAkun';
import PemantauPosting from '@/Komponen/Persediaan/PemantauPosting';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatHppSatuan, FormatJumlahStok, FormatNilai } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { AmbilTandaDesimal } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsDetailStokAwal } from '@/Tipe/Persediaan';

const alamat = '/kelola/persediaan/stok-awal';

/** Alasan pembatalan 5–255 karakter (`BatalkanStokAwalPermintaan`). */
export function PeriksaAlasanBatal(alasan: string): string | null {
    const panjang = alasan.trim().length;

    if (panjang < 5) {
        return 'Tulis alasan minimal 5 karakter.';
    }

    return panjang > 255 ? 'Alasan paling panjang 255 karakter.' : null;
}

type JenisDialog = 'Posting' | 'Batalkan' | 'Buang' | null;

function Keterangan({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-label font-semibold text-teks-sekunder">{label}</dt>
            <dd className="text-isi break-words text-teks-utama">{children}</dd>
        </div>
    );
}

type BarisStokAwal = PropsDetailStokAwal['Baris'][number];
type JurnalStokAwal = PropsDetailStokAwal['Jurnal'][number];

const kolomBaris: KolomTabel<BarisStokAwal>[] = [
    {
        id: 'Urutan',
        accessorKey: 'Urutan',
        header: 'No.',
        meta: { label: 'Nomor urut', angka: true, prioritas: 'rendah', kelasSel: 'w-12 text-teks-sekunder' },
    },
    {
        id: 'NamaProduk',
        accessorFn: (baris) => `${baris.NamaProduk} ${baris.Sku ?? ''}`,
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: baris } }) => (
            <>
                <span className="block font-semibold break-words text-teks-utama">{baris.NamaProduk}</span>
                <span className="font-mono text-keterangan text-teks-sekunder">{baris.Sku ?? 'Tanpa SKU'}</span>
            </>
        ),
    },
    {
        id: 'Jumlah',
        header: 'Jumlah',
        enableSorting: false,
        meta: { label: 'Jumlah', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatJumlahStok(row.original.Jumlah, row.original.SimbolSatuan),
    },
    {
        id: 'HppSatuan',
        header: 'Harga modal per satuan',
        enableSorting: false,
        meta: { label: 'Harga modal per satuan', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatHppSatuan(row.original.HppSatuan),
    },
    {
        id: 'Nilai',
        header: 'Nilai',
        enableSorting: false,
        meta: { label: 'Nilai', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatNilai(row.original.Nilai),
    },
    {
        id: 'Pelacakan',
        accessorKey: 'Pelacakan',
        header: 'Batch / nomor seri',
        enableSorting: false,
        meta: { label: 'Batch / nomor seri', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: baris } }) => (
            <>
                {baris.Pelacakan === 'Batch' ? (
                    <>
                        <span className="font-mono text-teks-utama">{baris.NomorBatch}</span>
                        <span className="block text-keterangan">
                            Kedaluwarsa {FormatTanggal(baris.TanggalKedaluwarsa)}
                        </span>
                    </>
                ) : null}
                {baris.Pelacakan === 'Seri' ? (
                    <details>
                        <summary className="cursor-pointer text-brand underline">
                            {baris.NomorSeri.length.toLocaleString('id-ID')} nomor seri
                        </summary>
                        <ul className="mt-1 max-h-48 overflow-y-auto font-mono text-keterangan text-teks-utama">
                            {baris.NomorSeri.map((seri) => (
                                <li key={seri}>{seri}</li>
                            ))}
                        </ul>
                    </details>
                ) : null}
                {baris.Pelacakan === 'Tidak' ? '—' : null}
            </>
        ),
    },
];

const kolomJurnal: KolomTabel<JurnalStokAwal>[] = [
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
    },
    {
        id: 'Keterangan',
        accessorKey: 'Keterangan',
        header: 'Keterangan',
        enableSorting: false,
        meta: { label: 'Keterangan', prioritas: 'rendah' },
    },
    {
        id: 'TotalDebit',
        header: 'Total debit',
        enableSorting: false,
        meta: { label: 'Total debit', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatNilai(row.original.TotalDebit),
    },
];

/** F-05a: detail dokumen stok awal, posting (J-05.1), pembatalan (jurnal pembalik), buang draf, jurnal & riwayat. */
export default function HalamanDetailStokAwal({
    StokAwal,
    Baris,
    Jurnal,
    Riwayat,
    Tindakan,
    Izin,
    KesiapanAkun,
    BatasPostingLangsung,
}: PropsDetailStokAwal) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [dialog, AturDialog] = useState<JenisDialog>(null);
    const [alasan, AturAlasan] = useState('');
    const [periksaAlasan, AturPeriksaAlasan] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const nomor = StokAwal.Nomor ?? 'Draf tanpa nomor';
    const barisMutasi = HitungBarisMutasi(Baris);
    const lewatAntrean = barisMutasi > BatasPostingLangsung;
    const galatAlasan = galat.Alasan ?? (periksaAlasan ? (PeriksaAlasanBatal(alasan) ?? undefined) : undefined);

    const Kirim = (aksi: 'posting' | 'batalkan' | 'buang', data: Record<string, string> = {}) =>
        router.post(`${alamat}/${StokAwal.Uuid}/${aksi}`, data, {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onSuccess: () => AturDialog(null),
        });

    const KirimBatal = () => {
        AturPeriksaAlasan(true);

        if (PeriksaAlasanBatal(alasan) === null) {
            Kirim('batalkan', { Alasan: alasan.trim() });
        }
    };

    const TutupDialog = () => {
        AturDialog(null);
        AturPeriksaAlasan(false);
    };

    return (
        <TataLetakAplikasi judul={`Stok awal ${nomor}`}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-subjudul font-semibold text-teks-utama">{nomor}</span>
                    <LabelStatusStokAwal status={StokAwal.Status} label={StokAwal.LabelStatus} />
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={alamat}>Kembali ke daftar</Link>
                    </Button>
                    {Tindakan.Ubah ? (
                        <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                            <Link href={`${alamat}/${StokAwal.Uuid}/ubah`}>Ubah draf</Link>
                        </Button>
                    ) : null}
                    {Tindakan.Buang ? (
                        <Tombol varian="sekunder" onClick={() => AturDialog('Buang')}>
                            Buang draf
                        </Tombol>
                    ) : null}
                    {Tindakan.Batalkan ? (
                        <Tombol varian="bahaya" onClick={() => AturDialog('Batalkan')}>
                            Batalkan stok awal
                        </Tombol>
                    ) : null}
                    {Tindakan.Posting ? (
                        <Tombol onClick={() => AturDialog('Posting')} disabled={!KesiapanAkun.Siap}>
                            Posting stok awal
                        </Tombol>
                    ) : null}
                </div>
            </div>

            <PemantauPosting uuid={StokAwal.Uuid} status={StokAwal.Status} />
            {StokAwal.Status === 'Draf' && StokAwal.PesanGalat ? (
                <Pemberitahuan jenis="bahaya" judul="Posting terakhir gagal">
                    {StokAwal.PesanGalat} Perbaiki draf lalu posting ulang.
                </Pemberitahuan>
            ) : null}
            {StokAwal.Status === 'Dibatalkan' ? (
                <Pemberitahuan jenis="info" judul="Stok awal dibatalkan">
                    Stok dan jurnal sudah dibalik{StokAwal.AlasanBatal ? `. Alasan: ${StokAwal.AlasanBatal}` : ''}. Buat
                    stok awal baru bila perlu.
                </Pemberitahuan>
            ) : null}
            {StokAwal.Status === 'Dibuang' ? (
                <Pemberitahuan jenis="info" judul="Draf dibuang">
                    Draf ini tidak dipakai dan tidak mengubah stok. Dokumen tetap tersimpan sebagai catatan.
                </Pemberitahuan>
            ) : null}
            {Tindakan.Posting ? <PanelKesiapanAkun kesiapan={KesiapanAkun} /> : null}
            {StokAwal.Status === 'Draf' && Izin.Kelola && !Izin.PostingStokAwal ? (
                <Pemberitahuan jenis="info" judul="Menunggu posting">
                    Anda bisa mengubah draf ini, tetapi tidak boleh memostingnya. Minta Manajer Outlet, Akuntan, atau
                    Pemilik toko memostingnya.
                </Pemberitahuan>
            ) : null}
            {dialog === null ? <DaftarGalatServer galat={galat} /> : null}

            <Panel judul="Ringkasan" idJudul="judul-ringkasan-stok-awal">
                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Keterangan label="Lokasi stok">
                        {StokAwal.NamaGudang}
                        {StokAwal.NamaOutlet ? (
                            <span className="block text-keterangan text-teks-sekunder">{StokAwal.NamaOutlet}</span>
                        ) : null}
                    </Keterangan>
                    <Keterangan label="Tanggal stok awal">{FormatTanggal(StokAwal.Tanggal)}</Keterangan>
                    <Keterangan label="Total nilai">
                        <span className="font-semibold tabular-nums">{FormatNilai(StokAwal.TotalNilai)}</span>
                        <span className="block text-keterangan text-teks-sekunder">
                            {StokAwal.JumlahBaris.toLocaleString('id-ID')} baris
                        </span>
                    </Keterangan>
                    <Keterangan label="Sumber">
                        {StokAwal.Sumber === 'Impor' && StokAwal.UuidImpor ? (
                            <Link
                                href={`${alamat}/impor/${StokAwal.UuidImpor}`}
                                className="font-semibold text-brand underline"
                            >
                                Impor Excel
                            </Link>
                        ) : StokAwal.Sumber === 'Impor' ? (
                            'Impor Excel'
                        ) : (
                            'Diisi manual'
                        )}
                    </Keterangan>
                    <Keterangan label="Dibuat">
                        {FormatTanggalWaktu(StokAwal.DibuatPada)}
                        {StokAwal.DibuatOleh ? ` oleh ${StokAwal.DibuatOleh}` : ''}
                    </Keterangan>
                    {StokAwal.DipostingPada ? (
                        <Keterangan label="Diposting">
                            {FormatTanggalWaktu(StokAwal.DipostingPada)}
                            {StokAwal.DipostingOleh ? ` oleh ${StokAwal.DipostingOleh}` : ''}
                        </Keterangan>
                    ) : null}
                    {StokAwal.DibatalkanPada ? (
                        <Keterangan label="Dibatalkan">
                            {FormatTanggalWaktu(StokAwal.DibatalkanPada)}
                            {StokAwal.DibatalkanOleh ? ` oleh ${StokAwal.DibatalkanOleh}` : ''}
                        </Keterangan>
                    ) : null}
                    {StokAwal.AlasanBatal ? (
                        <Keterangan label="Alasan pembatalan">{StokAwal.AlasanBatal}</Keterangan>
                    ) : null}
                    {StokAwal.Catatan ? <Keterangan label="Catatan">{StokAwal.Catatan}</Keterangan> : null}
                </dl>
            </Panel>

            <Panel judul="Barang" idJudul="judul-barang-detail-stok-awal">
                <TabelData
                    id="persediaan-stok-awal-baris"
                    label={`Barang stok awal, ${String(Baris.length)} baris`}
                    kolom={kolomBaris}
                    sumber={{ mode: 'lokal', data: Baris }}
                    ambilIdBaris={(baris) => String(baris.Urutan)}
                    urutBawaan="Urutan"
                    cari="Cari nama produk atau SKU"
                    kosong={{ judul: 'Dokumen ini belum berisi barang.' }}
                />
            </Panel>

            <Panel
                judul="Jurnal"
                idJudul="judul-jurnal-stok-awal"
                keterangan="Jurnal otomatis: Debit persediaan, Kredit ekuitas saldo awal. Pembatalan membuat jurnal pembalik."
            >
                <TabelData
                    id="persediaan-stok-awal-jurnal"
                    label="Jurnal stok awal"
                    kolom={[
                        {
                            id: 'Nomor',
                            accessorKey: 'Nomor',
                            header: 'Nomor jurnal',
                            meta: { label: 'Nomor jurnal', prioritas: 'utama', wajib: true },
                            cell: ({ row: { original: jurnal } }) => (
                                <>
                                    {Izin.LihatJurnal ? (
                                        <Link
                                            href={`/kelola/akuntansi/jurnal/${jurnal.Uuid}`}
                                            className="font-mono font-semibold text-brand underline"
                                        >
                                            {jurnal.Nomor}
                                        </Link>
                                    ) : (
                                        <span className="font-mono">{jurnal.Nomor}</span>
                                    )}
                                    {jurnal.Pembalik ? (
                                        <span className="ml-2">
                                            <LabelStatus jenis="peringatan" teks="Pembalik" />
                                        </span>
                                    ) : null}
                                </>
                            ),
                        },
                        ...kolomJurnal,
                    ]}
                    sumber={{ mode: 'lokal', data: Jurnal }}
                    ambilIdBaris={(jurnal) => jurnal.Uuid}
                    kosong={{
                        judul:
                            StokAwal.Status === 'Diposting' || StokAwal.Status === 'Dibatalkan'
                                ? 'Tidak ada jurnal karena total nilai stok awal Rp 0.'
                                : 'Jurnal dibuat saat stok awal diposting.',
                    }}
                />
            </Panel>

            <Panel judul="Riwayat status" idJudul="judul-riwayat-stok-awal">
                {Riwayat.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">Belum ada perubahan status.</p>
                ) : (
                    <ol className="flex flex-col gap-2">
                        {Riwayat.map((riwayat, indeks) => (
                            <li
                                key={`${riwayat.Pada}-${String(indeks)}`}
                                className="flex flex-col gap-0.5 border-l-2 border-garis pl-3"
                            >
                                <span className="text-isi font-semibold text-teks-utama">{riwayat.LabelStatusKe}</span>
                                <span className="text-keterangan text-teks-sekunder">
                                    {FormatTanggalWaktu(riwayat.Pada)}
                                    {riwayat.Oleh ? ` | ${riwayat.Oleh}` : ''}
                                </span>
                                {riwayat.Alasan ? (
                                    <span className="text-keterangan text-teks-sekunder">Alasan: {riwayat.Alasan}</span>
                                ) : null}
                            </li>
                        ))}
                    </ol>
                )}
            </Panel>

            {dialog === 'Posting' ? (
                <DialogKonfirmasi
                    galatUmum={galat.Umum}
                    judul="Posting stok awal?"
                    labelAksi="Posting stok awal"
                    varian="utama"
                    memproses={memproses}
                    saatKonfirmasi={() => Kirim('posting')}
                    saatBatal={TutupDialog}
                >
                    <p>
                        {StokAwal.JumlahBaris.toLocaleString('id-ID')} baris di {StokAwal.NamaGudang} dicatat ke stok
                        dengan total nilai{' '}
                        <span className="font-semibold text-teks-utama tabular-nums">
                            {FormatNilai(StokAwal.TotalNilai)}
                        </span>
                        .
                    </p>
                    {AmbilTandaDesimal(StokAwal.TotalNilai) === 0 ? (
                        <p>Total nilai Rp 0, jadi tidak ada jurnal yang dibuat.</p>
                    ) : (
                        <p>
                            Jurnal otomatis: Debit Persediaan, Kredit Ekuitas saldo awal, masing-masing{' '}
                            {FormatNilai(StokAwal.TotalNilai)}. Bila stok di lokasi ini sedang minus, selisih HPP ikut
                            dicatat.
                        </p>
                    )}
                    {lewatAntrean ? (
                        <p>
                            Dokumen ini besar ({barisMutasi.toLocaleString('id-ID')} baris mutasi), jadi diproses di
                            latar belakang. Halaman diperbarui otomatis setelah selesai.
                        </p>
                    ) : null}
                    <p>Setelah diposting, dokumen tidak bisa diubah. Koreksi dilakukan dengan membatalkannya.</p>
                </DialogKonfirmasi>
            ) : null}

            {dialog === 'Buang' ? (
                <DialogKonfirmasi
                    judul="Buang draf stok awal?"
                    labelAksi="Buang draf"
                    memproses={memproses}
                    saatKonfirmasi={() => Kirim('buang')}
                    saatBatal={TutupDialog}
                >
                    <p>Draf ini tidak dipakai lagi dan tidak bisa diubah. Stok tidak berubah.</p>
                    <p>Dokumen tetap tersimpan dengan status Dibuang sebagai catatan.</p>
                </DialogKonfirmasi>
            ) : null}

            {dialog === 'Batalkan' ? (
                <DialogFormulir
                    jenis="konfirmasi"
                    judul={`Batalkan stok awal ${nomor}?`}
                    keterangan={
                        <>
                            <p>
                                Stok {FormatNilai(StokAwal.TotalNilai)} dikeluarkan lagi dan jurnal pembalik dibuat
                                dengan tanggal hari ini.
                            </p>
                            <p>
                                Hanya bisa bila stok ini belum terpakai (belum terjual atau dipindah). Bila sudah,
                                koreksi lewat penyesuaian stok.
                            </p>
                        </>
                    }
                    galatUmum={galat.Umum}
                    saatTutup={TutupDialog}
                >
                    <div className="flex flex-col gap-3">
                        <DaftarGalatServer galat={galat} kecuali={['Alasan']} />
                        <BidangTeksPanjang
                            label="Alasan pembatalan"
                            nilai={alasan}
                            saatBerubah={AturAlasan}
                            galat={galatAlasan}
                            keterangan="5–255 karakter. Tercatat di riwayat dan log audit."
                            baris={3}
                            maksimal={255}
                            required
                        />
                        <div className="flex flex-wrap justify-end gap-2">
                            <Tombol varian="sekunder" onClick={TutupDialog} disabled={memproses}>
                                Kembali
                            </Tombol>
                            <Tombol varian="bahaya" memproses={memproses} onClick={KirimBatal}>
                                Batalkan stok awal
                            </Tombol>
                        </div>
                    </div>
                </DialogFormulir>
            ) : null}
        </TataLetakAplikasi>
    );
}
