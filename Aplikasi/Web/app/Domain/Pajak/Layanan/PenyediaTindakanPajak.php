<?php

declare(strict_types=1);

namespace App\Domain\Pajak\Layanan;

use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;
use App\Domain\Pajak\Model\TarifPajak;

/**
 * Kotak Tindakan domain Pajak (audit E10): tenant diberi tahu saat tarif pajak nasional terbit dan berubah (bukan tarif
 * pertama suatu jenis pajak). Muncul untuk tarif yang mulai berlaku paling lama 30 hari ke depan atau paling
 * lama 7 hari ke belakang. Aplikasi kasir mengambil tarif baru lewat
 * `data-awal` dan server menerima tarif lama selama 30 hari (D-115), jadi butir ini hanya pengingat agar pemilik
 * memastikan semua perangkat tersinkron; tarif daerah (PBJT) tidak ditampilkan karena bergantung kota outlet.
 * Untuk pemegang izin `akuntansi.kelola`.
 */
final class PenyediaTindakanPajak implements PenyediaTindakan
{
    /** Hari ke depan tarif yang akan berlaku sudah diumumkan. */
    public const HARI_UMUM_DI_DEPAN = 30;

    /** Hari ke belakang tarif yang baru berlaku masih diingatkan. */
    public const HARI_INGAT_DI_BELAKANG = 7;

    public function Kumpulkan(DataKonteksTindakan $konteks): array
    {
        if (! $konteks->CekIzin('akuntansi.kelola')) {
            return [];
        }

        $batasBawah = $konteks->hariIni->subDays(self::HARI_INGAT_DI_BELAKANG)->toDateString();
        $batasAtas = $konteks->hariIni->addDays(self::HARI_UMUM_DI_DEPAN)->toDateString();
        $butir = [];

        $tarif = TarifPajak::query()
            ->with('JenisPajak')
            ->where('Status', StatusDataMaster::Terbit->value)
            ->whereNull('KodeWilayah')
            ->whereDate('BerlakuMulai', '>=', $batasBawah)
            ->whereDate('BerlakuMulai', '<=', $batasAtas)
            ->whereExists(fn ($kueri) => $kueri->selectRaw('1')->from('TarifPajak as Lama')
                ->whereColumn('Lama.IdJenisPajak', 'TarifPajak.IdJenisPajak')
                ->whereNull('Lama.KodeWilayah')
                ->where('Lama.Status', StatusDataMaster::Terbit->value)
                ->whereColumn('Lama.BerlakuMulai', '<', 'TarifPajak.BerlakuMulai'))
            ->orderBy('BerlakuMulai')
            ->get();

        foreach ($tarif as $baru) {
            $mulai = $baru->BerlakuMulai;
            $sudahBerlaku = $mulai->toDateString() <= $konteks->hariIni->toDateString();
            $nama = $baru->JenisPajak->Nama ?? 'Pajak';
            $persen = rtrim(rtrim((string) $baru->Tarif, '0'), '.');
            $butir[] = new DataButirTindakan(
                'pajak.tarif.'.$baru->Id,
                'Pajak',
                $sudahBerlaku ? TingkatTindakan::Perhatian : TingkatTindakan::Info,
                $sudahBerlaku ? "Tarif {$nama} {$persen}% sudah berlaku" : "Tarif {$nama} berubah menjadi {$persen}%",
                ($sudahBerlaku ? 'Berlaku sejak ' : 'Berlaku mulai ').$mulai->format('d/m/Y')
                    .'. Aplikasi kasir memperbarui tarif otomatis saat tersinkron. Pastikan semua perangkat kasir pernah tersambung internet sebelum dan sesudah tanggal itu.',
                1,
                '/kelola/kelompok-pajak',
                'Lihat kelompok pajak',
            );
        }

        return $butir;
    }

    public function AmbilJenisDokumen(): array
    {
        return [];
    }

    public function SaringDokumen(string $jenisDokumen, array $uuid): array
    {
        return [];
    }
}
