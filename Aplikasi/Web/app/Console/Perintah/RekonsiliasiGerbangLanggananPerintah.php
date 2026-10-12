<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Pengelola\Tagihan\Aksi\RekonsiliasiPembayaranGerbangLangganan;
use Illuminate\Console\Command;

/**
 * P-08 (v4.06): tanyakan status pembayaran langganan gerbang yang tersangkut `Menunggu` ke gerbang billing.
 * Dijadwalkan tiap 15 menit, ditambah putaran harian `--arsip` untuk pembayaran lama (routes/console.php).
 */
final class RekonsiliasiGerbangLanggananPerintah extends Command
{
    protected $signature = 'tagihan:rekonsiliasi-gerbang {--arsip : Putaran harian untuk pembayaran yang lebih tua dari jendela 7 hari}';

    protected $description = 'Merekonsiliasi pembayaran langganan lewat gerbang yang belum mendapat notifikasi (P-08).';

    public function handle(RekonsiliasiPembayaranGerbangLangganan $rekonsiliasi): int
    {
        $hasil = $this->option('arsip') ? $rekonsiliasi->JalankanArsip() : $rekonsiliasi->Jalankan();
        $this->info("{$hasil['Diperiksa']} pembayaran diperiksa, {$hasil['Selesai']} selesai, {$hasil['Menunggu']} masih menunggu, {$hasil['Gagal']} gagal diperiksa.");

        return self::SUCCESS;
    }
}
