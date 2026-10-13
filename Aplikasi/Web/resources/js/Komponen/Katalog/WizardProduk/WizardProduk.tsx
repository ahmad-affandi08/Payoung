import type { ReactNode } from 'react';

import CangkangWizard from '@/Komponen/Formulir/Wizard/CangkangWizard';
import { TahanBilaBarcodeTertunda } from '@/Komponen/Katalog/BantuanKatalog';

import type { KunciLangkah } from './LangkahProduk';
import type { KontrolWizardProduk } from './useAlurWizardProduk';

type PropsWizardProduk = {
    kontrol: KontrolWizardProduk;
    /** Isi tiap langkah; hanya isi langkah aktif yang dipasang. */
    panel: Record<KunciLangkah, ReactNode>;
    /** Langkah yang memuat isian bermasalah. */
    bergalat: KunciLangkah[];
    /** Ringkasan galat langkah aktif (aria-live) dan galat server yang tidak terikat isian. */
    pesanGalat: ReactNode;
    /** Dipanggil hanya dari langkah terakhir (Tinjau). */
    saatSimpan: () => void;
    memproses: boolean;
    /** Pengguna boleh menyimpan produk (izin `produk.kelola`). */
    bolehSimpan: boolean;
    /** Simpan ditahan, misal batas produk paket tercapai. */
    simpanDitahan?: boolean;
    tautanBatal: string;
};

/**
 * Wizard "Tambah produk" (D-128) di atas `CangkangWizard`. Penjaga produknya: barcode yang diketik tetapi belum
 * ditambahkan tidak boleh hilang diam-diam saat pindah langkah atau menyimpan.
 */
export default function WizardProduk(props: PropsWizardProduk) {
    return (
        <CangkangWizard
            {...props}
            kunciTinjau="Tinjau"
            labelNavigasi="Langkah tambah produk"
            teksSimpan="Simpan produk"
            tahan={TahanBilaBarcodeTertunda}
        />
    );
}
