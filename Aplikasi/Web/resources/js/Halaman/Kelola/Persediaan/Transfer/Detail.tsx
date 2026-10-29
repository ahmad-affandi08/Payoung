import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import {
    DialogAlasan,
    Keterangan,
    LabelStatusDokumen,
    PanelJurnalDokumen,
    PanelRiwayatDokumen,
} from '@/Komponen/Persediaan/Dokumen/KomponenDokumen';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatJumlahStok, FormatNilai } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { AmbilTandaDesimal, BandingkanDesimal, CekDesimalValid, JumlahkanDesimal } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisDetailTransferStok, PropsDetailTransferStok } from '@/Tipe/DokumenPersediaan';

const alamat = '/kelola/persediaan/transfer';

type JenisDialog = 'Kirim' | 'Terima' | 'Tutup' | 'Batalkan' | null;

const kolomBaris: KolomTabel<BarisDetailTransferStok>[] = [
    {
        id: 'NamaProduk',
        accessorFn: (b) => `${b.NamaProduk} ${b.Sku ?? ''}`,
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block font-semibold break-words text-teks-utama">{b.NamaProduk}</span>
                <span className="font-mono text-keterangan text-teks-sekunder">{b.Sku ?? 'Tanpa SKU'}</span>
                {b.NomorBatch ? (
                    <span className="block text-keterangan text-teks-sekunder">
                        Batch <span className="font-mono">{b.NomorBatch}</span>
                        {b.TanggalKedaluwarsa ? ` | kedaluwarsa ${FormatTanggal(b.TanggalKedaluwarsa)}` : ''}
                    </span>
                ) : null}
                {b.NomorSeri ? (
                    <span className="block text-keterangan text-teks-sekunder">
                        Nomor seri <span className="font-mono">{b.NomorSeri}</span>
                    </span>
                ) : null}
            </>
        ),
    },
    {
        id: 'JumlahDikirim',
        header: 'Dikirim',
        enableSorting: false,
        meta: { label: 'Dikirim', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatJumlahStok(row.original.JumlahDikirim, row.original.SimbolSatuan),
    },
    {
        id: 'JumlahDiterima',
        header: 'Diterima',
        enableSorting: false,
        meta: { label: 'Diterima', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatJumlahStok(row.original.JumlahDiterima, row.original.SimbolSatuan),
    },
    {
        id: 'JumlahSisa',
        header: 'Dalam perjalanan',
        enableSorting: false,
        meta: { label: 'Dalam perjalanan', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatJumlahStok(row.original.JumlahSisa, row.original.SimbolSatuan),
    },
    {
        id: 'JumlahSusut',
        header: 'Rusak / selisih (susut)',
        enableSorting: false,
        meta: { label: 'Rusak / selisih (susut)', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatJumlahStok(row.original.JumlahSusut, row.original.SimbolSatuan),
    },
    {
        id: 'JumlahDitolak',
        header: 'Dikembalikan',
        enableSorting: false,
        meta: { label: 'Dikembalikan', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatJumlahStok(row.original.JumlahDitolak, row.original.SimbolSatuan),
    },
    {
        id: 'NilaiKirim',
        header: 'Nilai kirim',
        enableSorting: false,
        meta: { label: 'Nilai kirim', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatNilai(row.original.NilaiKirim),
    },
];

/** Jumlah diterima: kosong/0 = tidak diterima kali ini; selain itu > 0 dan ≤ sisa. */
export function PeriksaJumlahTerima(jumlah: string, sisa: string, bolehDesimal: boolean): string | null {
    if (jumlah === '' || (CekDesimalValid(jumlah) && AmbilTandaDesimal(jumlah) === 0)) {
        return null;
    }

    if (!CekDesimalValid(jumlah) || AmbilTandaDesimal(jumlah) < 0 || (!bolehDesimal && jumlah.includes('.'))) {
        return 'Jumlah tidak valid.';
    }

    return BandingkanDesimal(jumlah, sisa) > 0 ? 'Melebihi sisa dalam perjalanan.' : null;
}

export type BagianTerima = { Terima: string; Rusak: string; Ditolak: string };
export type GalatBagianTerima = Partial<Record<keyof BagianTerima | 'Total', string>>;

/**
 * D-105: satu barang bisa dibagi tiga: diterima, rusak, dikembalikan ke asal. Tiap bagian valid sendiri-sendiri, dan
 * jumlah ketiganya tidak boleh melebihi sisa dalam perjalanan. Kosong/0 di ketiganya = barang tidak diproses.
 */
export function PeriksaBagianTerima(
    bagian: BagianTerima,
    sisa: string,
    bolehDesimal: boolean,
): GalatBagianTerima | null {
    const galat: GalatBagianTerima = {};
    const nilai: string[] = [];

    for (const kunci of ['Terima', 'Rusak', 'Ditolak'] as const) {
        const teks = bagian[kunci];

        if (teks === '' || (CekDesimalValid(teks) && AmbilTandaDesimal(teks) === 0)) {
            continue;
        }

        if (!CekDesimalValid(teks) || AmbilTandaDesimal(teks) < 0 || (!bolehDesimal && teks.includes('.'))) {
            galat[kunci] = 'Jumlah tidak valid.';
        } else {
            nilai.push(teks);
        }
    }

    if (Object.keys(galat).length === 0 && nilai.length > 0 && BandingkanDesimal(JumlahkanDesimal(nilai), sisa) > 0) {
        galat.Total = 'Diterima + rusak + dikembalikan melebihi sisa dalam perjalanan.';
    }

    return Object.keys(galat).length === 0 ? null : galat;
}

/** F-05b: detail transfer stok: kirim (J-05.2), terima parsial (J-05.3), tutup dengan selisih (J-05.4), batal draf. */
export default function HalamanDetailTransferStok({
    Transfer,
    Baris,
    Jurnal,
    Riwayat,
    Tindakan,
    Izin,
    HariIni,
}: PropsDetailTransferStok) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [dialog, AturDialog] = useState<JenisDialog>(null);
    const [memproses, AturMemproses] = useState(false);
    const [tanggalTerima, AturTanggalTerima] = useState(HariIni);
    const [terima, AturTerima] = useState<Record<number, string>>(() =>
        Object.fromEntries(
            Baris.map((b) => [b.Urutan, AmbilTandaDesimal(b.JumlahSisa) > 0 ? b.JumlahSisa.replace(/\.?0+$/, '') : '']),
        ),
    );
    const [rusak, AturRusak] = useState<Record<number, string>>({});
    const [tolak, AturTolak] = useState<Record<number, string>>({});
    const [periksaTerima, AturPeriksaTerima] = useState(false);
    const nomor = Transfer.Nomor ?? 'Draf tanpa nomor';
    const barisSisa = Baris.filter((b) => AmbilTandaDesimal(b.JumlahSisa) > 0);

    const Kirim = (aksi: string, data: Parameters<typeof router.post>[1] = {}) =>
        router.post(`${alamat}/${Transfer.Uuid}/${aksi}`, data, {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onSuccess: () => AturDialog(null),
        });

    const BagianBaris = (urutan: number): BagianTerima => ({
        Terima: terima[urutan] ?? '',
        Rusak: rusak[urutan] ?? '',
        Ditolak: tolak[urutan] ?? '',
    });
    const Positif = (teks: string) => teks !== '' && CekDesimalValid(teks) && AmbilTandaDesimal(teks) > 0;

    const KirimTerima = () => {
        AturPeriksaTerima(true);
        const galatBaris = barisSisa.some(
            (b) => PeriksaBagianTerima(BagianBaris(b.Urutan), b.JumlahSisa, b.BolehDesimal) !== null,
        );
        const isi = barisSisa.filter((b) => {
            const bagian = BagianBaris(b.Urutan);

            return Positif(bagian.Terima) || Positif(bagian.Rusak) || Positif(bagian.Ditolak);
        });

        if (galatBaris || isi.length === 0) {
            return;
        }

        Kirim('terima', {
            Tanggal: tanggalTerima,
            Baris: isi.map((b) => {
                const bagian = BagianBaris(b.Urutan);

                return {
                    Urutan: b.Urutan,
                    Jumlah: Positif(bagian.Terima) ? bagian.Terima : '0',
                    ...(Positif(bagian.Rusak) ? { Rusak: bagian.Rusak } : {}),
                    ...(Positif(bagian.Ditolak) ? { Ditolak: bagian.Ditolak } : {}),
                };
            }),
        });
    };

    return (
        <TataLetakAplikasi judul={`Transfer ${nomor}`}>
            <AksiHalaman
                keterangan={<LabelStatusDokumen status={Transfer.Status} label={Transfer.LabelStatus} />}
                langkah={[
                    ...(Tindakan.Terima ? [{ label: 'Terima barang', saatKlik: () => AturDialog('Terima') }] : []),
                    ...(Tindakan.Kirim ? [{ label: 'Kirim transfer', saatKlik: () => AturDialog('Kirim') }] : []),
                ]}
                lainnya={[
                    ...(Transfer.Status === 'Draf' ||
                    Transfer.Status === 'Dikirim' ||
                    Transfer.Status === 'DiterimaSebagian'
                        ? [
                              {
                                  label: 'Cetak daftar ambil barang',
                                  href: `${alamat}/${Transfer.Uuid}/cetak-ambil`,
                                  tabBaru: true,
                              },
                          ]
                        : []),
                    ...(Transfer.Status !== 'Draf'
                        ? [{ label: 'Cetak surat jalan', href: `${alamat}/${Transfer.Uuid}/cetak`, tabBaru: true }]
                        : []),
                    ...(Tindakan.Ubah ? [{ label: 'Ubah draf', href: `${alamat}/${Transfer.Uuid}/ubah` }] : []),
                    ...(Tindakan.Tutup ? [{ label: 'Tutup dengan selisih', saatKlik: () => AturDialog('Tutup') }] : []),
                    ...(Tindakan.Batalkan
                        ? [{ label: 'Batalkan draf', saatKlik: () => AturDialog('Batalkan'), bahaya: true }]
                        : []),
                ]}
            />

            {Transfer.Status === 'Dibatalkan' ? (
                <Pemberitahuan jenis="info" judul="Transfer dibatalkan">
                    Draf ini tidak dikirim dan stok tidak berubah
                    {Transfer.AlasanBatal ? `. Alasan: ${Transfer.AlasanBatal}` : ''}.
                </Pemberitahuan>
            ) : null}
            {Transfer.Status === 'Diterima' && Transfer.AlasanSelisih ? (
                <Pemberitahuan jenis="peringatan" judul="Ditutup dengan selisih">
                    Selisih {FormatNilai(Transfer.TotalNilaiSusut)} dicatat sebagai susut. Alasan:{' '}
                    {Transfer.AlasanSelisih}
                </Pemberitahuan>
            ) : null}
            {(Transfer.Status === 'Dikirim' || Transfer.Status === 'DiterimaSebagian') && !Tindakan.Terima ? (
                <Pemberitahuan jenis="info" judul="Menunggu diterima">
                    Barang sedang dalam perjalanan. Penerimaan dicatat oleh pengguna di outlet tujuan yang boleh
                    mengelola stok.
                </Pemberitahuan>
            ) : null}
            {dialog === null ? <DaftarGalatServer galat={galat} /> : null}

            <Panel judul="Ringkasan" idJudul="judul-ringkasan-transfer">
                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Keterangan label="Dari">
                        {Transfer.NamaGudangAsal}
                        {Transfer.NamaOutletAsal ? (
                            <span className="block text-keterangan text-teks-sekunder">{Transfer.NamaOutletAsal}</span>
                        ) : null}
                    </Keterangan>
                    <Keterangan label="Ke">
                        {Transfer.NamaGudangTujuan}
                        {Transfer.NamaOutletTujuan ? (
                            <span className="block text-keterangan text-teks-sekunder">
                                {Transfer.NamaOutletTujuan}
                            </span>
                        ) : null}
                    </Keterangan>
                    <Keterangan label="Tanggal kirim">{FormatTanggal(Transfer.Tanggal)}</Keterangan>
                    <Keterangan label="Nilai kirim">
                        <span className="font-semibold tabular-nums">
                            {Transfer.Status === 'Draf' ? '—' : FormatNilai(Transfer.TotalNilaiKirim)}
                        </span>
                        <span className="block text-keterangan text-teks-sekunder">
                            {Transfer.JumlahBaris.toLocaleString('id-ID')} baris
                        </span>
                    </Keterangan>
                    {Transfer.Status !== 'Draf' && Transfer.Status !== 'Dibatalkan' ? (
                        <Keterangan label="Nilai diterima">
                            <span className="tabular-nums">{FormatNilai(Transfer.TotalNilaiDiterima)}</span>
                        </Keterangan>
                    ) : null}
                    <Keterangan label="Dibuat">
                        {FormatTanggalWaktu(Transfer.DibuatPada)}
                        {Transfer.DibuatOleh ? ` oleh ${Transfer.DibuatOleh}` : ''}
                    </Keterangan>
                    {Transfer.DikirimPada ? (
                        <Keterangan label="Dikirim">
                            {FormatTanggalWaktu(Transfer.DikirimPada)}
                            {Transfer.DikirimOleh ? ` oleh ${Transfer.DikirimOleh}` : ''}
                        </Keterangan>
                    ) : null}
                    {Transfer.Permintaan ? (
                        <Keterangan label="Menjawab permintaan">
                            <Link
                                href={`/kelola/persediaan/permintaan/${Transfer.Permintaan.Uuid}`}
                                className="font-mono break-all text-brand underline"
                            >
                                {Transfer.Permintaan.Nomor}
                            </Link>
                        </Keterangan>
                    ) : null}
                    {Transfer.Catatan ? <Keterangan label="Catatan">{Transfer.Catatan}</Keterangan> : null}
                </dl>
            </Panel>

            <Panel judul="Barang" idJudul="judul-barang-transfer">
                <TabelData
                    id="persediaan-transfer-baris"
                    label={`Barang transfer, ${String(Baris.length)} baris`}
                    kolom={kolomBaris}
                    sumber={{ mode: 'lokal', data: Baris }}
                    ambilIdBaris={(b) => String(b.Urutan)}
                    cari="Cari nama produk atau SKU"
                    kosong={{ judul: 'Transfer ini belum berisi barang.' }}
                />
            </Panel>

            <PanelJurnalDokumen
                id="persediaan-transfer-jurnal"
                jurnal={Jurnal}
                lihatJurnal={Izin.LihatJurnal}
                keterangan="Kirim: Debit persediaan dalam perjalanan, Kredit persediaan asal. Terima: sebaliknya ke persediaan tujuan. Selisih: Debit susut."
                kosong={
                    Transfer.Status === 'Draf' || Transfer.Status === 'Dibatalkan'
                        ? 'Jurnal dibuat saat transfer dikirim.'
                        : 'Tidak ada jurnal karena nilai transfer Rp 0.'
                }
            />
            <PanelRiwayatDokumen riwayat={Riwayat} id="judul-riwayat-transfer" />

            {dialog === 'Kirim' ? (
                <DialogKonfirmasi
                    galatUmum={galat.Umum}
                    judul="Kirim transfer?"
                    labelAksi="Kirim transfer"
                    varian="utama"
                    memproses={memproses}
                    saatKonfirmasi={() => Kirim('kirim')}
                    saatBatal={() => AturDialog(null)}
                >
                    <p>
                        {Transfer.JumlahBaris.toLocaleString('id-ID')} baris keluar dari {Transfer.NamaGudangAsal} dan
                        tercatat di lokasi dalam perjalanan sampai diterima di {Transfer.NamaGudangTujuan}.
                    </p>
                    <p>
                        Nilai dihitung dari HPP lokasi asal saat dikirim. Setelah dikirim, transfer tidak bisa diubah
                        atau dibatalkan.
                    </p>
                </DialogKonfirmasi>
            ) : null}

            {dialog === 'Terima' ? (
                <DialogFormulir
                    jenis="dialog"
                    lebar="lebar"
                    judul="Terima barang"
                    keterangan="Per barang: isi yang diterima baik, yang rusak, dan yang dikembalikan ke pengirim. Kosongkan barang yang belum datang."
                    galatUmum={galat.Umum}
                    saatTutup={() => AturDialog(null)}
                >
                    <div className="flex flex-col gap-3">
                        <DaftarGalatServer galat={galat} kecuali={['Umum']} />
                        <PemilihTanggal
                            id="tanggal-terima"
                            label="Tanggal terima"
                            nilai={tanggalTerima}
                            min={Transfer.Tanggal}
                            max={HariIni}
                            required
                            saatBerubah={AturTanggalTerima}
                        />
                        <ul
                            className="flex max-h-[50vh] flex-col divide-y divide-garis overflow-y-auto"
                            aria-label="Jumlah diterima"
                        >
                            {barisSisa.map((b) => {
                                const galatBaris = periksaTerima
                                    ? PeriksaBagianTerima(BagianBaris(b.Urutan), b.JumlahSisa, b.BolehDesimal)
                                    : null;
                                const desimal = b.BolehDesimal ? 4 : 0;

                                return (
                                    <li key={b.Urutan} className="flex flex-col gap-2 py-3">
                                        <div className="min-w-0">
                                            <span className="block font-semibold break-words text-teks-utama">
                                                {b.NamaProduk}
                                            </span>
                                            <span className="block text-keterangan text-teks-sekunder">
                                                Sisa {FormatJumlahStok(b.JumlahSisa, b.SimbolSatuan)}
                                                {b.NomorBatch ? ` | batch ${b.NomorBatch}` : ''}
                                                {b.NomorSeri ? ` | ${b.NomorSeri}` : ''}
                                            </span>
                                        </div>
                                        <div className="grid gap-2 sm:grid-cols-3">
                                            <BidangJumlah
                                                label={`Diterima ${b.NamaProduk}`}
                                                nilai={terima[b.Urutan] ?? ''}
                                                saatBerubah={(nilai) =>
                                                    AturTerima((lama) => ({ ...lama, [b.Urutan]: nilai }))
                                                }
                                                desimal={desimal}
                                                akhiran={b.SimbolSatuan}
                                                galat={galatBaris?.Terima ?? galatBaris?.Total}
                                            />
                                            <BidangJumlah
                                                label={`Rusak ${b.NamaProduk}`}
                                                nilai={rusak[b.Urutan] ?? ''}
                                                saatBerubah={(nilai) =>
                                                    AturRusak((lama) => ({ ...lama, [b.Urutan]: nilai }))
                                                }
                                                desimal={desimal}
                                                akhiran={b.SimbolSatuan}
                                                galat={galatBaris?.Rusak}
                                            />
                                            <BidangJumlah
                                                label={`Dikembalikan ${b.NamaProduk}`}
                                                nilai={tolak[b.Urutan] ?? ''}
                                                saatBerubah={(nilai) =>
                                                    AturTolak((lama) => ({ ...lama, [b.Urutan]: nilai }))
                                                }
                                                desimal={desimal}
                                                akhiran={b.SimbolSatuan}
                                                galat={galatBaris?.Ditolak}
                                            />
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                        <p className="text-keterangan text-teks-sekunder">
                            <span className="font-semibold text-teks-utama">Rusak</span> = barang sampai tetapi tidak
                            layak jual (dicatat sebagai susut).{' '}
                            <span className="font-semibold text-teks-utama">Dikembalikan</span> = barang ditolak dan
                            kembali ke {Transfer.NamaGudangAsal}.
                        </p>
                        <div className="flex flex-wrap justify-end gap-2">
                            <Tombol varian="sekunder" onClick={() => AturDialog(null)} disabled={memproses}>
                                Kembali
                            </Tombol>
                            <Tombol memproses={memproses} onClick={KirimTerima}>
                                Simpan penerimaan
                            </Tombol>
                        </div>
                    </div>
                </DialogFormulir>
            ) : null}

            {dialog === 'Tutup' ? (
                <DialogAlasan
                    judul={`Tutup transfer ${nomor}?`}
                    keterangan={
                        <p>
                            Semua barang yang belum diterima dicatat sebagai susut atau barang rusak. Transfer tidak
                            bisa diterima lagi.
                        </p>
                    }
                    labelAksi="Tutup transfer"
                    labelAlasan="Alasan selisih"
                    memproses={memproses}
                    galat={galat}
                    saatKirim={(alasan) => Kirim('tutup', { Alasan: alasan })}
                    saatTutup={() => AturDialog(null)}
                />
            ) : null}

            {dialog === 'Batalkan' ? (
                <DialogAlasan
                    judul="Batalkan draf transfer?"
                    keterangan={
                        <p>Draf tidak dikirim dan stok tidak berubah. Dokumen tetap tersimpan sebagai catatan.</p>
                    }
                    labelAksi="Batalkan draf"
                    memproses={memproses}
                    galat={galat}
                    saatKirim={(alasan) => Kirim('batalkan', { Alasan: alasan })}
                    saatTutup={() => AturDialog(null)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
