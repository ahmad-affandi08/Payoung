<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Layanan;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Bersama\Tenant\LingkupTenant;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Tenant\Enum\StatusTenant;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\PemakaianPenyimpanan;
use App\Domain\Tenant\Model\PermintaanPenghapusanData;
use App\Domain\Tenant\Model\RefundLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Satu-satunya jalan Platform Pengelola membaca data milik tenant (model `MilikTenant`), CLAUDE.md #11, PRD §13.4,
 * §13.8. Setiap pemanggilan wajib beralasan dan dicatat di `LogAuditPengelola` (aksi `tenant.data.akses`).
 *
 * - Dengan `$idTenant`: tenant aktif sementara ditetapkan ke tenant itu, sehingga scope `MilikTenant` tetap bekerja dan
 *   hanya data tenant tersebut yang terbaca. Konteks sebelumnya dipulihkan setelah selesai (juga saat galat).
 * - Tanpa `$idTenant` (lintas semua tenant, misal agregat platform): closure memakai `KueriLintas()` yang melepas
 *   `LingkupTenant`. `KueriLintas()` menolak dipanggil di luar `JalankanLintasTenant`.
 */
final class KonteksPengelola
{
    public const AKSI_AUDIT = 'tenant.data.akses';

    /**
     * Model `MilikTenant` yang isinya catatan milik platform tentang tenant (bukan data usaha tenant), sehingga boleh
     * dibaca pengelola tanpa log akses per kueri (PRD §13.4). Aksi yang mengubahnya tetap diaudit oleh aksinya.
     *
     * @var list<class-string<Model>>
     */
    public const MODEL_DATA_PLATFORM = [TagihanLangganan::class, PembayaranLangganan::class, RefundLangganan::class, PermintaanPenghapusanData::class, PemakaianPenyimpanan::class];

    private int $kedalamanLintas = 0;

    public function __construct(
        private readonly KonteksTenant $konteksTenant,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    /**
     * @template THasil
     *
     * @param  Closure(self): THasil  $fungsi
     * @return THasil
     */
    public function JalankanLintasTenant(string $alasan, Closure $fungsi, ?int $idTenant = null, int $auditSekaliPerMenit = 0): mixed
    {
        $alasan = trim($alasan);

        if ($alasan === '') {
            throw new InvalidArgumentException('Akses data tenant oleh pengelola wajib menyebut alasan.');
        }

        if ($this->CekPerluAudit($alasan, $idTenant, $auditSekaliPerMenit)) {
            $this->audit->Catat(
                self::AKSI_AUDIT,
                nilaiBaru: ['Cakupan' => $idTenant === null ? 'SemuaTenant' : 'SatuTenant'],
                alasan: $alasan,
                idTenant: $idTenant,
            );
        }

        if ($idTenant === null) {
            $this->kedalamanLintas++;

            try {
                return $fungsi($this);
            } finally {
                $this->kedalamanLintas--;
            }
        }

        $konteksSebelumnya = $this->konteksTenant->Ambil();
        $this->konteksTenant->Atur($idTenant);

        try {
            return $fungsi($this);
        } finally {
            $konteksSebelumnya === null ? $this->konteksTenant->Kosongkan() : $this->konteksTenant->Atur($konteksSebelumnya);
        }
    }

    /**
     * Akses yang diulang berkala oleh layar waktu nyata (inbox obrolan dukungan, PLT-12) dicatat satu kali per
     * `$auditSekaliPerMenit` untuk pelaku, alasan, dan tenant yang sama, bukan tiap polling; akses lain selalu dicatat.
     */
    private function CekPerluAudit(string $alasan, ?int $idTenant, int $auditSekaliPerMenit): bool
    {
        if ($auditSekaliPerMenit <= 0) {
            return true;
        }

        $pelaku = (int) (Auth::guard('pengelola')->id() ?? 0);

        return Cache::add('konteks-pengelola.audit.'.hash('sha256', "{$pelaku}|{$idTenant}|{$alasan}"), true, $auditSekaliPerMenit * 60);
    }

    /**
     * Kueri catatan platform (lihat `MODEL_DATA_PLATFORM`) lintas tenant, misal antrean verifikasi tagihan P-08.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $kelasModel
     * @return Builder<TModel>
     */
    public function KueriDataPlatform(string $kelasModel): Builder
    {
        $namaKelas = ltrim($kelasModel, '\\');

        if (! in_array($namaKelas, self::MODEL_DATA_PLATFORM, true)) {
            throw new LogicException("{$kelasModel} adalah data usaha tenant; baca lewat JalankanLintasTenant.");
        }

        return $kelasModel::query()->withoutGlobalScope(LingkupTenant::class);
    }

    /**
     * Daftar `IdTenant` yang punya pekerjaan tertunda untuk penyapu terjadwal (webhook, kedaluwarsa, rekonsiliasi, dsb.).
     *
     * Sengaja **tanpa baris audit** `tenant.data.akses`: penyapu berjalan tiap menit dan hasilnya hanya pengenal tenant
     * (bukan data usaha), jadi audit per kali jalan hanya menggelembungkan log. Akses ke datanya tetap lewat scope tenant
     * biasa setelah penyapu memilih tenant (`KonteksTenant::Atur`). Tanpa ini penyapu harus mengunjungi semua tenant,
     * yaitu puluhan ribu kueri per menit pada skala besar.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $kelasModel
     * @param  Closure(Builder<TModel>): mixed  $saring  menyempitkan kueri ke baris yang butuh dikerjakan
     * @return list<int>
     */
    public function IdTenantDenganPekerjaan(string $kelasModel, Closure $saring, int $batas = 5000, bool $termasukDitutup = false): array
    {
        $kueri = $kelasModel::query()->withoutGlobalScope(LingkupTenant::class);
        $saring($kueri);

        $dasar = $kueri->toBase();

        // Tenant yang akunnya sudah ditutup (UU PDP, P-07) tidak punya pekerjaan lagi; hanya penyapu retensi berkas yang
        // sengaja mencarinya.
        if (! $termasukDitutup) {
            $dasar->whereNotIn('IdTenant', DB::table('Tenant')->where('Status', StatusTenant::Ditutup->value)->select('Id'));
        }

        return array_values(array_map('intval', $dasar->distinct()->orderBy('IdTenant')->limit($batas)->pluck('IdTenant')->all()));
    }

    /**
     * Kueri model `MilikTenant` tanpa scope tenant. Hanya di dalam `JalankanLintasTenant` tanpa `$idTenant`.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $kelasModel
     * @return Builder<TModel>
     */
    public function KueriLintas(string $kelasModel): Builder
    {
        if ($this->kedalamanLintas === 0) {
            throw new LogicException('KueriLintas hanya boleh dipanggil di dalam KonteksPengelola::JalankanLintasTenant tanpa tenant.');
        }

        return $kelasModel::query()->withoutGlobalScope(LingkupTenant::class);
    }
}
