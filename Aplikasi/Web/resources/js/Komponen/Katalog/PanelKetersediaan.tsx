import { router } from '@inertiajs/react';
import { useState } from 'react';

import Panel from '@/Komponen/Kelola/Panel';
import Tombol from '@/Komponen/Formulir/Tombol';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import type { BarisKetersediaanOutlet, KanalKetersediaan } from '@/Tipe/Katalog';

type PropsPanelKetersediaan = {
    uuidProduk: string;
    baris: BarisKetersediaanOutlet[];
    bolehUbah: boolean;
};

const daftarKanal: { kunci: KanalKetersediaan; label: string }[] = [
    { kunci: 'Kasir', label: 'Kasir' },
    { kunci: 'TokoOnline', label: 'Toko online' },
    { kunci: 'SelfOrder', label: 'Self-order & kios' },
];

/**
 * F-17 BR-17.2 + PRD-11: tandai produk habis ("86") atau tersedia lagi per outlet, untuk semua kanal sekaligus atau satu
 * kanal saja (kasir, toko online, self-order & kios). Habis = hilang dari menu / tidak bisa ditambahkan di kanal itu pada
 * outlet itu; pesanan yang sudah masuk tidak berubah dan stok tidak disentuh.
 */
export default function PanelKetersediaan({ uuidProduk, baris, bolehUbah }: PropsPanelKetersediaan) {
    const [memproses, AturMemproses] = useState<string | null>(null);

    const Ubah = (item: BarisKetersediaanOutlet, habis: boolean, kanal?: KanalKetersediaan) =>
        router.post(
            `/kelola/produk/${uuidProduk}/habis`,
            kanal === undefined
                ? { UuidOutlet: item.UuidOutlet, Habis: habis }
                : { UuidOutlet: item.UuidOutlet, Habis: habis, Kanal: kanal },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(`${item.UuidOutlet}:${kanal ?? 'Semua'}`),
                onFinish: () => AturMemproses(null),
            },
        );

    return (
        <Panel
            judul="Ketersediaan per outlet"
            idJudul="judul-ketersediaan"
            keterangan="Tandai habis bila bahan atau barangnya kosong hari ini, untuk semua kanal atau hanya satu (kasir, toko online, self-order & kios). Produk hilang dari kanal itu di outlet itu; stok tidak berubah."
        >
            {baris.length === 0 ? (
                <p className="text-isi text-teks-sekunder">Belum ada outlet aktif.</p>
            ) : (
                <ul className="flex flex-col divide-y divide-garis">
                    {baris.map((item) => {
                        const sebagian = !item.Habis && Object.values(item.Kanal).some(Boolean);

                        return (
                            <li key={item.UuidOutlet} className="flex flex-col gap-2 py-2">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="font-semibold text-teks-utama">{item.NamaOutlet}</span>
                                        <LabelStatus
                                            jenis={item.Habis || sebagian ? 'peringatan' : 'sukses'}
                                            teks={item.Habis ? 'Habis' : sebagian ? 'Habis sebagian' : 'Tersedia'}
                                        />
                                    </span>
                                    {bolehUbah ? (
                                        <Tombol
                                            varian="sekunder"
                                            memproses={memproses === `${item.UuidOutlet}:Semua`}
                                            disabled={memproses !== null}
                                            onClick={() => Ubah(item, !item.Habis)}
                                            aria-label={`${item.Habis ? 'Tersedia lagi' : 'Tandai habis'} di ${item.NamaOutlet}`}
                                        >
                                            {item.Habis ? 'Tersedia lagi' : 'Tandai habis'}
                                        </Tombol>
                                    ) : null}
                                </div>
                                <ul aria-label={`Kanal di ${item.NamaOutlet}`} className="flex flex-wrap gap-2">
                                    {daftarKanal.map((kanal) => {
                                        const habis = item.Kanal[kanal.kunci];

                                        return (
                                            <li key={kanal.kunci} className="flex items-center gap-2">
                                                <span className="text-keterangan text-teks-sekunder">
                                                    {kanal.label} | {habis ? 'habis' : 'ada'}
                                                </span>
                                                {bolehUbah ? (
                                                    <Tombol
                                                        varian="sekunder"
                                                        memproses={memproses === `${item.UuidOutlet}:${kanal.kunci}`}
                                                        disabled={memproses !== null}
                                                        onClick={() => Ubah(item, !habis, kanal.kunci)}
                                                        aria-label={`${habis ? 'Tersedia lagi' : 'Tandai habis'} di kanal ${kanal.label}, ${item.NamaOutlet}`}
                                                    >
                                                        {habis ? 'Tersedia lagi' : 'Tandai habis'}
                                                    </Tombol>
                                                ) : null}
                                            </li>
                                        );
                                    })}
                                </ul>
                            </li>
                        );
                    })}
                </ul>
            )}
        </Panel>
    );
}
