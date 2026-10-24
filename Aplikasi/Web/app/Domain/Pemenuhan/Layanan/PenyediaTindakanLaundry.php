<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Layanan;

use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Data\DataRincianTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\TerbatasSektor;
use App\Domain\Pemenuhan\Kueri\DaftarLaundry;
use App\Domain\Pemenuhan\Kueri\PengaturanLaundryTenant;
use App\Domain\Pemenuhan\Model\TiketLaundry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kotak Tindakan laundry (§9.9, izin `laundry.kelola`, dibatasi outlet akses): cucian lewat estimasi tetapi belum siap
 * (Penting: pelanggan bisa datang dan kecewa) dan cucian siap yang belum diambil lebih dari N hari pengaturan
 * (Perhatian: hubungi pelanggan). Selesai sendiri saat statusnya berubah.
 */
final class PenyediaTindakanLaundry implements PenyediaTindakan, TerbatasSektor
{
    public function __construct(private readonly PengaturanLaundryTenant $pengaturan) {}

    public function AmbilSektor(): ?array
    {
        return ['SVC-LDR'];
    }

    public function AmbilKecualiSektor(): array
    {
        return [];
    }

    public function Kumpulkan(DataKonteksTindakan $konteks): array
    {
        if (! $konteks->CekIzin('laundry.kelola')) {
            return [];
        }

        $dasar = fn (): Builder => TiketLaundry::query()
            ->when($konteks->idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $konteks->idOutletBoleh ?: [0]));
        $hari = $this->pengaturan->Ambil()->HariBelumDiambil;
        $butir = [];

        $belumSiap = $dasar();
        DaftarLaundry::TerapkanBelumSiap($belumSiap);
        $butir[] = $this->Butir($belumSiap, 'laundry.lewat-estimasi', TingkatTindakan::Penting, 'Cucian lewat estimasi selesai', 'Selesaikan atau kabari pelanggan bahwa cucian terlambat.', 'BelumSiap', 'EstimasiSelesaiPada');

        $belumDiambil = $dasar();
        DaftarLaundry::TerapkanBelumDiambil($belumDiambil, $hari);
        $butir[] = $this->Butir($belumDiambil, 'laundry.belum-diambil', TingkatTindakan::Perhatian, "Cucian siap belum diambil lebih dari {$hari} hari", 'Hubungi pelanggan agar cucian segera diambil.', 'BelumDiambil', 'SiapPada');

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

    /**
     * @param  Builder<TiketLaundry>  $kueri
     */
    private function Butir(Builder $kueri, string $kode, TingkatTindakan $tingkat, string $judul, string $saran, string $saring, string $kolomUrut): DataButirTindakan
    {
        $jumlah = (clone $kueri)->count();

        return new DataButirTindakan(
            $kode,
            'Laundry',
            $tingkat,
            $judul,
            $saran,
            $jumlah,
            '/kelola/laundry?saring[Terlambat]='.$saring,
            'Lihat cucian',
            $jumlah === 0 ? [] : array_values($kueri->orderBy($kolomUrut)->limit(DataButirTindakan::BATAS_RINCIAN)->get()->map(
                fn (TiketLaundry $t): DataRincianTindakan => new DataRincianTindakan($t->Uuid, $t->Nomor, $t->NamaPelanggan, $t->EstimasiSelesaiPada->toDateString(), '/kelola/laundry?cari='.rawurlencode($t->Nomor)),
            )->all()),
        );
    }
}
