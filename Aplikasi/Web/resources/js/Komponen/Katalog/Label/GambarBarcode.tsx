import { useMemo } from 'react';

import { SusunBarcode, SusunGarisBarcode, ZonaHeningModul } from '@/Pustaka/Barcode';

type PropsGambarBarcode = { kode: string; lebarMm: number; tinggiMm: number };

/**
 * PRD-10: barcode 1D (EAN-13 atau Code 128) sebagai SVG berukuran milimeter, hitam di atas putih, dengan zona hening
 * kiri-kanan. Tidak menggambar apa pun bila isinya tidak bisa dikodekan.
 */
export default function GambarBarcode({ kode, lebarMm, tinggiMm }: PropsGambarBarcode) {
    const hasil = useMemo(() => {
        try {
            const barcode = SusunBarcode(kode);

            return { Garis: SusunGarisBarcode(barcode.Modul), Total: barcode.Modul.length + ZonaHeningModul * 2 };
        } catch {
            return null;
        }
    }, [kode]);

    if (hasil === null) {
        return null;
    }

    return (
        <svg
            role="img"
            aria-label={`Barcode ${kode}`}
            viewBox={`0 0 ${String(hasil.Total)} 1`}
            preserveAspectRatio="none"
            shapeRendering="crispEdges"
            fill="currentColor"
            style={{ width: `${String(lebarMm)}mm`, height: `${String(tinggiMm)}mm`, display: 'block', flexShrink: 0 }}
        >
            {hasil.Garis.map((garis) => (
                <rect key={garis.Mulai} x={garis.Mulai + ZonaHeningModul} y={0} width={garis.Lebar} height={1} />
            ))}
        </svg>
    );
}
