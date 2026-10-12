<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPermintaanTurunPaket;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Enum\StatusTenant;
use App\Domain\Tenant\Layanan\PenganonimDataTenant;
use App\Domain\Tenant\Layanan\PenguncianTenant;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\LanggananAddon;
use App\Domain\Tenant\Model\PermintaanTurunPaket;
use App\Domain\Tenant\Model\TagihanLangganan;
use Illuminate\Support\Facades\DB;

/**
 * Menutup akun satu tenant dan menghapus data pribadinya (UU PDP, P-07). Dipanggil `LaksanakanPenghapusanDataTenant`
 * (Pengelola) di dalam konteks tenant. Dalam satu transaksi: tagihan terbuka dibatalkan, rencana turun paket dibatalkan,
 * add-on diakhiri, langganan menjadi `Berhenti` (pengecualian tertulis atas tabel transisi BR-00.7: penutupan akun boleh
 * dari status apa pun), data pribadi dianonimkan/dihapus (`PenganonimDataTenant`), lalu `Tenant.Status` menjadi
 * `Ditutup`. Dokumen transaksi dan pembukuan tidak disentuh. Ditolak bila pembayaran tagihan sedang diverifikasi
 * (`BatalkanTagihanLangganan`): selesaikan dulu supaya tidak ada uang yang menggantung.
 */
final class TutupAkunTenant
{
    public function __construct(
        private readonly PenguncianTenant $kunci,
        private readonly BatalkanTagihanLangganan $batalkanTagihan,
        private readonly PenganonimDataTenant $penganonim,
    ) {}

    /**
     * @return array{Dianonimkan: array<string, int>, Dihapus: array<string, int>, PenggunaDianonimkan: int, AnggotaDilepas: int, AuditDibersihkan: int}
     */
    public function Jalankan(int $idTenant): array
    {
        return DB::transaction(function () use ($idTenant): array {
            $tenant = $this->kunci->Kunci($idTenant);

            if ($tenant->Status === StatusTenant::Ditutup) {
                throw new PelanggaranAturanBisnis('TenantSudahDitutup', 'Usaha ini sudah ditutup.');
            }

            foreach (TagihanLangganan::query()->whereIn('Status', StatusTagihanLangganan::NilaiTerbuka())->get() as $terbuka) {
                $this->batalkanTagihan->Jalankan($terbuka->Uuid, 'Dibatalkan karena akun usaha ditutup.');
            }

            $langganan = Langganan::query()->where('IdTenant', $idTenant)->lockForUpdate()->first();

            if ($langganan !== null) {
                PermintaanTurunPaket::query()->where('IdLangganan', $langganan->Id)->where('Status', StatusPermintaanTurunPaket::Menunggu->value)
                    ->update(['Status' => StatusPermintaanTurunPaket::Dibatalkan->value, 'DibatalkanPada' => now(), 'AlasanBatal' => 'Akun usaha ditutup.']);
                $langganan->update(['Status' => StatusLangganan::Berhenti, 'StatusSebelumDitangguhkan' => null]);
            }

            LanggananAddon::query()->where('IdTenant', $idTenant)->where('SelesaiPada', '>', now())
                ->update(['SelesaiPada' => now(), 'PerpanjangOtomatis' => false, 'BerhentiPada' => now()]);
            LanggananAddon::query()->where('IdTenant', $idTenant)->where('PerpanjangOtomatis', true)->update(['PerpanjangOtomatis' => false]);

            return $this->penganonim->Jalankan($idTenant);
        });
    }
}
