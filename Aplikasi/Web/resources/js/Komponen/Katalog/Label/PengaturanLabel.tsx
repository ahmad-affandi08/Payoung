import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import { CariMedia, type PreferensiLabel } from '@/Komponen/Katalog/Label/BantuanLabel';
import {
    KodeMediaKustomA4,
    KodeMediaKustomGulung,
    MaksGeserMm,
    MediaBawaan,
    type IsiLabel,
    type MediaLabel,
} from '@/Pustaka/UkuranLabel';

const opsiMedia = [
    ...MediaBawaan.map((m) => ({ Nilai: m.Kode, Label: m.Nama })),
    { Nilai: KodeMediaKustomA4, Label: 'A4 | ukuran stiker sendiri' },
    { Nilai: KodeMediaKustomGulung, Label: 'Gulung | ukuran sendiri' },
];

function UraiAngka(teks: string): number {
    return teks.trim() === '' ? Number.NaN : Number(teks.replace(',', '.'));
}

/** Isian angka yang boleh diketik bebas ("5," sementara): angka baru dikirim ke induk hanya bila sah. */
function BidangAngka({
    label,
    nilai,
    saatBerubah,
    keterangan,
}: {
    label: string;
    nilai: number;
    saatBerubah: (nilai: number) => void;
    keterangan?: string;
}) {
    const [teks, AturTeks] = useState(String(nilai));

    // Teks yang sedang diketik ("5," atau kosong) dipertahankan selama masih bermakna sama dengan nilai induk.
    const urai = UraiAngka(teks);
    const tampil = urai === nilai || (Number.isNaN(urai) && Number.isNaN(nilai)) ? teks : String(nilai);

    return (
        <BidangTeks
            label={label}
            nilai={tampil}
            inputMode="decimal"
            maxLength={7}
            {...(keterangan === undefined ? {} : { keterangan })}
            saatBerubah={(baru) => {
                AturTeks(baru);
                const angka = UraiAngka(baru);
                saatBerubah(Number.isFinite(angka) ? angka : Number.NaN);
            }}
        />
    );
}

type PropsPengaturanLabel = {
    preferensi: PreferensiLabel;
    saatUbah: (perubahan: Partial<PreferensiLabel>) => void;
    /** A4: jumlah stiker pertama yang dilewati. */
    lewati: number;
    saatLewati: (jumlah: number) => void;
    perHalaman: number;
};

const labelIsi: { kunci: keyof IsiLabel; label: string }[] = [
    { kunci: 'Nama', label: 'Nama barang' },
    { kunci: 'Sku', label: 'SKU' },
    { kunci: 'Harga', label: 'Harga' },
    { kunci: 'Satuan', label: 'Satuan di samping harga' },
    { kunci: 'Barcode', label: 'Barcode' },
];

/** PRD-10: pilihan media (A4 stiker atau label gulung), ukuran sendiri, isi stiker, dan kalibrasi posisi cetak. */
export default function PengaturanLabel({
    preferensi,
    saatUbah,
    lewati,
    saatLewati,
    perHalaman,
}: PropsPengaturanLabel) {
    const media = CariMedia(preferensi.KodeMedia, preferensi.Kustom);
    const kustom = preferensi.KodeMedia === KodeMediaKustomA4 || preferensi.KodeMedia === KodeMediaKustomGulung;
    const a4 = media.Jenis === 'A4';

    const UbahKustom = (perubahan: Partial<MediaLabel>) =>
        saatUbah({ Kustom: { ...preferensi.Kustom, [media.Jenis]: { ...media, ...perubahan } } });

    return (
        <div className="flex flex-col gap-4">
            <BidangPilihan
                label="Ukuran dan jenis kertas label"
                nilai={preferensi.KodeMedia}
                opsi={opsiMedia}
                saatBerubah={(kode) => saatUbah({ KodeMedia: kode })}
            />

            {kustom ? (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <BidangAngka
                        label={a4 ? 'Stiker per baris (kolom)' : 'Stiker berdampingan'}
                        nilai={media.Kolom}
                        saatBerubah={(kolom) => UbahKustom({ Kolom: kolom })}
                    />
                    {a4 ? (
                        <BidangAngka
                            label="Baris per lembar"
                            nilai={media.Baris}
                            saatBerubah={(baris) => UbahKustom({ Baris: baris })}
                        />
                    ) : null}
                    <BidangAngka
                        label="Lebar stiker (mm)"
                        nilai={media.LebarMm}
                        saatBerubah={(lebar) => UbahKustom({ LebarMm: lebar })}
                    />
                    <BidangAngka
                        label="Tinggi stiker (mm)"
                        nilai={media.TinggiMm}
                        saatBerubah={(tinggi) => UbahKustom({ TinggiMm: tinggi })}
                    />
                    <BidangAngka
                        label="Jarak antarkolom (mm)"
                        nilai={media.JarakXMm}
                        saatBerubah={(jarak) => UbahKustom({ JarakXMm: jarak })}
                    />
                    {a4 ? (
                        <BidangAngka
                            label="Jarak antarbaris (mm)"
                            nilai={media.JarakYMm}
                            saatBerubah={(jarak) => UbahKustom({ JarakYMm: jarak })}
                        />
                    ) : null}
                </div>
            ) : null}

            <fieldset className="flex flex-col gap-1">
                <legend className="text-label font-semibold text-teks-utama">Isi stiker</legend>
                <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
                    {labelIsi.map(({ kunci, label }) => (
                        <KotakCentang
                            key={kunci}
                            label={label}
                            nilai={preferensi.Isi[kunci]}
                            saatBerubah={(nilai) => saatUbah({ Isi: { ...preferensi.Isi, [kunci]: nilai } })}
                        />
                    ))}
                </div>
            </fieldset>

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {a4 ? (
                    <BidangAngka
                        label="Lewati stiker pertama"
                        nilai={lewati}
                        keterangan={`Untuk lembar yang sebagian stikernya sudah terpakai (0 sampai ${String(perHalaman - 1)}).`}
                        saatBerubah={(jumlah) => saatLewati(Number.isFinite(jumlah) ? Math.trunc(jumlah) : 0)}
                    />
                ) : null}
                <BidangAngka
                    label="Geser ke kanan (mm)"
                    nilai={preferensi.GeserXMm}
                    keterangan={`Kalibrasi printer, -${String(MaksGeserMm)} sampai ${String(MaksGeserMm)}.`}
                    saatBerubah={(mm) => saatUbah({ GeserXMm: mm })}
                />
                <BidangAngka
                    label="Geser ke bawah (mm)"
                    nilai={preferensi.GeserYMm}
                    saatBerubah={(mm) => saatUbah({ GeserYMm: mm })}
                />
            </div>
        </div>
    );
}
