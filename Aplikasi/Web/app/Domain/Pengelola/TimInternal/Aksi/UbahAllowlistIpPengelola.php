<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\TimInternal\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\AllowlistIpPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Model\PengaturanPlatform;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Super Admin mengatur daftar alamat IP yang boleh membuka konsol (PGL-01, D-146). Aturan:
 * - hanya pemegang izin `tim.batasan-ip.kelola`;
 * - tiap entri alamat IP atau CIDR yang sah, paling banyak 50, alasan 5–255 karakter;
 * - **alamat IP pelaku sendiri harus termasuk di daftar baru** (tidak boleh mengunci diri sendiri); daftar kosong
 *   mematikan pembatasan;
 * - tercatat di audit beserta daftar lama, baru, dan alasan. Pemulihan bila semua terkunci: `pengelola:kosongkan-allowlist-ip`.
 */
final class UbahAllowlistIpPengelola
{
    public function __construct(
        private readonly AllowlistIpPengelola $allowlist,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    /**
     * @return list<string> daftar yang tersimpan
     */
    public function Jalankan(PenggunaPengelola $pelaku, string $isian, string $ipPelaku, string $alasan): array
    {
        if (! $pelaku->PunyaIzin(IzinPengelola::TimBatasanIpKelola)) {
            throw new PelanggaranAturanBisnis('TanpaIzin', 'Pembatasan IP hanya bisa diatur Super Admin.');
        }

        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis('AlasanWajib', 'Tulis alasan 5–255 karakter.', 'Alasan');
        }

        $urai = AllowlistIpPengelola::Urai($isian);

        if ($urai['TidakSah'] !== []) {
            throw new PelanggaranAturanBisnis('IpTidakSah', 'Alamat tidak sah: '.implode(', ', array_slice($urai['TidakSah'], 0, 5)).'. Tulis alamat IP atau rentang CIDR, satu per baris.', 'Daftar');
        }

        if (count($urai['Sah']) > AllowlistIpPengelola::MAKS_ENTRI) {
            throw new PelanggaranAturanBisnis('TerlaluBanyak', 'Paling banyak '.AllowlistIpPengelola::MAKS_ENTRI.' entri.', 'Daftar');
        }

        if ($urai['Sah'] !== [] && ! IpUtils::checkIp($ipPelaku, $urai['Sah'])) {
            throw new PelanggaranAturanBisnis('MengunciDiriSendiri', "Alamat IP Anda saat ini ({$ipPelaku}) tidak ada di daftar. Tambahkan dulu supaya Anda tidak terkunci.", 'Daftar');
        }

        return DB::transaction(function () use ($pelaku, $urai, $alasan): array {
            $lama = $this->allowlist->Ambil();
            PengaturanPlatform::query()->updateOrCreate(
                ['Kunci' => AllowlistIpPengelola::KUNCI],
                ['Nilai' => implode("\n", $urai['Sah']), 'IdPenggunaPengelolaPengubah' => $pelaku->Id],
            );
            $this->audit->Catat(
                'tim.batasan-ip.ubah',
                null,
                nilaiLama: ['Daftar' => $lama],
                nilaiBaru: ['Daftar' => $urai['Sah']],
                alasan: $alasan,
                idPelaku: $pelaku->Id,
            );

            return $urai['Sah'];
        });
    }
}
