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
export default function HalamanNotifikasi({
    Notifikasi,
    BelumDibaca,
    Dibisukan,
    TanpaPush,
    OutletDibisukan,
    Outlet,
    Jenis,
    Saring,
}: PropsNotifikasi) {
    const bisaDibisukan = Jenis.filter((j) => j.BisaDibisukan);
    const [diterima, AturDiterima] = useState<JenisNotifikasi[]>(
        bisaDibisukan.map((j) => j.Nilai).filter((nilai) => !Dibisukan.includes(nilai)),
    );
    const [pushAktif, AturPushAktif] = useState<JenisNotifikasi[]>(
        bisaDibisukan.map((j) => j.Nilai).filter((nilai) => !TanpaPush.includes(nilai)),
    );
    const [outletDikabari, AturOutletDikabari] = useState<string[]>(
        Outlet.map((o) => o.Uuid).filter((uuid) => !OutletDibisukan.includes(uuid)),
    );
    const [menyimpan, AturMenyimpan] = useState(false);
    const berubah =
        bisaDibisukan.some(
            (j) =>
                diterima.includes(j.Nilai) === Dibisukan.includes(j.Nilai) ||
                pushAktif.includes(j.Nilai) === TanpaPush.includes(j.Nilai),
        ) || Outlet.some((o) => outletDikabari.includes(o.Uuid) === OutletDibisukan.includes(o.Uuid));

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
            {
                Dibisukan: bisaDibisukan.map((j) => j.Nilai).filter((nilai) => !diterima.includes(nilai)),
                TanpaPush: bisaDibisukan.map((j) => j.Nilai).filter((nilai) => !pushAktif.includes(nilai)),
                OutletDibisukan: Outlet.map((o) => o.Uuid).filter((uuid) => !outletDikabari.includes(uuid)),
            },
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
                    <nav aria-label="Filter notifikasi" className="flex flex-wrap gap-2">
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
                        <div key={j.Nilai} className="flex flex-col gap-1">
                            <KotakCentang
                                label={j.Label}
                                nilai={diterima.includes(j.Nilai)}
                                saatBerubah={(terima) => Alihkan(j.Nilai, terima)}
                            />
                            <div className="ml-7">
                                <KotakCentang
                                    label={`Kirim juga sebagai push ke Aplikasi Owner: ${j.Label.toLowerCase()}`}
                                    nilai={diterima.includes(j.Nilai) && pushAktif.includes(j.Nilai)}
                                    disabled={!diterima.includes(j.Nilai)}
                                    saatBerubah={(aktif) =>
                                        AturPushAktif((lama) =>
                                            aktif ? [...lama, j.Nilai] : lama.filter((n) => n !== j.Nilai),
                                        )
                                    }
                                />
                            </div>
                        </div>
                    ))}
                    <p className="text-keterangan text-teks-sekunder">
                        Permintaan persetujuan selalu aktif karena menunggu keputusan Anda.
                    </p>
                    {Outlet.length > 1 ? (
                        <fieldset className="mt-2 flex flex-col gap-2 rounded-kontrol border border-garis p-4">
                            <legend className="px-1 text-label font-semibold text-teks-utama">
                                Outlet yang dikabari
                            </legend>
                            <p className="text-keterangan text-teks-sekunder">
                                Kabar void, retur, diskon besar, dan pesanan online hanya dibuat untuk outlet yang
                                dicentang.
                            </p>
                            {Outlet.map((o) => (
                                <KotakCentang
                                    key={o.Uuid}
                                    label={o.Nama}
                                    nilai={outletDikabari.includes(o.Uuid)}
                                    saatBerubah={(aktif) =>
                                        AturOutletDikabari((lama) =>
                                            aktif ? [...lama, o.Uuid] : lama.filter((u) => u !== o.Uuid),
                                        )
                                    }
                                />
                            ))}
                        </fieldset>
                    ) : null}
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
