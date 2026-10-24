import { useCallback, useRef, useState } from 'react';

import { BacaQrDariBerkas } from '@/Pustaka/PembacaQris';
import { PeriksaQris } from '@/Pustaka/Qris';

export type KeadaanBacaQris =
    | { Tahap: 'kosong' }
    | { Tahap: 'membaca' }
    | { Tahap: 'berhasil'; Teks: string; NamaMerchant: string; Kota: string }
    | { Tahap: 'gagal'; Pesan: string };

const PESAN_TIDAK_TERBACA =
    'Kode QR belum terbaca dari gambar ini. QRIS tetap bisa dipakai sebagai gambar, tetapi pelanggan harus mengetik nominal sendiri. Untuk nominal otomatis, unggah gambar QRIS yang lebih jelas dan utuh (jangan terpotong).';

/**
 * D-189: membaca teks QRIS dari gambar yang dipilih pemilik toko lalu memeriksanya (CRC, statis, rupiah). Hanya hasil
 * yang sah yang dikirim ke server sebagai `TeksQris`; yang gagal tampil sebagai pesan, tanpa memblokir unggah gambar.
 */
export function useBacaQris() {
    const [keadaan, AturKeadaan] = useState<KeadaanBacaQris>({ Tahap: 'kosong' });
    // Pilih gambar lain saat gambar sebelumnya masih dibaca: hanya hasil terakhir yang dipakai.
    const urutan = useRef(0);

    const Baca = useCallback(async (berkas: File | null) => {
        const saya = ++urutan.current;

        if (berkas === null) {
            AturKeadaan({ Tahap: 'kosong' });

            return;
        }

        AturKeadaan({ Tahap: 'membaca' });
        const teks = await BacaQrDariBerkas(berkas);

        if (saya !== urutan.current) {
            return;
        }

        if (teks === null) {
            AturKeadaan({ Tahap: 'gagal', Pesan: PESAN_TIDAK_TERBACA });

            return;
        }

        const hasil = PeriksaQris(teks);

        AturKeadaan(
            hasil.Sah
                ? { Tahap: 'berhasil', Teks: teks, NamaMerchant: hasil.NamaMerchant, Kota: hasil.Kota }
                : { Tahap: 'gagal', Pesan: hasil.Pesan },
        );
    }, []);

    const Ulang = useCallback(() => {
        urutan.current++;
        AturKeadaan({ Tahap: 'kosong' });
    }, []);

    return { keadaan, Baca, Ulang };
}
