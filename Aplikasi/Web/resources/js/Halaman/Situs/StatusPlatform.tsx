import { CircleAlert, CircleCheck, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';

import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakSitus from '@/TataLetak/TataLetakSitus';
import {
    AmbilJenisKondisi,
    type InsidenStatus,
    type KondisiKomponen,
    type StatusPlatformData,
} from '@/Tipe/StatusPlatform';

/** Selang muat ulang otomatis. Sama dengan lama tembolok di server supaya tidak menembus tembolok setiap kali. */
const SELANG_MUAT_ULANG_MS = 60_000;

const kelasBanner: Record<KondisiKomponen, string> = {
    Normal: 'border-sukses bg-sukses-lembut',
    Sebagian: 'border-peringatan bg-peringatan-lembut',
    Besar: 'border-bahaya bg-bahaya-lembut',
};

function IkonKondisi({ kondisi }: { kondisi: KondisiKomponen }) {
    if (kondisi === 'Normal') {
        return <CircleCheck aria-hidden className="size-6 shrink-0 text-sukses" />;
    }

    return kondisi === 'Sebagian' ? (
        <TriangleAlert aria-hidden className="size-6 shrink-0 text-peringatan" />
    ) : (
        <CircleAlert aria-hidden className="size-6 shrink-0 text-bahaya" />
    );
}

function KartuInsiden({ insiden }: { insiden: InsidenStatus }) {
    return (
        <li className="flex flex-col gap-3 rounded-panel border border-garis bg-permukaan p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <h3 className="text-isi font-semibold break-words text-teks-utama">{insiden.Judul}</h3>
                <LabelStatus
                    jenis={
                        insiden.Status === 'Selesai' ? 'sukses' : insiden.Tingkat === 'Kecil' ? 'peringatan' : 'bahaya'
                    }
                    teks={insiden.LabelStatus}
                />
            </div>
            <p className="text-keterangan text-teks-sekunder">
                Mulai {FormatTanggalWaktu(insiden.MulaiPada)}
                {insiden.SelesaiPada ? ` | selesai ${FormatTanggalWaktu(insiden.SelesaiPada)}` : ''} |{' '}
                {insiden.Komponen.join(', ')}
            </p>
            <p className="text-isi break-words text-teks-utama">{insiden.Dampak}</p>
            <ol aria-label={`Kronologi ${insiden.Judul}`} className="flex flex-col gap-2 border-l-2 border-garis pl-3">
                {insiden.Pembaruan.map((p, indeks) => (
                    <li key={`${p.Waktu ?? ''}-${String(indeks)}`} className="text-isi text-teks-utama">
                        <span className="font-semibold">
                            {p.LabelStatus}
                            {p.Waktu ? ` (${FormatTanggalWaktu(p.Waktu)})` : ''}:
                        </span>{' '}
                        <span className="break-words">{p.Isi}</span>
                    </li>
                ))}
            </ol>
            {insiden.PostMortem ? (
                <details className="rounded-kontrol border border-garis p-3">
                    <summary className="text-isi font-semibold text-teks-utama">Post-mortem</summary>
                    <p className="mt-2 text-isi whitespace-pre-wrap text-teks-utama">{insiden.PostMortem}</p>
                </details>
            ) : null}
        </li>
    );
}

/**
 * Halaman status layanan Payoung (P-11 PGL-21): kondisi tiap komponen, gangguan yang sedang berjalan, dan riwayat 30 hari.
 * Hanya insiden yang diumumkan Teknis. Memuat ulang diam-diam tiap menit dari `/status/data` tanpa berpindah halaman;
 * bila gagal, tampilan terakhir dipertahankan. Salinan statisnya (`status:ekspor`) tetap hidup saat server utama mati.
 */
export default function HalamanStatusPlatform({ Status }: { Status: StatusPlatformData }) {
    const [status, AturStatus] = useState(Status);

    useEffect(() => {
        const pengatur = window.setInterval(() => {
            fetch('/status/data', { headers: { Accept: 'application/json' }, cache: 'no-store' })
                .then((respons) => (respons.ok ? (respons.json() as Promise<StatusPlatformData>) : null))
                .then((data) => {
                    if (data !== null) {
                        AturStatus(data);
                    }
                })
                .catch(() => undefined);
        }, SELANG_MUAT_ULANG_MS);

        return () => window.clearInterval(pengatur);
    }, []);

    return (
        <TataLetakSitus judul="Status layanan">
            <header className="border-b border-garis bg-brand-lembut">
                <div className="mx-auto flex max-w-4xl flex-col gap-3 px-4 py-12 sm:py-16">
                    <JudulHalaman skala="situs">Status layanan Payoung</JudulHalaman>
                    <p className="text-pengantar text-teks-sekunder">
                        Kondisi layanan saat ini dan riwayat gangguan 30 hari terakhir.
                    </p>
                </div>
            </header>
            <div className="mx-auto flex max-w-4xl flex-col gap-10 px-4 py-10 sm:py-14">
                <section
                    aria-label="Kondisi keseluruhan"
                    role="status"
                    className={`flex items-center gap-3 rounded-panel border-2 p-5 ${kelasBanner[status.Keseluruhan]}`}
                >
                    <IkonKondisi kondisi={status.Keseluruhan} />
                    <div className="flex flex-col">
                        <h2 className="text-judul font-bold text-teks-utama">{status.LabelKeseluruhan}</h2>
                        <p className="text-keterangan text-teks-sekunder">
                            Diperbarui {FormatTanggalWaktu(status.DiperbaruiPada)}
                        </p>
                    </div>
                </section>

                <section aria-label="Komponen layanan" className="flex flex-col gap-3">
                    <h2 className="text-judul font-bold text-teks-utama">Komponen</h2>
                    <ul className="flex flex-col gap-2">
                        {status.Komponen.map((k) => (
                            <li
                                key={k.Kode}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-panel border border-garis bg-permukaan px-4 py-3"
                            >
                                <span className="text-isi text-teks-utama">{k.Nama}</span>
                                <LabelStatus jenis={AmbilJenisKondisi(k.Kondisi)} teks={k.LabelKondisi} />
                            </li>
                        ))}
                    </ul>
                </section>

                <section aria-label="Gangguan yang sedang berjalan" className="flex flex-col gap-3">
                    <h2 className="text-judul font-bold text-teks-utama">Gangguan yang sedang berjalan</h2>
                    {status.Aktif.length === 0 ? (
                        <p className="rounded-panel border border-dashed border-garis-input bg-permukaan px-4 py-8 text-center text-isi text-teks-sekunder">
                            Tidak ada gangguan saat ini.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-4">
                            {status.Aktif.map((i) => (
                                <KartuInsiden key={i.Uuid} insiden={i} />
                            ))}
                        </ul>
                    )}
                </section>

                <section aria-label="Riwayat 30 hari terakhir" className="flex flex-col gap-3">
                    <h2 className="text-judul font-bold text-teks-utama">Riwayat 30 hari terakhir</h2>
                    {status.Riwayat.length === 0 ? (
                        <p className="rounded-panel border border-dashed border-garis-input bg-permukaan px-4 py-8 text-center text-isi text-teks-sekunder">
                            Tidak ada gangguan dalam 30 hari terakhir.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-4">
                            {status.Riwayat.map((i) => (
                                <KartuInsiden key={i.Uuid} insiden={i} />
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </TataLetakSitus>
    );
}
