import './Gaya/Dasbor.css';
import DialogKonfirmasiDuaFaktor from '@/Komponen/Pengelola/DialogKonfirmasiDuaFaktor';
import { PasangPenandaNavigasi } from '@/Pustaka/PenandaNavigasi';
import { TutupLayarMuat } from '@/Pustaka/LayarMuat';

import { createInertiaApp } from '@inertiajs/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { StrictMode, type ComponentType } from 'react';
import { createRoot } from 'react-dom/client';

import { BuatKlienKueri } from './Pustaka/KlienKueri';

/*
 * Entry Platform Pengelola (PRD §13.8). Bundle terpisah: kode pengelola tidak pernah terkirim ke browser tenant,
 * dan bundle ini hanya memuat halaman di Halaman/Pengelola/.
 */
const klienKueri = BuatKlienKueri();
const daftarHalaman = import.meta.glob<{ default: ComponentType }>('./Halaman/Pengelola/**/*.tsx');

async function MuatHalaman(nama: string): Promise<ComponentType> {
    const MuatModul = daftarHalaman[`./Halaman/${nama}.tsx`];

    if (!MuatModul || !nama.startsWith('Pengelola/')) {
        throw new Error(`Halaman Pengelola tidak ditemukan: ${nama}`);
    }

    return (await MuatModul()).default;
}

void createInertiaApp({
    title: (judul) => (judul ? `${judul} | Pengelola` : 'Platform Pengelola'),
    resolve: MuatHalaman,
    setup({ el, App, props }) {
        PasangPenandaNavigasi();
        TutupLayarMuat();
        createRoot(el).render(
            <StrictMode>
                <QueryClientProvider client={klienKueri}>
                    <App {...props} />
                    {/* Dipasang di akar (bukan di tata letak) supaya hidup di semua halaman konsol dan selama pindah halaman. */}
                    <DialogKonfirmasiDuaFaktor />
                </QueryClientProvider>
            </StrictMode>,
        );
    },
    progress: false,
});
