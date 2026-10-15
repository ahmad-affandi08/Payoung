import type { MetodeBayarSnap } from '@/Tipe/TagihanLangganan';

export type PetunjukBayar = { Judul: string; Langkah: string[] };

/**
 * Langkah membayar per cara bayar, ditampilkan sebagai "Cara membayar" di halaman bayar. Teks umum (tidak khusus satu
 * bank) karena menu tiap bank berbeda sedikit; nama menu yang sama di hampir semua bank dipakai supaya tidak menyesatkan.
 */
export function AmbilPetunjukBayar(metode: MetodeBayarSnap, label: string): PetunjukBayar[] {
    if (metode === 'Qris') {
        return [
            {
                Judul: 'Pindai dari aplikasi bank atau dompet digital',
                Langkah: [
                    'Buka aplikasi m-banking atau dompet digital (GoPay, OVO, DANA, ShopeePay, LinkAja, dan lainnya).',
                    'Pilih menu Bayar atau Scan QRIS.',
                    'Arahkan kamera ke kode QR di halaman ini.',
                    'Periksa nama penerima dan jumlah, lalu konfirmasi dengan PIN.',
                    'Halaman ini berganti otomatis begitu pembayaran kami terima.',
                ],
            },
            {
                Judul: 'Kamera tidak bisa memindai layar',
                Langkah: [
                    'Tekan Unduh QR untuk menyimpan kode ke galeri.',
                    'Di aplikasi pembayaran, pilih Scan QRIS lalu pilih gambar dari galeri.',
                    'Periksa jumlah dan konfirmasi pembayaran.',
                ],
            },
        ];
    }

    if (metode === 'VirtualAccount') {
        return [
            {
                Judul: 'ATM',
                Langkah: [
                    'Masukkan kartu dan PIN, lalu pilih Transfer atau Pembayaran, kemudian Virtual Account.',
                    'Masukkan nomor virtual account di atas.',
                    'Periksa nama penerima dan jumlah tagihan.',
                    'Konfirmasi pembayaran dan simpan struknya.',
                ],
            },
            {
                Judul: 'Mobile banking',
                Langkah: [
                    `Buka aplikasi m-banking ${label.replace(/^Virtual account\s+/i, '')} dan masuk.`,
                    'Pilih Transfer lalu Virtual Account, atau Pembayaran lalu Virtual Account.',
                    'Masukkan nomor virtual account di atas.',
                    'Periksa nama penerima dan jumlah, lalu konfirmasi dengan PIN atau biometrik.',
                ],
            },
            {
                Judul: 'Internet banking',
                Langkah: [
                    'Masuk ke internet banking dan pilih Transfer lalu Virtual Account.',
                    'Masukkan nomor virtual account di atas.',
                    'Periksa nama penerima dan jumlah, lalu selesaikan dengan token atau kode otorisasi.',
                ],
            },
        ];
    }

    return [
        {
            Judul: `Bayar dengan ${label}`,
            Langkah: [
                `Tekan Lanjut ke ${label}. Aplikasi atau halaman ${label} terbuka.`,
                `Masuk ke akun ${label} Anda bila diminta.`,
                'Periksa jumlah pembayaran, lalu konfirmasi dengan PIN.',
                'Setelah selesai, kembali ke halaman ini. Status berganti otomatis begitu pembayaran kami terima.',
            ],
        },
    ];
}
