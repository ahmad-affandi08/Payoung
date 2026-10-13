<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Pengelola\Operasional\Layanan\PengaturanAlertOperasional;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Model\PengaturanPlatform;
use Illuminate\Support\Facades\DB;

/**
 * Teknis mengatur nomor WhatsApp penerima alert kritis (P-11, PGL-20). Nomor dirapikan ke `628…`, harus sah, tanpa
 * duplikat, maksimal 5. Daftar kosong = alert WhatsApp mati (email tetap). Perubahan diaudit dengan nomor tersamar.
 */
final class AturNomorWaAlertOperasional
{
    public function __construct(
        private readonly PengaturanAlertOperasional $pengaturan,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    /**
     * @param  list<string>  $nomor
     * @return list<string> Nomor tersimpan.
     */
    public function Jalankan(PenggunaPengelola $pelaku, array $nomor): array
    {
        $rapi = [];

        foreach ($nomor as $mentah) {
            if (trim($mentah) === '') {
                continue;
            }

            $sah = PesanWhatsapp::AmbilNomorSah($mentah);

            if ($sah === null) {
                throw new PelanggaranAturanBisnis('NomorWaTidakSah', "Nomor \"{$mentah}\" bukan nomor WhatsApp Indonesia yang sah.", 'NomorWa');
            }

            $rapi[$sah] = $sah;
        }

        $rapi = array_values($rapi);

        if (count($rapi) > PengaturanAlertOperasional::MAKS_NOMOR) {
            throw new PelanggaranAturanBisnis('NomorWaTerlaluBanyak', 'Maksimal '.PengaturanAlertOperasional::MAKS_NOMOR.' nomor penerima alert.', 'NomorWa');
        }

        return DB::transaction(function () use ($pelaku, $rapi): array {
            $lama = $this->pengaturan->NomorWa();
            PengaturanPlatform::query()->updateOrCreate(
                ['Kunci' => PengaturanAlertOperasional::KUNCI_NOMOR_WA],
                ['Nilai' => json_encode($rapi, JSON_THROW_ON_ERROR), 'IdPenggunaPengelolaPengubah' => $pelaku->Id],
            );
            $this->audit->Catat(
                'operasional.alert.nomor-wa.atur',
                null,
                nilaiLama: ['Nomor' => array_map(self::Samarkan(...), $lama)],
                nilaiBaru: ['Nomor' => array_map(self::Samarkan(...), $rapi)],
                idPelaku: $pelaku->Id,
            );

            return $rapi;
        });
    }

    /** `6281234567890` → `62812••••7890`. */
    private static function Samarkan(string $nomor): string
    {
        return strlen($nomor) <= 7 ? '••••' : substr($nomor, 0, 5).'••••'.substr($nomor, -4);
    }
}
