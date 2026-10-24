import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import { Card, CardContent } from '@/Komponen/Ui/card';
import { Separator } from '@/Komponen/Ui/separator';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, PunyaIzin, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

type Tugas = {
    Uuid: string;
    Koneksi: string;
    Antrean: string;
    NamaTugas: string;
    Payload: Record<string, unknown>;
    Galat: string;
    GagalPada: string;
};

/** Detail job gagal (P-11). Payload hanya metadata; isi job & rahasia disaring di server. */
export default function TugasGagal({ Tugas }: { Tugas: Tugas }) {
    const { props } = usePage<PropsBersamaPengelola>();
    const bolehKelola = PunyaIzin(props.Pengguna, IzinPengelola.OperasionalKelola);
    const [alasan, AturAlasan] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const opsi = { onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) };
    const alamat = `/operasional/tugas-gagal/${Tugas.Uuid}`;

    return (
        <TataLetakPengelola judul="Detail job gagal" jejak={[{ label: 'Dasbor operasional', href: '/operasional' }]}>
            {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}
            <Card className="py-4 rounded-panel shadow-none">
                <CardContent className="flex flex-col gap-3 px-4">
                    <dl className="grid gap-2 text-label sm:grid-cols-2">
                        <div>
                            <dt className="text-teks-sekunder">Job</dt>
                            <dd className="break-all font-mono text-teks-utama">{Tugas.NamaTugas}</dd>
                        </div>
                        <div>
                            <dt className="text-teks-sekunder">Gagal pada</dt>
                            <dd className="text-teks-utama">{FormatTanggalWaktu(Tugas.GagalPada)}</dd>
                        </div>
                        <div>
                            <dt className="text-teks-sekunder">Koneksi | antrean</dt>
                            <dd className="font-mono text-teks-utama">
                                {Tugas.Koneksi} | {Tugas.Antrean}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-teks-sekunder">UUID</dt>
                            <dd className="break-all font-mono text-teks-utama">{Tugas.Uuid}</dd>
                        </div>
                    </dl>
                    <h2 className="text-subjudul font-semibold text-teks-utama">Metadata payload</h2>
                    <pre className="overflow-x-auto rounded-kontrol border border-garis bg-latar p-3 font-mono text-keterangan text-teks-utama">
                        {JSON.stringify(Tugas.Payload, null, 2)}
                    </pre>
                    <h2 className="text-subjudul font-semibold text-teks-utama">Galat (difilter, 20 baris pertama)</h2>
                    <pre className="overflow-x-auto whitespace-pre-wrap break-all rounded-kontrol border border-garis bg-latar p-3 font-mono text-keterangan text-teks-utama">
                        {Tugas.Galat}
                    </pre>
                </CardContent>
            </Card>

            {bolehKelola ? (
                <Card className="py-4 rounded-panel shadow-none">
                    <CardContent className="flex flex-col gap-3 px-4">
                        <div>
                            <Tombol memproses={memproses} onClick={() => router.post(`${alamat}/coba-ulang`, {}, opsi)}>
                                Coba ulang job
                            </Tombol>
                        </div>
                        <Separator />
                        <div className="flex flex-col gap-2 sm:max-w-md">
                            <BidangTeks
                                label="Alasan membuang (wajib)"
                                nilai={alasan}
                                maxLength={500}
                                saatBerubah={AturAlasan}
                                galat={props.errors.Alasan}
                                required
                            />
                            <div>
                                <Tombol
                                    varian="bahaya"
                                    memproses={memproses}
                                    onClick={() => router.delete(alamat, { data: { Alasan: alasan }, ...opsi })}
                                >
                                    Buang job
                                </Tombol>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            ) : null}
        </TataLetakPengelola>
    );
}
