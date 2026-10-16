import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import {
    DialogAlasanGrosir,
    KartuKeteranganGrosir,
    KeteranganGrosir,
    RingkasanNilaiGrosir,
    RiwayatGrosirDokumen,
} from '@/Komponen/Grosir/BagianDokumenGrosir';
import { AlamatInvoice, FormatJumlahInvoice, KeteranganTermin } from '@/Komponen/Invoice/BagianInvoice';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import { AlamatPenawaran, LabelStatusPenawaran } from '@/Komponen/Penawaran/BagianPenawaran';
import DialogKeputusanPenawaran from '@/Komponen/Penawaran/DialogKeputusanPenawaran';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import { Button } from '@/Komponen/Ui/button';
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatPersen, FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { KeputusanPenawaran, PropsDetailPenawaran } from '@/Tipe/Penawaran';

const kelasKepala = 'px-2 text-label text-teks-sekunder';
const kelasSel = 'px-2 py-2 align-top whitespace-normal';

/**
 * Detail penawaran (F-21 bagian 3, D-168). Angka diambil dari server. Setelah terbit, dokumen tidak bisa diubah (aturan
 * emas #8): revisi lewat "Salin" menjadi penawaran baru. Penawaran tidak berjurnal, jadi tidak ada panel jurnal.
 */
