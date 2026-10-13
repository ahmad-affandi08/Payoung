import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import KeadaanKosong from '@/Komponen/Katalog/KeadaanKosong';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { Button } from '@/Komponen/Ui/button';
import { cn } from '@/Komponen/Ui/utils';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisNotifikasi, JenisNotifikasi, PropsNotifikasi } from '@/Tipe/Notifikasi';

const alamat = '/kelola/notifikasi';

/**
 * F31 (PLT-08) pusat notifikasi back-office: notifikasi milik pengguna ini (selisih kas, stok menipis, perangkat tidak
 * aktif, piutang lewat jatuh tempo, barang mendekati kedaluwarsa), tanda sudah dibaca, dan jenis yang ingin dibisukan.
 * Isinya sama dengan Aplikasi Owner; membuka satu notifikasi menandainya dibaca lalu menuju halaman terkait.
 */
export default function HalamanNotifikasi({ Notifikasi, BelumDibaca, Dibisukan, Jenis, Saring }: PropsNotifikasi) {
    const bisaDibisukan = Jenis.filter((j) => j.BisaDibisukan);
    const [diterima, AturDiterima] = useState<JenisNotifikasi[]>(
        bisaDibisukan.map((j) => j.Nilai).filter((nilai) => !Dibisukan.includes(nilai)),
    );
    const [menyimpan, AturMenyimpan] = useState(false);
    const berubah = bisaDibisukan.some((j) => diterima.includes(j.Nilai) === Dibisukan.includes(j.Nilai));

    const TandaiDibaca = (uuid: string[], semua: boolean, sesudah?: () => void) => {
        router.post(`${alamat}/dibaca`, semua ? { Semua: true } : { Uuid: uuid }, {
            preserveScroll: true,
            preserveState: true,
            ...(sesudah ? { onSuccess: sesudah } : {}),
        });
    };

    const Buka = (n: BarisNotifikasi) => {
        if (n.Tautan === null) {
            return;
        }

        const tujuan = n.Tautan;

        if (n.DibacaPada === null) {
            TandaiDibaca([n.Uuid], false, () => router.visit(tujuan));

            return;
        }

        router.visit(tujuan);
    };

    const Simpan = () => {
        router.put(
            `${alamat}/pengaturan`,
            { Dibisukan: bisaDibisukan.map((j) => j.Nilai).filter((nilai) => !diterima.includes(nilai)) },
            {
                preserveScroll: true,
                onStart: () => AturMenyimpan(true),
                onFinish: () => AturMenyimpan(false),
            },
        );
    };

    const Alihkan = (nilai: JenisNotifikasi, terima: boolean) =>
        AturDiterima((lama) => (terima ? [...lama, nilai] : lama.filter((j) => j !== nilai)));

    return (
        <TataLetakAplikasi judul="Notifikasi">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Kabar penting dari usaha Anda: shift dan kas yang perlu dicek, stok menipis, perangkat kasir yang tidak
                aktif, piutang lewat jatuh tempo, dan barang mendekati kedaluwarsa. Isinya sama dengan yang tampil di
                Aplikasi Owner.
            </p>
            <AksiHalaman
                keterangan={
                    <nav aria-label="Saringan notifikasi" className="flex flex-wrap gap-2">
                        {(
                            [
                                ['semua', 'Semua'],
                                ['belum-dibaca', `Belum dibaca (${String(BelumDibaca)})`],
                            ] as const
                        ).map(([nilai, label]) => (
                            <Button
                                key={nilai}
                                asChild
                                variant={Saring.Tampil === nilai ? 'default' : 'outline'}
                                className="h-8 pointer-coarse:h-11 text-label font-semibold"
                            >
                                <Link
                                    href={nilai === 'semua' ? alamat : `${alamat}?tampil=${nilai}`}
                                    aria-current={Saring.Tampil === nilai ? 'page' : undefined}
                                >
                                    {label}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                }
            >
                <Tombol varian="sekunder" disabled={BelumDibaca === 0} onClick={() => TandaiDibaca([], true)}>
                    Tandai semua dibaca
                </Tombol>
            </AksiHalaman>

            {Notifikasi.length === 0 ? (
                <KeadaanKosong
                    ilustrasi
                    judul={
                        Saring.Tampil === 'belum-dibaca'
                            ? 'Semua notifikasi sudah dibaca.'
                            : 'Belum ada notifikasi. Kabar penting usaha Anda akan muncul di sini.'
                    }
                />
            ) : (
                <ul
                    aria-label="Daftar notifikasi"
                    className="flex flex-col divide-y divide-garis rounded-panel border border-garis bg-card"
                >
                    {Notifikasi.map((n) => (
                        <li
                            key={n.Uuid}
                            className={cn(
                                'flex flex-col gap-2 p-4 sm:flex-row sm:items-start sm:gap-4',
                                n.DibacaPada === null && 'bg-permukaan-redup',
                            )}
                        >
                            <div className="flex min-w-0 flex-1 flex-col gap-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2
                                        className={cn(
                                            'text-isi text-teks-utama wrap-anywhere',
                                            n.DibacaPada === null && 'font-semibold',
                                        )}
                                    >
                                        {n.Judul}
                                    </h2>
                                    {n.DibacaPada === null ? <LabelStatus jenis="peringatan" teks="Baru" /> : null}
                                    <span className="text-keterangan text-teks-sekunder">{n.LabelJenis}</span>
                                </div>
                                <p className="text-isi text-teks-sekunder wrap-anywhere">{n.Isi}</p>
                                <p className="text-keterangan text-teks-sekunder">{FormatTanggalWaktu(n.DibuatPada)}</p>
                                {n.Tautan === null ? (
                                    <p className="text-keterangan text-teks-sekunder">
                                        Diputuskan lewat Aplikasi Owner.
                                    </p>
                                ) : null}
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {n.Tautan !== null ? (
                                    <Tombol varian="sekunder" onClick={() => Buka(n)}>
                                        Buka
                                    </Tombol>
                                ) : null}
                                {n.DibacaPada === null ? (
                                    <Tombol varian="sekunder" onClick={() => TandaiDibaca([n.Uuid], false)}>
                                        Tandai dibaca
                                    </Tombol>
                                ) : null}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <Panel
                judul="Jenis notifikasi"
                keterangan="Matikan jenis yang tidak Anda butuhkan. Berlaku untuk notifikasi baru, di web maupun Aplikasi Owner."
            >
                <div className="flex flex-col gap-2">
                    {bisaDibisukan.map((j) => (
                        <KotakCentang
                            key={j.Nilai}
                            label={j.Label}
                            nilai={diterima.includes(j.Nilai)}
                            saatBerubah={(terima) => Alihkan(j.Nilai, terima)}
                        />
                    ))}
                    <p className="text-keterangan text-teks-sekunder">
                        Permintaan persetujuan selalu aktif karena menunggu keputusan Anda.
                    </p>
                    <div>
                        <Tombol memproses={menyimpan} disabled={!berubah} onClick={Simpan}>
                            Simpan pengaturan
                        </Tombol>
                    </div>
                </div>
            </Panel>
        </TataLetakAplikasi>
    );
}
