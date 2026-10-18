import { Check } from 'lucide-react';
import { RadioGroup as RadioGroupPrimitive } from 'radix-ui';
import { useId } from 'react';

import { cn } from '@/Komponen/Ui/utils';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { AmbilTampilanSektor } from '@/Pustaka/TampilanSektor';
import type { TemplatePilihan } from '@/Tipe/PanduanAwal';

/** Kategori awal yang ditampilkan sebagai chip; sisanya diringkas "+N lainnya". */
const BATAS_KATEGORI_TAMPIL = 6;

type PropsKartuSektor = {
    template: TemplatePilihan;
    terpilih: boolean;
    diterapkan: boolean;
    tandaiTidakValid: boolean;
};

/**
 * Satu pilihan jenis usaha (langkah Jenis usaha panduan awal, F-01): baris lebar dengan ilustrasi jenis usaha (D-178,
 * dekoratif: namanya selalu tertulis di sebelahnya); yang dipilih membuka rincian apa yang akan disiapkan (kategori, akun, produk contoh, fitur kasir).
 * Seluruh baris adalah satu radio, jadi bisa dipilih dengan ketukan di mana saja dan dengan tombol panah.
 */
export default function KartuSektor({ template, terpilih, diterapkan, tandaiTidakValid }: PropsKartuSektor) {
    const idKeterangan = useId();
    const { Ilustrasi } = AmbilTampilanSektor(template.Kode);

    return (
        <div
            className={cn(
                'rounded-panel border-2 bg-permukaan p-4 transition-colors',
                terpilih ? 'border-primary' : 'border-garis',
            )}
        >
            <RadioGroupPrimitive.Item
                value={template.Kode}
                aria-describedby={template.Keterangan ? idKeterangan : undefined}
                aria-invalid={tandaiTidakValid || undefined}
                className="flex w-full cursor-pointer items-center gap-4 rounded-panel text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:ring-[3px] aria-invalid:ring-bahaya/40"
            >
                <img
                    src={Ilustrasi}
                    alt=""
                    width={112}
                    height={112}
                    loading="lazy"
                    className="size-24 shrink-0 object-contain sm:size-28"
                />
                <span className="flex min-w-0 flex-1 flex-col gap-1">
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="text-subjudul font-semibold break-words text-teks-utama">{template.Nama}</span>
                        {diterapkan ? <LabelStatus jenis="sukses" teks="Sedang dipakai" /> : null}
                    </span>
                    {template.Keterangan ? (
                        <span id={idKeterangan} className="text-isi text-teks-sekunder">
                            {template.Keterangan}
                        </span>
                    ) : null}
                </span>
                <span
                    aria-hidden="true"
                    className={cn(
                        'flex size-7 shrink-0 items-center justify-center rounded-full border-2',
                        terpilih ? 'border-aksen bg-aksen text-brand-gelap' : 'border-garis-input bg-permukaan',
                    )}
                >
                    {terpilih ? <Check className="size-4" strokeWidth={3} /> : null}
                </span>
            </RadioGroupPrimitive.Item>
            {terpilih ? <RincianSektor template={template} /> : null}
        </div>
    );
}

/** Isi template yang akan disiapkan, dibuka di bawah kartu yang dipilih. */
function RincianSektor({ template }: { template: TemplatePilihan }) {
    const kategoriTampil = template.Kategori.slice(0, BATAS_KATEGORI_TAMPIL);
    const sisaKategori = template.Kategori.length - kategoriTampil.length;
    const fiturTerkunci = template.Fitur.filter((fitur) => !fitur.TersediaDiPaket);

    return (
        <div className="mt-4 flex flex-wrap gap-x-8 gap-y-5 rounded-panel bg-brand-lembut p-4">
            <section className="flex min-w-0 flex-[1_1_15rem] flex-col gap-2" aria-label="Kategori awal">
                <h4 className="text-label font-semibold text-teks-sekunder">Kategori awal</h4>
                {kategoriTampil.length > 0 ? (
                    <ul className="flex flex-wrap gap-1.5">
                        {kategoriTampil.map((kategori) => (
                            <li
                                key={kategori}
                                className="rounded-full bg-permukaan px-2.5 py-1 text-isi break-words text-teks-utama"
                            >
                                {kategori}
                            </li>
                        ))}
                        {sisaKategori > 0 ? (
                            <li className="rounded-full px-2.5 py-1 text-isi text-teks-sekunder">
                                +{sisaKategori} lainnya
                            </li>
                        ) : null}
                    </ul>
                ) : (
                    <p className="text-isi text-teks-sekunder">Belum ada</p>
                )}
            </section>

            <section className="flex min-w-0 flex-[1_1_11rem] flex-col gap-2" aria-label="Yang disiapkan">
                <h4 className="text-label font-semibold text-teks-sekunder">Disiapkan</h4>
                <p className="text-isi text-teks-utama">
                    <span className="tabular-nums">{template.JumlahAkun}</span> akun pembukuan
                    <br />
                    {template.JumlahProdukContoh > 0 ? (
                        <>
                            <span className="tabular-nums">{template.JumlahProdukContoh}</span> produk contoh
                        </>
                    ) : (
                        'Belum ada produk contoh'
                    )}
                </p>
                {template.ModeKasir.length > 0 ? (
                    <p className="text-isi text-teks-sekunder">
                        Mode kasir: {template.ModeKasir.map((mode) => mode.Label).join(', ')}
                    </p>
                ) : null}
            </section>

            {template.Fitur.length > 0 ? (
                <details open className="flex min-w-0 flex-[1_1_18rem] flex-col gap-2">
                    <summary className="cursor-pointer rounded-sm text-label font-semibold text-brand outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50">
                        {template.Fitur.length} fitur kasir
                        {fiturTerkunci.length > 0 ? `, ${String(fiturTerkunci.length)} butuh paket lebih tinggi` : ''}
                    </summary>
                    <ul className="mt-2 flex flex-col gap-2">
                        {template.Fitur.map((fitur) => (
                            <li
                                key={fitur.Kunci}
                                className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-isi text-teks-utama"
                            >
                                <span className="break-words">{fitur.Nama}</span>
                                {fitur.TersediaDiPaket ? (
                                    <LabelStatus jenis="sukses" teks="Tersedia di paket Anda" />
                                ) : (
                                    <LabelStatus jenis="peringatan" teks="Butuh paket lebih tinggi" />
                                )}
                            </li>
                        ))}
                    </ul>
                </details>
            ) : null}

            <p className="w-full text-keterangan text-teks-sekunder">Versi template {template.Versi}</p>
        </div>
    );
}
