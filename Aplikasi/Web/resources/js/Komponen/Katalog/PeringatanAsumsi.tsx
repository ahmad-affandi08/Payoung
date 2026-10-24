import type { ReactNode } from 'react';

import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';

/**
 * Peringatan preset impor yang nama kolomnya masih asumsi (majoo, Moka, Pawoon; DesainF03 H.10).
 * Bilah status bersama, jadi tampil sama dengan pemberitahuan lain.
 */
export default function PeringatanAsumsi({ children }: { children: ReactNode }) {
    return (
        <Pemberitahuan jenis="peringatan" judul="Periksa pemetaan kolom sebelum mengimpor">
            {children}
        </Pemberitahuan>
    );
}
