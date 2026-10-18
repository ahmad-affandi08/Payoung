<?php

declare(strict_types=1);

namespace Tests\Pendukung\Integrasi;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\Billing\HasilPembayaranBilling;
use App\Domain\Integrasi\Billing\NomorPesananBilling;
use App\Domain\Integrasi\Billing\NotifikasiBilling;
use App\Domain\Integrasi\Billing\PenyediaTagihanPlatform;
use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use App\Domain\Integrasi\Snap\Data\PembayaranSnapDibuat;
use App\Domain\Integrasi\Snap\Enum\MetodeBayarSnap;
use DateTimeInterface;
use Illuminate\Http\Request;
use LogicException;

/**
 * Penyedia pembayaran tiruan untuk membuktikan bahwa tagihan langganan bisa berpindah penyedia hanya dengan memasang
 * adaptor baru di belakang `PenyediaTagihanPlatform` (D-179). Tidak ada satu pun kelas DOKU di sini. Hanya jalur
 * Checkout yang disediakan (halaman bayar Payoung mati), seperti penyedia sederhana pada umumnya.
 */
final class PenyediaTagihanPalsu implements PenyediaTagihanPlatform
{
    /** Status yang dilaporkan penyedia untuk semua pembayaran. */
    public static StatusPembayaranGerbang $status = StatusPembayaranGerbang::Lunas;

    /**
     * Nomor pesanan yang dibuat lewat adaptor ini, urut.
     *
     * @var list<string>
     */
    public static array $dibuat = [];

    public static function Setel(StatusPembayaranGerbang $status): void
    {
        self::$status = $status;
        self::$dibuat = [];
    }

    public function AmbilKode(): string
    {
        return 'Palsu';
    }

    public function AmbilLabel(): string
    {
        return 'Penyedia Palsu';
    }

    public function CekAktif(): bool
    {
        return true;
    }

    public function CekCheckoutAktif(): bool
    {
        return true;
    }

    public function CekHalamanBayarAktif(): bool
    {
        return false;
    }

    public function CekAktifUntuk(?string $metodeBayar): bool
    {
        return $metodeBayar === null;
    }

    public function AmbilPilihanHalamanBayar(): array
    {
        return [];
    }

    public function AmbilMenitKedaluwarsaHalamanBayar(): int
    {
        return 60;
    }

    public function BuatCheckout(string $nomorPesanan, Uang $total, string $namaPembayar, string $emailPembayar, string $urlKembali): HasilPembayaranBilling
    {
        self::$dibuat[] = $nomorPesanan;

        return new HasilPembayaranBilling("https://bayar.penyedia-palsu.test/{$nomorPesanan}");
    }

    public function BuatPembayaranHalamanBayar(
        MetodeBayarSnap $metode,
        ?string $kanal,
        string $nomorPesanan,
        Uang $total,
        string $namaPembayar,
        string $emailPembayar,
        string $urlKembali,
        ?string $ipPelanggan = null,
    ): PembayaranSnapDibuat {
        throw new LogicException('Penyedia palsu tidak punya halaman bayar Payoung.');
    }

    public function TanyaStatus(
        string $nomorPesanan,
        ?string $metodeBayar,
        ?array $rincian,
        ?DateTimeInterface $kedaluwarsaPada,
        DateTimeInterface $dibuatPada,
        string $jumlah,
    ): ?NotifikasiBilling {
        return $this->Bentuk($nomorPesanan, $jumlah);
    }

    public function CekTandaTanganSah(Request $permintaan): bool
    {
        return $permintaan->header('X-Tanda-Tangan-Palsu') === 'sah';
    }

    public function UraiNotifikasi(Request $permintaan): ?NotifikasiBilling
    {
        $nomor = (string) $permintaan->input('NomorPesanan');

        return NomorPesananBilling::Urai($nomor) === null ? null : $this->Bentuk($nomor, (string) $permintaan->input('Jumlah'));
    }

    public function Konfirmasi(NotifikasiBilling $notifikasi): NotifikasiBilling
    {
        return $notifikasi;
    }

    private function Bentuk(string $nomorPesanan, string $jumlah): ?NotifikasiBilling
    {
        $bagian = NomorPesananBilling::Urai($nomorPesanan);

        return $bagian === null ? null : new NotifikasiBilling(
            nomorPesanan: $nomorPesanan,
            idTenant: $bagian['IdTenant'],
            uuidPembayaran: $bagian['Uuid'],
            status: self::$status,
            jumlah: $jumlah,
            idTransaksi: 'palsu-1',
            statusAsli: self::$status->value,
        );
    }
}
