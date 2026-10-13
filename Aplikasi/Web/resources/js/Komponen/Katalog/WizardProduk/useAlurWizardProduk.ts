import { useAlurWizard, type KontrolWizard } from '@/Komponen/Formulir/Wizard/useAlurWizard';

import { urutanLangkahProduk, type KunciLangkah, type LangkahProduk } from './LangkahProduk';

export type KontrolWizardProduk = KontrolWizard<KunciLangkah>;

/** Keadaan wizard "Tambah produk" di atas kerangka bersama; langkah pertamanya selalu Jenis & nama. */
export function useAlurWizardProduk(
    langkah: LangkahProduk[],
    periksa: (kunci: KunciLangkah) => boolean,
): KontrolWizardProduk {
    return useAlurWizard(langkah, periksa, { awal: 'JenisNama', urutan: urutanLangkahProduk });
}
