import { BagianRingkasan, BarisRingkasan, TampilYaTidak } from '@/Komponen/Formulir/Wizard/RingkasanWizard';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import type { KanalKampanye, OpsiKampanye, PratinjauKampanye, SegmenKampanye } from '@/Tipe/Kampanye';

import {
    ContohIsiKampanye,
    type IsianKampanye,
    type KunciLangkahKampanye,
    type LangkahKampanye,
} from './LangkahKampanye';

type PropsRingkasanKampanye = {
    isian: IsianKampanye;
    kanal: KanalKampanye;
    labelKanal: string;
    kanalAktif: boolean;
    segmen: SegmenKampanye;
    opsiRfm: OpsiKampanye[];
    opsiTier: OpsiKampanye[];
    opsiTag: OpsiKampanye[];
    pratinjau: PratinjauKampanye | undefined;
    langkah: LangkahKampanye[];
    saatUbah: (kunci: KunciLangkahKampanye) => void;
};

const Sebut = (terpilih: string[] | undefined, opsi: OpsiKampanye[]): string =>
    (terpilih ?? []).map((nilai) => opsi.find((item) => item.Nilai === nilai)?.Label ?? nilai).join(', ');

/** Ringkasan baca-saja langkah Tinjau kampanye: kanal, penerima dengan jumlahnya, dan contoh pesan. */
export default function RingkasanKampanye({
    isian,
    labelKanal,
    kanalAktif,
    segmen,
    opsiRfm,
    opsiTier,
    opsiTag,
    pratinjau,
    langkah,
    saatUbah,
}: PropsRingkasanKampanye) {
    const Cari = (kunci: KunciLangkahKampanye) => langkah.find((item) => item.Kunci === kunci);
    const langkahKampanye = Cari('Kampanye');
    const langkahPenerima = Cari('Penerima');
    const langkahPesan = Cari('Pesan');
    const jumlah = pratinjau?.JumlahPenerima;

    return (
        <div className="flex flex-col gap-4">
            {jumlah === 0 ? (
                <Pemberitahuan jenis="peringatan" judul="Belum ada pelanggan yang cocok">
                    Draf tetap bisa disimpan, tetapi belum ada yang akan menerimanya. Longgarkan saringan di langkah
                    Penerima.
                </Pemberitahuan>
            ) : null}
            {!kanalAktif ? (
                <Pemberitahuan jenis="peringatan" judul="Pengiriman belum aktif">
                    {labelKanal} belum aktif untuk usaha ini. Draf tetap bisa disimpan, tetapi belum bisa dikirim.
                </Pemberitahuan>
            ) : null}
            {langkahKampanye ? (
                <BagianRingkasan langkah={langkahKampanye} saatUbah={saatUbah}>
                    <BarisRingkasan nama="Nama kampanye">{isian.Nama}</BarisRingkasan>
                    <BarisRingkasan nama="Kanal">{labelKanal}</BarisRingkasan>
                </BagianRingkasan>
            ) : null}
            {langkahPenerima ? (
                <BagianRingkasan langkah={langkahPenerima} saatUbah={saatUbah}>
                    <BarisRingkasan nama="Segmen belanja">
                        {(segmen.Rfm ?? []).length === 0 ? 'Semua segmen' : Sebut(segmen.Rfm, opsiRfm)}
                    </BarisRingkasan>
                    {opsiTier.length > 0 ? (
                        <BarisRingkasan nama="Tier">
                            {(segmen.UuidTier ?? []).length === 0 ? 'Semua tier' : Sebut(segmen.UuidTier, opsiTier)}
                        </BarisRingkasan>
                    ) : null}
                    {opsiTag.length > 0 ? (
                        <BarisRingkasan nama="Tag">
                            {(segmen.Tag ?? []).length === 0 ? 'Semua tag' : Sebut(segmen.Tag, opsiTag)}
                        </BarisRingkasan>
                    ) : null}
                    <BarisRingkasan nama="Hanya ulang tahun bulan ini">
                        {TampilYaTidak(segmen.UlangTahunBulanIni ?? false)}
                    </BarisRingkasan>
                    <BarisRingkasan nama="Perkiraan penerima">
                        {jumlah === undefined ? (
                            'Menghitung…'
                        ) : (
                            <span className="font-semibold tabular-nums">{String(jumlah)} pelanggan</span>
                        )}
                    </BarisRingkasan>
                </BagianRingkasan>
            ) : null}
            {langkahPesan ? (
                <BagianRingkasan langkah={langkahPesan} saatUbah={saatUbah}>
                    <BarisRingkasan nama="Contoh untuk pelanggan bernama Budi">
                        <span className="whitespace-pre-wrap">{ContohIsiKampanye(isian.Isi)}</span>
                    </BarisRingkasan>
                </BagianRingkasan>
            ) : null}
        </div>
    );
}
