<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Data;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use App\Domain\Penjualan\Kalkulasi\DataPembulatanTunai;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Masukan `TerimaPenjualanPos` (F-07b) dari item outbox `Penjualan.Buat`: penjualan lunas yang dibuat di perangkat
 * (bisa offline). `uuid` = `UuidKlien` (ULID perangkat). Semua harga, pajak, dan pengaturan adalah snapshot saat
 * transaksi (BR-07.2); server menghitung ulang dengan `MesinKalkulasi`. `poinDitukar`/`nilaiTukarPoin` (F-16b): poin
 * pelanggan yang ditukar sebagai diskon pesanan sebelum pajak. `promo` (F-16c): promo yang diterapkan perangkat.
 * `uuidPenyetujuTempo` (F-12, BR-12.1): pemberi PIN untuk tempo di atas limit / piutang lewat jatuh tempo.
 * `kodeVoucher` (F-16c bagian 2): voucher yang dipesan online kasir untuk penjualan ini.
 * `uuidPesananPenjualan` (F-12 bagian 2): pre-order yang diambil lewat penjualan ini (DP dipakai lewat metode Uang Muka).
 * `uuidPesananOnline` (F-17 bagian 2): pesanan toko online yang sudah dibayar di muka lewat QRIS web dan ditagihkan
 * lewat penjualan ini; uang mukanya juga dipakai lewat metode Uang Muka. Salah satu dari keduanya, tidak pernah dua.
 * `biayaKirim`/`diskonKirim` (F-17 bagian 3): ongkir yang ditagih ke pembeli dan diskonnya, keduanya kotor.
 */
final readonly class DataPenjualanPos
{
    /**
     * @param  list<DataPajakPenjualanPos>  $pajak
     * @param  list<DataBarisPenjualanPos>  $baris
     * @param  list<DataPembayaranPenjualanPos>  $pembayaran
     * @param  list<DataPromoPenjualanPos>  $promo
     */
    public function __construct(
        public string $uuid,
        public int $idPerangkat,
        public string $uuidShift,
        public string $uuidPengguna,
        public string $nomor,
        public KanalPenjualan $kanal,
        public CarbonImmutable $dibuatPada,
        public bool $hargaTermasukPajak,
        public BigDecimal $persenBiayaLayanan,
        public ?DataPembulatanTunai $pembulatanTunai,
        public array $pajak,
        public array $baris,
        public ?DataDiskonManual $diskonManualPesanan,
        public ?string $uuidPenyetujuDiskon,
        public array $pembayaran,
        public DataRingkasanPenjualanPos $ringkasan,
        public ?string $catatan,
        public ?string $uuidPesananTerbuka = null,
        public bool $kirimDapur = false,
        public ?string $uuidPelanggan = null,
        public int $poinDitukar = 0,
        public ?Uang $nilaiTukarPoin = null,
        public array $promo = [],
        public ?string $uuidPenyetujuTempo = null,
        public ?string $kodeVoucher = null,
        public ?string $uuidPesananPenjualan = null,
        public ?string $uuidPesananOnline = null,
        // F-07 mode service bagian 2: reservasi yang dibayar lewat penjualan ini (diselesaikan & ditautkan).
        public ?string $uuidReservasi = null,
        // FnB reservasi meja + DP (D-145): reservasi meja yang ditagih lewat penjualan ini (memakai uang muka, diselesaikan).
        public ?string $uuidReservasiMeja = null,
        // Laundry (§9.9): blok tiket laundry `{JenisLayanan, Berat?, Item?, Parfum?, Catatan?, EstimasiSelesaiPada?,
        // NamaPelanggan?, NoHp?}` yang dibuat bersama penjualan ini.
        /** @var array{JenisLayanan: string, Berat?: string|null, Item?: list<array{Nama: string, Jumlah: int}>|null, Parfum?: string|null, Catatan?: string|null, EstimasiSelesaiPada?: string|null, NamaPelanggan?: string|null, NoHp?: string|null}|null */
        public ?array $laundry = null,
        // F-17 bagian 3: ongkir yang ditagih ke pembeli dan diskonnya (mis. promo gratis ongkir); bawaan nol supaya
        // perangkat versi lama tetap diterima (CLAUDE.md #16).
        public ?Uang $biayaKirim = null,
        public ?Uang $diskonKirim = null,
        // v3.52 (§9.2): nomor panggil & nama pemesan penjualan bayar-dulu; opsional (perangkat lama tidak mengirim).
        public ?string $nomorAntrian = null,
        public ?string $namaPemesan = null,
        // K-11: retur tukar barang yang nilainya membayar penjualan ini lewat metode `Tukar`.
        public ?string $uuidReturTukar = null,
        // Sektor Bengkel (§9.10): perintah kerja yang ditagih lewat penjualan ini (ditandai Ditagih & ditautkan).
        public ?string $uuidPerintahKerja = null,
        // Apotek (§9.5): resep dokter untuk baris obat wajib resep & apoteker yang menyerahkan obat keras/OWA.
        public ?DataResepPenjualanPos $resep = null,
        public ?string $uuidApoteker = null,
        // Price engine lapis 1: penyetuju (PIN) perubahan harga baris bila kasirnya tanpa izin `pos.harga.timpa`.
        public ?string $uuidPenyetujuHarga = null,
    ) {}
}
