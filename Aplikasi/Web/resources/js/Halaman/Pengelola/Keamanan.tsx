import { Link, router } from '@inertiajs/react';
import { LaptopIcon } from 'lucide-react';

import { Button } from '@/Komponen/Ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';

type Perangkat = {
    Uuid: string;
    Keterangan: string;
    AlamatIp: string | null;
    DibuatPada: string | null;
    TerakhirDipakaiPada: string | null;
    BerlakuSampai: string;
    PerangkatIni: boolean;
};

type PropsKeamanan = { Perangkat: Perangkat[]; HariPerangkatTepercaya: number; MenitKonfirmasi: number };

/** Keamanan akun konsol (D-42): kata sandi dan perangkat tepercaya yang melewati kode 2FA saat login. */
export default function Keamanan({ Perangkat, HariPerangkatTepercaya, MenitKonfirmasi }: PropsKeamanan) {
    const Cabut = (perangkat: Perangkat) =>
        router.delete(`/keamanan/perangkat/${perangkat.Uuid}`, { preserveScroll: true });
    const CabutSemua = () => router.post('/keamanan/perangkat/cabut-semua', {}, { preserveScroll: true });

    return (
        <TataLetakPengelola judul="Keamanan akun">
            <Card className="gap-3 rounded-panel py-6 shadow-none sm:flex-row sm:items-center sm:justify-between">
                <CardHeader className="gap-1 px-6">
                    <CardTitle className="text-subjudul font-bold text-teks-utama">
                        <h2>Kata sandi</h2>
                    </CardTitle>
                    <CardDescription className="text-isi text-teks-sekunder">
                        Mengganti kata sandi juga mencabut semua perangkat tepercaya.
                    </CardDescription>
                </CardHeader>
                <CardContent className="px-6">
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11 text-label font-semibold">
                        <Link href="/ganti-kata-sandi">Ganti kata sandi</Link>
                    </Button>
                </CardContent>
            </Card>

            <Card className="gap-4 rounded-panel py-6 shadow-none">
                <CardHeader className="gap-1 px-6">
                    <CardTitle className="text-subjudul font-bold text-teks-utama">
                        <h2>Perangkat tepercaya</h2>
                    </CardTitle>
                    <CardDescription className="text-isi text-teks-sekunder">
                        Browser di sini cukup kata sandi saat login, selama {HariPerangkatTepercaya} hari sejak
                        dipercaya. Kode 2FA tetap diminta untuk aksi penting (tim, integrasi, tangguhkan tenant) bila
                        kode terakhir lebih dari {MenitKonfirmasi} menit lalu.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-4 px-6">
                    {Perangkat.length === 0 ? (
                        <div className="flex items-start gap-3 rounded-kontrol border border-dashed border-garis p-4">
                            <LaptopIcon aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-teks-sekunder" />
                            <p className="text-isi text-teks-sekunder">
                                Belum ada perangkat tepercaya. Centang &quot;Percayai perangkat ini&quot; saat
                                memasukkan kode 2FA berikutnya.
                            </p>
                        </div>
                    ) : (
                        <ul className="flex flex-col divide-y divide-garis rounded-kontrol border border-garis">
                            {Perangkat.map((perangkat) => (
                                <li
                                    key={perangkat.Uuid}
                                    className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div className="flex min-w-0 items-start gap-3">
                                        <LaptopIcon
                                            aria-hidden="true"
                                            className="mt-0.5 size-5 shrink-0 text-teks-sekunder"
                                        />
                                        <div className="flex min-w-0 flex-col gap-0.5">
                                            <span className="flex flex-wrap items-center gap-2 text-label font-semibold text-teks-utama">
                                                {perangkat.Keterangan}
                                                {perangkat.PerangkatIni ? (
                                                    <LabelStatus jenis="sukses" teks="Perangkat ini" />
                                                ) : null}
                                            </span>
                                            <span className="text-keterangan text-teks-sekunder">
                                                Terakhir dipakai {FormatTanggalWaktu(perangkat.TerakhirDipakaiPada)}
                                                {perangkat.AlamatIp ? ` | IP ${perangkat.AlamatIp}` : ''}
                                            </span>
                                            <span className="text-keterangan text-teks-sekunder">
                                                Berlaku sampai {FormatTanggalWaktu(perangkat.BerlakuSampai)}
                                            </span>
                                        </div>
                                    </div>
                                    <Button
                                        variant="outline"
                                        className="h-8 pointer-coarse:h-11 shrink-0 text-label font-semibold"
                                        onClick={() => Cabut(perangkat)}
                                    >
                                        Cabut
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {Perangkat.length > 1 ? (
                        <div>
                            <Button
                                variant="outline"
                                className="h-8 pointer-coarse:h-11 text-label font-semibold text-bahaya"
                                onClick={CabutSemua}
                            >
                                Cabut semua perangkat
                            </Button>
                        </div>
                    ) : null}
                </CardContent>
            </Card>
        </TataLetakPengelola>
    );
}
