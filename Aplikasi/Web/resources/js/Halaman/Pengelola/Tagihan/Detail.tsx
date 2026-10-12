import { useForm, usePage } from '@inertiajs/react';
import { useId, type FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import RincianTagihan from '@/Komponen/Langganan/RincianTagihan';
import { PanelTindakanTagihan, RiwayatRefund } from '@/Komponen/Langganan/TindakanTagihanPengelola';
import { Card } from '@/Komponen/Ui/card';
import { Label } from '@/Komponen/Ui/label';
import { Textarea } from '@/Komponen/Ui/textarea';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, PunyaIzin, type PropsBersamaPengelola } from '@/Tipe/Pengelola';
import {
    JenisLabelPembayaran,
    type PembayaranLangganan,
    type RefundLangganan,
    type TagihanLangganan,
} from '@/Tipe/TagihanLangganan';

type BarisPembayaran = PembayaranLangganan & { JumlahDiterima: string | null; Verifikator: string | null };

type PropsDetailTagihan = {
    Tagihan: TagihanLangganan & { NamaTenant: string };
    Pembayaran: BarisPembayaran[];
    Refund?: RefundLangganan[];
    Izin?: { Terbitkan: boolean; Refund: boolean; SetujuiRefund: boolean };
    BatasRefundTanpaPersetujuan?: string;
};

/** Detail tagihan & verifikasi bukti transfer (P-08 langkah 3). Terima hanya bila jumlah di rekening cocok. */
export default function HalamanDetailTagihan({
    Tagihan,
    Pembayaran,
    Refund = [],
    Izin = { Terbitkan: false, Refund: false, SetujuiRefund: false },
    BatasRefundTanpaPersetujuan = '1000000',
}: PropsDetailTagihan) {
    const { props } = usePage<PropsBersamaPengelola>();
    const bolehVerifikasi = PunyaIzin(props.Pengguna, IzinPengelola.TagihanVerifikasi);

    return (
        <TataLetakPengelola judul={`Tagihan ${Tagihan.Nomor}`} jejak={[{ label: 'Semua tagihan', href: '/tagihan' }]}>
            {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}
            <RincianTagihan tagihan={Tagihan} namaTenant={Tagihan.NamaTenant} />
            <PanelTindakanTagihan
                tagihan={Tagihan}
                izin={Izin}
                adaPembayaranMenunggu={Pembayaran.some((baris) => baris.Status === 'Menunggu')}
                batasRefund={BatasRefundTanpaPersetujuan}
            />
            <RiwayatRefund refund={Refund} />
            <section aria-labelledby="judul-pembayaran" className="flex flex-col gap-3">
                <h2 id="judul-pembayaran" className="text-subjudul font-semibold text-teks-utama">
                    Pembayaran
                </h2>
                {Pembayaran.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">Belum ada pembayaran.</p>
                ) : (
                    Pembayaran.map((baris) => (
                        <KartuPembayaran
                            key={baris.Uuid}
                            pembayaran={baris}
                            total={Tagihan.Total}
                            bolehVerifikasi={bolehVerifikasi}
                        />
                    ))
                )}
            </section>
        </TataLetakPengelola>
    );
}

