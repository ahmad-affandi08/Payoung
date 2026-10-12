<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Data\DataWhatsappTenant;
use App\Domain\Integrasi\Enum\StatusUjiGerbang;
use App\Domain\Integrasi\Model\WhatsappTenant;
use App\Domain\Integrasi\Whatsapp\DaftarPenyediaWhatsapp;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Menyimpan WhatsApp milik tenant (D-108): nomor dan akun penyedia sendiri sehingga pesan tidak memakai kuota
 * Payoung. Kredensial terenkripsi dengan petunjuk 4 karakter terakhir (BR-P05.1); ganti penyedia = kredensial wajib
 * diisi ulang. Perubahan apa pun mengembalikan status ke `BelumDiuji` dan menonaktifkan sampai diuji ulang (pola
 * BR-P05.4). Audit hanya memuat nama bidang kredensial yang diganti.
 */
final class SimpanWhatsappTenant
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PemeriksaFiturTenant $fitur,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(DataWhatsappTenant $data): WhatsappTenant
    {
        if (! $this->fitur->CekAktif($this->konteks->Wajib(), PemeriksaFiturTenant::KUNCI_WHATSAPP)) {
            throw new PelanggaranAturanBisnis('FiturTidakAktif', 'WhatsApp belum termasuk di paket Anda. Naikkan paket atau tambah add-on.', 'Penyedia');
        }

        if (! DaftarPenyediaWhatsapp::CekDikenal($data->penyedia)) {
            throw new PelanggaranAturanBisnis('PenyediaTidakDikenal', 'Pilih penyedia WhatsApp dari daftar.', 'Penyedia');
        }

        try {
            return DB::transaction(fn (): WhatsappTenant => $this->Simpan($data));
        } catch (UniqueConstraintViolationException) {
            throw new PelanggaranAturanBisnis('SudahAda', 'Pengaturan WhatsApp baru saja disimpan pengguna lain. Muat ulang halaman.');
        }
    }

    private function Simpan(DataWhatsappTenant $data): WhatsappTenant
    {
        $baris = WhatsappTenant::query()->lockForUpdate()->first();
        $gantiPenyedia = $baris !== null && $baris->Penyedia !== $data->penyedia;
        $kredensialLama = $baris === null || $gantiPenyedia ? [] : $baris->Kredensial;
        $kredensialBaru = [];
        $kredensialBerubah = [];

        foreach ($data->penyedia->AmbilBidangKredensial() as $bidang) {
            $kunci = $bidang['Kunci'];
            $nilai = $data->kredensial[$kunci] ?? '';

            if ($nilai === '') {
                if (isset($kredensialLama[$kunci])) {
                    $kredensialBaru[$kunci] = $kredensialLama[$kunci];
                } elseif ($bidang['Wajib']) {
                    throw new PelanggaranAturanBisnis('KredensialWajib', "{$bidang['Label']} wajib diisi.", "Kredensial.{$kunci}");
                }

                continue;
            }

            $kredensialBaru[$kunci] = $nilai;

            if (($kredensialLama[$kunci] ?? null) !== $nilai) {
                $kredensialBerubah[] = $kunci;
            }
        }

        $isiBerubah = $baris === null || $gantiPenyedia || $baris->Pengaturan !== $data->pengaturan || $kredensialBerubah !== [];
        $nilaiLama = $baris === null ? null : ['Penyedia' => $baris->Penyedia->value, 'Aktif' => $baris->Aktif];

        $baris ??= new WhatsappTenant;
        $baris->fill([
            'Penyedia' => $data->penyedia,
            'Pengaturan' => $data->pengaturan,
            'Kredensial' => $kredensialBaru,
            'PetunjukKredensial' => array_map(self::BuatPetunjuk(...), $kredensialBaru),
        ]);

        if ($isiBerubah) {
            // Isian yang belum terbukti tersambung tidak dipakai mengirim pesan.
            $baris->fill(['StatusUji' => StatusUjiGerbang::BelumDiuji, 'PesanUji' => null, 'DiujiPada' => null, 'Aktif' => false]);
        }

        $baris->save();
        $this->audit->Catat(
            $nilaiLama === null ? 'whatsapp-tenant.buat' : 'whatsapp-tenant.ubah',
            $baris,
            $nilaiLama,
            ['Penyedia' => $baris->Penyedia->value, 'Aktif' => $baris->Aktif, 'KredensialDiganti' => $kredensialBerubah],
        );

        return $baris;
    }

    /** BR-P05.1: hanya 4 karakter terakhir; nilai pendek tidak ditampilkan sama sekali. */
    public static function BuatPetunjuk(string $nilai): string
    {
        return mb_strlen($nilai) >= 12 ? '••••'.mb_substr($nilai, -4) : '••••';
    }
}
