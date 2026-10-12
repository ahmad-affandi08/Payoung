import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { AmbilGalatUmum, KerangkaForm } from '@/Komponen/Pengelola/Tenant/FormTindakan';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Card } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { JenisLabelRefund, type RefundLangganan, type TagihanLangganan } from '@/Tipe/TagihanLangganan';

type IzinTindakanTagihan = { Terbitkan: boolean; Refund: boolean; SetujuiRefund: boolean };

type PropsPanelTindakan = {
    tagihan: TagihanLangganan;
    izin: IzinTindakanTagihan;
    /** Ada pembayaran `Menunggu`: pencatatan langsung dan hapus-buku ditunda sampai diputuskan. */
    adaPembayaranMenunggu: boolean;
    batasRefund: string;
};

type TindakanTagihan = 'catat' | 'hapus-buku' | 'refund' | null;

/**
 * D-112 (P-08): tombol tindakan di detail tagihan konsol. Catat pembayaran langsung hanya untuk tagihan manual yang
 * masih terbuka; hapus-buku hanya untuk tagihan yang sudah lewat jatuh tempo; refund hanya untuk tagihan lunas bernilai.
 */
export function PanelTindakanTagihan({ tagihan, izin, adaPembayaranMenunggu, batasRefund }: PropsPanelTindakan) {
    const [tindakan, AturTindakan] = useState<TindakanTagihan>(null);
    const Tutup = () => AturTindakan(null);
    const terbuka = tagihan.Status === 'Terbit' || tagihan.Status === 'JatuhTempo';
    const bolehCatat = izin.Terbitkan && tagihan.Manual && terbuka && !adaPembayaranMenunggu;
    const bolehHapusBuku = izin.Terbitkan && tagihan.Status === 'JatuhTempo' && !adaPembayaranMenunggu;
    const bolehRefund = izin.Refund && tagihan.Status === 'Lunas' && tagihan.Total !== '0.00';

    if (!bolehCatat && !bolehHapusBuku && !bolehRefund) {
        return null;
    }

    return (
        <section aria-labelledby="judul-tindakan-tagihan" className="flex flex-col gap-3">
            <h2 id="judul-tindakan-tagihan" className="text-subjudul font-semibold text-teks-utama">
                Tindakan tagihan
            </h2>
            <div className="flex flex-wrap gap-2" role="group" aria-label="Tindakan pada tagihan">
                {bolehCatat ? (
                    <Tombol varian="sekunder" onClick={() => AturTindakan('catat')}>
                        Catat pembayaran masuk
                    </Tombol>
                ) : null}
                {bolehHapusBuku ? (
                    <Tombol varian="sekunder" onClick={() => AturTindakan('hapus-buku')}>
                        Hapus-buku tagihan
                    </Tombol>
                ) : null}
                {bolehRefund ? (
                    <Tombol varian="bahaya" onClick={() => AturTindakan('refund')}>
                        Ajukan refund
                    </Tombol>
                ) : null}
            </div>
            {tindakan === 'catat' ? <FormCatatPembayaran tagihan={tagihan} saatSelesai={Tutup} /> : null}
            {tindakan === 'hapus-buku' ? <FormHapusBuku tagihan={tagihan} saatSelesai={Tutup} /> : null}
            {tindakan === 'refund' ? (
                <FormAjukanRefund
                    tagihan={tagihan}
                    batasRefund={batasRefund}
                    bisaLangsung={izin.SetujuiRefund}
                    saatSelesai={Tutup}
                />
            ) : null}
        </section>
    );
}

type PropsFormTagihan = { tagihan: TagihanLangganan; saatSelesai: () => void };

