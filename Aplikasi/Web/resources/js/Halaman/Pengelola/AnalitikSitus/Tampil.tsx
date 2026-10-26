import { Link } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import SaringLaporan from '@/Komponen/Laporan/SaringLaporan';
import { JalurGulirTab, kelasDaftarTabPanel, kelasItemTabPanel } from '@/Komponen/Navigasi/TabTautan';
import GrafikSeriGa, { type TitikGa } from '@/Komponen/Pengelola/AnalitikSitus/GrafikSeriGa';
import KartuMetrikGa from '@/Komponen/Pengelola/AnalitikSitus/KartuMetrikGa';
import PanelLaporanGa from '@/Komponen/Pengelola/AnalitikSitus/PanelLaporanGa';
import TabelLaporanGa from '@/Komponen/Pengelola/AnalitikSitus/TabelLaporanGa';
import TabAnalitik from '@/Komponen/Pengelola/TabAnalitik';
import { Card } from '@/Komponen/Ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Komponen/Ui/tabs';
import KeadaanKosong from '@/Komponen/Pengelola/KeadaanKosong';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import type { LaporanGa, PropsAnalitikSitus, TabelGa } from '@/Tipe/AnalitikGoogle';

/** Metrik yang bisa dipilih untuk grafik tren harian (kunci kolom tabel `harian` dari server). */
const metrikTren = [
    { kunci: 'PenggunaAktif', label: 'Pengguna aktif' },
    { kunci: 'Sesi', label: 'Sesi' },
    { kunci: 'TampilanHalaman', label: 'Tampilan halaman' },
    { kunci: 'EventKunci', label: 'Event kunci' },
] as const;

function BuatTitik(tabel: TabelGa | undefined, kunciEtiket: string, kunciNilai: string): TitikGa[] {
    return (tabel?.Baris ?? []).map((baris) => ({
        Etiket: baris[kunciEtiket] ?? '',
        Nilai: Number(baris[kunciNilai] ?? '0'),
    }));
}

function RingkasanGa({ data }: { data: LaporanGa }) {
    const [metrik, AturMetrik] = useState<(typeof metrikTren)[number]['kunci']>('PenggunaAktif');
    const harian = data.Tabel.find((t) => t.Id === 'harian');
    const label = metrikTren.find((m) => m.kunci === metrik)?.label ?? '';

    return (
        <>
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Periode {data.Rentang.Dari} sampai {data.Rentang.Sampai} ({String(data.Rentang.Hari)} hari), dibanding{' '}
                {data.Pembanding.Dari} sampai {data.Pembanding.Sampai}.
            </p>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                {data.Kartu.map((kartu) => (
                    <KartuMetrikGa key={kartu.Kunci} kartu={kartu} />
                ))}
            </div>
            <Card className="flex flex-col gap-3 rounded-panel p-4 shadow-none">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-subjudul font-semibold text-teks-utama">Tren harian</h2>
                    <div className="flex flex-wrap gap-2" role="group" aria-label="Metrik grafik">
                        {metrikTren.map((m) => (
                            <Tombol
                                key={m.kunci}
                                varian={m.kunci === metrik ? 'utama' : 'sekunder'}
                                aria-pressed={m.kunci === metrik}
                                onClick={() => AturMetrik(m.kunci)}
                            >
                                {m.label}
                            </Tombol>
                        ))}
                    </div>
                </div>
                <GrafikSeriGa
                    data={BuatTitik(harian, 'Tanggal', metrik)}
                    judul={`Tren harian ${label}`}
                    labelSeri={label}
                    jenis="garis"
                />
            </Card>
            {harian ? <TabelLaporanGa tabel={harian} awalan="ga-ringkasan" /> : null}
        </>
    );
}

function RealtimeGa({ data }: { data: LaporanGa }) {
    const perMenit = data.Tabel.find((t) => t.Id === 'per-menit');
    // Dari 29 menit lalu (kiri) sampai sekarang (kanan).
    const titik = BuatTitik(perMenit, 'MenitLalu', 'PenggunaAktif').sort((a, b) => Number(b.Etiket) - Number(a.Etiket));

    return (
        <>
            <div className="grid gap-3 sm:grid-cols-2">
                {data.Kartu.map((kartu) => (
                    <KartuMetrikGa key={kartu.Kunci} kartu={kartu} />
                ))}
            </div>
            <Card className="flex flex-col gap-3 rounded-panel p-4 shadow-none">
                <h2 className="text-subjudul font-semibold text-teks-utama">Pengguna aktif per menit</h2>
                <GrafikSeriGa data={titik} judul="Pengguna aktif per menit" labelSeri="Pengguna aktif" jenis="batang" />
            </Card>
            <div className="grid gap-4 lg:grid-cols-2">
                {data.Tabel.filter((t) => t.Id !== 'per-menit').map((tabel) => (
                    <section key={tabel.Id} className="flex min-w-0 flex-col gap-2">
                        <h2 className="text-subjudul font-semibold text-teks-utama">{tabel.Judul}</h2>
                        <TabelLaporanGa tabel={tabel} awalan="ga-realtime" kosong="Belum ada pengunjung aktif." />
                    </section>
                ))}
            </div>
        </>
    );
}

