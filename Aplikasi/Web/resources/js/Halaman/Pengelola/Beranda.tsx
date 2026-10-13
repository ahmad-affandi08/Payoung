import { Link, usePage } from '@inertiajs/react';
import { AlertTriangleIcon, CircleCheckIcon, InfoIcon, type LucideIcon, OctagonAlertIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import Panel from '@/Komponen/Kelola/Panel';
import { Card } from '@/Komponen/Ui/card';
import { cn } from '@/Komponen/Ui/utils';
import { FormatRupiah } from '@/Pustaka/Format';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, PunyaIzin, type PropsBersamaPengelola } from '@/Tipe/Pengelola';
import type { PropsBerandaPengelola } from '@/Tipe/Pengelola';

const ikonTingkat: Record<PropsBerandaPengelola['Ringkasan']['Perhatian'][number]['Tingkat'], LucideIcon> = {
    bahaya: OctagonAlertIcon,
    peringatan: AlertTriangleIcon,
    info: InfoIcon,
};

const kelasTingkat: Record<PropsBerandaPengelola['Ringkasan']['Perhatian'][number]['Tingkat'], string> = {
    bahaya: 'text-bahaya',
    peringatan: 'text-peringatan',
    info: 'text-brand',
};

/** Satu angka ringkasan; seluruh ubin menjadi tautan ke halaman rinciannya. */
function Ubin({
    label,
    nilai,
    keterangan,
    href,
    perhatian = false,
}: {
    label: string;
    nilai: ReactNode;
    keterangan?: string;
    href: string;
    perhatian?: boolean;
}) {
    return (
        <Link
            href={href}
            className="rounded-kontrol border border-garis bg-permukaan p-3 hover:bg-permukaan-sorot focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
        >
            <dt className="text-label text-teks-sekunder">{label}</dt>
            <dd
                className={cn(
                    'mt-1 text-judul font-semibold tabular-nums',
                    perhatian ? 'text-bahaya' : 'text-teks-utama',
                )}
            >
                {nilai}
            </dd>
            {keterangan ? <p className="mt-1 text-keterangan text-teks-sekunder">{keterangan}</p> : null}
        </Link>
    );
}

/**
 * Beranda konsol Platform Pengelola: butir yang perlu tindakan dulu, lalu ringkasan per bidang (tenant, tagihan,
 * dukungan, sistem). Bagian hanya muncul bila pelaku berizin melihatnya.
 */
