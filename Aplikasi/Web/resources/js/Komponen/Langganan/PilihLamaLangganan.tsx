import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';

export type AturanLamaLangganan = {
    MinimalBulan: number;
    MaksimalBulan: number;
    TanggaDiskon: { DariBulan: number; Persen: string }[];
};

export type HargaPerLama = {
    JumlahBulan: number;
    Subtotal: string;
    HargaPenuh: string;
    DiskonPersen: string;
};

/**
 * Pilihan cepat lama langganan dari aturan server (D-249): batas minimal, setiap anak tangga diskon, dan batas
 * maksimal. Tidak ada angka lama di sini, jadi mengubah aturan di konsol langsung mengubah pilihan ini.
 */
export function PilihanCepatLama(aturan: AturanLamaLangganan): number[] {
    const kandidat = [aturan.MinimalBulan, ...aturan.TanggaDiskon.map((anak) => anak.DariBulan), aturan.MaksimalBulan];

    return [...new Set(kandidat.filter((bulan) => bulan >= aturan.MinimalBulan && bulan <= aturan.MaksimalBulan))].sort(
        (a, b) => a - b,
    );
}

/** Mengubah isian teks menjadi lama bulat dalam rentang aturan; null bila belum sah. */
export function UraiLama(teks: string, aturan: AturanLamaLangganan): number | null {
    if (!/^\d{1,3}$/.test(teks.trim())) {
        return null;
    }

    const bulan = Number(teks.trim());

    return bulan >= aturan.MinimalBulan && bulan <= aturan.MaksimalBulan ? bulan : null;
}

export function CariHargaLama(daftar: HargaPerLama[], bulan: number | null): HargaPerLama | null {
    return bulan === null ? null : (daftar.find((harga) => harga.JumlahBulan === bulan) ?? null);
}

function KalimatRentang(aturan: AturanLamaLangganan): string {
    return aturan.MinimalBulan === aturan.MaksimalBulan
        ? `Lama langganan ${aturan.MinimalBulan} bulan.`
        : `Isi bebas ${aturan.MinimalBulan} sampai ${aturan.MaksimalBulan} bulan.`;
}

/**
 * Input lama langganan bebas (D-249): ketik jumlah bulan atau pilih cepat. Harga yang tampil datang dari server
 * (`HargaPerLama`), bukan dihitung di peramban, sehingga sama persis dengan yang ditagihkan.
 */
export default function PilihLamaLangganan({
    aturan,
    nilai,
    saatBerubah,
    harga,
    galat,
}: {
    aturan: AturanLamaLangganan;
    nilai: string;
    saatBerubah: (nilai: string) => void;
    harga: HargaPerLama[];
    galat?: string | undefined;
}) {
    const bulan = UraiLama(nilai, aturan);
    const terpilih = CariHargaLama(harga, bulan);
    const hemat = terpilih !== null && terpilih.DiskonPersen !== '0';
    const pilihanCepat = PilihanCepatLama(aturan);

    return (
        <div className="flex flex-col gap-2">
            <BidangTeks
                label="Lama langganan (bulan)"
                nilai={nilai}
                saatBerubah={(teks) => saatBerubah(teks.replace(/\D/g, '').slice(0, 3))}
                inputMode="numeric"
                maxLength={3}
                keterangan={KalimatRentang(aturan)}
                galat={galat ?? (nilai !== '' && bulan === null ? KalimatRentang(aturan) : undefined)}
                required
            />
            {pilihanCepat.length > 1 ? (
                <div className="flex flex-wrap gap-2" role="group" aria-label="Pilihan cepat lama langganan">
                    {pilihanCepat.map((pilihan) => (
                        <Tombol
                            key={pilihan}
                            varian={pilihan === bulan ? 'utama' : 'sekunder'}
                            aria-pressed={pilihan === bulan}
                            onClick={() => saatBerubah(String(pilihan))}
                        >
                            {`${pilihan} bulan`}
                        </Tombol>
                    ))}
                </div>
            ) : null}
            {terpilih !== null ? (
                <p className="flex flex-wrap items-center gap-2 text-isi text-teks-utama">
                    <span>
                        {`${terpilih.JumlahBulan} bulan: `}
                        <strong className="tabular-nums">{FormatRupiah(terpilih.Subtotal)}</strong>
                    </span>
                    {hemat ? (
                        <>
                            <span className="text-teks-sekunder line-through tabular-nums">
                                {FormatRupiah(terpilih.HargaPenuh)}
                            </span>
                            <LabelStatus jenis="sukses" teks={`Hemat ${terpilih.DiskonPersen}%`} />
                        </>
                    ) : null}
                </p>
            ) : null}
        </div>
    );
}
