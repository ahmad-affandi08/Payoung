import { usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';

import TeksKaya from '@/Komponen/Situs/TeksKaya';
import TombolSitus from '@/Komponen/Situs/TombolSitus';
import { cn } from '@/Komponen/Ui/utils';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { BagianSitus, DataSitus, PaketHarga } from '@/Tipe/Situs';

import { CekGelap, KepalaBagian, type LatarBagian, WadahBagian } from './KepalaBagian';

type Periode = 'Bulanan' | 'Tahunan';

function CekNol(nilai: string): boolean {
    return /^0+(\.0+)?$/.test(nilai);
}

function TampilkanHarga({ paket, periode }: { paket: PaketHarga; periode: Periode }) {
    if (paket.HargaNegosiasi) {
        return <p className="text-judul font-bold text-teks-utama">Hubungi kami</p>;
    }

    const nilai = periode === 'Tahunan' ? paket.HargaTahunan : paket.HargaBulanan;

    if (nilai === null) {
        return null;
    }

    if (CekNol(nilai)) {
        return <p className="text-tampilan font-bold text-teks-utama">Gratis</p>;
    }

    const promo = paket.Promo;
    const normal = promo === null ? null : periode === 'Tahunan' ? promo.HargaTahunanNormal : promo.HargaBulananNormal;

    return (
        <div className="flex flex-col gap-1">
            {promo !== null && normal !== null ? (
                <p className="flex flex-wrap items-center gap-2">
                    <span className="text-keterangan rounded-full bg-aksen px-2.5 py-0.5 font-semibold text-teks-utama">
                        Diskon peluncuran {promo.PersenDiskon}%
                    </span>
                    <span className="text-isi text-teks-sekunder line-through">{FormatRupiah(normal)}</span>
                </p>
            ) : null}
            <p className="flex flex-wrap items-baseline gap-1">
                <span className="text-tampilan font-bold text-teks-utama">{FormatRupiah(nilai)}</span>
                <span className="text-isi text-teks-sekunder">/{periode === 'Tahunan' ? 'tahun' : 'bulan'}</span>
            </p>
            {promo !== null ? (
                <p className="text-label text-teks-sekunder">
                    Promo sampai {FormatTanggal(promo.BerlakuSampai)}.
                    {promo.HargaTerkunci ? ' Daftar sebelum itu, harga ini terkunci selama langganan Anda aktif.' : ''}
                </p>
            ) : null}
        </div>
    );
}

/**
 * Harga paket otomatis dari konsol (P-04): harga terbit yang berlaku hari ini, batas, fitur. Pengelola hanya mengatur
 * judul, paket yang disorot, teks tombol, dan catatan kaki. Harga sudah termasuk/tidak termasuk PPN ditulis di catatan.
 */
export default function BagianHarga({
    bagian,
    latar,
    garisAtas,
}: {
    bagian: Extract<BagianSitus, { Jenis: 'Harga' }>;
    latar: LatarBagian;
    garisAtas?: boolean | undefined;
}) {
    const { props } = usePage<{ Situs: DataSitus }>();
    const [periode, AturPeriode] = useState<Periode>('Bulanan');
    const adaTahunan =
        bagian.TampilkanTahunan && bagian.Paket.some((p) => p.HargaTahunan !== null && !CekNol(p.HargaTahunan));
    const tautanKontak = props.Situs.Kontak.TautanWhatsApp ?? '/kontak';

    return (
        <WadahBagian latar={latar} id="harga" garisAtas={garisAtas}>
            <KepalaBagian
                label={bagian.Label}
                judul={bagian.Judul}
                subjudul={bagian.Subjudul}
                gelap={CekGelap(latar)}
            />
            {adaTahunan ? (
                <div className="mb-10 flex justify-center">
                    <div
                        role="radiogroup"
                        aria-label="Periode tagihan"
                        className="inline-flex rounded-full border border-garis bg-permukaan p-1"
                    >
                        {(['Bulanan', 'Tahunan'] as const).map((p) => (
                            <button
                                key={p}
                                type="button"
                                role="radio"
                                aria-checked={periode === p}
                                onClick={() => AturPeriode(p)}
                                className={cn(
                                    'min-h-10 rounded-full px-5 text-isi font-semibold',
                                    periode === p
                                        ? 'bg-brand text-brand-teks'
                                        : 'text-teks-utama hover:bg-permukaan-sorot',
                                )}
                            >
                                {p}
                            </button>
                        ))}
                    </div>
                </div>
            ) : null}
            {bagian.Paket.length === 0 ? (
                <p className="text-isi text-teks-sekunder">
                    Harga paket sedang diperbarui. Hubungi kami untuk informasi terbaru.
                </p>
            ) : (
                <ul className="grid gap-4 md:grid-cols-2 lg:grid-cols-[repeat(auto-fit,minmax(16rem,1fr))]">
                    {bagian.Paket.map((paket) => {
                        const disorot = paket.Kode === bagian.PaketDisorot;

                        return (
                            <li
                                key={paket.Kode}
                                className={cn(
                                    'relative flex flex-col gap-5 rounded-3xl border bg-permukaan p-6',
                                    disorot ? 'border-2 border-aksen ring-4 ring-aksen/25' : 'border-garis',
                                )}
                            >
                                {disorot ? (
                                    <p className="text-keterangan absolute -top-3 left-6 rounded-full bg-aksen px-3 py-0.5 font-semibold text-teks-utama">
                                        Disarankan
                                    </p>
                                ) : null}
                                <div className="flex flex-col gap-1">
                                    <h3 className="text-judul font-semibold text-teks-utama">{paket.Nama}</h3>
                                    {paket.Keterangan ? (
                                        <p className="text-isi text-teks-sekunder">{paket.Keterangan}</p>
                                    ) : null}
                                </div>
                                <div key={periode} className="muncul-cepat flex flex-col gap-1">
                                    <TampilkanHarga paket={paket} periode={periode} />
                                    {periode === 'Tahunan' && paket.HematTahunan ? (
                                        <p className="text-label font-semibold text-sukses">
                                            Hemat {FormatRupiah(paket.HematTahunan)} per tahun
                                        </p>
                                    ) : null}
                                    {paket.MasaTrialHari > 0 ? (
                                        <p className="text-label text-teks-sekunder">
                                            Coba gratis {paket.MasaTrialHari} hari
                                        </p>
                                    ) : null}
                                </div>
                                <TombolSitus
                                    href={paket.HargaNegosiasi ? tautanKontak : bagian.TautanDaftar}
                                    varian={disorot ? 'aksen' : 'garis-merek'}
                                >
                                    {paket.HargaNegosiasi ? 'Hubungi kami' : (bagian.TeksTombol ?? 'Mulai sekarang')}
                                </TombolSitus>
                                <DaftarFiturPaket baris={[...paket.Batas, ...paket.Fitur]} />
                            </li>
                        );
                    })}
                </ul>
            )}
            {bagian.CatatanKaki ? (
                <TeksKaya teks={bagian.CatatanKaki} className="text-label mt-8 max-w-3xl text-teks-sekunder" />
            ) : null}
        </WadahBagian>
    );
}

/** Butir fitur yang langsung tampil per kartu; sisanya di balik "Lihat N fitur lainnya" (D-39). */
const FITUR_TAMPIL = 8;

function BarisFitur({ baris }: { baris: string }) {
    return (
        <li className="flex gap-2 text-isi text-teks-utama">
            <Check className="mt-0.5 size-4 shrink-0 text-sukses" aria-hidden />
            <span>{baris}</span>
        </li>
    );
}

/**
 * Daftar batas & fitur paket. Paket besar punya 20+ butir, sehingga kartu Gratis di sebelahnya menyisakan ruang
 * kosong setinggi layar. Delapan butir pertama tampil, sisanya dibuka dengan `<details>` (tetap ada di HTML untuk
 * mesin pencari dan pembaca layar, tanpa JavaScript).
 */
function DaftarFiturPaket({ baris }: { baris: string[] }) {
    const utama = baris.slice(0, FITUR_TAMPIL);
    const sisa = baris.slice(FITUR_TAMPIL);

    return (
        <div className="flex flex-col gap-2 border-t border-garis pt-4">
            <ul className="flex flex-col gap-2">
                {utama.map((b) => (
                    <BarisFitur key={b} baris={b} />
                ))}
            </ul>
            {sisa.length > 0 ? (
                <details className="group">
                    <summary className="cursor-pointer list-none text-label font-semibold text-brand hover:underline">
                        <span className="group-open:hidden">Lihat {sisa.length} fitur lainnya</span>
                        <span className="hidden group-open:inline">Sembunyikan fitur lainnya</span>
                    </summary>
                    <ul className="mt-2 flex flex-col gap-2">
                        {sisa.map((b) => (
                            <BarisFitur key={b} baris={b} />
                        ))}
                    </ul>
                </details>
            ) : null}
        </div>
    );
}
