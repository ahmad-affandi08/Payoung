import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import { KartuKeteranganGrosir, KeteranganGrosir } from '@/Komponen/Grosir/BagianDokumenGrosir';
import { AlamatInvoice, FormatJumlahInvoice, KeteranganTermin } from '@/Komponen/Invoice/BagianInvoice';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import TabelForm from '@/Komponen/TabelData/TabelForm';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { Button } from '@/Komponen/Ui/button';
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsDetailInvoiceBerulang } from '@/Tipe/Invoice';

const alamat = `${AlamatInvoice}/berulang`;
const kelasKepala = 'px-2 text-label text-teks-sekunder';
const kelasSel = 'px-2 py-2 align-top whitespace-normal';

/** Detail templat invoice berulang (F-21): jadwal, isi tagihan, galat terakhir, dan invoice yang sudah terbit darinya. */
export default function HalamanDetailInvoiceBerulang({
    Templat: t,
    Baris,
    InvoiceTerbit,
    Tindakan,
}: PropsDetailInvoiceBerulang) {
    const [memproses, AturMemproses] = useState<string | null>(null);
    const [hentikan, AturHentikan] = useState(false);

    const Ubah = (aksi: 'jeda' | 'lanjut' | 'hentikan') =>
        router.post(
            `${alamat}/${t.Uuid}/${aksi}`,
            {},
            {
                preserveScroll: true,
                onStart: () => AturMemproses(aksi),
                onFinish: () => AturMemproses(null),
                onSuccess: () => AturHentikan(false),
            },
        );

    return (
        <TataLetakAplikasi
            judul={t.Nama}
            jejak={[
                { label: 'Invoice', href: AlamatInvoice },
                { label: 'Invoice berulang', href: alamat },
            ]}
        >
            <div className="flex flex-wrap items-center gap-3">
                <LabelStatus
                    jenis={t.Status === 'Aktif' ? 'sukses' : t.Status === 'Dijeda' ? 'peringatan' : 'netral'}
                    teks={t.LabelStatus}
                />
                <span className="text-isi text-teks-sekunder">{t.LabelMode}</span>
            </div>

            {t.GalatTerakhir !== null ? (
                <Pemberitahuan jenis="bahaya" judul="Siklus terakhir gagal terbit">
                    {t.GalatTerakhir} Sistem mencoba lagi besok pagi; perbaiki masalahnya agar invoice terbit.
                </Pemberitahuan>
            ) : null}

            <AksiHalaman>
                {Tindakan.Ubah ? (
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={`${alamat}/${t.Uuid}/ubah`}>Ubah templat</Link>
                    </Button>
                ) : null}
                {Tindakan.Jeda ? (
                    <Tombol varian="sekunder" memproses={memproses === 'jeda'} onClick={() => Ubah('jeda')}>
                        Jeda
                    </Tombol>
                ) : null}
                {Tindakan.Lanjut ? (
                    <Tombol memproses={memproses === 'lanjut'} onClick={() => Ubah('lanjut')}>
                        Lanjutkan
                    </Tombol>
                ) : null}
                {Tindakan.Hentikan ? (
                    <Button
                        variant="outline"
                        onClick={() => AturHentikan(true)}
                        className="h-8 text-bahaya pointer-coarse:h-11"
                    >
                        Hentikan
                    </Button>
                ) : null}
            </AksiHalaman>

            <KartuKeteranganGrosir>
                <KeteranganGrosir label="Pelanggan">{t.NamaPelanggan}</KeteranganGrosir>
                <KeteranganGrosir label="Outlet penerbit">{t.KodeOutlet}</KeteranganGrosir>
                <KeteranganGrosir label="Jadwal">{t.Jadwal}</KeteranganGrosir>
                <KeteranganGrosir label="Mulai">{FormatTanggal(t.TanggalMulai)}</KeteranganGrosir>
                <KeteranganGrosir label="Terbit berikutnya">
                    {t.BerikutnyaPada === null ? '—' : FormatTanggal(t.BerikutnyaPada)}
                </KeteranganGrosir>
                <KeteranganGrosir label="Berakhir">
                    {t.TanggalBerakhir !== null
                        ? FormatTanggal(t.TanggalBerakhir)
                        : t.BatasKali !== null
                          ? `Setelah ${String(t.BatasKali)} invoice`
                          : 'Tanpa batas'}
                </KeteranganGrosir>
                <KeteranganGrosir label="Sudah terbit">{String(t.JumlahTerbit)} invoice</KeteranganGrosir>
                <KeteranganGrosir label="Termin">
                    {t.TerminHari === null ? 'Ikut data pelanggan' : KeteranganTermin(t.TerminHari)}
                </KeteranganGrosir>
                <KeteranganGrosir label="Harga">
                    {t.HargaTermasukPajak ? 'Sudah termasuk pajak' : 'Belum termasuk pajak'}
                </KeteranganGrosir>
                {t.Perihal ? <KeteranganGrosir label="Perihal">{t.Perihal}</KeteranganGrosir> : null}
                {t.KodePph ? (
                    <KeteranganGrosir label="PPh dipotong pelanggan">
                        {t.KodePph} {t.TarifPph ?? ''}%
                    </KeteranganGrosir>
                ) : null}
                {t.TerakhirTerbitPada ? (
                    <KeteranganGrosir label="Terakhir terbit">
                        {FormatTanggalWaktu(t.TerakhirTerbitPada)}
                    </KeteranganGrosir>
                ) : null}
            </KartuKeteranganGrosir>

            <section aria-label="Isi tagihan" className="flex flex-col gap-2">
                <h2 className="text-subjudul font-semibold text-teks-utama">Isi tagihan tiap siklus</h2>
                <TabelForm label="Isi tagihan" lebar="sedang">
                    <TableHeader>
                        <TableRow>
                            <TableHead className={kelasKepala}>Uraian</TableHead>
                            <TableHead className={`${kelasKepala} text-right`}>Jumlah</TableHead>
                            <TableHead className={`${kelasKepala} text-right`}>Harga</TableHead>
                            <TableHead className={`${kelasKepala} text-right`}>Diskon</TableHead>
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
                            </TableRow>
                        ))}
                    </TableBody>
                </TabelForm>
                <p className="text-keterangan text-teks-sekunder">
                    Pajak dan total dihitung sistem tiap siklus. Tulisan {'{bulan}'}, {'{tahun}'}, dan {'{periode}'} di
                    perihal dan catatan diganti sesuai tanggal invoice.
                </p>
            </section>

            <Panel judul="Invoice yang sudah terbit">
                {InvoiceTerbit.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">Belum ada invoice yang terbit dari templat ini.</p>
                ) : (
                    <ul className="flex flex-col gap-1 text-isi">
                        {InvoiceTerbit.map((i) => (
                            <li key={i.Uuid} className="break-words text-teks-sekunder">
                                <Link
                                    href={`${AlamatInvoice}/${i.Uuid}`}
                                    className="font-mono font-semibold text-brand underline"
                                >
                                    {i.Nomor ?? 'Draf'}
                                </Link>{' '}
                                | {FormatTanggal(i.Tanggal)} | {FormatRupiah(i.Total)} | {i.LabelStatus}
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            {hentikan ? (
                <DialogFormulir
                    jenis="konfirmasi"
                    judul="Hentikan templat ini?"
                    keterangan="Tidak ada invoice baru yang terbit dari templat ini. Invoice yang sudah terbit tidak berubah. Templat yang dihentikan tidak bisa dihidupkan lagi; buat templat baru bila perlu. Untuk berhenti sementara, pakai Jeda."
                    saatTutup={() => AturHentikan(false)}
                >
                    <div className="flex flex-wrap justify-end gap-2">
                        <Tombol type="button" varian="sekunder" onClick={() => AturHentikan(false)}>
                            Batal
                        </Tombol>
                        <Tombol varian="bahaya" memproses={memproses === 'hentikan'} onClick={() => Ubah('hentikan')}>
                            Hentikan templat
                        </Tombol>
                    </div>
                </DialogFormulir>
            ) : null}
        </TataLetakAplikasi>
    );
}
