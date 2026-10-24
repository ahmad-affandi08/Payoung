import type { ReactNode } from 'react';

import { JalurGulirTab, kelasDaftarTabPanel, kelasItemTabPanel } from '@/Komponen/Navigasi/TabTautan';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Komponen/Ui/tabs';

export type ItemTab<K extends string> = { Kunci: K; Label: string; AdaGalat?: boolean };

type PropsDaftarTab<K extends string> = {
    label: string;
    tab: ItemTab<K>[];
    aktif: K;
    saatPilih: (kunci: K) => void;
    /** Isi panel per kunci; panel tidak aktif disembunyikan (nilai isian tetap tersimpan). */
    panel: Record<K, ReactNode>;
};

/**
 * Tab dalam satu formulir (Tabs shadcn, pola ARIA tabs): panah kiri/kanan, Home, End berpindah tab.
 * Semua panel tetap terpasang (tersembunyi) agar galat & fokus isian di tab lain bisa dijangkau.
 * Tab dengan galat diberi teks "perlu diperbaiki" (bukan warna saja).
 */
export default function DaftarTab<K extends string>({ label, tab, aktif, saatPilih, panel }: PropsDaftarTab<K>) {
    const Pilih = (nilai: string) => {
        const item = tab.find((kandidat) => kandidat.Kunci === nilai);

        if (item) {
            saatPilih(item.Kunci);
        }
    };

    return (
        <Tabs value={aktif} onValueChange={Pilih} className="gap-4">
            <JalurGulirTab>
                <TabsList aria-label={label} className={kelasDaftarTabPanel}>
                    {tab.map((item) => (
                        <TabsTrigger
                            key={item.Kunci}
                            value={item.Kunci}
                            // Klik tanpa mousedown (pembaca layar, test) tetap memilih tab.
                            onClick={() => saatPilih(item.Kunci)}
                            className={kelasItemTabPanel}
                        >
                            {item.Label}
                            {item.AdaGalat ? <span className="ml-1 text-bahaya">(perlu diperbaiki)</span> : null}
                        </TabsTrigger>
                    ))}
                </TabsList>
            </JalurGulirTab>
            {tab.map((item) => (
                <TabsContent
                    key={item.Kunci}
                    value={item.Kunci}
                    forceMount
                    hidden={item.Kunci !== aktif}
                    tabIndex={-1}
                    className="flex flex-col gap-4"
                >
                    {panel[item.Kunci]}
                </TabsContent>
            ))}
        </Tabs>
    );
}
