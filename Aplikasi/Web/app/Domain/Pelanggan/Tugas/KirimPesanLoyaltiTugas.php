<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Tugas;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Enum\JenisPesanWhatsapp;
use App\Domain\Integrasi\Layanan\PengirimPesanOtomatisWhatsapp;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Pelanggan\Enum\JenisMutasiDeposit;
use App\Domain\Pelanggan\Enum\JenisMutasiPoin;
use App\Domain\Pelanggan\Model\MutasiDeposit;
use App\Domain\Pelanggan\Model\MutasiPoin;
use App\Domain\Pelanggan\Model\Pelanggan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * D-299 tahap 2: pesan WhatsApp otomatis ke pelanggan saat poin bertambah/ditukar atau deposit diisi/dipakai.
 * Dikirim setelah commit lewat antrean (efek non-kritis, aturan #10) dan hanya bila tenant menyalakan jenisnya.
 * Payload hanya id; nomor pelanggan tidak ikut antrean. Satu mutasi = satu tugas, tanpa coba ulang supaya
 * pelanggan tidak menerima pesan ganda.
 */
final class KirimPesanLoyaltiTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $idTenant,
        public readonly string $sumber,
        public readonly int $idMutasi,
    ) {}

    public function handle(KonteksTenant $konteks, PengirimPesanOtomatisWhatsapp $pengirim, NamaTampilUsaha $namaTampil): void
    {
        $sebelumnya = $konteks->Ambil();
        $konteks->Atur($this->idTenant);

        try {
            $isi = $this->sumber === 'poin' ? $this->SusunPoin() : $this->SusunDeposit();

            if ($isi === null) {
                return;
            }

            [$jenis, $pelanggan, $kalimat] = $isi;
            $nama = trim(explode(' ', trim($pelanggan->Nama))[0] ?? '');
            $toko = $namaTampil->UntukTenant($this->idTenant);
            $pengirim->Kirim($jenis, null, (string) $pelanggan->NoHp, ($nama === '' ? 'Halo' : "Halo {$nama}").", {$kalimat} di {$toko}. Terima kasih.");
        } finally {
            $konteks->Atur($sebelumnya);
        }
    }

    /** 12500 → "12.500" (tanpa number_format, aturan arsitektur D-05). */
    private static function FormatRibuan(int $nilai): string
    {
        $angka = strrev(implode('.', str_split(strrev((string) abs($nilai)), 3)));

        return $nilai < 0 ? "-{$angka}" : $angka;
    }

    /**
     * @return array{0: JenisPesanWhatsapp, 1: Pelanggan, 2: string}|null
     */
    private function SusunPoin(): ?array
    {
        $mutasi = MutasiPoin::query()->find($this->idMutasi);
        $pelanggan = $mutasi === null ? null : Pelanggan::query()->find($mutasi->IdPelanggan);

        if ($mutasi === null || $pelanggan === null) {
            return null;
        }

        $saldo = (int) MutasiPoin::query()->where('IdPelanggan', $pelanggan->Id)->sum('Poin');
        $poin = self::FormatRibuan(abs($mutasi->Poin));
        $total = self::FormatRibuan($saldo);

        return match ($mutasi->Jenis) {
            JenisMutasiPoin::Perolehan => $mutasi->Poin > 0
                ? [JenisPesanWhatsapp::PoinBertambah, $pelanggan, "Anda mendapat {$poin} poin. Total poin Anda sekarang {$total}"]
                : null,
            JenisMutasiPoin::Penukaran => [JenisPesanWhatsapp::PoinDitukar, $pelanggan, "Anda menukar {$poin} poin. Sisa poin Anda {$total}"],
            default => null,
        };
    }

    /**
     * @return array{0: JenisPesanWhatsapp, 1: Pelanggan, 2: string}|null
     */
    private function SusunDeposit(): ?array
    {
        $mutasi = MutasiDeposit::query()->find($this->idMutasi);
        $pelanggan = $mutasi === null ? null : Pelanggan::query()->find($mutasi->IdPelanggan);

        if ($mutasi === null || $pelanggan === null) {
            return null;
        }

        $jumlah = Uang::Dari(ltrim((string) $mutasi->Jumlah, '-'))->FormatRupiah();
        $saldo = Uang::Dari((string) $mutasi->SaldoSetelah)->FormatRupiah();

        return match ($mutasi->Jenis) {
            JenisMutasiDeposit::Isi => [JenisPesanWhatsapp::DepositDiisi, $pelanggan, "Deposit {$jumlah} sudah masuk. Saldo deposit Anda sekarang {$saldo}"],
            JenisMutasiDeposit::Pemakaian => [JenisPesanWhatsapp::DepositDipakai, $pelanggan, "Deposit {$jumlah} dipakai untuk pembayaran. Sisa saldo deposit Anda {$saldo}"],
            default => null,
        };
    }
}
