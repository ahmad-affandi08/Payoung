import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Switch } from '@/Komponen/Ui/switch';
import { useWhatsappTersedia } from '@/Pustaka/Whatsapp';
import type { InsightWhatsappLaporan } from '@/Tipe/Laporan';

/** X6 (v3.79): berlangganan insight penjualan mingguan lewat WhatsApp (pilihan pribadi; Owner bawaan aktif). */
export default function PilihanInsightWhatsapp({ insight }: { insight: InsightWhatsappLaporan }) {
    const [memproses, AturMemproses] = useState(false);
    const whatsappTersedia = useWhatsappTersedia();

    // D-299: tanpa layanan WhatsApp, sakelar ini hilang total.
    if (!whatsappTersedia) {
        return null;
    }

    return (
        <div className="flex max-w-3xl items-start gap-3 rounded-panel border border-garis bg-permukaan p-4">
            <Switch
                id="InsightWhatsapp"
                checked={insight.Aktif}
                disabled={!insight.BisaWhatsapp || memproses}
                onCheckedChange={(aktif) =>
                    router.put(
                        '/kelola/laporan/penjualan/insight-whatsapp',
                        { Aktif: aktif },
                        {
                            preserveScroll: true,
                            onStart: () => AturMemproses(true),
                            onFinish: () => AturMemproses(false),
                        },
                    )
                }
            />
            <div className="flex min-w-0 flex-col gap-0.5">
                <label htmlFor="InsightWhatsapp" className="text-isi font-semibold text-teks-utama">
                    Kirim insight mingguan ke WhatsApp saya
                </label>
                <span className="text-label text-teks-sekunder">
                    {insight.BisaWhatsapp
                        ? 'Tiap Senin pukul 07.15 WIB: penjualan minggu lalu, produk naik dan turun, dan stok yang segera habis.'
                        : 'Akun Anda belum punya nomor HP, jadi insight tidak bisa dikirim. Tambahkan nomor HP di profil akun.'}
                </span>
            </div>
        </div>
    );
}
