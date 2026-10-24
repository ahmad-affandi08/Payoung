<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Penjualan\Data\DataKuotaTransaksi;
use App\Domain\Penjualan\Enum\StatusKuotaTransaksi;
use App\Domain\Penjualan\Kueri\PemakaianTransaksiBulanan;
use App\Domain\Tenant\Kueri\StatusLanggananTenant;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use Carbon\CarbonImmutable;

/**
 * Kuota transaksi penjualan per bulan (P-04, BR-P04.3). Batas = `KuotaTransaksiBulanan` efektif tenant (paket + add-on,
 * ditimpa override konsol); null = tak terbatas, 0 = tidak boleh membuat transaksi baru. Tenant tanpa baris langganan
 * tidak dikenai kuota (statusnya diurus `StatusLanggananTenant`, bukan kuota).
 *
 * Sifat penegakan sengaja **lunak**: penjualan yang sudah terjadi di perangkat (uang sudah di laci, struk sudah
 * diberikan) selalu diterima server saat sinkron (K33, D-115). Kuota hanya menahan transaksi *baru*: masuk kasir online,
 * pesanan toko online/kios/self-order, dan layar jual aplikasi Kasir. Hanya dipanggil dalam konteks tenant (scope
 * `MilikTenant`).
 */
final class KuotaTransaksiTenant
{
    public const KOLOM_BATAS = 'KuotaTransaksiBulanan';

    /** Mulai peringatan "hampir habis" pada persentase ini. */
    public const AMBANG_HAMPIR_HABIS_PERSEN = 80;

    public function __construct(
        private readonly PemeriksaFiturTenant $fitur,
        private readonly StatusLanggananTenant $langganan,
        private readonly PemakaianTransaksiBulanan $pemakaian,
    ) {}

    public function Ambil(int $idTenant, ?CarbonImmutable $pada = null): DataKuotaTransaksi
    {
        $periode = $this->pemakaian->AmbilPeriode($pada);
        $batas = $this->AmbilBatas($idTenant);
        $terpakai = $this->pemakaian->Hitung($periode);

        return new DataKuotaTransaksi($periode, $terpakai, $batas, self::TentukanStatus($terpakai, $batas));
    }

    /** Batas efektif bulan ini; null = tak terbatas (juga untuk tenant tanpa langganan). */
    public function AmbilBatas(int $idTenant): ?int
    {
        return $this->langganan->Ambil($idTenant) === null ? null : $this->fitur->AmbilBatasEfektif($idTenant, self::KOLOM_BATAS);
    }

    /**
     * True bila transaksi baru masih boleh dimulai (kuota belum habis atau tanpa batas). Tanpa batas tidak menghitung
     * dokumen sama sekali, jadi halaman publik yang sering dibuka tidak membebani basis data.
     */
    public function CekBolehMulai(int $idTenant): bool
    {
        $batas = $this->AmbilBatas($idTenant);

        if ($batas === null) {
            return true;
        }

        return $this->pemakaian->Hitung($this->pemakaian->AmbilPeriode()) < $batas;
    }

    public static function TentukanStatus(int $terpakai, ?int $batas): StatusKuotaTransaksi
    {
        if ($batas === null) {
            return StatusKuotaTransaksi::TanpaBatas;
        }

        if ($terpakai >= $batas) {
            return StatusKuotaTransaksi::Habis;
        }

        return $terpakai * 100 >= $batas * self::AMBANG_HAMPIR_HABIS_PERSEN
            ? StatusKuotaTransaksi::HampirHabis
            : StatusKuotaTransaksi::Normal;
    }
}
