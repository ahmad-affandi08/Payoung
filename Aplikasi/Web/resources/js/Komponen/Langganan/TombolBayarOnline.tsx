import { useEffect, useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { KirimJson } from '@/Pustaka/PermintaanJson';

export type PropsTombolBayarOnline = {
    uuidTagihan: string;
    /** D-159: bila halaman bayar Payoung (SNAP) tersedia, Checkout ditawarkan untuk kartu dan metode lain. */
    label?: string;
    keterangan?: string;
    /** `sekunder` saat ada cara bayar SNAP yang menjadi aksi utama halaman. */
    varian?: 'utama' | 'sekunder';
};

/**
 * "Bayar online" untuk tagihan langganan (BR-P08.11): minta transaksi DOKU Checkout ke server, lalu arahkan
 * peramban ke halaman bayar DOKU.
 *
 * Kembalinya peramban dari halaman bayar **tidak** dipakai untuk menyatakan tagihan lunas — itu hanya perpindahan
 * halaman dan bisa dipalsukan. Pelunasan datang dari notifikasi webhook bertanda tangan (atau rekonsiliasi status),
 * jadi halaman tagihan hanya menampilkan keadaan menurut server saat dibuka kembali.
 */
export default function TombolBayarOnline({
    uuidTagihan,
    label = 'Bayar online',
    keterangan,
    varian = 'utama',
}: PropsTombolBayarOnline) {
    const [memproses, AturMemproses] = useState(false);
    const [galat, AturGalat] = useState<string | null>(null);

    // Tombol kembali dari halaman bayar bisa memulihkan halaman ini dari cache peramban dalam keadaan "memproses".
    useEffect(() => {
        const Pulihkan = (kejadian: PageTransitionEvent) => {
            if (kejadian.persisted) {
                AturMemproses(false);
            }
        };
        window.addEventListener('pageshow', Pulihkan);

        return () => window.removeEventListener('pageshow', Pulihkan);
    }, []);

    const Bayar = async () => {
        AturGalat(null);
        AturMemproses(true);

        try {
            const hasil = await KirimJson<{ UrlBayar: string }>(
                `/kelola/langganan/tagihan/${uuidTagihan}/bayar-online`,
            );

            // Tombol dibiarkan "memproses" sampai halaman berganti, supaya tidak terklik dua kali.
            window.location.assign(hasil.UrlBayar);
        } catch (kegagalan) {
            AturGalat(kegagalan instanceof Error ? kegagalan.message : 'Pembayaran online tidak bisa dimulai.');
            AturMemproses(false);
        }
    };

    return (
        <div className="flex flex-col gap-2">
            <div>
                <Tombol varian={varian} onClick={() => void Bayar()} memproses={memproses}>
                    {label}
                </Tombol>
            </div>
            <p className="text-keterangan text-teks-sekunder">
                {keterangan ??
                    'Anda akan diarahkan ke halaman pembayaran DOKU (QRIS, virtual account, e-wallet, dan kartu). Tagihan otomatis lunas setelah pembayaran dikonfirmasi.'}
            </p>
            {galat ? (
                <Pemberitahuan jenis="bahaya" judul="Pembayaran online gagal dimulai">
                    {galat}
                </Pemberitahuan>
            ) : null}
        </div>
    );
}
