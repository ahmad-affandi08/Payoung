<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Tugas;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Aksi\BuatNotifikasiPengguna;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Kueri\PenerimaNotifikasi;
use App\Domain\Penjualan\Model\PesananOnline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * OWN-03: kabar "pesanan online masuk" ke pengguna ber-izin `toko-online.kelola` di outlet pesanan, saat pesanan siap
 * dikonfirmasi toko (bayar saat ambil/COD: begitu dibuat; bayar di muka: begitu pembayarannya diterima). Satu kabar per
 * pesanan (kunci unik), jadi tugas yang diulang tidak menggandakannya.
 */
final class BeritahuPesananOnlineMasukTugas implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $idTenant, public int $idPesanan) {}

    public function handle(KonteksTenant $konteks, PenerimaNotifikasi $penerima, BuatNotifikasiPengguna $buat): void
    {
        $sebelumnya = $konteks->Ambil();
        $konteks->Atur($this->idTenant);

        try {
            $pesanan = PesananOnline::query()->whereKey($this->idPesanan)->first();

            if ($pesanan === null) {
                return;
            }

            foreach ($penerima->AmbilIdPengguna($this->idTenant, IzinTenant::TokoOnlineKelola, $pesanan->IdOutlet) as $idPengguna) {
                $buat->Jalankan(
                    $idPengguna,
                    JenisNotifikasiPengguna::PesananOnline,
                    'PesananOnline:'.$pesanan->Uuid,
                    'Pesanan online baru '.Uang::Dari($pesanan->Total)->FormatRupiah(),
                    "{$pesanan->Nomor} dari {$pesanan->NamaPelanggan}. Konfirmasi atau tolak segera.",
                    ['Tautan' => 'notifikasi', 'Href' => '/kelola/toko-online'],
                );
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }
    }
}
