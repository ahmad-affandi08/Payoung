<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Snap\Data;

/**
 * Satu bank virtual account yang dibuka di akun DOKU Payoung. Nilainya diambil dari dasbor DOKU dan diisi operator di
 * konsol Integrasi, bukan ditebak kode: `kode` = `additionalInfo.channel` (misal `VIRTUAL_ACCOUNT_BNI`),
 * `partnerServiceId` = kode perusahaan/BIN dari DOKU, `prefiksPelanggan` = awalan `customerNo`.
 *
 * Format satu entri teks: `Label|KODE_CHANNEL|PartnerServiceId|Prefiks`, entri dipisah titik koma atau baris baru.
 */
final class KanalVaSnap
{
    public function __construct(
        public readonly string $label,
        public readonly string $kode,
        public readonly string $partnerServiceId,
        public readonly string $prefiksPelanggan,
    ) {}

    /**
     * @return list<self> entri yang tidak lengkap atau tidak sah dibuang diam-diam (tidak jadi pilihan bayar)
     */
    public static function Urai(string $teks): array
    {
        $daftar = [];

        foreach (preg_split('/[;\r\n]+/', $teks) ?: [] as $baris) {
            $bagian = array_map('trim', explode('|', $baris));

            if (count($bagian) !== 4) {
                continue;
            }

            [$label, $kode, $partner, $prefiks] = $bagian;

            if ($label === '' || preg_match('/^[A-Z][A-Z0-9_]{2,29}$/', $kode) !== 1
                || preg_match('/^\d{1,8}$/', $partner) !== 1 || preg_match('/^\d{1,19}$/', $prefiks) !== 1) {
                continue;
            }

            $daftar[$kode] = new self(mb_substr($label, 0, 40), $kode, $partner, $prefiks);
        }

        return array_values($daftar);
    }

    /** `partnerServiceId` rata kanan dengan spasi 8 karakter, sebagaimana diminta SNAP. */
    public function AmbilPartnerServiceIdBerisi(): string
    {
        return str_pad($this->partnerServiceId, 8, ' ', STR_PAD_LEFT);
    }
}
