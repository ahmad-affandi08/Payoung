/**
 * Tipe dan logika murni kerangka wizard bersama (D-128, diperluas ke promo, kampanye, tim, dan outlet). Setiap
 * wizard menyediakan daftar langkahnya sendiri; kerangka ini hanya mengurus urutan, penguncian, dan fokus.
 */
export type LangkahWizard<K extends string = string> = { Kunci: K; Label: string; Keterangan: string };

/**
 * Langkah `kunci` bila ada di daftar; kalau sudah hilang (misal pilihan jenis berganti), langkah terdekat sebelumnya
 * menurut `urutan` baku seluruh langkah.
 */
export function CariLangkahTerdekat<K extends string>(langkah: LangkahWizard<K>[], kunci: K, urutan: readonly K[]): K {
    if (langkah.some((item) => item.Kunci === kunci)) {
        return kunci;
    }

    const indeksAsal = urutan.indexOf(kunci);
    const sebelum = langkah.filter((item) => urutan.indexOf(item.Kunci) < indeksAsal);

    return (sebelum[sebelum.length - 1] ?? langkah[0])?.Kunci ?? kunci;
}
