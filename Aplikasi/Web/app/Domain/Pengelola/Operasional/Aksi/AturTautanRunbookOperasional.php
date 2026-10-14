<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Operasional\Layanan\PengaturanAlertOperasional;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Model\PengaturanPlatform;
use Illuminate\Support\Facades\DB;

/**
 * Teknis mengatur alamat dasar runbook alert (PGL-20 lanjutan). Hanya `https://` tanpa kredensial dalam alamat, tanpa
 * spasi/karakter kendali, dan tanpa fragmen (jangkar per alert ditambahkan dasbor). Kosong = tautan runbook dimatikan.
 * Perubahan diaudit dengan nilai lama dan baru (alamatnya bukan rahasia).
 */
final class AturTautanRunbookOperasional
{
    public function __construct(
        private readonly PengaturanAlertOperasional $pengaturan,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    /**
     * @return string|null Alamat tersimpan (null = dimatikan).
     *
     * @throws PelanggaranAturanBisnis TautanTidakSah
     */
    public function Jalankan(PenggunaPengelola $pelaku, ?string $tautan): ?string
    {
        $rapi = $tautan === null ? '' : trim($tautan);

        if ($rapi !== '') {
            $bagian = parse_url($rapi);

            if (
                $bagian === false
                || strlen($rapi) > PengaturanAlertOperasional::MAKS_PANJANG_TAUTAN
                || preg_match('/[\s\x00-\x1F\x7F]/', $rapi) === 1
                || ($bagian['scheme'] ?? '') !== 'https'
                || ($bagian['host'] ?? '') === ''
                || isset($bagian['user']) || isset($bagian['pass'])
                || isset($bagian['fragment'])
            ) {
                throw new PelanggaranAturanBisnis('TautanTidakSah', 'Tautan runbook harus berupa alamat https:// tanpa kredensial, spasi, dan tanda #.', 'TautanRunbook');
            }
        }

        $baru = $rapi === '' ? null : $rapi;

        return DB::transaction(function () use ($pelaku, $baru): ?string {
            $lama = $this->pengaturan->TautanRunbook();
            PengaturanPlatform::query()->updateOrCreate(
                ['Kunci' => PengaturanAlertOperasional::KUNCI_TAUTAN_RUNBOOK],
                ['Nilai' => $baru ?? '', 'IdPenggunaPengelolaPengubah' => $pelaku->Id],
            );
            $this->audit->Catat('operasional.runbook.atur', null, nilaiLama: ['Tautan' => $lama], nilaiBaru: ['Tautan' => $baru], idPelaku: $pelaku->Id);

            return $baru;
        });
    }
}
