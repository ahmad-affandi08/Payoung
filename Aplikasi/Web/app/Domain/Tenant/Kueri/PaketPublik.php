<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Tenant\Enum\StatusPaket;
use App\Domain\Tenant\Model\Fitur;
use App\Domain\Tenant\Model\HargaPaket;
use App\Domain\Tenant\Model\Paket;
use Carbon\CarbonImmutable;

/**
 * Paket untuk halaman harga situs pemasaran (D-21): paket Aktif urut `Urutan`, harga terbit yang berlaku hari ini
 * (WIB) untuk pelanggan baru, batas paket, dan nama fitur. Paket tanpa harga berlaku dan bukan harga negosiasi tidak
 * ditampilkan. Perubahan paket/harga di konsol (P-04) langsung tampil di situs.
 */
final class PaketPublik
{
    /** Label batas paket untuk pembaca umum. */
    private const LABEL_BATAS = [
        'BatasOutlet' => ['outlet', 'Outlet tanpa batas'],
        'BatasPerangkatPerOutlet' => ['perangkat kasir per outlet', 'Perangkat tanpa batas'],
        'BatasPengguna' => ['pengguna', 'Pengguna tanpa batas'],
        'BatasSku' => ['produk (SKU)', 'Produk tanpa batas'],
        'KuotaPesanWaBulanan' => ['pesan WhatsApp/bulan', null],
    ];

    public function __construct(private readonly HargaPaketBerlaku $harga) {}

    /**
     * @return list<array{Kode: string, Nama: string, Keterangan: string|null, HargaNegosiasi: bool, MasaTrialHari: int, HargaBulanan: string|null, HargaTahunan: string|null, HematTahunan: string|null, Promo: array{HargaBulananNormal: string, HargaTahunanNormal: string, BerlakuSampai: string, PersenDiskon: int, HargaTerkunci: bool}|null, Batas: list<string>, Fitur: list<string>}>
     */
    public function Ambil(): array
    {
        $hariIni = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        $namaFitur = Fitur::query()->pluck('Nama', 'Kunci')->all();
        $hasil = [];

        foreach (Paket::query()->where('Status', StatusPaket::Aktif->value)->with('Fitur')->orderBy('Urutan')->orderBy('Id')->get() as $paket) {
            $harga = $paket->HargaNegosiasi ? null : $this->harga->Cari($paket->Id, $hariIni, $hariIni);

            if (! $paket->HargaNegosiasi && $harga === null) {
                continue;
            }

            $bulanan = $harga === null ? null : Uang::Dari($harga->HargaBulanan);
            $tahunan = $harga === null ? null : Uang::Dari($harga->HargaTahunan);
            $hemat = $bulanan !== null && $tahunan !== null ? $bulanan->Kali(12)->Kurangi($tahunan) : null;

            $hasil[] = [
                'Kode' => $paket->Kode,
                'Nama' => $paket->Nama,
                'Keterangan' => $paket->Keterangan,
                'HargaNegosiasi' => $paket->HargaNegosiasi,
                'MasaTrialHari' => $paket->MasaTrialHari,
                'HargaBulanan' => $bulanan?->KeString(),
                'HargaTahunan' => $tahunan?->KeString(),
                'HematTahunan' => $hemat !== null && $hemat->Bandingkan(Uang::Nol()) > 0 ? $hemat->KeString() : null,
                'Promo' => $harga === null || $bulanan === null ? null : $this->AmbilPromo($paket, $harga, $bulanan, $hariIni),
                'Batas' => self::SusunBatas($paket),
                'Fitur' => array_values(array_filter(array_map(
                    fn (string $kunci): ?string => $namaFitur[$kunci] ?? null,
                    $paket->AmbilKunciFitur(),
                ))),
            ];
        }

        return $hasil;
    }

