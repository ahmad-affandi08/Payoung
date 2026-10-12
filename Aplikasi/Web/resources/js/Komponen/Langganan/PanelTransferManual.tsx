import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangBerkas from '@/Komponen/Formulir/BidangBerkas';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Card } from '@/Komponen/Ui/card';
import { FormatRupiah } from '@/Pustaka/Format';

export type RekeningTujuan = { Kode: string; NamaBank: string; NomorRekening: string; AtasNama: string };

type PropsPanelTransfer = {
    uuidTagihan: string;
    total: string;
    rekening: RekeningTujuan[];
    /** false = bukti sedang diverifikasi atau tagihan tak lagi terbuka: hanya tampilkan rekening tujuan. */
    bolehUnggah: boolean;
};

/**
 * D-112: pembayaran tagihan manual (paket harga negosiasi) dengan transfer. Menampilkan rekening tujuan platform dan
 * form unggah bukti; Keuangan memverifikasinya di antrean P-08. Jumlah transfer harus sama dengan total tagihan.
 */
export default function PanelTransferManual({ uuidTagihan, total, rekening, bolehUnggah }: PropsPanelTransfer) {
    const [membuka, AturMembuka] = useState(false);

    return (
        <section aria-labelledby="judul-transfer" className="flex flex-col gap-3">
            <h2 id="judul-transfer" className="text-subjudul font-semibold text-teks-utama">
                Bayar dengan transfer
            </h2>
            <Card className="gap-3 rounded-panel px-4 py-4 shadow-none">
                <p className="text-isi text-teks-sekunder">
                    Transfer tepat <strong className="tabular-nums text-teks-utama">{FormatRupiah(total)}</strong> ke
                    salah satu rekening berikut, lalu unggah bukti transfernya.
                </p>
                <ul className="flex flex-col gap-2">
                    {rekening.map((baris) => (
                        <li key={baris.Kode} className="text-isi">
                            {baris.NamaBank} <span className="font-mono">{baris.NomorRekening}</span> a.n.{' '}
                            {baris.AtasNama}
                        </li>
                    ))}
                </ul>
                {bolehUnggah ? (
                    membuka ? (
                        <FormUnggahBukti
                            uuidTagihan={uuidTagihan}
                            total={total}
                            rekening={rekening}
                            saatBatal={() => AturMembuka(false)}
                        />
                    ) : (
                        <div>
                            <Tombol onClick={() => AturMembuka(true)}>Unggah bukti transfer</Tombol>
                        </div>
                    )
                ) : (
                    <p className="text-keterangan text-teks-sekunder">
                        Bukti transfer sedang diperiksa tim keuangan. Anda diberi tahu lewat email setelah selesai.
                    </p>
                )}
            </Card>
        </section>
    );
}

function FormUnggahBukti({
    uuidTagihan,
    total,
    rekening,
    saatBatal,
}: {
    uuidTagihan: string;
    total: string;
    rekening: RekeningTujuan[];
    saatBatal: () => void;
}) {
    const formulir = useForm<{
        Bukti: File | null;
        Jumlah: string;
        TanggalTransfer: string;
        BankPengirim: string;
        NamaPengirim: string;
        KodeRekeningTujuan: string;
    }>({
        Bukti: null,
        Jumlah: total.replace(/\.00$/, ''),
        TanggalTransfer: '',
        BankPengirim: '',
        NamaPengirim: '',
        KodeRekeningTujuan: rekening[0]?.Kode ?? '',
    });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/kelola/langganan/tagihan/${uuidTagihan}/bukti`, { preserveScroll: true, forceFormData: true });
    };

    return (
        <form onSubmit={Kirim} noValidate className="flex flex-col gap-4 border-t border-garis pt-4">
            <BidangBerkas
                label="Bukti transfer"
                berkas={formulir.data.Bukti ? [formulir.data.Bukti] : []}
                saatBerubah={(berkas) => formulir.setData('Bukti', berkas[0] ?? null)}
                ekstensi={['jpg', 'jpeg', 'png', 'webp', 'pdf']}
                maksimal={1}
                ukuranMaksimalKb={5120}
                galat={formulir.errors.Bukti}
                wajib
            />
            <BidangTeks
                label="Jumlah transfer (Rp)"
                inputMode="decimal"
                nilai={formulir.data.Jumlah}
                saatBerubah={(nilai) => formulir.setData('Jumlah', nilai)}
                galat={formulir.errors.Jumlah}
                keterangan={`Harus sama dengan total tagihan ${FormatRupiah(total)}.`}
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
                label="Bank pengirim"
                nilai={formulir.data.BankPengirim}
                saatBerubah={(nilai) => formulir.setData('BankPengirim', nilai)}
                galat={formulir.errors.BankPengirim}
                required
            />
            <BidangTeks
                label="Nama pemilik rekening pengirim"
                nilai={formulir.data.NamaPengirim}
                saatBerubah={(nilai) => formulir.setData('NamaPengirim', nilai)}
                galat={formulir.errors.NamaPengirim}
                required
            />
            {rekening.length > 1 ? (
                <BidangPilihan
                    label="Rekening tujuan"
                    nilai={formulir.data.KodeRekeningTujuan}
                    opsi={rekening.map((baris) => ({
                        Nilai: baris.Kode,
                        Label: `${baris.NamaBank} ${baris.NomorRekening}`,
                    }))}
                    saatBerubah={(nilai) => formulir.setData('KodeRekeningTujuan', nilai)}
                    galat={formulir.errors.KodeRekeningTujuan}
                    required
                />
            ) : null}
            <div className="flex flex-wrap gap-2">
                <Tombol type="submit" memproses={formulir.processing}>
                    Kirim bukti transfer
                </Tombol>
                <Tombol varian="sekunder" onClick={saatBatal}>
                    Batal
                </Tombol>
            </div>
        </form>
    );
}