function FormCatatPembayaran({ tagihan, saatSelesai }: PropsFormTagihan) {
    const formulir = useForm({
        JumlahDiterima: '',
        TanggalTransfer: '',
        NamaPengirim: '',
        BankPengirim: '',
        Catatan: '',
    });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tagihan/${tagihan.Uuid}/catat-pembayaran`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <KerangkaForm
            judul="Catat pembayaran masuk"
            keterangan={
                <p>
                    Gunakan bila uang tagihan {tagihan.Nomor} sudah masuk ke rekening tanpa pemilik usaha mengunggah
                    bukti. Tagihan langsung lunas dan langganan aktif. Jumlah harus sama dengan total{' '}
                    {FormatRupiah(tagihan.Total)}.
                </p>
            }
            labelKirim="Catat dan aktifkan langganan"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <BidangUang
                label="Jumlah masuk di mutasi rekening"
                nilai={formulir.data.JumlahDiterima}
                saatBerubah={(nilai) => formulir.setData('JumlahDiterima', nilai)}
                galat={formulir.errors.JumlahDiterima}
                required
            />
            <PemilihTanggal
                label="Tanggal transfer"
                nilai={formulir.data.TanggalTransfer}
                saatBerubah={(nilai) => formulir.setData('TanggalTransfer', nilai)}
                galat={formulir.errors.TanggalTransfer}
                required
            />
            <BidangTeks
                label="Nama pengirim"
                nilai={formulir.data.NamaPengirim}
                saatBerubah={(nilai) => formulir.setData('NamaPengirim', nilai)}
                galat={formulir.errors.NamaPengirim}
                required
            />
            <BidangTeks
                label="Bank pengirim (opsional)"
                nilai={formulir.data.BankPengirim}
                saatBerubah={(nilai) => formulir.setData('BankPengirim', nilai)}
                galat={formulir.errors.BankPengirim}
            />
            <BidangTeks
                label="Catatan (opsional)"
                nilai={formulir.data.Catatan}
                saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                galat={formulir.errors.Catatan}
            />
        </KerangkaForm>
    );
}

function FormHapusBuku({ tagihan, saatSelesai }: PropsFormTagihan) {
    const formulir = useForm({ Alasan: '' });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tagihan/${tagihan.Uuid}/hapus-buku`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <KerangkaForm
            judul="Hapus-buku tagihan"
            keterangan={
                <p>
                    Tagihan {tagihan.Nomor} dianggap tidak akan tertagih. Nomornya tetap tercatat, tidak lagi dihitung
                    sebagai piutang, dan tidak menghalangi tagihan baru. Tindakan ini tidak bisa dibatalkan.
                </p>
            }
            labelKirim="Hapus-buku tagihan"
            varianKirim="bahaya"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <BidangTeksPanjang
                label="Alasan"
                nilai={formulir.data.Alasan}
                baris={3}
                maksimal={500}
                saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                galat={formulir.errors.Alasan}
                required
            />
        </KerangkaForm>
    );
}

