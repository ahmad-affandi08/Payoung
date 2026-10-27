import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import {
    DialogAlasanGrosir,
    JurnalDokumenGrosir,
    KartuKeteranganGrosir,
    KeteranganGrosir,
    RingkasanNilaiGrosir,
    RiwayatGrosirDokumen,
} from '@/Komponen/Grosir/BagianDokumenGrosir';
import {
    AlamatInvoice,
    FormatJumlahInvoice,
    KeteranganTermin,
    LabelStatusBayarInvoice,
} from '@/Komponen/Invoice/BagianInvoice';
import DialogPembayaranInvoice from '@/Komponen/Invoice/DialogPembayaranInvoice';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatPersen, FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { JumlahkanDesimal, KurangiDesimal, AmbilTandaDesimal } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { KonfirmasiInvoice, PropsDetailInvoice } from '@/Tipe/Invoice';

const kelasKepala = 'px-2 text-label text-teks-sekunder';
const kelasSel = 'px-2 py-2 align-top whitespace-normal';

/**
 * Detail invoice (F-21, D-166, J-21.1/J-21.2). Angka diambil dari server; sisa tagihan dibaca dari piutang. Setelah
 * terbit, dokumen tidak bisa diubah (aturan emas #8): koreksi lewat pembatalan (jurnal pembalik) lalu invoice baru.
 */
