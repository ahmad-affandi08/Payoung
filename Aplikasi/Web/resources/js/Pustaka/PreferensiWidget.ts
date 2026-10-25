/**
 * Widget yang disembunyikan pengguna di peramban ini (Beranda tenant dan Beranda konsol, D-203, D-207). Hanya
 * kemudahan per perangkat: bila penyimpanan tidak bisa dibaca (jendela pribadi, data situs diblokir) semua widget
 * tampil, dan pilihan hanya berlaku selama halaman terbuka.
 */
export function BacaWidgetTersembunyi<T extends string>(kunci: string, sah: readonly T[]): T[] {
    try {
        const mentah = window.localStorage.getItem(kunci);
        const nilai: unknown = mentah === null ? [] : JSON.parse(mentah);

        return Array.isArray(nilai) ? sah.filter((kode) => nilai.includes(kode)) : [];
    } catch {
        return [];
    }
}

export function SimpanWidgetTersembunyi(kunci: string, tersembunyi: readonly string[]): void {
    try {
        window.localStorage.setItem(kunci, JSON.stringify(tersembunyi));
    } catch {
        // Penyimpanan tak tersedia: pilihan hanya berlaku selama halaman terbuka.
    }
}