function FormAjukanRefund({
    tagihan,
    batasRefund,
    bisaLangsung,
    saatSelesai,
}: PropsFormTagihan & { batasRefund: string; bisaLangsung: boolean }) {
    const formulir = useForm({ Alasan: '', CatatanPengembalian: '' });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tagihan/${tagihan.Uuid}/refund`, { preserveScroll: true, onSuccess: saatSelesai });
    };
    const diAtasBatas = Number(tagihan.Total) > Number(batasRefund);

    return (
        <KerangkaForm
            judul="Ajukan refund"
            keterangan={
                <>
                    <p>
                        Refund penuh {FormatRupiah(tagihan.Total)} untuk tagihan {tagihan.Nomor}. Tagihan menjadi
                        Dikembalikan dan layanan dari tagihan ini disesuaikan. Komisi mitra yang masih tertunda
                        dibatalkan. Pengembalian uangnya Anda lakukan sendiri lewat transfer.
                    </p>
                    {diAtasBatas && !bisaLangsung ? (
                        <p>
                            Karena di atas {FormatRupiah(batasRefund)}, refund menunggu persetujuan Super Admin sebelum
                            dilaksanakan.
                        </p>
                    ) : null}
                </>
            }
            labelKirim={diAtasBatas && !bisaLangsung ? 'Ajukan untuk disetujui' : 'Setujui refund'}
            varianKirim="bahaya"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <BidangTeksPanjang
                label="Alasan refund"
                nilai={formulir.data.Alasan}
                baris={3}
                maksimal={500}
                saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                galat={formulir.errors.Alasan}
                required
            />
            <BidangTeks
                label="Catatan pengembalian dana (opsional)"
                nilai={formulir.data.CatatanPengembalian}
                saatBerubah={(nilai) => formulir.setData('CatatanPengembalian', nilai)}
                galat={formulir.errors.CatatanPengembalian}
                keterangan="Misal rekening tujuan atau nomor referensi transfer balik. Ikut dikirim ke pemilik usaha."
            />
        </KerangkaForm>
    );
}

/** Riwayat pengajuan refund satu tagihan, terbaru di atas. */
export function RiwayatRefund({ refund }: { refund: RefundLangganan[] }) {
    if (refund.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="judul-refund" className="flex flex-col gap-3">
            <h2 id="judul-refund" className="text-subjudul font-semibold text-teks-utama">
                Refund
            </h2>
            {refund.map((baris) => (
                <article key={baris.Uuid}>
                    <Card className="gap-2 rounded-panel px-4 py-4 shadow-none">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <LabelStatus jenis={JenisLabelRefund(baris.Status)} teks={baris.LabelStatus} />
                            <span className="tabular-nums text-isi">{FormatRupiah(baris.Jumlah)}</span>
                        </div>
                        <p className="text-isi">{baris.Alasan}</p>
                        <p className="text-keterangan text-teks-sekunder">
                            Diajukan {baris.Pengaju ?? '—'} | {FormatTanggalWaktu(baris.DiajukanPada)}
                            {baris.DiputuskanPada
                                ? ` | Diputuskan ${baris.Pemutus ?? '—'} | ${FormatTanggalWaktu(baris.DiputuskanPada)}`
                                : ''}
                        </p>
                        {baris.CatatanPengembalian ? (
                            <p className="text-keterangan text-teks-sekunder">
                                Pengembalian dana: {baris.CatatanPengembalian}
                            </p>
                        ) : null}
                        {baris.AlasanTolak ? (
                            <p className="text-keterangan text-bahaya">Ditolak: {baris.AlasanTolak}</p>
                        ) : null}
                        {baris.Status === 'Disetujui' ? (
                            <p className="text-keterangan text-teks-sekunder">
                                {baris.LayananDiakhiri
                                    ? 'Layanan dari tagihan ini sudah diakhiri.'
                                    : 'Layanan tenant tidak diubah karena sudah ada pembayaran yang lebih baru.'}
                            </p>
                        ) : null}
                    </Card>
                </article>
            ))}
        </section>
    );
}

export type BarisRefundMenunggu = RefundLangganan & {
    UuidTagihan: string | null;
    NomorTagihan: string | null;
    NamaTenant: string;
};

/** Antrean refund di atas batas yang menunggu keputusan Super Admin (BR-P08.2). */
export function AntreanRefund({ antrean, boleh }: { antrean: BarisRefundMenunggu[]; boleh: boolean }) {
    const [menolak, AturMenolak] = useState<string | null>(null);

    if (antrean.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="judul-antrean-refund" className="flex flex-col gap-3">
            <h2 id="judul-antrean-refund" className="text-subjudul font-semibold text-teks-utama">
                Refund menunggu persetujuan ({antrean.length})
            </h2>
            {antrean.map((baris) => (
                <article key={baris.Uuid}>
                    <Card className="gap-2 rounded-panel px-4 py-4 shadow-none">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-isi font-semibold text-teks-utama">
                                {baris.NamaTenant} | <span className="font-mono">{baris.NomorTagihan}</span>
                            </p>
                            <span className="tabular-nums text-isi">{FormatRupiah(baris.Jumlah)}</span>
                        </div>
                        <p className="text-isi">{baris.Alasan}</p>
                        <p className="text-keterangan text-teks-sekunder">
                            Diajukan {baris.Pengaju ?? '—'} | {FormatTanggalWaktu(baris.DiajukanPada)}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {baris.UuidTagihan ? (
                                <a
                                    href={`/tagihan/${baris.UuidTagihan}`}
                                    className="self-center text-label font-semibold underline"
                                >
                                    Buka tagihan
                                </a>
                            ) : null}
                            {boleh ? (
                                <>
                                    <Tombol
                                        onClick={() =>
                                            router.post(
                                                `/tagihan/refund/${baris.Uuid}/setujui`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Setujui refund
                                    </Tombol>
                                    <Tombol varian="bahaya" onClick={() => AturMenolak(baris.Uuid)}>
                                        Tolak
                                    </Tombol>
                                </>
                            ) : null}
                        </div>
                    </Card>
                    {menolak === baris.Uuid ? (
                        <FormTolakRefund uuid={baris.Uuid} saatSelesai={() => AturMenolak(null)} />
                    ) : null}
                </article>
            ))}
        </section>
    );
}

function FormTolakRefund({ uuid, saatSelesai }: { uuid: string; saatSelesai: () => void }) {
    const formulir = useForm({ Alasan: '' });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tagihan/refund/${uuid}/tolak`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <KerangkaForm
            judul="Tolak refund"
            keterangan={<p>Tagihan tetap Lunas dan layanan tidak berubah. Alasan tercatat di audit.</p>}
            labelKirim="Tolak refund"
            varianKirim="bahaya"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <BidangTeksPanjang
                label="Alasan penolakan"
                nilai={formulir.data.Alasan}
                baris={3}
                maksimal={500}
                saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                galat={formulir.errors.Alasan}
                required
            />
        </KerangkaForm>
    );
}
