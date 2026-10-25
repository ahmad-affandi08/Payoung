import { Link, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

import { DaftarPeringkat, KartuWidget } from '@/Komponen/Kelola/KartuWidget';
import Panel from '@/Komponen/Kelola/Panel';
import DialogAturWidget from '@/Komponen/Laporan/DialogAturWidget';
import { Button } from '@/Komponen/Ui/button';
import { Card } from '@/Komponen/Ui/card';
import { cn } from '@/Komponen/Ui/utils';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah, FormatRupiahRingkas } from '@/Pustaka/Format';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { BacaWidgetTersembunyi, SimpanWidgetTersembunyi } from '@/Pustaka/PreferensiWidget';
import {
    DAFTAR_WIDGET_PENGELOLA,
    FormatPerubahanPersen,
    HitungTinggiBatang,
    KUNCI_WIDGET_PENGELOLA,
    NamaBulanSingkat,
    type KodeWidgetPengelola,
} from '@/Pustaka/WidgetPengelola';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import {
    IzinPengelola,
    PunyaIzin,
    type DasborBisnisPengelola,
    type PropsBerandaPengelola,
    type PropsBersamaPengelola,
    type TitikTrenMrr,
} from '@/Tipe/Pengelola';

const BISNIS_KOSONG: DasborBisnisPengelola = {
    Bisnis: null,
    TenantPerSektor: null,
    TenantRisiko: null,
    Konversi: null,
    Aktivitas: null,
};

const labelTingkat = { bahaya: 'Penting', peringatan: 'Perhatian', info: 'Info' } as const;
const jenisTingkat = { bahaya: 'bahaya', peringatan: 'peringatan', info: 'netral' } as const;

type Nada = 'sehat' | 'perhatian' | 'bahaya' | undefined;

type Angka = {
    label: string;
    nilai: ReactNode;
    keterangan?: string;
    href: string;
    nada?: Nada;
};

/** Satu angka utama; seluruh ubin menjadi tautan ke halaman rinciannya. */
function UbinAngka({ label, nilai, keterangan, href, nada }: Angka) {
    return (
        <Link
            href={href}
            className="rounded-panel border border-garis bg-permukaan p-3 hover:bg-permukaan-sorot focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
        >
            <dt className="text-label font-semibold text-teks-sekunder">{label}</dt>
            <dd
                className={cn(
                    'mt-1 text-judul font-semibold tabular-nums',
                    nada === 'sehat' && 'text-sukses',
                    nada === 'perhatian' && 'text-peringatan',
                    nada === 'bahaya' && 'text-bahaya',
                    nada === undefined && 'text-teks-utama',
                )}
            >
                {nilai}
            </dd>
            {keterangan ? <p className="mt-0.5 text-keterangan text-teks-sekunder">{keterangan}</p> : null}
        </Link>
    );
}

function BarisKunci({ label, nilai, nada }: { label: string; nilai: ReactNode; nada?: Nada }) {
    return (
        <li className="flex items-center justify-between gap-3 border-b border-garis py-1.5 text-isi last:border-b-0">
            <span className="text-teks-utama">{label}</span>
            <span
                className={cn(
                    'font-semibold tabular-nums',
                    nada === 'sehat' && 'text-sukses',
                    nada === 'perhatian' && 'text-peringatan',
                    nada === 'bahaya' && 'text-bahaya',
                )}
            >
                {nilai}
            </span>
        </li>
    );
}

/**
 * Beranda konsol Platform Pengelola sebagai pusat komando (D-207): angka bisnis, tren MRR, butir yang perlu tindakan,
 * lalu ringkasan per bidang (paket, sektor, tenant berisiko, sistem, antrean kerja, aktivitas, pintasan). Setiap
 * bagian hanya ada bila pelaku berizin melihat sumbernya; petugas boleh menyembunyikan widget (disimpan per peramban).
 */
