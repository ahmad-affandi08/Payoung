import { useEffect, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

import type { TataHalaman } from '@/Pustaka/UkuranLabel';

/**
 * PRD-10: area yang benar-benar dicetak. Dipasang sebagai anak langsung `<body>` (di luar `#app`) dan disembunyikan di
 * layar; saat mencetak, semua anak `<body>` lain disembunyikan dan `@page` memakai ukuran kertas dari media yang
 * dipilih, jadi hasil cetak hanya berisi stiker (tanpa menu, judul, atau kontrol halaman).
 */
export default function AreaCetakLabel({ tata, children }: { tata: TataHalaman; children: ReactNode }) {
    // Elemen dibuat sekali (belum dipasang); dipasang ke <body> di efek dan dilepas saat halaman ditinggalkan.
    const [wadah] = useState<HTMLElement | null>(() => {
        if (typeof document === 'undefined') {
            return null;
        }
        const elemen = document.createElement('div');
        elemen.id = 'area-cetak-label';

        return elemen;
    });

    useEffect(() => {
        if (wadah === null) {
            return;
        }
        document.body.appendChild(wadah);

        return () => {
            wadah.remove();
        };
    }, [wadah]);

    if (wadah === null) {
        return null;
    }

    return createPortal(
        <>
            <style>{`
#area-cetak-label { display: none; }
@media print {
    @page { size: ${String(tata.LebarHalamanMm)}mm ${String(tata.TinggiHalamanMm)}mm; margin: 0; }
    html, body { margin: 0 !important; padding: 0 !important; background: var(--color-permukaan) !important; }
    body > *:not(#area-cetak-label) { display: none !important; }
    #area-cetak-label { display: block !important; }
}`}</style>
            {children}
        </>,
        wadah,
    );
}
