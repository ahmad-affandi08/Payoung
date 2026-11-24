<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Tenant\Model\HargaPaket;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Harga langganan untuk lama berapa pun dalam bulan (D-249). Murni, tanpa kueri dan tanpa float. Seluruh angka (tangga
 * diskon) datang dari `PengaturanLamaLangganan`, tidak ada persen di kode ini.
 *
 * - Lama kelipatan 12 bulan dan katalog punya harga tahunan: harga tahunan katalog × (lama ÷ 12). Harga tahunan
 *   katalog adalah harga yang sudah dipromosikan pemilik produk, jadi tangga tidak ditumpuk di atasnya.
 * - Lama lain: harga bulanan × lama, dikurangi diskon dari anak tangga tertinggi yang `DariBulan`-nya <= lama
 *   (dibulatkan ke rupiah terdekat, HalfUp).
 * - Pengaman: harga tidak pernah melebihi harga tahunan katalog × jumlah tahun yang membulatkan lama ke atas, sehingga
 *   memilih lama lebih pendek tidak pernah lebih mahal daripada kelipatan 12 bulan di atasnya.
 */
final class PenghitungHargaPeriode
{
    public function __construct(private readonly PengaturanLamaLangganan $pengaturan) {}

    /**
     * Harga satu paket (versi harga katalog tertentu) untuk lama tertentu, dengan tangga diskon yang berlaku saat ini.
     */
    public function UntukHarga(HargaPaket $harga, int $jumlahBulan): Uang
    {
        return $this->Hitung($harga->AmbilHargaBulanan(), $harga->AmbilHargaTahunan(), $jumlahBulan, $this->pengaturan->Ambil()['TanggaDiskon'])['Subtotal'];
    }

    /**
     * @param  list<array{DariBulan: int, Persen: string}>  $tanggaDiskon
     * @return array{Subtotal: Uang, HargaPenuh: Uang, DiskonPersen: string, Dasar: string}
     */
    public function Hitung(Uang $hargaBulanan, ?Uang $hargaTahunan, int $jumlahBulan, array $tanggaDiskon): array
    {
        if ($jumlahBulan < 1) {
            throw new InvalidArgumentException('Lama langganan minimal 1 bulan.');
        }

        $tahunan = $hargaTahunan !== null && $hargaTahunan->Bandingkan(Uang::Nol()) > 0 ? $hargaTahunan : null;
        $penuh = $hargaBulanan->Kali($jumlahBulan);

        if ($tahunan !== null && $jumlahBulan % 12 === 0) {
            $subtotalTahunan = $tahunan->Kali(intdiv($jumlahBulan, 12));

            return ['Subtotal' => $subtotalTahunan, 'HargaPenuh' => $penuh, 'DiskonPersen' => self::PersenHemat($penuh, $subtotalTahunan), 'Dasar' => 'Tahunan'];
        }

        $persen = '0';

        foreach ($tanggaDiskon as $anak) {
            if ($anak['DariBulan'] <= $jumlahBulan) {
                $persen = $anak['Persen'];
            }
        }

        $diskon = $penuh
            ->Kali(BigDecimal::of($persen)->dividedBy(100, 8, RoundingMode::HalfUp), RoundingMode::HalfUp)
            ->BulatkanKeKelipatan(1, RoundingMode::HalfUp);
        $subtotal = $penuh->Kurangi($diskon);
        $dasar = BigDecimal::of($persen)->isZero() ? 'Bulanan' : 'TanggaDiskon';

        if ($tahunan !== null) {
            $batas = $tahunan->Kali(intdiv($jumlahBulan + 11, 12));

            if ($subtotal->Bandingkan($batas) > 0) {
                return ['Subtotal' => $batas, 'HargaPenuh' => $penuh, 'DiskonPersen' => self::PersenHemat($penuh, $batas), 'Dasar' => 'Tahunan'];
            }
        }

        return ['Subtotal' => $subtotal, 'HargaPenuh' => $penuh, 'DiskonPersen' => $persen, 'Dasar' => $dasar];
    }

    /**
     * Persen hemat yang benar-benar didapat dibanding harga bulanan penuh, satu desimal tanpa nol di belakang ('20',
     * '12.7'), atau '0' bila tidak lebih murah. Dipakai saat harga ditentukan harga tahunan katalog, yang tidak punya
     * persen sendiri, supaya layar memperlihatkan hematnya (D-323).
     */
    private static function PersenHemat(Uang $penuh, Uang $subtotal): string
    {
        $selisih = $penuh->Kurangi($subtotal);

        if ($penuh->Bandingkan(Uang::Nol()) <= 0 || $selisih->Bandingkan(Uang::Nol()) <= 0) {
            return '0';
        }

        $persen = BigDecimal::of($selisih->KeString())
            ->multipliedBy(100)
            ->dividedBy($penuh->KeString(), 1, RoundingMode::HalfUp);

        $teks = (string) $persen;

        return $persen->isZero() ? '0' : (str_ends_with($teks, '.0') ? substr($teks, 0, -2) : $teks);
    }
}
