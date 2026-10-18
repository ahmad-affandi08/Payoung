import { router, usePage } from '@inertiajs/react';
import { MapPinIcon } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';

export type LokasiAbsensiOutletData = {
    Lintang: string | null;
    Bujur: string | null;
    RadiusMeter: number;
};

type Props = { alamatOutlet: string; data: LokasiAbsensiOutletData; bolehKelola: boolean };

/**
 * F-18 bagian 4 (D-37): titik lokasi & radius absensi dari HP. Karyawan hanya bisa absen web bila berada dalam radius
 * titik ini. Titik bisa diambil dari lokasi perangkat pengelola saat berdiri di outlet ("Pakai lokasi saya sekarang")
 * atau disalin dari Google Maps. Lokasi karyawan dicatat sebagai bukti; yang jauh dari titik ini ditandai "perlu dicek"
 * (atau ditolak bila aturan kehadiran menyalakan tolak-di-luar-radius, D-180).
 */
export default function LokasiAbsensiOutlet({ alamatOutlet, data, bolehKelola }: Props) {
    const { props } = usePage<{ errors: Record<string, string> }>();
    const [lintang, AturLintang] = useState(data.Lintang ?? '');
    const [bujur, AturBujur] = useState(data.Bujur ?? '');
    const [radius, AturRadius] = useState(String(data.RadiusMeter));
    const [memproses, AturMemproses] = useState(false);
    const [mencari, AturMencari] = useState(false);
    const [galatLokasi, AturGalatLokasi] = useState<string | null>(null);
    const ada = data.Lintang !== null && data.Bujur !== null;

    const Kirim = (isi: { Lintang: string | null; Bujur: string | null }) => {
        AturMemproses(true);
        router.post(
            `${alamatOutlet}/lokasi-absensi`,
            { ...isi, RadiusAbsensiMeter: Number(radius) },
            { preserveScroll: true, onFinish: () => AturMemproses(false) },
        );
    };

    const Simpan = (e: FormEvent) => {
        e.preventDefault();
        Kirim({ Lintang: lintang.trim() || null, Bujur: bujur.trim() || null });
    };

    const PakaiLokasiSaya = () => {
        AturGalatLokasi(null);

        if (!('geolocation' in navigator)) {
            AturGalatLokasi('Peramban ini tidak mendukung lokasi. Salin titik dari Google Maps.');

            return;
        }

        AturMencari(true);
        navigator.geolocation.getCurrentPosition(
            (posisi) => {
                AturMencari(false);
                AturLintang(posisi.coords.latitude.toFixed(7));
                AturBujur(posisi.coords.longitude.toFixed(7));
            },
            () => {
                AturMencari(false);
                AturGalatLokasi(
                    'Lokasi tidak didapat. Izinkan lokasi untuk situs ini, atau salin titik dari Google Maps.',
                );
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
        );
    };

    return (
        <Panel
            judul="Lokasi absensi dari HP"
            keterangan="Karyawan hanya bisa absen dari HP pribadi bila berada dalam radius titik ini."
        >
            {ada ? (
                <p className="flex flex-wrap items-center gap-2 text-isi text-teks-utama">
                    <MapPinIcon aria-hidden="true" className="size-4 shrink-0 text-teks-sekunder" />
                    <span className="font-mono tabular-nums">
                        {data.Lintang}, {data.Bujur}
                    </span>
                    <span>| radius {data.RadiusMeter} m |</span>
                    <a
                        className="text-brand underline underline-offset-2"
                        href={`https://www.google.com/maps?q=${data.Lintang ?? ''},${data.Bujur ?? ''}`}
                        target="_blank"
                        rel="noreferrer"
                    >
                        Lihat di peta
                    </a>
                </p>
            ) : (
                <p className="text-isi text-teks-sekunder">
                    Belum ada titik lokasi. Karyawan belum bisa absen dari HP di outlet ini.
                </p>
            )}
            {bolehKelola ? (
                <form noValidate className="grid gap-3 sm:grid-cols-3" onSubmit={Simpan}>
                    <BidangTeks
                        label="Lintang"
                        nilai={lintang}
                        saatBerubah={AturLintang}
                        keterangan="Misal -7.5560000"
                        inputMode="decimal"
                        galat={props.errors.Lintang}
                    />
                    <BidangTeks
                        label="Bujur"
                        nilai={bujur}
                        saatBerubah={AturBujur}
                        keterangan="Misal 110.8310000"
                        inputMode="decimal"
                        galat={props.errors.Bujur}
                    />
                    <BidangTeks
                        label="Radius (meter)"
                        nilai={radius}
                        saatBerubah={AturRadius}
                        keterangan="20–1.000 m. Bawaan 100 m."
                        inputMode="numeric"
                        galat={props.errors.RadiusAbsensiMeter}
                        required
                    />
                    {galatLokasi ? (
                        <div className="sm:col-span-3">
                            <Pemberitahuan jenis="peringatan">{galatLokasi}</Pemberitahuan>
                        </div>
                    ) : null}
                    <div className="flex flex-wrap gap-2 sm:col-span-3">
                        <Tombol type="submit" memproses={memproses}>
                            Simpan lokasi absensi
                        </Tombol>
                        <Tombol varian="sekunder" memproses={mencari} onClick={PakaiLokasiSaya}>
                            Pakai lokasi saya sekarang
                        </Tombol>
                        {ada ? (
                            <Tombol
                                varian="bahaya"
                                disabled={memproses}
                                onClick={() => Kirim({ Lintang: null, Bujur: null })}
                            >
                                Hapus titik lokasi
                            </Tombol>
                        ) : null}
                    </div>
                </form>
            ) : null}
        </Panel>
    );
}