function KartuPembayaran({
    pembayaran,
    total,
    bolehVerifikasi,
}: {
    pembayaran: BarisPembayaran;
    total: string;
    bolehVerifikasi: boolean;
}) {
    return (
        <article>
            <Card className="gap-3 px-4 py-4 rounded-panel shadow-none">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <LabelStatus jenis={JenisLabelPembayaran(pembayaran.Status)} teks={pembayaran.LabelStatus} />
                    {pembayaran.Metode === 'TransferManual' && (
                        <a
                            href={`/tagihan/pembayaran/${pembayaran.Uuid}/bukti`}
                            target="_blank"
                            rel="noreferrer"
                            className="text-label font-semibold underline"
                        >
                            Buka bukti transfer
                        </a>
                    )}
                </div>
                <dl className="grid gap-x-6 gap-y-2 text-isi sm:grid-cols-3">
                    <div>
                        <dt className="text-keterangan text-teks-sekunder">Jumlah</dt>
                        <dd className="tabular-nums">{FormatRupiah(pembayaran.Jumlah)}</dd>
                    </div>
                    <div>
                        <dt className="text-keterangan text-teks-sekunder">Cara bayar</dt>
                        <dd>{pembayaran.LabelMetode}</dd>
                    </div>
                    <div>
                        <dt className="text-keterangan text-teks-sekunder">Waktu</dt>
                        <dd>{FormatTanggalWaktu(pembayaran.DiunggahPada)}</dd>
                    </div>
                    {pembayaran.Metode === 'TransferManual' ? (
                        <>
                            <div>
                                <dt className="text-keterangan text-teks-sekunder">Tanggal transfer</dt>
                                <dd>{FormatTanggal(pembayaran.TanggalTransfer)}</dd>
                            </div>
                            <div>
                                <dt className="text-keterangan text-teks-sekunder">Pengirim</dt>
                                <dd>
                                    {pembayaran.BankPengirim} | {pembayaran.NamaPengirim}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-keterangan text-teks-sekunder">Rekening tujuan</dt>
                                <dd>
                                    {pembayaran.BankTujuan}{' '}
                                    <span className="font-mono">{pembayaran.NomorRekeningTujuan}</span>
                                </dd>
                            </div>
                        </>
                    ) : null}
                    {pembayaran.DiverifikasiPada ? (
                        <div>
                            <dt className="text-keterangan text-teks-sekunder">Diverifikasi</dt>
                            <dd>
                                {FormatTanggalWaktu(pembayaran.DiverifikasiPada)} oleh {pembayaran.Verifikator ?? '—'}
                            </dd>
                        </div>
                    ) : null}
                    {pembayaran.AlasanTolak ? (
                        <div className="sm:col-span-3">
                            <dt className="text-keterangan text-teks-sekunder">Alasan ditolak</dt>
                            <dd>{pembayaran.AlasanTolak}</dd>
                        </div>
                    ) : null}
                </dl>
                {pembayaran.Metode === 'TransferManual' && pembayaran.Status === 'Menunggu' && bolehVerifikasi ? (
                    <div className="grid gap-4 border-t border-garis pt-3 lg:grid-cols-2">
                        <FormTerima uuid={pembayaran.Uuid} total={total} />
                        <FormTolak uuid={pembayaran.Uuid} />
                    </div>
                ) : null}
            </Card>
        </article>
    );
}

function FormTerima({ uuid, total }: { uuid: string; total: string }) {
    const formulir = useForm({ JumlahDiterima: '', Catatan: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tagihan/pembayaran/${uuid}/terima`, { preserveScroll: true });
    };

    return (
        <form onSubmit={Kirim} className="flex flex-col gap-3" noValidate>
            <h3 className="text-label font-semibold text-teks-utama">Terima pembayaran</h3>
            <BidangTeks
                label="Jumlah masuk di mutasi rekening (Rp)"
                inputMode="decimal"
                nilai={formulir.data.JumlahDiterima}
                saatBerubah={(nilai) => formulir.setData('JumlahDiterima', nilai)}
                galat={formulir.errors.JumlahDiterima}
                required
                keterangan={`Harus sama dengan total tagihan ${FormatRupiah(total)}.`}
            />
            <BidangTeks
                label="Catatan (opsional)"
                nilai={formulir.data.Catatan}
                saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                galat={formulir.errors.Catatan}
            />
            <div>
                <Tombol type="submit" memproses={formulir.processing}>
                    Terima dan aktifkan langganan
                </Tombol>
            </div>
        </form>
    );
}

function FormTolak({ uuid }: { uuid: string }) {
    const id = useId();
    const formulir = useForm({ Alasan: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tagihan/pembayaran/${uuid}/tolak`, { preserveScroll: true });
    };

    return (
        <form onSubmit={Kirim} className="flex flex-col gap-3" noValidate>
            <h3 className="text-label font-semibold text-teks-utama">Tolak bukti</h3>
            <div className="flex flex-col gap-1">
                <Label htmlFor={id} className="text-label font-semibold text-teks-utama">
                    Alasan (dikirim ke pemilik usaha)
                </Label>
                <Textarea
                    id={id}
                    rows={3}
                    value={formulir.data.Alasan}
                    onChange={(peristiwa) => formulir.setData('Alasan', peristiwa.target.value)}
                    required
                    aria-invalid={formulir.errors.Alasan ? true : undefined}
                    aria-describedby={formulir.errors.Alasan ? `${id}-galat` : undefined}
                    className="h-auto py-2 text-isi field-sizing-fixed"
                />
                {formulir.errors.Alasan ? (
                    <p id={`${id}-galat`} className="text-keterangan font-semibold text-bahaya">
                        {formulir.errors.Alasan}
                    </p>
                ) : null}
            </div>
            <div>
                <Tombol type="submit" varian="bahaya" memproses={formulir.processing}>
                    Tolak bukti transfer
                </Tombol>
            </div>
        </form>
    );
}
