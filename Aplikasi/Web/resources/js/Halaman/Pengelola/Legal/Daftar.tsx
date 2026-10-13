import { Link, router, usePage } from '@inertiajs/react';

import Tombol from '@/Komponen/Formulir/Tombol';
import TabLegal from '@/Komponen/Pengelola/Legal/TabLegal';
import { Card } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, PunyaIzin, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

type StatusTampilan = 'Draf' | 'Terjadwal' | 'Berlaku' | 'Digantikan';

type VersiDokumen = {
    Uuid: string;
    Versi: number;
    Judul: string;
    RingkasanPerubahan: string | null;
    Materiil: boolean;
    BerlakuMulai: string;
    StatusTampilan: StatusTampilan;
};

type KelompokDokumen = { Jenis: string; Label: string; WajibRegistrasi: boolean; Versi: VersiDokumen[] };

const jenisStatus = { Draf: 'peringatan', Terjadwal: 'netral', Berlaku: 'sukses', Digantikan: 'netral' } as const;

/** Dokumen legal berversi (P-06). */
export default function HalamanDaftarLegal({ Dokumen }: { Dokumen: KelompokDokumen[] }) {
    const { props } = usePage<PropsBersamaPengelola>();
    const bolehKelola = PunyaIzin(props.Pengguna, IzinPengelola.LegalKelola);
    const kurangRegistrasi = Dokumen.filter(
        (kelompok) => kelompok.WajibRegistrasi && !kelompok.Versi.some((versi) => versi.StatusTampilan === 'Berlaku'),
    );

    return (
        <TataLetakPengelola judul="Dokumen legal">
            <TabLegal />
            {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}
            {props.errors.Jenis ? <Pemberitahuan jenis="bahaya">{props.errors.Jenis}</Pemberitahuan> : null}
            {kurangRegistrasi.length > 0 ? (
                <Pemberitahuan jenis="peringatan" judul="Registrasi tenant belum bisa dibuka">
                    Terbitkan {kurangRegistrasi.map((kelompok) => kelompok.Label).join(' dan ')} yang sudah berlaku
                    lebih dulu.
                </Pemberitahuan>
            ) : null}
            {Dokumen.map((kelompok) => {
                const adaDraf = kelompok.Versi.some((versi) => versi.StatusTampilan === 'Draf');

                return (
                    <Card key={kelompok.Jenis} className="gap-2 px-5 py-5 rounded-panel shadow-none">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-subjudul font-semibold text-teks-utama">{kelompok.Label}</h2>
                            {bolehKelola && !adaDraf ? (
                                <Tombol
                                    varian="sekunder"
                                    onClick={() =>
                                        router.post('/legal', { Jenis: kelompok.Jenis }, { preserveScroll: true })
                                    }
                                >
                                    {kelompok.Versi.length === 0 ? 'Buat draf pertama' : 'Buat draf versi baru'}
                                </Tombol>
                            ) : null}
                        </div>
                        {kelompok.Versi.length === 0 ? (
                            <p className="text-keterangan text-teks-sekunder">Belum ada versi.</p>
                        ) : (
                            <ul className="flex flex-col divide-y divide-garis">
                                {kelompok.Versi.map((versi) => (
                                    <li
                                        key={versi.Uuid}
                                        className="flex flex-wrap items-center justify-between gap-2 py-2"
                                    >
                                        <div className="flex flex-col">
                                            <Link
                                                href={`/legal/${versi.Uuid}`}
                                                className="font-semibold text-teks-utama underline outline-none focus-visible:ring-2 focus-visible:ring-brand"
                                            >
                                                Versi {versi.Versi} | {versi.Judul}
                                            </Link>
                                            <span className="text-keterangan text-teks-sekunder">
                                                Berlaku mulai {FormatTanggal(versi.BerlakuMulai)}
                                                {versi.Materiil ? ' | perubahan materiil' : ''}
                                                {versi.RingkasanPerubahan ? ` | ${versi.RingkasanPerubahan}` : ''}
                                            </span>
                                        </div>
                                        <LabelStatus
                                            jenis={jenisStatus[versi.StatusTampilan]}
                                            teks={versi.StatusTampilan}
                                        />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                );
            })}
        </TataLetakPengelola>
    );
}
