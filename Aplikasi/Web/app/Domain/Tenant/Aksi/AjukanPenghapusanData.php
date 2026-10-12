<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Lisensi\Enum\EdisiAplikasi;
use App\Domain\Organisasi\Kueri\PemilikTenant;
use App\Domain\Tenant\Enum\StatusPermintaanPenghapusanData;
use App\Domain\Tenant\Enum\StatusTenant;
use App\Domain\Tenant\Model\EksporDataTenant;
use App\Domain\Tenant\Model\PermintaanPenghapusanData;
use App\Domain\Tenant\Model\Tenant;
use App\Domain\Tenant\Surel\PenghapusanDataDiajukan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Owner mengajukan penghapusan data dan penutupan akun (UU PDP, P-07). Syarat: edisi SaaS (di edisi Lisensi server
 * milik pembeli sendiri), tenant masih aktif, belum ada permintaan menunggu, dan Owner sudah mengunduh ekspor data
 * lengkap sedikitnya sekali ("tenant mengunduh export lengkap"). Pelaksanaan paling cepat setelah
 * `tenant.HariTungguPenghapusan` hari dan oleh Super Admin; selama itu Owner bisa membatalkan. Semua Owner aktif
 * diberi tahu lewat email, jadi permintaan dari sesi yang diambil alih tidak berjalan diam-diam.
 * Verifikasi identitas (kata sandi / frasa konfirmasi) dilakukan pemanggil sebelum aksi ini.
 */
final class AjukanPenghapusanData
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PemilikTenant $pemilik,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(int $idPengguna, ?string $alasan): PermintaanPenghapusanData
    {
        $idTenant = $this->konteks->Wajib();

        if (EdisiAplikasi::CekLisensi()) {
            throw new PelanggaranAturanBisnis('PenghapusanTidakTersedia', 'Penutupan akun tidak tersedia di edisi Mandiri: data ada di server Anda sendiri.');
        }

        if (! $this->pemilik->CekPemilik($idPengguna, $idTenant)) {
            throw new PelanggaranAturanBisnis('HanyaPemilik', 'Hanya Pemilik usaha yang bisa mengajukan penghapusan data.');
        }

        $permintaan = DB::transaction(function () use ($idTenant, $idPengguna, $alasan): PermintaanPenghapusanData {
            $tenant = Tenant::query()->whereKey($idTenant)->lockForUpdate()->firstOrFail();

            if ($tenant->Status !== StatusTenant::Aktif) {
                throw new PelanggaranAturanBisnis('TenantTidakAktif', 'Usaha ini sudah ditutup.');
            }

            if (PermintaanPenghapusanData::query()->where('Status', StatusPermintaanPenghapusanData::Menunggu->value)->exists()) {
                throw new PelanggaranAturanBisnis('PermintaanSudahAda', 'Sudah ada permintaan penghapusan data yang menunggu. Batalkan dulu bila ingin mengajukan ulang.');
            }

            if (! EksporDataTenant::query()->whereNotNull('DiunduhPada')->exists()) {
                throw new PelanggaranAturanBisnis('EksporBelumDiunduh', 'Unduh ekspor data lengkap Anda lebih dulu (bagian Ekspor data usaha di halaman ini). Setelah akun ditutup, data tidak bisa dikembalikan.');
            }

            $baru = PermintaanPenghapusanData::query()->create([
                'IdTenant' => $idTenant,
                'IdPenggunaPeminta' => $idPengguna,
                'Status' => StatusPermintaanPenghapusanData::Menunggu,
                'Alasan' => $alasan !== null && trim($alasan) !== '' ? trim($alasan) : null,
                'BerlakuPada' => now()->addDays((int) config('tenant.HariTungguPenghapusan')),
            ]);
            $this->audit->Catat('data.penghapusan-ajukan', $baru, nilaiBaru: ['BerlakuPada' => $baru->BerlakuPada->toIso8601String()], idTenant: $idTenant, idPengguna: $idPengguna);

            return $baru;
        });

        $namaUsaha = (string) Tenant::query()->whereKey($idTenant)->value('Nama');
        $berlakuPada = $permintaan->BerlakuPada->copy()->setTimezone('Asia/Jakarta')->translatedFormat('j F Y H:i').' WIB';

        foreach ($this->pemilik->AmbilKontak($idTenant) as $kontak) {
            if ($kontak['Email'] === null) {
                continue;
            }

            try {
                Mail::to($kontak['Email'])->queue(new PenghapusanDataDiajukan($kontak['Nama'], $namaUsaha, $berlakuPada));
            } catch (Throwable $galat) {
                Log::warning('Email permintaan penghapusan data gagal dikirim.', ['IdTenant' => $idTenant, 'Galat' => $galat->getMessage()]);
            }
        }

        return $permintaan;
    }
}
