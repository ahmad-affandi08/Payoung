/** Teks bersama untuk fitur harga otomatis dan "Harga dari harga beli" (D-266). */

/** Label pembulatan sama dengan dialog harga massal supaya pemilik toko tidak menemukan dua cara menyebut hal yang sama. */
export function LabelPembulatan(nilai: number): string {
    return nilai === 0 ? 'Tanpa pembulatan' : `Dibulatkan ke Rp ${nilai.toLocaleString('id-ID')} terdekat`;
}

/** Persen tanpa ekor nol untuk tampilan: "20.0000" menjadi "20", "7.5000" menjadi "7,5". */
export function RapikanPersen(nilai: string): string {
    const bersih = nilai.includes('.') ? nilai.replace(/\.?0+$/, '') : nilai;

    return bersih.replace('.', ',');
}

/** Persen tanpa ekor nol untuk isian (titik desimal, dikirim ke server apa adanya): "20.0000" menjadi "20". */
export function PersenIsian(nilai: string): string {
    return nilai.includes('.') ? nilai.replace(/\.?0+$/, '') : nilai;
}
