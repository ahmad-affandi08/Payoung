import { AmbilBarcodeCetak, type BarisCetak } from '@/Komponen/Katalog/Label/BantuanLabel';
import GambarBarcode from '@/Komponen/Katalog/Label/GambarBarcode';
import { SusunBarcode } from '@/Pustaka/Barcode';
import { FormatRupiah } from '@/Pustaka/Format';
import { HitungLebarBarcodeMm, type IsiLabel, type MediaLabel, type TataIsiLabel } from '@/Pustaka/UkuranLabel';

type PropsStikerLabel = {
    baris: BarisCetak;
    isi: IsiLabel;
    media: MediaLabel;
    /** Tata isi untuk baris ini (dihitung dengan atau tanpa barcode sesuai baris). */
    tata: TataIsiLabel;
};

function JumlahModul(kode: string): number {
    try {
        return SusunBarcode(kode).Modul.length;
    } catch {
        return 0;
    }
}

/**
 * PRD-10: satu stiker, ukuran persis media (mm). Tanpa barcode (produk belum punya, atau barcode dimatikan) stiker
 * menjadi label harga biasa. Hitam di atas putih; tidak ada warna merek supaya terbaca di printer hitam-putih.
 */
export default function StikerLabel({ baris, isi, media, tata }: PropsStikerLabel) {
    const kode = isi.Barcode ? AmbilBarcodeCetak(baris) : null;
    const lebarDalamMm = media.LebarMm - tata.PadMm * 2;
    const harga = baris.Harga === null ? null : FormatRupiah(baris.Harga);

    return (
        <div
            data-slot="stiker-label"
            style={{
                width: `${String(media.LebarMm)}mm`,
                height: `${String(media.TinggiMm)}mm`,
                padding: `${String(tata.PadMm)}mm`,
                boxSizing: 'border-box',
                overflow: 'hidden',
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                textAlign: 'center',
                lineHeight: 1.15,
            }}
        >
            {isi.Nama ? (
                <div
                    style={{
                        width: '100%',
                        fontSize: `${String(tata.NamaPt)}pt`,
                        fontWeight: 600,
                        display: '-webkit-box',
                        WebkitLineClamp: tata.NamaBaris,
                        WebkitBoxOrient: 'vertical',
                        overflow: 'hidden',
                        overflowWrap: 'anywhere',
                    }}
                >
                    {baris.Nama}
                </div>
            ) : null}
            {isi.Sku && baris.Sku !== null ? (
                <div style={{ fontSize: `${String(tata.SkuPt)}pt`, fontFamily: 'var(--font-mono, monospace)' }}>
                    {baris.Sku}
                </div>
            ) : null}
            {isi.Harga ? (
                <div style={{ fontSize: `${String(tata.HargaPt)}pt`, fontWeight: 700, whiteSpace: 'nowrap' }}>
                    {harga ?? '—'}
                    {isi.Satuan && harga !== null ? (
                        <span style={{ fontSize: `${String(Math.max(6, tata.HargaPt * 0.6))}pt`, fontWeight: 500 }}>
                            {' '}
                            / {baris.SimbolSatuan}
                        </span>
                    ) : null}
                </div>
            ) : null}
            {kode !== null ? (
                <>
                    <GambarBarcode
                        kode={kode}
                        lebarMm={HitungLebarBarcodeMm(JumlahModul(kode), lebarDalamMm)}
                        tinggiMm={tata.TinggiBarcodeMm}
                    />
                    <div
                        style={{
                            fontSize: `${String(tata.KodePt)}pt`,
                            fontFamily: 'var(--font-mono, monospace)',
                            letterSpacing: '0.04em',
                        }}
                    >
                        {kode}
                    </div>
                </>
            ) : null}
        </div>
    );
}