export default function Beranda({ Ringkasan, Bisnis = BISNIS_KOSONG }: PropsBerandaPengelola) {
    const { props } = usePage<PropsBersamaPengelola>();
    const pengguna = props.Pengguna;
    const { Tenant, Tagihan, Dukungan, Operasional, Perhatian } = Ringkasan;
    const { Bisnis: bisnis, TenantPerSektor, TenantRisiko, Konversi, Aktivitas } = Bisnis;
    const [tersembunyi, AturTersembunyi] = useState<KodeWidgetPengelola[]>(() =>
        BacaWidgetTersembunyi(
            KUNCI_WIDGET_PENGELOLA,
            DAFTAR_WIDGET_PENGELOLA.map((w) => w.Kode),
        ),
    );
    const [aturBuka, AturAturBuka] = useState(false);
    const adaBagian = Tenant !== null || Tagihan !== null || Dukungan !== null || Operasional !== null;

    const angka: Angka[] = [];

    if (bisnis !== null) {
        angka.push(
            {
                label: 'MRR',
                nilai: FormatRupiahRingkas(bisnis.Mrr),
                keterangan:
                    FormatPerubahanPersen(bisnis.PerubahanMrrPersen) === null
                        ? 'pendapatan berulang per bulan'
                        : `${FormatPerubahanPersen(bisnis.PerubahanMrrPersen) ?? ''} dari bulan lalu`,
                href: '/laporan-langganan',
                nada: 'sehat',
            },
            {
                label: 'ARR',
                nilai: FormatRupiahRingkas(bisnis.Arr),
                keterangan: 'proyeksi 12 bulan',
                href: '/laporan-langganan',
            },
        );
    }

    if (Tenant !== null) {
        angka.push({
            label: 'Tenant berbayar',
            nilai: Tenant.Berbayar,
            keterangan: `${String(Tenant.Baru7Hari)} tenant baru 7 hari | ${String(Tenant.Trial)} uji coba`,
            href: '/tenant',
        });
    }

    if (Konversi !== null) {
        angka.push({
            label: 'Konversi uji coba',
            nilai: `${Konversi.Persen.replace('.', ',')}%`,
            keterangan:
                Konversi.Kohort === 0
                    ? 'belum ada kohort 30–60 hari'
                    : `${String(Konversi.Berbayar)} dari ${String(Konversi.Kohort)} yang mendaftar 30–60 hari lalu`,
            href: '/tenant',
        });
    }

    if (bisnis !== null) {
        angka.push({
            label: 'Churn bulan ini',
            nilai: `${bisnis.Churn.PersenChurn.replace('.', ',')}%`,
            keterangan: `${String(bisnis.Churn.PelangganBerhenti)} tenant berhenti`,
            href: '/laporan-langganan',
            nada: bisnis.Churn.PelangganBerhenti > 0 ? 'perhatian' : undefined,
        });
    }

    if (Tagihan !== null) {
        angka.push({
            label: 'Tagihan lewat tempo',
            nilai: FormatRupiahRingkas(Tagihan.NilaiJatuhTempo),
            keterangan: `${String(Tagihan.JatuhTempo)} tagihan`,
            href: '/tagihan',
            nada: Tagihan.JatuhTempo > 0 ? 'bahaya' : undefined,
        });
    }

    const tersedia: KodeWidgetPengelola[] = DAFTAR_WIDGET_PENGELOLA.map((w) => w.Kode).filter((kode) => {
        switch (kode) {
            case 'angka':
                return angka.length > 0;
            case 'mrr':
                return bisnis !== null;
            case 'perhatian':
                return adaBagian;
            case 'paket':
                return bisnis !== null;
            case 'sektor':
                return TenantPerSektor !== null;
            case 'risiko':
                return TenantRisiko !== null;
            case 'sistem':
                return Operasional !== null;
            case 'kerja':
                return Dukungan !== null || Tagihan !== null;
            case 'aktivitas':
                return Aktivitas !== null;
            case 'pintasan':
                return true;
        }
    });
    const Tampil = (kode: KodeWidgetPengelola) => tersedia.includes(kode) && !tersembunyi.includes(kode);
    const Ubah = (daftar: KodeWidgetPengelola[]) => {
        AturTersembunyi(daftar);
        SimpanWidgetTersembunyi(KUNCI_WIDGET_PENGELOLA, daftar);
    };
    const pintasan = [
        { label: 'Cari tenant', href: '/tenant', izin: IzinPengelola.TenantLihat },
        { label: 'Terbitkan tagihan manual', href: '/tagihan', izin: IzinPengelola.TagihanTerbitkan },
        { label: 'Kirim pengumuman', href: '/pengumuman', izin: IzinPengelola.RilisLihat },
        { label: 'Buka insiden', href: '/operasional/insiden', izin: IzinPengelola.OperasionalLihat },
        { label: 'Catat backup', href: '/operasional', izin: IzinPengelola.OperasionalKelola },
        { label: 'Rilis aplikasi', href: '/rilis', izin: IzinPengelola.RilisLihat },
        { label: 'Tiket dukungan', href: '/dukungan/tiket', izin: IzinPengelola.DukunganTiketLihat },
    ].filter((p) => PunyaIzin(pengguna, p.izin));

    return (
        <TataLetakPengelola
            judul={`Halo, ${pengguna?.Nama ?? ''}`}
            aksi={
                <Button
                    type="button"
                    variant="outline"
                    className="h-8 pointer-coarse:h-11 px-4 text-label font-semibold"
                    onClick={() => AturAturBuka(true)}
                >
                    Atur widget
                </Button>
            }
        >
            {!adaBagian && pintasan.length === 0 ? (
                <Card className="gap-2 rounded-panel p-4 shadow-none">
                    <p className="text-isi text-teks-utama">
                        Belum ada ringkasan untuk peran Anda. Hubungi Super Admin bila butuh akses.
                    </p>
                </Card>
            ) : null}

            {Tampil('angka') ? (
                <dl className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    {angka.map((a) => (
                        <UbinAngka key={a.label} {...a} />
                    ))}
                </dl>
            ) : null}

            {Tampil('mrr') || Tampil('perhatian') ? (
                <div className={cn('grid gap-3', Tampil('mrr') && Tampil('perhatian') && 'lg:grid-cols-[1.6fr_1fr]')}>
                    {Tampil('mrr') && bisnis !== null ? (
                        <Panel
                            judul="Pendapatan berulang bulanan (MRR)"
                            tingkat="h3"
                            keterangan={`Baru ${String(bisnis.Churn.PelangganBaru)} tenant (+${FormatRupiahRingkas(bisnis.Churn.MrrBaru)}), berhenti ${String(bisnis.Churn.PelangganBerhenti)} (−${FormatRupiahRingkas(bisnis.Churn.MrrBerhenti)}) bulan ini`}
                        >
                            <p className="text-judul font-semibold text-teks-utama tabular-nums">
                                {FormatRupiah(bisnis.Mrr)}
                            </p>
                            <GrafikMrr tren={bisnis.Tren} />
                            <Button asChild variant="link" className="h-auto w-fit p-0 text-label font-semibold">
                                <Link href="/laporan-langganan">Buka laporan langganan</Link>
                            </Button>
                        </Panel>
                    ) : null}

                    {Tampil('perhatian') ? (
                        <Panel judul="Perlu perhatian" tingkat="h3" keterangan="Diurut dari yang paling mendesak.">
                            {Perhatian.length === 0 ? (
                                <p className="text-isi text-teks-utama">
                                    Semua terkendali. Tidak ada yang menunggu tindakan Anda.
                                </p>
                            ) : (
                                <ul className="flex flex-col divide-y divide-garis">
                                    {Perhatian.map((butir) => (
                                        <li key={butir.Judul}>
                                            <Link
                                                href={butir.Tautan}
                                                className="flex items-start gap-3 py-2.5 first:pt-0 last:pb-0 hover:text-brand"
                                            >
                                                <LabelStatus
                                                    jenis={jenisTingkat[butir.Tingkat]}
                                                    teks={labelTingkat[butir.Tingkat]}
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
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    ) : null}
                </div>
            ) : null}

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {Tampil('paket') && bisnis !== null ? (
                    <KartuWidget
                        judul="Pendapatan per paket"
                        angka={FormatRupiahRingkas(bisnis.Mrr)}
                        tautan="/laporan-langganan"
                        teksTautan="Buka laporan langganan"
                    >
                        <DaftarPeringkat
                            baris={bisnis.MrrPerPaket.map((b) => ({
                                kunci: b.Kunci,
                                nama: `${b.Nama} (${String(b.Pelanggan)})`,
                                kanan: FormatRupiahRingkas(b.Mrr),
                                nilai: b.Mrr,
                            }))}
                            kosong="Belum ada pelanggan berbayar."
                        />
                    </KartuWidget>
                ) : null}

                {Tampil('sektor') && TenantPerSektor !== null ? (
                    <KartuWidget
                        judul="Tenant per sektor"
                        angka={String(TenantPerSektor.reduce((n, s) => n + s.Jumlah, 0))}
                        tautan="/analitik"
                        teksTautan="Buka analitik"
                    >
                        <DaftarPeringkat
                            baris={TenantPerSektor.map((s) => ({
                                kunci: s.Nama,
                                nama: s.Nama,
                                kanan: `${String(s.Jumlah)} tenant`,
                                nilai: String(s.Jumlah),
                            }))}
                            kosong="Belum ada tenant."
                        />
                    </KartuWidget>
                ) : null}

                {Tampil('risiko') && TenantRisiko !== null ? (
                    <KartuWidget
                        judul="Tenant berisiko"
                        angka={String(Tenant?.Berisiko ?? TenantRisiko.length)}
                        tautan="/tugas-tenant"
                        teksTautan="Buka tugas tenant"
                    >
                        {TenantRisiko.length === 0 ? (
                            <p className="text-isi text-teks-sekunder">Tidak ada tenant berisiko berhenti.</p>
                        ) : (
                            <ul className="flex flex-col">
                                {TenantRisiko.map((t) => (
                                    <li
                                        key={t.Uuid}
                                        className="flex items-start justify-between gap-2 border-b border-garis py-2 last:border-b-0"
                                    >
                                        <span className="min-w-0">
                                            <Link
                                                href={`/tenant/${t.Uuid}`}
                                                className="block truncate text-isi font-semibold text-brand underline"
                                                title={t.Nama}
                                            >
                                                {t.Nama}
                                            </Link>
                                            <span className="block text-keterangan text-teks-sekunder">
                                                {[t.Paket, t.StatusLangganan].filter(Boolean).join(' | ')}
                                            </span>
                                        </span>
                                        <LabelStatus jenis="bahaya" teks={`Skor ${String(t.Skor)}`} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </KartuWidget>
                ) : null}

                {Tampil('sistem') && Operasional !== null ? (
                    <KartuWidget
                        judul="Kondisi sistem"
                        angka={
                            Operasional.PenjadwalSehat && Operasional.AntreanSehat && Operasional.BackupSehat
                                ? 'Sehat'
                                : 'Bermasalah'
                        }
                        tautan="/operasional"
                        teksTautan="Buka kondisi sistem"
                    >
                        <ul className="flex flex-col">
                            <BarisKunci
                                label="Penjadwal (scheduler)"
                                nilai={Operasional.PenjadwalSehat ? 'Sehat' : 'Bermasalah'}
                                nada={Operasional.PenjadwalSehat ? 'sehat' : 'bahaya'}
                            />
                            <BarisKunci
                                label="Antrean tugas"
                                nilai={Operasional.AntreanSehat ? 'Sehat' : 'Bermasalah'}
                                nada={Operasional.AntreanSehat ? 'sehat' : 'bahaya'}
                            />
                            <BarisKunci
                                label="Backup"
                                nilai={Operasional.BackupSehat ? 'Sehat' : 'Bermasalah'}
                                nada={Operasional.BackupSehat ? 'sehat' : 'bahaya'}
                            />
                            <BarisKunci
                                label="Alert aktif"
                                nilai={Operasional.AlertAktif}
                                nada={Operasional.AlertAktif > 0 ? 'perhatian' : undefined}
                            />
                            <BarisKunci
                                label="Insiden belum selesai"
                                nilai={Operasional.InsidenAktif}
                                nada={Operasional.InsidenAktif > 0 ? 'bahaya' : undefined}
                            />
                        </ul>
                    </KartuWidget>
                ) : null}
            </div>

            {Tampil('kerja') || Tampil('aktivitas') || Tampil('pintasan') ? (
                <div className="grid gap-3 lg:grid-cols-3">
                    {Tampil('kerja') ? (
                        <Panel judul="Antrean kerja" tingkat="h3">
                            <ul className="flex flex-col">
                                {Dukungan !== null ? (
                                    <>
                                        <BarisKunci label="Tiket terbuka" nilai={Dukungan.Terbuka} />
                                        <BarisKunci
                                            label="Tiket belum ditangani"
                                            nilai={Dukungan.Baru}
                                            nada={Dukungan.Baru > 0 ? 'perhatian' : undefined}
                                        />
                                        <BarisKunci
                                            label="Tiket lewat batas SLA"
                                            nilai={Dukungan.LewatSla}
                                            nada={Dukungan.LewatSla > 0 ? 'bahaya' : undefined}
                                        />
                                    </>
                                ) : null}
                                {Tagihan !== null ? (
                                    <>
                                        <BarisKunci
                                            label="Pembayaran menunggu verifikasi"
                                            nilai={Tagihan.MenungguVerifikasi}
                                            nada={Tagihan.MenungguVerifikasi > 0 ? 'perhatian' : undefined}
                                        />
                                        <BarisKunci label="Tagihan terbit, belum dibayar" nilai={Tagihan.Terbit} />
                                        <BarisKunci label="Tagihan lunas bulan ini" nilai={Tagihan.LunasBulanIni} />
                                    </>
                                ) : null}
                            </ul>
                        </Panel>
                    ) : null}

                    {Tampil('aktivitas') && Aktivitas !== null ? (
                        <Panel judul="Aktivitas terbaru" tingkat="h3">
                            {Aktivitas.length === 0 ? (
                                <p className="text-isi text-teks-sekunder">Belum ada aktivitas.</p>
                            ) : (
                                <ul className="flex flex-col">
                                    {Aktivitas.map((a) => (
                                        <li
                                            key={`${a.Waktu}-${a.Teks}`}
                                            className="border-b border-garis py-2 text-isi last:border-b-0"
                                        >
                                            {a.Tautan === null ? (
                                                <span className="text-teks-utama">{a.Teks}</span>
                                            ) : (
                                                <Link href={a.Tautan} className="text-teks-utama hover:text-brand">
                                                    {a.Teks}
                                                </Link>
                                            )}
                                            <span className="block text-keterangan text-teks-sekunder">
                                                {FormatTanggalWaktu(a.Waktu)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    ) : null}

                    {Tampil('pintasan') && pintasan.length > 0 ? (
                        <Panel judul="Pintasan petugas" tingkat="h3">
                            <div className="flex flex-wrap gap-2">
                                {pintasan.map((p) => (
                                    <Button
                                        key={p.label}
                                        asChild
                                        variant="outline"
                                        className="h-8 pointer-coarse:h-11 px-3 text-label font-semibold"
                                    >
                                        <Link href={p.href}>{p.label}</Link>
                                    </Button>
                                ))}
                            </div>
                        </Panel>
                    ) : null}
                </div>
            ) : null}

            {tersedia.every((kode) => !Tampil(kode)) ? (
                <p className="rounded-panel border border-garis bg-permukaan px-4 py-3 text-isi text-teks-sekunder">
                    Semua widget disembunyikan. Pilih "Atur widget" untuk menampilkannya lagi.
                </p>
            ) : null}

            {aturBuka ? (
                <DialogAturWidget
                    daftar={DAFTAR_WIDGET_PENGELOLA.filter((w) => tersedia.includes(w.Kode))}
                    tersembunyi={tersembunyi}
                    saatUbah={Ubah}
                    saatTutup={() => AturAturBuka(false)}
                />
            ) : null}
        </TataLetakPengelola>
    );
}

/** Batang MRR per bulan; nama dan nilai tiap bulan ada di teks alternatif, bukan hanya tinggi batang. */
function GrafikMrr({ tren }: { tren: TitikTrenMrr[] }) {
    const tinggi = HitungTinggiBatang(tren);
    const ringkas = tren.map((t) => `${NamaBulanSingkat(t.Bulan)} ${FormatRupiahRingkas(t.Mrr)}`).join(', ');

    return (
        <div
            role="img"
            aria-label={`Tren MRR ${String(tren.length)} bulan: ${ringkas}`}
            className="flex flex-col gap-1"
        >
            <div className="flex h-36 items-end gap-2 border-b border-garis">
                {tren.map((t, i) => (
                    <div
                        key={t.Bulan}
                        className="flex h-full flex-1 items-end"
                        title={`${t.Bulan}: ${FormatRupiah(t.Mrr)}`}
                    >
                        <div
                            className={cn('w-full rounded-t-sm', i === tren.length - 1 ? 'bg-brand' : 'bg-garis-input')}
                            style={{ height: `${String(tinggi[i] ?? 0)}%` }}
                        />
                    </div>
                ))}
            </div>
            <div aria-hidden="true" className="flex gap-2 text-keterangan text-teks-sekunder">
                {tren.map((t) => (
                    <span key={t.Bulan} className="flex-1 text-center">
                        {NamaBulanSingkat(t.Bulan)}
                    </span>
                ))}
            </div>
        </div>
    );
}