export default function HalamanDetailInvoice({
    Invoice: invoice,
    Baris,
    UangMuka,
    Pelunasan,
    Jurnal,
    Riwayat,
    Pengiriman,
    Konfirmasi,
    Izin,
    HariIni,
    OpsiAkun,
    Tindakan,
}: PropsDetailInvoice) {
    const [batalkan, AturBatalkan] = useState(false);
    const [bayar, AturBayar] = useState(false);
    // Klaim pelanggan yang sedang diterima (membuka dialog pembayaran terisi) atau ditolak (dialog alasan).
    const [terimaKonfirmasi, AturTerimaKonfirmasi] = useState<KonfirmasiInvoice | null>(null);
    const [tolakKonfirmasi, AturTolakKonfirmasi] = useState<KonfirmasiInvoice | null>(null);
    const [memproses, AturMemproses] = useState<string | null>(null);
    const [tersalin, AturTersalin] = useState(false);
    const judul = invoice.Nomor === null ? 'Draf invoice' : `Invoice ${invoice.Nomor}`;
    const pelunasanBerlaku = Pelunasan.filter((p) => p.Status === 'Diposting');
    const sudahDipotong = JumlahkanDesimal(['0.00', ...pelunasanBerlaku.map((p) => p.PotonganPph)]);
    const sisaPph = KurangiDesimal(invoice.NilaiPph, sudahDipotong);

    const Kirim = (jenis: 'Invoice' | 'Pengingat') =>
        router.post(
            `${AlamatInvoice}/${invoice.Uuid}/kirim`,
            { Jenis: jenis },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(jenis),
                onFinish: () => AturMemproses(null),
            },
        );

    const Terbitkan = () =>
        router.post(
            `${AlamatInvoice}/${invoice.Uuid}/terbitkan`,
            {},
            {
                preserveScroll: true,
                onStart: () => AturMemproses('Terbit'),
                onFinish: () => AturMemproses(null),
            },
        );

    const SalinTautan = () => {
        if (invoice.TautanPublik === null) {
            return;
        }

        void navigator.clipboard.writeText(invoice.TautanPublik).then(() => {
            AturTersalin(true);
            window.setTimeout(() => AturTersalin(false), 3000);
        });
    };

    return (
        <TataLetakAplikasi judul={judul} jejak={[{ label: 'Invoice', href: AlamatInvoice }]}>
            <div className="flex flex-wrap items-center gap-3">
                <LabelStatusBayarInvoice status={invoice.StatusBayar} label={invoice.LabelStatusBayar} />
                <span className="text-isi text-teks-sekunder">{invoice.LabelJenis}</span>
            </div>

            {invoice.AlasanBatal !== null ? (
                <Pemberitahuan jenis="bahaya" judul="Invoice dibatalkan">
                    {invoice.AlasanBatal}. Jurnalnya sudah dibalik. Pelanggan yang membuka tautan melihat tanda
                    dibatalkan.
                </Pemberitahuan>
            ) : null}
            {invoice.Status === 'Draf' ? (
                <Pemberitahuan jenis="info" judul="Masih draf">
                    Draf belum bernomor, belum dibukukan, dan belum bisa dikirim. Periksa angkanya, lalu terbitkan.
                </Pemberitahuan>
            ) : null}

            <AksiHalaman
                langkah={[
                    ...(Tindakan.Terbitkan
                        ? [{ label: 'Terbitkan invoice', saatKlik: Terbitkan, nonaktif: memproses === 'Terbit' }]
                        : []),
                    ...(Tindakan.CatatPembayaran
                        ? [{ label: 'Catat pembayaran', saatKlik: () => AturBayar(true) }]
                        : []),
                    ...(Tindakan.Kirim
                        ? [
                              {
                                  label: 'Kirim lewat WhatsApp',
                                  saatKlik: () => Kirim('Invoice'),
                                  nonaktif: memproses === 'Invoice',
                              },
                          ]
                        : []),
                    ...(Tindakan.Ingatkan
                        ? [
                              {
                                  label: 'Kirim pengingat',
                                  saatKlik: () => Kirim('Pengingat'),
                                  nonaktif: memproses === 'Pengingat',
                              },
                          ]
                        : []),
                ]}
                lainnya={[
                    ...(Tindakan.Ubah ? [{ label: 'Ubah draf', href: `${AlamatInvoice}/${invoice.Uuid}/ubah` }] : []),
                    { label: 'Cetak / PDF', href: `${AlamatInvoice}/${invoice.Uuid}/cetak`, tabBaru: true },
                    ...(invoice.TautanPublik !== null
                        ? [{ label: tersalin ? 'Tautan tersalin' : 'Salin tautan', saatKlik: SalinTautan }]
                        : []),
                    ...(Tindakan.Batalkan
                        ? [
                              {
                                  label: invoice.Status === 'Draf' ? 'Batalkan draf' : 'Batalkan invoice',
                                  saatKlik: () => AturBatalkan(true),
                                  bahaya: true,
                              },
                          ]
                        : []),
                ]}
            />

            <KartuKeteranganGrosir>
                <KeteranganGrosir label="Pelanggan">{invoice.NamaPelanggan}</KeteranganGrosir>
                <KeteranganGrosir label="Outlet penerbit">{invoice.KodeOutlet}</KeteranganGrosir>
                <KeteranganGrosir label="Tanggal invoice">{FormatTanggal(invoice.Tanggal)}</KeteranganGrosir>
                <KeteranganGrosir label="Jatuh tempo">
                    {FormatTanggal(invoice.JatuhTempo)} ({KeteranganTermin(invoice.TerminHari)})
                </KeteranganGrosir>
                {invoice.Perihal ? <KeteranganGrosir label="Perihal">{invoice.Perihal}</KeteranganGrosir> : null}
                {invoice.NomorReferensi ? (
                    <KeteranganGrosir label="No. PO / referensi">
                        <span className="font-mono">{invoice.NomorReferensi}</span>
                    </KeteranganGrosir>
                ) : null}
                <KeteranganGrosir label="Harga">
                    {invoice.HargaTermasukPajak ? 'Sudah termasuk pajak' : 'Belum termasuk pajak'}
                </KeteranganGrosir>
                {invoice.Sisa !== null ? (
                    <KeteranganGrosir label="Sisa tagihan">{FormatRupiah(invoice.Sisa)}</KeteranganGrosir>
                ) : null}
                {invoice.Status === 'Terbit' ? (
                    <KeteranganGrosir label="Dikirim / dilihat pelanggan">
                        {invoice.TerkirimPada === null
                            ? 'Belum dikirim'
                            : `Dikirim ${FormatTanggalWaktu(invoice.TerkirimPada)}`}
                        {' | '}
                        {invoice.DilihatPada === null
                            ? 'Belum dilihat'
                            : `Dilihat ${FormatTanggalWaktu(invoice.DilihatPada)} (${String(invoice.JumlahDilihat)}x)`}
                    </KeteranganGrosir>
                ) : null}
                {invoice.Penawaran !== null ? (
                    <KeteranganGrosir label="Dari penawaran">
                        <Link
                            href={`/kelola/penawaran/${invoice.Penawaran.Uuid}`}
                            className="font-mono font-semibold text-brand underline"
                        >
                            {invoice.Penawaran.Nomor ?? 'Penawaran'}
                        </Link>
                    </KeteranganGrosir>
                ) : null}
                {invoice.DipakaiDiInvoiceAkhir ? (
                    <KeteranganGrosir label="Uang muka">Sudah dipotong di invoice akhir</KeteranganGrosir>
                ) : null}
            </KartuKeteranganGrosir>

            <section aria-label="Baris invoice" className="flex flex-col gap-2">
                <h2 className="text-subjudul font-semibold text-teks-utama">Baris invoice</h2>
                <TabelForm label="Baris invoice" lebar="dokumen">
                    <TableHeader>
                        <TableRow>
                            <TableHead className={kelasKepala}>Uraian</TableHead>
                            <TableHead className={`${kelasKepala} text-right`}>Jumlah</TableHead>
                            <TableHead className={`${kelasKepala} text-right`}>Harga</TableHead>
                            <TableHead className={`${kelasKepala} text-right`}>Diskon</TableHead>
                            <TableHead className={`${kelasKepala} text-right`}>Pajak</TableHead>
                            <TableHead className={`${kelasKepala} text-right`}>Subtotal</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {Baris.map((b) => (
                            <TableRow key={b.Kunci}>
                                <TableCell className={kelasSel}>
                                    <span className="font-semibold break-words">{b.Nama}</span>
                                    {b.Keterangan ? (
                                        <span className="block text-keterangan text-teks-sekunder">{b.Keterangan}</span>
                                    ) : null}
                                </TableCell>
                                <TableCell className={`${kelasSel} text-right tabular-nums`}>
                                    {FormatJumlahInvoice(b.Jumlah)} {b.SimbolSatuan ?? ''}
                                </TableCell>
                                <TableCell className={`${kelasSel} text-right tabular-nums`}>
                                    {FormatRupiah(b.Harga)}
                                </TableCell>
                                <TableCell className={`${kelasSel} text-right tabular-nums`}>
                                    {FormatRupiah(b.Diskon)}
                                </TableCell>
                                <TableCell className={`${kelasSel} text-right tabular-nums`}>
                                    {FormatRupiah(b.Pajak)}
                                </TableCell>
                                <TableCell className={`${kelasSel} text-right tabular-nums`}>
                                    {FormatRupiah(b.Subtotal)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </TabelForm>
                <RingkasanNilaiGrosir
                    baris={[
                        { label: 'Subtotal', nilai: invoice.Subtotal },
                        { label: 'Diskon', nilai: invoice.Diskon },
                        { label: 'Dasar pengenaan pajak', nilai: invoice.DasarPengenaanPajak },
                        {
                            label:
                                invoice.TarifPpn === null ? 'Pajak' : `Pajak (PPN ${FormatPersen(invoice.TarifPpn)})`,
                            nilai: invoice.Pajak,
                        },
                        { label: 'Total invoice', nilai: invoice.Total, tebal: true },
                        ...(AmbilTandaDesimal(invoice.PotonganUangMukaDpp) > 0
                            ? [
                                  {
                                      label: 'Uang muka dipotong (DPP)',
                                      nilai: invoice.PotonganUangMukaDpp,
                                  },
                                  { label: 'Pajak uang muka dipotong', nilai: invoice.PotonganUangMukaPajak },
                                  { label: 'Tagihan', nilai: invoice.Tagihan, tebal: true },
                              ]
                            : []),
                    ]}
                />
                {invoice.KodePph !== null && AmbilTandaDesimal(invoice.NilaiPph) > 0 ? (
                    <p className="ml-auto max-w-md text-right text-isi text-teks-sekunder">
                        {invoice.KodePph} {invoice.TarifPph === null ? '' : `${FormatPersen(invoice.TarifPph)} `}
                        dari {FormatRupiah(invoice.DasarPph)} ={' '}
                        <span className="font-semibold text-teks-utama tabular-nums">
                            {FormatRupiah(invoice.NilaiPph)}
                        </span>
                        , dipotong pelanggan saat membayar dan dibuktikan dengan bukti potong.
                    </p>
                ) : null}
            </section>

            {UangMuka.length > 0 ? (
                <Panel judul="Uang muka yang dipotong">
                    <ul className="flex flex-col gap-1 text-isi">
                        {UangMuka.map((dp) => (
                            <li key={dp.Uuid} className="break-words text-teks-sekunder">
                                <Link
                                    href={`${AlamatInvoice}/${dp.Uuid}`}
                                    className="font-mono font-semibold text-brand underline"
                                >
                                    {dp.Nomor}
                                </Link>{' '}
                                | {FormatTanggal(dp.Tanggal)} | DPP {FormatRupiah(dp.Dpp)} | Pajak{' '}
                                {FormatRupiah(dp.Pajak)}
                            </li>
                        ))}
                    </ul>
                </Panel>
            ) : null}

            {Pelunasan.length > 0 ? (
                <Panel judul="Pembayaran">
                    <ul className="flex flex-col gap-1 text-isi">
                        {Pelunasan.map((p) => (
                            <li key={p.Uuid} className="break-words text-teks-sekunder">
                                <span className="font-mono font-semibold text-teks-utama">{p.Nomor}</span> |{' '}
                                {FormatTanggal(p.Tanggal)} | {FormatRupiah(p.Jumlah)}
                                {AmbilTandaDesimal(p.PotonganPph) > 0
                                    ? ` (PPh ${FormatRupiah(p.PotonganPph)}${p.NomorBuktiPotong ? `, bukti potong ${p.NomorBuktiPotong}` : ''})`
                                    : ''}{' '}
                                | {p.LabelStatus}
                                {p.Status === 'Diposting' ? (
                                    <>
                                        {' | '}
                                        <a
                                            href={`${AlamatInvoice}/${invoice.Uuid}/kuitansi/${p.Uuid}`}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="font-semibold text-brand underline"
                                        >
                                            Kuitansi
                                        </a>
                                    </>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </Panel>
            ) : null}

            {Konfirmasi.length > 0 ? (
                <Panel
                    judul="Konfirmasi pembayaran dari pelanggan"
                    keterangan="Klaim sudah bayar dari halaman invoice. Cocokkan dengan mutasi bank, lalu terima (mencatat pembayaran) atau tolak."
                >
                    <ul className="flex flex-col gap-3 text-isi">
                        {Konfirmasi.map((k) => (
                            <li key={k.Uuid} className="flex flex-col gap-1 break-words text-teks-sekunder">
                                <span>
                                    <span className="font-semibold text-teks-utama">{k.NamaPengirim}</span> |{' '}
                                    <span className="tabular-nums">{FormatRupiah(k.Jumlah)}</span> | bayar{' '}
                                    {FormatTanggal(k.TanggalBayar)} | dikirim {FormatTanggalWaktu(k.Pada)} |{' '}
                                    {k.TidakDiperlukan
                                        ? 'Tidak diperlukan lagi (invoice sudah lunas atau dibatalkan)'
                                        : k.LabelStatus}
                                </span>
                                {k.Catatan ? <span>Catatan pelanggan: {k.Catatan}</span> : null}
                                {k.AdaBukti ? (
                                    <a
                                        href={`${AlamatInvoice}/${invoice.Uuid}/konfirmasi/${k.Uuid}/bukti`}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="font-semibold text-brand underline"
                                    >
                                        Lihat foto bukti transfer
                                    </a>
                                ) : null}
                                {k.AlasanTolak ? <span>Alasan ditolak: {k.AlasanTolak}</span> : null}
                                {k.BisaDiputuskan && Izin.Kelola ? (
                                    <span className="flex flex-wrap gap-2">
                                        {Tindakan.CatatPembayaran ? (
                                            <Tombol onClick={() => AturTerimaKonfirmasi(k)}>
                                                Terima & catat pembayaran
                                            </Tombol>
                                        ) : null}
                                        <Tombol varian="sekunder" onClick={() => AturTolakKonfirmasi(k)}>
                                            Tolak
                                        </Tombol>
                                    </span>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </Panel>
            ) : null}

            {Pengiriman.length > 0 ? (
                <Panel judul="Pengiriman WhatsApp">
                    <ul className="flex flex-col gap-1 text-isi">
                        {Pengiriman.map((k) => (
                            <li key={k.Uuid} className="break-words text-teks-sekunder">
                                {k.Jenis === 'Pengingat' ? `Pengingat${k.Tahap ? ` ${k.Tahap}` : ''}` : 'Invoice'} |{' '}
                                {k.LabelStatus} | {FormatTanggalWaktu(k.Pada)}
                                {k.PesanGalat ? ` | ${k.PesanGalat}` : ''}
                            </li>
                        ))}
                    </ul>
                </Panel>
            ) : null}

            {invoice.Catatan || invoice.Syarat || invoice.InfoPembayaran ? (
                <Panel judul="Catatan & cara bayar">
                    <dl className="grid gap-4 lg:grid-cols-3">
                        {invoice.Catatan ? (
                            <KeteranganGrosir label="Catatan">{invoice.Catatan}</KeteranganGrosir>
                        ) : null}
                        {invoice.Syarat ? (
                            <KeteranganGrosir label="Syarat & ketentuan">{invoice.Syarat}</KeteranganGrosir>
                        ) : null}
                        {invoice.InfoPembayaran ? (
                            <KeteranganGrosir label="Cara pembayaran">{invoice.InfoPembayaran}</KeteranganGrosir>
                        ) : null}
                    </dl>
                </Panel>
            ) : null}

            <JurnalDokumenGrosir
                jurnal={Jurnal}
                izin={{ Kelola: Izin.Kelola, SetujuiKredit: false, LihatJurnal: Izin.LihatJurnal, LihatPiutang: true }}
            />
            <RiwayatGrosirDokumen riwayat={Riwayat} />

            {batalkan ? (
                <DialogAlasanGrosir
                    judul={invoice.Status === 'Draf' ? 'Batalkan draf invoice' : 'Batalkan invoice'}
                    keterangan={
                        invoice.Status === 'Draf'
                            ? 'Draf dibatalkan tanpa jurnal. Draf yang sudah dibatalkan tidak bisa dibuka lagi.'
                            : 'Jurnalnya dibalik dan piutangnya dibatalkan. Invoice yang sudah dibayar tidak bisa dibatalkan. Batalkan dulu pembayarannya. Uang muka yang dipotong dilepas dan bisa dipakai lagi.'
                    }
                    labelAksi="Batalkan"
                    alamat={`${AlamatInvoice}/${invoice.Uuid}/batalkan`}
                    saatTutup={() => AturBatalkan(false)}
                />
            ) : null}
            {tolakKonfirmasi !== null ? (
                <DialogAlasanGrosir
                    judul="Tolak konfirmasi pembayaran"
                    keterangan="Beri alasan, misalnya uangnya tidak ditemukan di mutasi bank atau jumlahnya tidak cocok. Pelanggan tidak diberi tahu otomatis. Hubungi mereka bila perlu."
                    labelAksi="Tolak konfirmasi"
                    alamat={`${AlamatInvoice}/${invoice.Uuid}/konfirmasi/${tolakKonfirmasi.Uuid}/tolak`}
                    saatTutup={() => AturTolakKonfirmasi(null)}
                />
            ) : null}
            {terimaKonfirmasi !== null && invoice.Sisa !== null ? (
                <DialogPembayaranInvoice
                    uuidInvoice={invoice.Uuid}
                    nomor={invoice.Nomor ?? ''}
                    sisa={invoice.Sisa}
                    kodePph={invoice.KodePph}
                    saranPph={AmbilTandaDesimal(sisaPph) > 0 ? sisaPph : ''}
                    opsiAkun={OpsiAkun}
                    hariIni={HariIni}
                    konfirmasi={terimaKonfirmasi}
                    saatTutup={() => AturTerimaKonfirmasi(null)}
                />
            ) : null}
            {bayar && invoice.Sisa !== null ? (
                <DialogPembayaranInvoice
                    uuidInvoice={invoice.Uuid}
                    nomor={invoice.Nomor ?? ''}
                    sisa={invoice.Sisa}
                    kodePph={invoice.KodePph}
                    saranPph={AmbilTandaDesimal(sisaPph) > 0 ? sisaPph : ''}
                    opsiAkun={OpsiAkun}
                    hariIni={HariIni}
                    saatTutup={() => AturBayar(false)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