    /**
     * Penawaran termurah untuk iklan di halaman masuk/daftar (D-87): paket berbayar berharga tetap dengan harga bulanan
     * terendah yang berlaku hari ini, beserta diskon peluncuran bila ada. Null bila tidak ada paket berbayar yang dijual
     * (mis. edisi Lisensi), sehingga iklan tidak pernah menyebut harga yang tidak ada.
     *
     * `Paket` = semua paket berbayar berharga tetap yang dijual (nama, harga, harga normal bila diskon, tiga batas
     * pertama), semuanya dibaca dari katalog sehingga iklan tidak pernah memuat angka atau klaim paket yang ditulis
     * tangan.
     *
     * @return array{NamaPaket: string, HargaBulanan: string, MasaTrialHari: int, Promo: array{HargaBulananNormal: string, HargaTahunanNormal: string, BerlakuSampai: string, PersenDiskon: int, HargaTerkunci: bool}|null, Paket: list<array{Nama: string, HargaBulanan: string, HargaNormal: string|null, Sorotan: list<string>}>}|null
     */
    public function AmbilPenawaranTermurah(): ?array
    {
        $termurah = null;
        $daftar = [];

        foreach ($this->Ambil() as $paket) {
            if ($paket['HargaNegosiasi'] || $paket['HargaBulanan'] === null || Uang::Dari($paket['HargaBulanan'])->BernilaiNol()) {
                continue;
            }

            $daftar[] = [
                'Nama' => $paket['Nama'],
                'HargaBulanan' => $paket['HargaBulanan'],
                'HargaNormal' => $paket['Promo']['HargaBulananNormal'] ?? null,
                'Sorotan' => array_slice($paket['Batas'], 0, 3),
            ];

            if ($termurah === null || Uang::Dari($paket['HargaBulanan'])->Bandingkan(Uang::Dari($termurah['HargaBulanan'])) < 0) {
                $termurah = $paket;
            }
        }

        return $termurah === null ? null : [
            'NamaPaket' => $termurah['Nama'],
            'HargaBulanan' => $termurah['HargaBulanan'] ?? '0',
            'MasaTrialHari' => $termurah['MasaTrialHari'],
            'Promo' => $termurah['Promo'],
            'Paket' => $daftar,
        ];
    }

    /**
     * Harga yang berlaku hari ini disebut promo bila sudah dijadwalkan versi harga terbit yang LEBIH MAHAL sesudah
     * `BerlakuSampai` (D-86): harga normal yang dicoret tampil hanya bila benar-benar akan berlaku, bukan coretan kosong.
     * `HargaTerkunci` = langganan yang dimulai selama promo tetap di harga promo (versi normal tidak diterapkan ke
     * pelanggan lama, BR-P04.1).
     *
     * @return array{HargaBulananNormal: string, HargaTahunanNormal: string, BerlakuSampai: string, PersenDiskon: int, HargaTerkunci: bool}|null
     */
    private function AmbilPromo(Paket $paket, HargaPaket $berlaku, Uang $bulanan, CarbonImmutable $hariIni): ?array
    {
        if ($berlaku->BerlakuSampai === null) {
            return null;
        }

        $berikut = HargaPaket::query()
            ->where('IdPaket', $paket->Id)
            ->where('Status', StatusDataMaster::Terbit->value)
            ->whereDate('BerlakuMulai', '>', $hariIni->toDateString())
            ->orderBy('BerlakuMulai')
            ->first();

        if ($berikut === null || $berikut->AmbilHargaBulanan()->Bandingkan($bulanan) <= 0) {
            return null;
        }

        $normal = $berikut->AmbilHargaBulanan();
        $persen = intdiv(self::RupiahBulat($normal->Kurangi($bulanan)) * 100, max(1, self::RupiahBulat($normal)));

        return [
            'HargaBulananNormal' => $normal->KeString(),
            'HargaTahunanNormal' => $berikut->AmbilHargaTahunan()->KeString(),
            'BerlakuSampai' => $berlaku->BerlakuSampai->toDateString(),
            'PersenDiskon' => $persen,
            'HargaTerkunci' => ! $berikut->TerapkanKePelangganLama,
        ];
    }

    /** Rupiah utuh dari string desimal ("99000.00" → 99000), tanpa float. */
    private static function RupiahBulat(Uang $uang): int
    {
        return (int) explode('.', $uang->KeString())[0];
    }

    /** 12500 → "12.500" (tanpa number_format, D-05 arsitektur). */
    private static function FormatRibuan(int $nilai): string
    {
        return strrev(implode('.', str_split(strrev((string) $nilai), 3)));
    }

    /**
     * @return list<string>
     */
    private static function SusunBatas(Paket $paket): array
    {
        $baris = [];

        foreach (self::LABEL_BATAS as $kolom => [$label, $tanpaBatas]) {
            $nilai = $paket->AmbilBatas()[$kolom] ?? null;

            if ($nilai === null) {
                if ($tanpaBatas !== null) {
                    $baris[] = $tanpaBatas;
                }

                continue;
            }

            if ($nilai > 0) {
                $baris[] = self::FormatRibuan($nilai).' '.$label;
            }
        }

        return $baris;
    }
}