export default function Beranda({ Ringkasan }: PropsBerandaPengelola) {
    const { props } = usePage<PropsBersamaPengelola>();
    const pengguna = props.Pengguna;
    const { Tenant, Tagihan, Dukungan, Operasional, Perhatian } = Ringkasan;
    const adaBagian = Tenant !== null || Tagihan !== null || Dukungan !== null || Operasional !== null;

    return (
        <TataLetakPengelola judul={`Halo, ${pengguna?.Nama ?? ''}`}>
            {adaBagian ? (
                <Panel judul="Perlu perhatian" keterangan="Diurut dari yang paling mendesak.">
                    {Perhatian.length === 0 ? (
                        <p className="flex items-center gap-2 text-isi text-teks-utama">
                            <CircleCheckIcon aria-hidden="true" className="size-5 shrink-0 text-sukses" />
                            Semua terkendali. Tidak ada yang menunggu tindakan Anda.
                        </p>
                    ) : (
                        <ul className="flex flex-col divide-y divide-garis">
                            {Perhatian.map((butir) => {
                                const Ikon = ikonTingkat[butir.Tingkat];

                                return (
                                    <li key={butir.Judul}>
                                        <Link
                                            href={butir.Tautan}
                                            className="flex items-start gap-3 py-3 first:pt-0 last:pb-0 hover:text-brand"
                                        >
                                            <Ikon
                                                aria-hidden="true"
                                                className={cn('mt-0.5 size-5 shrink-0', kelasTingkat[butir.Tingkat])}
                                            />
                                            <span className="flex min-w-0 flex-col">
                                                <span className="text-isi font-semibold text-teks-utama">
                                                    {butir.Judul}
                                                </span>
                                                <span className="text-keterangan text-teks-sekunder">
                                                    {butir.Keterangan}
                                                </span>
                                            </span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Panel>
            ) : (
                <Card className="gap-2 rounded-panel p-4 shadow-none">
                    <p className="text-isi text-teks-utama">
                        Belum ada ringkasan untuk peran Anda. Hubungi Super Admin bila butuh akses.
                    </p>
                </Card>
            )}

            <div className="grid gap-4 lg:grid-cols-2">
                {Tenant !== null ? (
                    <Panel judul="Tenant" aksi={<TautanSemua href="/tenant" />}>
                        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <Ubin
                                label="Pelanggan aktif"
                                nilai={Tenant.Total}
                                keterangan={`${String(Tenant.Baru7Hari)} baru 7 hari`}
                                href="/tenant"
                            />
                            <Ubin label="Berbayar" nilai={Tenant.Berbayar} href="/tenant" />
                            <Ubin label="Uji coba" nilai={Tenant.Trial} href="/tenant" />
                            <Ubin
                                label="Tertunggak"
                                nilai={Tenant.Tertunggak}
                                href="/tenant"
                                perhatian={Tenant.Tertunggak > 0}
                            />
                            <Ubin label="Ditangguhkan" nilai={Tenant.Ditangguhkan} href="/tenant" />
                            <Ubin
                                label="Berisiko pergi"
                                nilai={Tenant.Berisiko}
                                keterangan={`${String(Tenant.TugasTerbuka)} tugas terbuka`}
                                href="/tugas-tenant"
                                perhatian={Tenant.Berisiko > 0}
                            />
                        </dl>
                    </Panel>
                ) : null}

                {Tagihan !== null ? (
                    <Panel judul="Tagihan langganan" aksi={<TautanSemua href="/tagihan" />}>
                        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <Ubin
                                label="Lewat jatuh tempo"
                                nilai={Tagihan.JatuhTempo}
                                keterangan={FormatRupiah(Tagihan.NilaiJatuhTempo)}
                                href="/tagihan"
                                perhatian={Tagihan.JatuhTempo > 0}
                            />
                            <Ubin
                                label="Menunggu verifikasi"
                                nilai={Tagihan.MenungguVerifikasi}
                                href="/tagihan"
                                perhatian={Tagihan.MenungguVerifikasi > 0}
                            />
                            <Ubin label="Terbit, belum dibayar" nilai={Tagihan.Terbit} href="/tagihan" />
                            <Ubin label="Lunas bulan ini" nilai={Tagihan.LunasBulanIni} href="/tagihan" />
                        </dl>
                        {PunyaIzin(pengguna, IzinPengelola.TagihanLihat) ? (
                            <p className="text-keterangan text-teks-sekunder">
                                MRR, churn, dan umur piutang ada di{' '}
                                <Link href="/laporan-langganan" className="text-brand underline">
                                    laporan langganan
                                </Link>
                                .
                            </p>
                        ) : null}
                    </Panel>
                ) : null}

                {Dukungan !== null ? (
                    <Panel judul="Dukungan" aksi={<TautanSemua href="/dukungan/tiket" />}>
                        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <Ubin label="Tiket terbuka" nilai={Dukungan.Terbuka} href="/dukungan/tiket" />
                            <Ubin
                                label="Belum ditangani"
                                nilai={Dukungan.Baru}
                                href="/dukungan/tiket"
                                perhatian={Dukungan.Baru > 0}
                            />
                            <Ubin
                                label="Lewat batas SLA"
                                nilai={Dukungan.LewatSla}
                                href="/dukungan/tiket"
                                perhatian={Dukungan.LewatSla > 0}
                            />
                        </dl>
                    </Panel>
                ) : null}

                {Operasional !== null ? (
                    <Panel judul="Kondisi sistem" aksi={<TautanSemua href="/operasional" />}>
                        <ul className="flex flex-col gap-2 text-isi">
                            <Kondisi label="Penjadwal (scheduler)" sehat={Operasional.PenjadwalSehat} />
                            <Kondisi label="Antrean tugas" sehat={Operasional.AntreanSehat} />
                            <Kondisi label="Backup" sehat={Operasional.BackupSehat} />
                        </ul>
                        <dl className="grid grid-cols-2 gap-3">
                            <Ubin
                                label="Alert aktif"
                                nilai={Operasional.AlertAktif}
                                href="/operasional"
                                perhatian={Operasional.AlertAktif > 0}
                            />
                            <Ubin
                                label="Insiden belum selesai"
                                nilai={Operasional.InsidenAktif}
                                href="/operasional/insiden"
                                perhatian={Operasional.InsidenAktif > 0}
                            />
                        </dl>
                    </Panel>
                ) : null}
            </div>
        </TataLetakPengelola>
    );
}

function TautanSemua({ href }: { href: string }) {
    return (
        <Link href={href} className="text-label font-semibold text-brand underline">
            Lihat semua
        </Link>
    );
}

function Kondisi({ label, sehat }: { label: string; sehat: boolean }) {
    return (
        <li className="flex items-center justify-between gap-3">
            <span className="text-teks-utama">{label}</span>
            <span className={cn('flex items-center gap-1.5 font-semibold', sehat ? 'text-sukses' : 'text-bahaya')}>
                {sehat ? (
                    <CircleCheckIcon aria-hidden="true" className="size-4" />
                ) : (
                    <OctagonAlertIcon aria-hidden="true" className="size-4" />
                )}
                {sehat ? 'Sehat' : 'Bermasalah'}
            </span>
        </li>
    );
}
