import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import PemilihJam from '@/Komponen/Tanggal/PemilihJam';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';

export type HariJamOperasional = { Nama: string; Buka: string | null; Tutup: string | null };
export type JamOperasionalOutletData = { Diatur: boolean; Hari: HariJamOperasional[]; ZonaWaktu: string };

type Props = { alamatOutlet: string; data: JamOperasionalOutletData; bolehKelola: boolean };

type IsianHari = { Nama: string; Buka: boolean; JamBuka: string; JamTutup: string };

const bawaanBuka = '08:00';
const bawaanTutup = '21:00';

function Awal(data: JamOperasionalOutletData): IsianHari[] {
    return data.Hari.map((h) => ({
        Nama: h.Nama,
        // Belum diatur: tawarkan 08:00–21:00 setiap hari supaya pemilik tinggal menyesuaikan.
        Buka: data.Diatur ? h.Buka !== null : true,
        JamBuka: h.Buka ?? bawaanBuka,
        JamTutup: h.Tutup ?? bawaanTutup,
    }));
}

/**
 * F-e: jam operasional outlet per hari (jam lokal outlet). Toko online tidak menerima pesanan baru di luar jam ini dan
 * halamannya menunjukkan kapan buka lagi. Tutup yang sama atau lebih awal dari buka berarti lewat tengah malam.
 * Tanpa pengaturan, outlet dianggap selalu buka.
 */
export default function JamOperasionalOutlet({ alamatOutlet, data, bolehKelola }: Props) {
    const { props } = usePage<{ errors: Record<string, string> }>();
    const [hari, AturHari] = useState<IsianHari[]>(() => Awal(data));
    const [memproses, AturMemproses] = useState(false);
    const galat = props.errors.JamOperasional ?? props.errors.Jam;

    const Ubah = (nama: string, ubah: Partial<IsianHari>) =>
        AturHari((lama) => lama.map((h) => (h.Nama === nama ? { ...h, ...ubah } : h)));

    const SalinPertama = () =>
        AturHari((lama) => {
            const acuan = lama[0];

            return acuan === undefined
                ? lama
                : lama.map((h) => ({ ...h, Buka: acuan.Buka, JamBuka: acuan.JamBuka, JamTutup: acuan.JamTutup }));
        });

    const Kirim = (jam: Record<string, { Buka: string; Tutup: string } | null> | null) => {
        AturMemproses(true);
        router.post(
            `${alamatOutlet}/jam-operasional`,
            { Jam: jam },
            { preserveScroll: true, onFinish: () => AturMemproses(false) },
        );
    };

    const Simpan = (e: FormEvent) => {
        e.preventDefault();
        Kirim(Object.fromEntries(hari.map((h) => [h.Nama, h.Buka ? { Buka: h.JamBuka, Tutup: h.JamTutup } : null])));
    };

    return (
        <Panel
            judul="Jam operasional"
            keterangan={`Jam lokal outlet (${data.ZonaWaktu}). Di luar jam ini toko online tidak menerima pesanan baru.`}
        >
            {!data.Diatur ? (
                <p className="text-isi text-teks-sekunder">
                    Jam operasional belum diatur: outlet dianggap selalu buka. Isi di bawah lalu simpan.
                </p>
            ) : null}
            {galat ? <Pemberitahuan jenis="bahaya">{galat}</Pemberitahuan> : null}
            {bolehKelola ? (
                <form noValidate onSubmit={Simpan} className="flex flex-col gap-3">
                    <ul aria-label="Jam operasional per hari" className="flex flex-col divide-y divide-garis">
                        {hari.map((h) => (
                            <li
                                key={h.Nama}
                                className="grid grid-cols-[7rem_1fr] items-center gap-x-3 gap-y-2 py-2 sm:grid-cols-[7rem_8rem_1fr_1fr]"
                            >
                                <span className="text-isi font-semibold text-teks-utama">{h.Nama}</span>
                                <KotakCentang
                                    label="Buka"
                                    nilai={h.Buka}
                                    saatBerubah={(buka) => Ubah(h.Nama, { Buka: buka })}
                                />
                                <PemilihJam
                                    label={`Jam buka ${h.Nama}`}
                                    labelTersembunyi
                                    nilai={h.JamBuka}
                                    saatBerubah={(nilai) => Ubah(h.Nama, { JamBuka: nilai })}
                                    disabled={!h.Buka}
                                    contoh="08:00"
                                />
                                <PemilihJam
                                    label={`Jam tutup ${h.Nama}`}
                                    labelTersembunyi
                                    nilai={h.JamTutup}
                                    saatBerubah={(nilai) => Ubah(h.Nama, { JamTutup: nilai })}
                                    disabled={!h.Buka}
                                    contoh="21:00"
                                />
                            </li>
                        ))}
                    </ul>
                    <div className="flex flex-wrap gap-2">
                        <Tombol type="submit" memproses={memproses}>
                            Simpan jam operasional
                        </Tombol>
                        <Tombol varian="sekunder" onClick={SalinPertama}>
                            Samakan semua hari dengan Senin
                        </Tombol>
                        {data.Diatur ? (
                            <Tombol varian="bahaya" disabled={memproses} onClick={() => Kirim(null)}>
                                Hapus pengaturan jam
                            </Tombol>
                        ) : null}
                    </div>
                </form>
            ) : (
                <ul aria-label="Jam operasional per hari" className="flex flex-col divide-y divide-garis text-isi">
                    {data.Hari.map((h) => (
                        <li key={h.Nama} className="flex flex-wrap gap-x-4 py-1.5">
                            <span className="w-24 font-semibold text-teks-utama">{h.Nama}</span>
                            <span className="text-teks-sekunder">
                                {h.Buka !== null && h.Tutup !== null ? `${h.Buka} – ${h.Tutup}` : 'Libur'}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}
