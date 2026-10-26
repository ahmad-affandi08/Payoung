<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Komunikasi\Enum\StatusKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Enum\StatusKontakProspek;
use App\Domain\Pengelola\Komunikasi\Enum\StatusPenerimaSiaran;
use App\Domain\Pengelola\Komunikasi\Layanan\PenghitungKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Model\KampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Model\KontakProspek;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Menjalankan, menjeda, melanjutkan, atau membatalkan kampanye prospek (D-216). Saat Draf dijalankan, penerima ditetapkan
 * dari kontak aktif di sektor terpilih pada saat itu. Kampanye hanya bisa jalan bila email kampanye (integrasi
 * `EmailKampanye`, domain pengirim terpisah dari email transaksi) aktif: tanpa itu tidak ada yang dikirim, dan
 * sengaja tidak jatuh ke pengirim email transaksional.
 */
final class UbahStatusKampanyeProspek
{
    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    public function Jalankan(PenggunaPengelola $pelaku, KampanyeProspek $kampanye, StatusKampanyeProspek $tujuan): KampanyeProspek
    {
        return DB::transaction(function () use ($pelaku, $kampanye, $tujuan): KampanyeProspek {
            $terkini = KampanyeProspek::query()->lockForUpdate()->findOrFail($kampanye->Id);
            $asal = $terkini->Status;

            if (! in_array($tujuan, $asal->AmbilTujuan(), true)) {
                throw new PelanggaranAturanBisnis('P-12', "Kampanye berstatus {$asal->AmbilLabel()} tidak bisa diubah menjadi {$tujuan->AmbilLabel()}.", 'Status');
            }

            if ($tujuan === StatusKampanyeProspek::Berjalan && config('integrasi.EmailKampanyeAktif') !== true) {
                throw new PelanggaranAturanBisnis('P-12', 'Email kampanye belum aktif. Atur dan aktifkan di Integrasi › Email kampanye (pakai domain pengirim terpisah dari email transaksi).', 'Status');
            }

            $ubah = ['Status' => $tujuan];

            if ($asal === StatusKampanyeProspek::Draf && $tujuan === StatusKampanyeProspek::Berjalan) {
                $jumlah = $this->TetapkanPenerima($terkini);

                if ($jumlah === 0) {
                    throw new PelanggaranAturanBisnis('P-12', 'Tidak ada kontak aktif di sektor yang dipilih, jadi kampanye tidak bisa dijalankan.', 'Status');
                }

                $ubah += ['JumlahPenerima' => $jumlah, 'MulaiKirimPada' => Carbon::now()];
            }

            if ($tujuan === StatusKampanyeProspek::Berjalan) {
                $ubah += ['AlasanJeda' => null];
            }

            if ($tujuan === StatusKampanyeProspek::Dibatalkan) {
                $ubah += ['SelesaiPada' => Carbon::now()];
                $terkini->update($ubah);
                DB::table('PenerimaKampanyeProspek')
                    ->where('IdKampanyeProspek', $terkini->Id)
                    ->where('Status', StatusPenerimaSiaran::Menunggu->value)
                    ->update(['Status' => StatusPenerimaSiaran::Dilewati->value, 'Alasan' => 'Kampanye dibatalkan.', 'DiubahPada' => Carbon::now()]);
                PenghitungKampanyeProspek::Segarkan($terkini);
            } else {
                $terkini->update($ubah);
            }

            $this->audit->Catat(
                'prospek.kampanye.status',
                $terkini,
                nilaiLama: ['Status' => $asal->value],
                nilaiBaru: ['Status' => $tujuan->value],
                idPelaku: $pelaku->Id,
            );

            return $terkini->refresh();
        });
    }

    private function TetapkanPenerima(KampanyeProspek $kampanye): int
    {
        $jumlah = 0;
        $sekarang = Carbon::now();
        $sektor = array_values(array_filter((array) ($kampanye->Segmen['Sektor'] ?? []), 'is_string'));

        KontakProspek::query()
            ->where('Status', StatusKontakProspek::Aktif->value)
            ->whereIn('Sektor', $sektor)
            ->orderBy('Id')
            ->chunkById(500, function ($kontak) use ($kampanye, $sekarang, &$jumlah): void {
                $baris = [];

                foreach ($kontak as $k) {
                    $baris[] = [
                        'IdKampanyeProspek' => $kampanye->Id,
                        'IdKontakProspek' => $k->Id,
                        'Status' => StatusPenerimaSiaran::Menunggu->value,
                        'DibuatPada' => $sekarang,
                        'DiubahPada' => $sekarang,
                    ];
                }

                DB::table('PenerimaKampanyeProspek')->insert($baris);
                $jumlah += count($baris);
            }, 'Id');

        return $jumlah;
    }
}