export default function HalamanDetailPenawaran({
    Penawaran: p,
    Baris,
    Riwayat,
    Pengiriman,
    Tindakan,
}: PropsDetailPenawaran) {
    const [batalkan, AturBatalkan] = useState(false);
    const [putuskan, AturPutuskan] = useState<KeputusanPenawaran | null>(null);
    const [memproses, AturMemproses] = useState<string | null>(null);
    const [tersalin, AturTersalin] = useState(false);
    const judul = p.Nomor === null ? 'Draf penawaran' : `Penawaran ${p.Nomor}`;

    const Kirim = (kunci: string, alamat: string) =>
        router.post(
            alamat,
            {},
            {
                preserveScroll: kunci !== 'Invoice',
                onStart: () => AturMemproses(kunci),
                onFinish: () => AturMemproses(null),
            },
        );

    const SalinTautan = () => {
        if (p.TautanPublik === null) {
            return;
        }

        void navigator.clipboard.writeText(p.TautanPublik).then(() => {
            AturTersalin(true);
            window.setTimeout(() => AturTersalin(false), 3000);
        });
    };

    return (
        <TataLetakAplikasi judul={judul} jejak={[{ label: 'Penawaran', href: AlamatPenawaran }]}>
            <div className="flex flex-wrap items-center gap-3">
                <LabelStatusPenawaran status={p.StatusTampil} label={p.LabelStatusTampil} />
            </div>

            {p.AlasanBatal !== null ? (
                <Pemberitahuan jenis="bahaya" judul="Penawaran dibatalkan">
                    {p.AlasanBatal}. Pelanggan yang membuka tautan melihat tanda dibatalkan.
                </Pemberitahuan>
            ) : null}
            {p.Status === 'Draf' ? (
                <Pemberitahuan jenis="info" judul="Masih draf">
                    Draf belum bernomor dan belum bisa dikirim. Periksa angkanya, lalu terbitkan.
                </Pemberitahuan>
            ) : null}
            {p.StatusTampil === 'Kedaluwarsa' ? (
                <Pemberitahuan jenis="peringatan" judul="Masa berlaku sudah lewat">
                    Pelanggan tidak bisa lagi menerima penawaran ini dari tautannya. Salin menjadi penawaran baru dengan
                    masa berlaku yang baru, atau tandai diterima bila pelanggan sudah menyetujui lewat jalur lain.
                </Pemberitahuan>
            ) : null}
            {p.Keputusan !== null ? (
                <Pemberitahuan
                    jenis={p.Status === 'Diterima' ? 'sukses' : 'info'}
                    judul={p.Status === 'Diterima' ? 'Penawaran diterima' : 'Penawaran ditolak'}
                >
                    {p.Keputusan.Sumber === 'Pelanggan' ? 'Diputuskan pelanggan' : 'Dicatat penagih'}
                    {p.Keputusan.Nama ? ` (${p.Keputusan.Nama})` : ''} pada {FormatTanggalWaktu(p.Keputusan.Pada)}.
                    {p.Keputusan.Catatan ? ` Catatan: ${p.Keputusan.Catatan}` : ''}
                </Pemberitahuan>
            ) : null}
            {p.Invoice !== null ? (
                <Pemberitahuan jenis="info" judul="Sudah dijadikan invoice">
                    <Link href={`${AlamatInvoice}/${p.Invoice.Uuid}`} className="font-semibold text-brand underline">
                        {p.Invoice.Nomor ?? 'Draf invoice'}
                    </Link>{' '}
                    ({p.Invoice.LabelStatus}).
                </Pemberitahuan>
            ) : null}

            <AksiHalaman>
                {Tindakan.Ubah ? (
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={`${AlamatPenawaran}/${p.Uuid}/ubah`}>Ubah draf</Link>
                    </Button>
                ) : null}
                {Tindakan.Salin ? (
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={`${AlamatPenawaran}/buat?salin=${p.Uuid}`}>Salin jadi penawaran baru</Link>
                    </Button>
                ) : null}
                {p.Status !== 'Draf' ? (
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <a href={`${AlamatPenawaran}/${p.Uuid}/cetak`} target="_blank" rel="noreferrer">
                            Cetak / PDF
                        </a>
                    </Button>
                ) : null}
                {p.TautanPublik !== null ? (
                    <Button variant="outline" onClick={SalinTautan} className="h-8 pointer-coarse:h-11">
                        {tersalin ? 'Tautan tersalin' : 'Salin tautan'}
                    </Button>
                ) : null}
                {Tindakan.Kirim ? (
                    <Tombol
                        varian="sekunder"
                        memproses={memproses === 'Kirim'}
                        onClick={() => Kirim('Kirim', `${AlamatPenawaran}/${p.Uuid}/kirim`)}
                    >
                        Kirim lewat WhatsApp
                    </Tombol>
                ) : null}
                {Tindakan.Putuskan ? (
                    <>
                        <Tombol varian="sekunder" onClick={() => AturPutuskan('Tolak')}>
                            Tandai ditolak
                        </Tombol>
                        <Tombol varian="sekunder" onClick={() => AturPutuskan('Terima')}>
                            Tandai diterima
                        </Tombol>
                    </>
                ) : null}
                {Tindakan.JadikanInvoice ? (
                    <Tombol
                        memproses={memproses === 'Invoice'}
                        onClick={() => Kirim('Invoice', `${AlamatPenawaran}/${p.Uuid}/jadikan-invoice`)}
                    >
                        {p.Invoice !== null && p.Invoice.Status === 'Draf' ? 'Buka draf invoice' : 'Jadikan invoice'}
                    </Tombol>
                ) : null}
                {Tindakan.Terbitkan ? (
                    <Tombol
                        memproses={memproses === 'Terbit'}
                        onClick={() => Kirim('Terbit', `${AlamatPenawaran}/${p.Uuid}/terbitkan`)}
                    >
                        Terbitkan penawaran
                    </Tombol>
                ) : null}
                {Tindakan.Batalkan ? (
                    <Button
                        variant="outline"
                        onClick={() => AturBatalkan(true)}
                        className="h-8 text-bahaya pointer-coarse:h-11"
                    >
                        {p.Status === 'Draf' ? 'Batalkan draf' : 'Batalkan penawaran'}
                    </Button>
                ) : null}
            </AksiHalaman>

            <KartuKeteranganGrosir>
                <KeteranganGrosir label="Pelanggan">{p.NamaPelanggan}</KeteranganGrosir>
                <KeteranganGrosir label="Outlet penerbit">{p.KodeOutlet}</KeteranganGrosir>
                <KeteranganGrosir label="Tanggal penawaran">{FormatTanggal(p.Tanggal)}</KeteranganGrosir>
                <KeteranganGrosir label="Berlaku sampai">{FormatTanggal(p.BerlakuSampai)}</KeteranganGrosir>
                <KeteranganGrosir label="Termin invoice">{KeteranganTermin(p.TerminHari)}</KeteranganGrosir>
                {p.Perihal ? <KeteranganGrosir label="Perihal">{p.Perihal}</KeteranganGrosir> : null}
                {p.NomorReferensi ? (
                    <KeteranganGrosir label="No. PO / referensi">
                        <span className="font-mono">{p.NomorReferensi}</span>
                    </KeteranganGrosir>
                ) : null}
                <KeteranganGrosir label="Harga">
                    {p.HargaTermasukPajak ? 'Sudah termasuk pajak' : 'Belum termasuk pajak'}
                </KeteranganGrosir>
                {p.Status !== 'Draf' ? (
                    <KeteranganGrosir label="Dikirim / dilihat pelanggan">
                        {p.TerkirimPada === null ? 'Belum dikirim' : `Dikirim ${FormatTanggalWaktu(p.TerkirimPada)}`}
                        {' | '}
                        {p.DilihatPada === null
                            ? 'Belum dilihat'
                            : `Dilihat ${FormatTanggalWaktu(p.DilihatPada)} (${String(p.JumlahDilihat)}x)`}
                    </KeteranganGrosir>
                ) : null}
            </KartuKeteranganGrosir>

            <section aria-label="Baris penawaran" className="flex flex-col gap-2">
                <h2 className="text-subjudul font-semibold text-teks-utama">Baris penawaran</h2>
                <TabelForm label="Baris penawaran" lebar="dokumen">
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
                        { label: 'Subtotal', nilai: p.Subtotal },
                        { label: 'Diskon', nilai: p.Diskon },
                        { label: 'Dasar pengenaan pajak', nilai: p.DasarPengenaanPajak },
                        {
                            label: p.TarifPpn === null ? 'Pajak' : `Pajak (PPN ${FormatPersen(p.TarifPpn)})`,
                            nilai: p.Pajak,
                        },
                        { label: 'Total penawaran', nilai: p.Total, tebal: true },
                    ]}
                />
            </section>

            {Pengiriman.length > 0 ? (
                <Panel judul="Pengiriman WhatsApp">
                    <ul className="flex flex-col gap-1 text-isi">
                        {Pengiriman.map((k) => (
                            <li key={k.Uuid} className="break-words text-teks-sekunder">
                                {k.LabelStatus} | {FormatTanggalWaktu(k.Pada)}
                                {k.PesanGalat ? ` | ${k.PesanGalat}` : ''}
                            </li>
                        ))}
                    </ul>
                </Panel>
            ) : null}

            {p.Catatan || p.Syarat ? (
                <Panel judul="Catatan & syarat">
                    <dl className="grid gap-4 lg:grid-cols-2">
                        {p.Catatan ? <KeteranganGrosir label="Catatan">{p.Catatan}</KeteranganGrosir> : null}
                        {p.Syarat ? <KeteranganGrosir label="Syarat & ketentuan">{p.Syarat}</KeteranganGrosir> : null}
                    </dl>
                </Panel>
            ) : null}

            <RiwayatGrosirDokumen riwayat={Riwayat} />

            {batalkan ? (
                <DialogAlasanGrosir
                    judul={p.Status === 'Draf' ? 'Batalkan draf penawaran' : 'Batalkan penawaran'}
                    keterangan="Penawaran yang dibatalkan tidak bisa dibuka lagi. Pelanggan yang membuka tautannya melihat tanda dibatalkan."
                    labelAksi="Batalkan"
                    alamat={`${AlamatPenawaran}/${p.Uuid}/batalkan`}
                    saatTutup={() => AturBatalkan(false)}
                />
            ) : null}
            {putuskan !== null ? (
                <DialogKeputusanPenawaran
                    uuidPenawaran={p.Uuid}
                    keputusan={putuskan}
                    saatTutup={() => AturPutuskan(null)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
