import { PlusIcon } from 'lucide-react';

/** Aksi di dasar daftar dropdown, mis. "Tambah pemasok baru" saat pemasok yang dicari belum ada (D-273). */
export type AksiTambahPilihan = { label: string; saatKlik: () => void };

/**
 * Baris aksi di dasar popover pemilih (`PilihanCari`, `KerangkaPemilihProduk`), di luar daftar yang bisa dicari supaya
 * tetap terlihat saat daftar digulir dan tidak ikut sorotan keyboard daftar. Menutup daftar lebih dulu, lalu memanggil
 * `saatKlik` setelah fokus pemicu selesai dikembalikan, jadi dialog yang dibuka tidak berebut fokus dengan popover.
 */
export default function BarisTambahPilihan({ aksi, saatTutup }: { aksi: AksiTambahPilihan; saatTutup: () => void }) {
    return (
        <button
            type="button"
            data-slot="baris-tambah-pilihan"
            onClick={() => {
                saatTutup();
                window.setTimeout(aksi.saatKlik, 0);
            }}
            className="flex min-h-9 w-full shrink-0 items-center gap-2 border-t border-garis px-3 py-2 text-left text-label font-semibold text-brand outline-none hover:bg-brand-lembut focus-visible:bg-brand-lembut pointer-coarse:min-h-11"
        >
            <PlusIcon aria-hidden="true" className="size-4 shrink-0" />
            {aksi.label}
        </button>
    );
}
