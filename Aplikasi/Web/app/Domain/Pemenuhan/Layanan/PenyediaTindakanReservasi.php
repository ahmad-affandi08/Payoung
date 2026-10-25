<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Layanan;

use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Data\DataRincianTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\TerbatasSektor;
use App\Domain\Pemenuhan\Enum\StatusReservasi;
use App\Domain\Pemenuhan\Enum\StatusReservasiMeja;
use App\Domain\Pemenuhan\Model\Reservasi;
use App\Domain\Pemenuhan\Model\ReservasiMeja;

/**
 * Kotak Tindakan untuk reservasi (F-07 mode service, izin `reservasi.kelola`, dibatasi outlet akses): reservasi
 * online yang menunggu konfirmasi toko dan belum lewat jamnya. Selesai sendiri saat dikonfirmasi/dibatalkan.
 *
 * FnB reservasi meja (D-145): reservasi yang sudah berakhir tetapi masih memegang sisa uang muka (tagihan lebih kecil
 * dari DP) — kewajiban menggantung sampai dikembalikan atau dihanguskan.
 */
final class PenyediaTindakanReservasi implements PenyediaTindakan, TerbatasSektor
{
    public function AmbilSektor(): array
    {
        return ['SVC', 'FNB', 'RTL-PET'];
    }

    public function AmbilKecualiSektor(): array
    {
        return [];
    }

    public function Kumpulkan(DataKonteksTindakan $konteks): array
    {
        if (! $konteks->CekIzin('reservasi.kelola')) {
            return [];
        }

        $kueri = Reservasi::query()
            ->where('Status', StatusReservasi::Menunggu->value)
            ->where('MulaiPada', '>', now())
            ->when($konteks->idOutletBoleh !== null, fn ($k) => $k->whereIn('IdOutlet', $konteks->idOutletBoleh ?: [0]));
        $jumlah = (clone $kueri)->count();
        $dpTergantung = ReservasiMeja::query()
            ->whereNotIn('Status', StatusReservasiMeja::AmbilNilaiMemakaiMeja())
            ->whereRaw('(`JumlahDp` - `DpTerpakai` - `DpDikembalikan` - `DpHangus`) > 0')
            ->when($konteks->idOutletBoleh !== null, fn ($k) => $k->whereIn('IdOutlet', $konteks->idOutletBoleh ?: [0]));
        $jumlahDp = (clone $dpTergantung)->count();

        return [new DataButirTindakan(
            'reservasi.menunggu-konfirmasi',
            'Reservasi',
            TingkatTindakan::Perhatian,
            'Reservasi online menunggu konfirmasi',
            'Konfirmasi atau tolak agar pelanggan tahu jadwalnya.',
            $jumlah,
            '/kelola/reservasi?saring[Status]=Menunggu',
            'Lihat reservasi',
            $jumlah === 0 ? [] : array_values($kueri->orderBy('MulaiPada')->limit(DataButirTindakan::BATAS_RINCIAN)->get()->map(
                fn (Reservasi $r): DataRincianTindakan => new DataRincianTindakan($r->Uuid, $r->Nomor, $r->NamaPelanggan, $r->MulaiPada->toDateString(), '/kelola/reservasi?cari='.rawurlencode($r->Nomor)),
            )->all()),
        ), new DataButirTindakan(
            'reservasi-meja.uang-muka-menggantung',
            'Reservasi',
            TingkatTindakan::Perhatian,
            'Uang muka reservasi meja belum diselesaikan',
            'Reservasi sudah berakhir tetapi uang mukanya masih tercatat sebagai kewajiban. Kembalikan ke tamu atau hanguskan.',
            $jumlahDp,
            '/kelola/reservasi-meja',
            'Lihat reservasi meja',
            $jumlahDp === 0 ? [] : array_values($dpTergantung->orderBy('MulaiPada')->limit(DataButirTindakan::BATAS_RINCIAN)->get()->map(
                fn (ReservasiMeja $r): DataRincianTindakan => new DataRincianTindakan($r->Uuid, $r->Nomor, $r->NamaPemesan, $r->MulaiPada->toDateString(), '/kelola/reservasi-meja?cari='.rawurlencode($r->Nomor)),
            )->all()),
        )];
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
