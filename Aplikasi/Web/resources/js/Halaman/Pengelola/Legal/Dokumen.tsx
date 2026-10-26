import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import TeksKaya from '@/Komponen/Situs/TeksKaya';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { Card } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, PunyaIzin, type PropsBersamaPengelola } from '@/Tipe/Pengelola';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';

type DokumenLegal = {
    Uuid: string;
    Jenis: string;
    Label: string;
    Versi: number;
    Judul: string;
    Isi: string;
    RingkasanPerubahan: string | null;
    Materiil: boolean;
    BerlakuMulai: string;
    Status: 'Draf' | 'Terbit';
    DiterbitkanPada: string | null;
};

/** Satu versi dokumen legal: sunting draf atau baca versi terbit (P-06, BR-P06.1). */
export default function HalamanDokumenLegal({ Dokumen }: { Dokumen: DokumenLegal }) {
    const { props } = usePage<PropsBersamaPengelola>();
    const bolehUbah = Dokumen.Status === 'Draf' && PunyaIzin(props.Pengguna, IzinPengelola.LegalKelola);
    const url = `/legal/${Dokumen.Uuid}`;

    const [memproses, AturMemproses] = useState(false);
    const [konfirmasiHapus, AturKonfirmasiHapus] = useState(false);
    const opsiKirim = {
        preserveScroll: true,
        onStart: () => AturMemproses(true),
        onFinish: () => AturMemproses(false),
    };
    const Hapus = () => {
        router.delete(url, { ...opsiKirim, onSuccess: () => AturKonfirmasiHapus(false) });
    };

    return (
        <TataLetakPengelola
            judul={`${Dokumen.Label} | versi ${Dokumen.Versi}`}
            aksi={
                bolehUbah ? (
                    <Tombol varian="bahaya" memproses={memproses} onClick={() => AturKonfirmasiHapus(true)}>
                        Hapus draf
                    </Tombol>
                ) : null
            }
        >
            {konfirmasiHapus ? (
                <DialogKonfirmasi
                    judul={`Hapus draf ${Dokumen.Label} versi ${Dokumen.Versi}?`}
                    labelAksi="Hapus draf"
                    memproses={memproses}
                    saatKonfirmasi={Hapus}
                    saatBatal={() => AturKonfirmasiHapus(false)}
                >
                    <p>Hanya draf ini yang dihapus. Versi yang sudah terbit tidak terpengaruh.</p>
                </DialogKonfirmasi>
            ) : null}
            <Link href="/legal" className="text-label font-semibold text-brand underline">
                Semua dokumen legal
            </Link>
            {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}
            <div className="flex flex-wrap items-center gap-2 text-keterangan text-teks-sekunder">
                <LabelStatus jenis={Dokumen.Status === 'Draf' ? 'peringatan' : 'sukses'} teks={Dokumen.Status} />
                <span>Berlaku mulai {FormatTanggal(Dokumen.BerlakuMulai)}</span>
                {Dokumen.DiterbitkanPada ? <span>Terbit {FormatTanggalWaktu(Dokumen.DiterbitkanPada)}</span> : null}
            </div>
            {bolehUbah ? (
                <FormDraf dokumen={Dokumen} url={url} galatHalaman={props.errors} />
            ) : (
                <article>
                    <Card className="gap-3 px-6 py-6 rounded-panel shadow-none">
                        <h2 className="text-subjudul font-semibold text-teks-utama">{Dokumen.Judul}</h2>
                        {Dokumen.RingkasanPerubahan ? (
                            <p className="text-keterangan text-teks-sekunder">
                                Perubahan: {Dokumen.RingkasanPerubahan}
                            </p>
                        ) : null}
                        <TeksKaya teks={Dokumen.Isi} className="text-isi text-teks-utama" />
                    </Card>
                </article>
            )}
        </TataLetakPengelola>
    );
}

type PropsFormDraf = { dokumen: DokumenLegal; url: string; galatHalaman: Record<string, string> };

