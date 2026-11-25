import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { jenisStatusLangganan, type RingkasanLangganan } from '@/Tipe/Langganan';

const MS_SEHARI = 86_400_000;

function UraiWaktu(iso: string | null): number | null {
    if (iso === null) {
        return null;
    }

    const waktu = new Date(iso).getTime();

    return Number.isNaN(waktu) ? null : waktu;
}

/** Hari tersisa dibulatkan ke atas: sisa 3 jam masih "1 hari lagi", bukan "0". Null bila tanggal tidak ada. */
export function HariTersisa(akhir: string | null, sekarang: number): number | null {
    const waktu = UraiWaktu(akhir);

    return waktu === null ? null : Math.ceil((waktu - sekarang) / MS_SEHARI);
}

/** Persen periode yang sudah terpakai (0 sampai 100), null bila periode tidak lengkap atau tidak masuk akal. */
export function PersenPeriodeTerpakai(mulai: string | null, akhir: string | null, sekarang: number): number | null {
    const awal = UraiWaktu(mulai);
    const selesai = UraiWaktu(akhir);

    if (awal === null || selesai === null || selesai <= awal) {
        return null;
    }

    return Math.min(100, Math.max(0, Math.round(((sekarang - awal) / (selesai - awal)) * 100)));
}

export function KalimatSisaHari(sisa: number | null, status: RingkasanLangganan['Status']): string {
    if (sisa === null) {
        return status === 'Gratis' ? 'Tanpa batas waktu' : '—';
    }

    if (sisa > 0) {
        return `${sisa.toLocaleString('id-ID')} hari lagi`;
    }

    return sisa === 0 ? 'Berakhir hari ini' : 'Sudah berakhir';
}

type PropsKepalaPaket = {
    langganan: RingkasanLangganan;
    /** Membuka tab Paket & harga (perpanjang atau ganti paket). */
    saatPilihPaket: () => void;
};

/**
 * Kepala halaman Langganan (D-332): paket aktif, sisa masa berlaku dengan batang kemajuan periode, dan aksi utama
 * perpanjang. Blok gelap bermerek, satu-satunya warna apricot di halaman ada pada aksi utamanya.
 */
export default function KepalaPaket({ langganan, saatPilihPaket }: PropsKepalaPaket) {
    const [sekarang] = useState(() => Date.now());
    const berbayar = langganan.Status === 'Aktif' || langganan.Status === 'Tertunggak';
    const akhir = langganan.Status === 'Trial' ? langganan.TrialBerakhirPada : langganan.PeriodeSelesai;
    const sisa = HariTersisa(akhir, sekarang);
    const persen = berbayar ? PersenPeriodeTerpakai(langganan.PeriodeMulai, langganan.PeriodeSelesai, sekarang) : null;
    const keterangan = berbayar
        ? `Langganan ${langganan.JumlahBulanSiklus.toLocaleString('id-ID')} bulan`
        : langganan.Status === 'Trial'
          ? 'Semua fitur terbuka selama masa trial'
          : langganan.Status === 'Gratis'
            ? 'Fitur dasar tanpa biaya'
            : null;

    return (
        <section aria-label="Paket langganan" className="rounded-panel bg-brand-gelap p-4 text-brand-gelap-teks sm:p-6">
            <div className="grid gap-5 lg:grid-cols-[auto_minmax(0,1fr)_auto] lg:items-center lg:gap-8">
                <div className="flex flex-col gap-1">
                    <span className="text-keterangan tracking-wide uppercase">Paket saat ini</span>
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="text-tampilan font-semibold text-brand-teks">{langganan.NamaPaket}</span>
                        <LabelStatus jenis={jenisStatusLangganan[langganan.Status]} teks={langganan.LabelStatus} />
                    </div>
                    {keterangan ? <span className="text-isi">{keterangan}</span> : null}
                </div>
                <div className="flex flex-col gap-2 border-brand-gelap-garis lg:border-l lg:pl-8">
                    <span className="text-keterangan">
                        {langganan.Status === 'Trial' ? 'Masa trial' : 'Masa berlaku'}
                    </span>
                    <span className="text-judul font-semibold text-brand-teks">
                        {KalimatSisaHari(sisa, langganan.Status)}
                    </span>
                    {persen !== null ? (
                        <>
                            <div
                                role="progressbar"
                                aria-label="Bagian masa langganan yang sudah berjalan"
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-valuenow={persen}
                                className="h-2 overflow-hidden rounded-full bg-brand-gelap-gulir"
                            >
                                <div className="h-full rounded-full bg-aksen" style={{ width: `${String(persen)}%` }} />
                            </div>
                            <div className="flex justify-between gap-3 text-keterangan">
                                <span>{FormatTanggal(langganan.PeriodeMulai)}</span>
                                <span>{FormatTanggal(langganan.PeriodeSelesai)}</span>
                            </div>
                        </>
                    ) : akhir !== null ? (
                        <span className="text-keterangan">Berakhir {FormatTanggal(akhir)}</span>
                    ) : null}
                </div>
                <div className="flex flex-col gap-2 sm:flex-row lg:min-w-52 lg:flex-col">
                    <Tombol varian="aksen" onClick={saatPilihPaket}>
                        {berbayar ? 'Perpanjang sekarang' : 'Pilih paket'}
                    </Tombol>
                    {langganan.Status === 'Aktif' ? (
                        <Tombol varian="terang" onClick={saatPilihPaket}>
                            Lihat paket lain
                        </Tombol>
                    ) : null}
                </div>
            </div>
        </section>
    );
}