function DaftarTabelGa({ data, awalan, cari }: { data: LaporanGa; awalan: string; cari: boolean }) {
    return (
        <>
            {data.Tabel.map((tabel) => (
                <section key={tabel.Id} className="flex min-w-0 flex-col gap-2">
                    <h2 className="text-subjudul font-semibold text-teks-utama">{tabel.Judul}</h2>
                    <TabelLaporanGa tabel={tabel} awalan={awalan} cari={cari} />
                </section>
            ))}
        </>
    );
}

const tabLaporan = [
    { nilai: 'ringkasan', label: 'Ringkasan' },
    { nilai: 'realtime', label: 'Real-time' },
    { nilai: 'akuisisi', label: 'Akuisisi' },
    { nilai: 'halaman', label: 'Halaman' },
    { nilai: 'event', label: 'Event' },
    { nilai: 'pengguna', label: 'Pengguna' },
    { nilai: 'teknologi', label: 'Teknologi' },
] as const;

const keteranganTab: Record<string, string> = {
    akuisisi: 'Dari mana pengunjung datang: saluran, sumber / medium, dan kampanye.',
    halaman: 'Halaman yang paling banyak dilihat dan halaman tempat pengunjung pertama kali masuk.',
    event: 'Semua event yang tercatat. Event kunci = event yang ditandai sebagai konversi di Google Analytics.',
    pengguna: 'Siapa pengunjungnya: negara, kota, bahasa, serta pengunjung baru dan kembali.',
    teknologi: 'Perangkat, sistem operasi, peramban, dan resolusi layar yang dipakai pengunjung.',
};

/**
 * D-215: analitik situs payoung.id dari Google Analytics 4, langsung di konsol: ringkasan dengan pembanding periode,
 * real-time, akuisisi, halaman, event, pengguna, dan teknologi. Data dibaca lewat Data API dengan akun layanan dari
 * Integrasi › Google Analytics; tiap tab mengambil laporannya sendiri saat dibuka.
 */
export default function HalamanAnalitikSitus({ Status, Rentang, MaksHari }: PropsAnalitikSitus) {
    return (
        <TataLetakPengelola judul="Analitik situs">
            <TabAnalitik />

            {!Status.Aktif ? (
                <KeadaanKosong judul="Google Analytics belum dihubungkan.">
                    Isi ID properti GA4 dan kunci akun layanan di{' '}
                    <Link href="/integrasi" className="font-semibold text-brand underline">
                        Integrasi › Google Analytics
                    </Link>
                    , lalu tekan Uji koneksi dan aktifkan. Langkah lengkap ada di Panduan/AnalitikGoogle.md.
                </KeadaanKosong>
            ) : (
                <>
                    <p className="max-w-3xl text-isi text-teks-sekunder">
                        Data dari properti Google Analytics {Status.IdProperti}. Google menyelesaikan penghitungan
                        sampai sekitar 24 jam, jadi angka hari ini bisa masih bertambah. Real-time memperlihatkan 30
                        menit terakhir dan tidak terpengaruh periode.
                    </p>

                    <SaringLaporan
                        alamat="/analitik/situs"
                        query={{ dari: Rentang.Dari, sampai: Rentang.Sampai }}
                        maksHari={MaksHari}
                        pilihan={[]}
                    />

                    <Tabs defaultValue="ringkasan" className="gap-4">
                        <div className="overflow-x-auto">
                            <JalurGulirTab>
                                <TabsList aria-label="Laporan Google Analytics" className={kelasDaftarTabPanel}>
                                    {tabLaporan.map((tab) => (
                                        <TabsTrigger key={tab.nilai} value={tab.nilai} className={kelasItemTabPanel}>
                                            {tab.label}
                                        </TabsTrigger>
                                    ))}
                                </TabsList>
                            </JalurGulirTab>
                        </div>

                        {tabLaporan.map((tab) => (
                            <TabsContent key={tab.nilai} value={tab.nilai} className="flex flex-col gap-4">
                                {keteranganTab[tab.nilai] ? (
                                    <p className="max-w-3xl text-isi text-teks-sekunder">{keteranganTab[tab.nilai]}</p>
                                ) : null}
                                <PanelLaporanGa
                                    laporan={tab.nilai}
                                    dari={Rentang.Dari}
                                    sampai={Rentang.Sampai}
                                    {...(tab.nilai === 'realtime' ? { segarkanOtomatisDetik: 30 } : {})}
                                >
                                    {(data) =>
                                        tab.nilai === 'ringkasan' ? (
                                            <RingkasanGa data={data} />
                                        ) : tab.nilai === 'realtime' ? (
                                            <RealtimeGa data={data} />
                                        ) : (
                                            <DaftarTabelGa
                                                data={data}
                                                awalan={`ga-${tab.nilai}`}
                                                cari={tab.nilai === 'halaman' || tab.nilai === 'event'}
                                            />
                                        )
                                    }
                                </PanelLaporanGa>
                            </TabsContent>
                        ))}
                    </Tabs>
                </>
            )}
        </TataLetakPengelola>
    );
}
