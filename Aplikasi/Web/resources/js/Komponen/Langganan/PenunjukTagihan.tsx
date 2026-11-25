import { Check } from 'lucide-react';

import { cn } from '@/Komponen/Ui/utils';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { JenisLabelTagihan, type StatusTagihanLangganan } from '@/Tipe/TagihanLangganan';

const langkah = ['Tagihan terbit', 'Menunggu pembayaran', 'Lunas', 'Layanan aktif'] as const;

/** Langkah yang sedang berjalan (indeks), atau null bila tagihan berakhir di luar alur normal (batal, hapus-buku, refund). */
export function IndeksLangkahTagihan(status: StatusTagihanLangganan): number | null {
    switch (status) {
        case 'Draf':
            return 0;
        case 'Terbit':
        case 'JatuhTempo':
            return 1;
        case 'Lunas':
            return langkah.length;
        default:
            return null;
    }
}

type PropsPenunjukTagihan = { status: StatusTagihanLangganan; labelStatus: string };

/**
 * Penunjuk perjalanan tagihan (D-332): terbit, menunggu pembayaran, lunas, layanan aktif. Di kolom sempit (container
 * kurang dari 42rem) tampil ringkas "Langkah 2 dari 4" dengan batang kemajuan, supaya muat di 360px. Tagihan yang dibatalkan, dihapus-buku, atau
 * dikembalikan tidak punya alur lanjutan, jadi cukup menampilkan statusnya.
 */
export default function PenunjukTagihan({ status, labelStatus }: PropsPenunjukTagihan) {
    const indeks = IndeksLangkahTagihan(status);

    if (indeks === null) {
        return (
            <div className="flex items-center gap-2 rounded-panel border border-garis bg-permukaan px-4 py-3">
                <span className="text-isi text-teks-sekunder">Status tagihan</span>
                <LabelStatus jenis={JenisLabelTagihan(status)} teks={labelStatus} />
            </div>
        );
    }

    const nomor = Math.min(indeks + 1, langkah.length);

    return (
        <div className="@container">
            <div className="flex flex-col gap-2 rounded-panel border border-garis bg-permukaan px-4 py-3 @2xl:hidden">
                <span className="text-label font-semibold text-teks-utama">
                    Langkah {nomor} dari {langkah.length}: {langkah[nomor - 1]}
                </span>
                <div aria-hidden="true" className="flex gap-1">
                    {langkah.map((nama, urutan) => (
                        <span
                            key={nama}
                            className={cn('h-1.5 flex-1 rounded-full', urutan < nomor ? 'bg-brand' : 'bg-garis')}
                        />
                    ))}
                </div>
            </div>
            <ol
                aria-label="Perjalanan tagihan"
                className="hidden items-center gap-3 rounded-panel border border-garis bg-permukaan px-4 py-3 @2xl:flex"
            >
                {langkah.map((nama, urutan) => {
                    const selesai = urutan < indeks;
                    const sedang = urutan === indeks;

                    return (
                        <li
                            key={nama}
                            aria-current={sedang ? 'step' : undefined}
                            className={cn('flex items-center gap-2', urutan < langkah.length - 1 ? 'flex-1' : '')}
                        >
                            <span
                                className={cn(
                                    'grid size-7 shrink-0 place-items-center rounded-full border-2 text-keterangan font-semibold',
                                    selesai
                                        ? 'border-brand bg-brand text-brand-teks'
                                        : sedang
                                          ? 'border-brand bg-permukaan text-brand'
                                          : 'border-garis-input bg-permukaan text-teks-sekunder',
                                )}
                            >
                                {selesai ? <Check aria-hidden="true" className="size-4" /> : urutan + 1}
                            </span>
                            <span
                                className={cn(
                                    'text-label font-semibold whitespace-nowrap',
                                    selesai || sedang ? 'text-teks-utama' : 'text-teks-sekunder',
                                )}
                            >
                                {nama}
                            </span>
                            {urutan < langkah.length - 1 ? (
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'h-0.5 min-w-4 flex-1 rounded-full',
                                        selesai ? 'bg-brand' : 'bg-garis',
                                    )}
                                />
                            ) : null}
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}