function FormDraf({ dokumen, url, galatHalaman }: PropsFormDraf) {
    const [menerbitkan, AturMenerbitkan] = useState(false);
    const formulir = useForm({
        Jenis: dokumen.Jenis,
        Judul: dokumen.Judul,
        Isi: dokumen.Isi,
        RingkasanPerubahan: dokumen.RingkasanPerubahan ?? '',
        Materiil: dokumen.Materiil,
        BerlakuMulai: dokumen.BerlakuMulai,
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.put(url, { preserveScroll: true });
    };
    // Terbitkan memakai versi tersimpan; perubahan yang belum disimpan harus disimpan dulu (BR-P06.1).
    const [konfirmasiTerbit, AturKonfirmasiTerbit] = useState(false);
    const Terbitkan = () => {
        router.post(
            `${url}/terbitkan`,
            {},
            {
                preserveScroll: true,
                onStart: () => AturMenerbitkan(true),
                onFinish: () => {
                    AturMenerbitkan(false);
                    AturKonfirmasiTerbit(false);
                },
            },
        );
    };

    return (
        <Card className="py-6 rounded-panel shadow-none">
            {konfirmasiTerbit ? (
                <DialogKonfirmasi
                    judul={`Terbitkan ${dokumen.Label} versi ${dokumen.Versi}?`}
                    labelAksi="Terbitkan"
                    varian="utama"
                    memproses={menerbitkan}
                    saatKonfirmasi={Terbitkan}
                    saatBatal={() => AturKonfirmasiTerbit(false)}
                >
                    <p>Versi terbit tidak bisa diubah lagi.</p>
                </DialogKonfirmasi>
            ) : null}
            <form onSubmit={Kirim} className="grid gap-4 px-6 sm:grid-cols-2" noValidate>
                <BidangTeks
                    label="Judul"
                    nilai={formulir.data.Judul}
                    saatBerubah={(nilai) => formulir.setData('Judul', nilai)}
                    galat={formulir.errors.Judul}
                    required
                />
                <PemilihTanggal
                    label="Berlaku mulai"
                    keterangan="Perubahan materiil paling cepat 30 hari setelah terbit."
                    nilai={formulir.data.BerlakuMulai}
                    saatBerubah={(nilai) => formulir.setData('BerlakuMulai', nilai)}
                    galat={formulir.errors.BerlakuMulai ?? galatHalaman.BerlakuMulai}
                    required
                />
                <div className="sm:col-span-2">
                    <KotakCentang
                        label="Perubahan materiil (mengubah hak atau kewajiban tenant)"
                        nilai={formulir.data.Materiil}
                        saatBerubah={(nilai) => formulir.setData('Materiil', nilai)}
                    />
                </div>
                <div className="sm:col-span-2">
                    <BidangTeks
                        label="Ringkasan perubahan (opsional)"
                        keterangan="Ditampilkan ke tenant saat versi ini diumumkan."
                        nilai={formulir.data.RingkasanPerubahan}
                        saatBerubah={(nilai) => formulir.setData('RingkasanPerubahan', nilai)}
                        galat={formulir.errors.RingkasanPerubahan}
                    />
                </div>
                <div className="sm:col-span-2">
                    <BidangTeksPanjang
                        label="Isi dokumen"
                        keterangan="# judul, ## subjudul, ### sub-subjudul, - butir, 1. bernomor, **tebal**, [teks](tautan). HTML tidak dirender."
                        nilai={formulir.data.Isi}
                        saatBerubah={(nilai) => formulir.setData('Isi', nilai)}
                        galat={formulir.errors.Isi}
                        baris={20}
                        kode
                        required
                    />
                </div>
                <div className="flex flex-wrap items-center gap-2 sm:col-span-2">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan draf
                    </Tombol>
                    <Tombol
                        varian="sekunder"
                        memproses={menerbitkan}
                        disabled={formulir.isDirty}
                        onClick={() => AturKonfirmasiTerbit(true)}
                    >
                        Terbitkan
                    </Tombol>
                    {formulir.isDirty ? (
                        <span className="text-keterangan text-teks-sekunder">
                            Simpan perubahan dulu sebelum menerbitkan.
                        </span>
                    ) : null}
                </div>
            </form>
        </Card>
    );
}
