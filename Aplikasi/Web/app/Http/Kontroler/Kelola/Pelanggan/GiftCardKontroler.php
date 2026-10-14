<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Pelanggan;

use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pelanggan\Aksi\JualGiftCard;
use App\Domain\Pelanggan\Aksi\PerpanjangGiftCard;
use App\Domain\Pelanggan\Aksi\TutupGiftCard;
use App\Domain\Pelanggan\Kueri\DaftarGiftCard;
use App\Domain\Pelanggan\Kueri\PengaturanGiftCardTenant;
use App\Domain\Pelanggan\Layanan\KodeGiftCard;
use App\Domain\Pelanggan\Model\GiftCard;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Respons\ResponsTabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Gift card di back-office (CRM-06, D-153). Lihat daftar & riwayat: `pelanggan.lihat`; jual, perpanjang, hanguskan, dan
 * kembalikan uang: `pelanggan.deposit.kelola` (kartu bernilai simpanan, izin yang sama dengan deposit). Kode lengkap
 * kartu baru hanya dikirim sekali lewat flash `GiftCardBaru` setelah dijual.
 */
final class GiftCardKontroler extends DasarKelolaKontroler
{
    private const KUNCI_KARTU_BARU = 'GiftCardBaru';

    public function Daftar(Request $permintaan, DaftarGiftCard $daftar, AksesPengguna $akses, PengaturanGiftCardTenant $pengaturan, DaftarAkunPilihan $akun): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarGiftCard::KOLOM_URUT, DaftarGiftCard::URUT_BAWAAN, DaftarGiftCard::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Pelanggan/GiftCard', 'GiftCard', fn (): array => $daftar->AmbilTabel($tabel), function () use ($permintaan, $daftar, $akses, $pengaturan, $akun): array {
            $kelola = $akses->CekIzin($this->IdTenant(), $this->Pelaku()->Id, IzinTenant::PelangganDepositKelola);

            return [
                'Izin' => ['Kelola' => $kelola],
                'Ringkasan' => $daftar->AmbilRingkasan(),
                'Pengaturan' => [
                    'Berlaku' => $pengaturan->CekBerlaku(),
                    'MinimalNilai' => PengaturanGiftCardTenant::AmbilMinimalNilai()->KeString(),
                    'MaksimalNilai' => PengaturanGiftCardTenant::AmbilMaksimalNilai()->KeString(),
                    'MasaBerlakuBawaanHari' => PengaturanGiftCardTenant::AmbilMasaBerlakuBawaanHari(),
                ],
                'AkunKasBank' => $kelola ? $akun->AmbilKasBank() : [],
                'KartuBaru' => $permintaan->session()->get(self::KUNCI_KARTU_BARU),
            ];
        });
    }

    /** Riwayat buku satu kartu untuk dialog rincian. */
    public function Rincian(string $giftCard, DaftarGiftCard $daftar): JsonResponse
    {
        return response()->json($daftar->Rincian(strtoupper($giftCard)) ?? abort(404));
    }

    /** Tautan sumber jurnal gift card: ke daftar yang disaring nama pemegangnya tidak mungkin, jadi ke daftar kartu. */
    public function TampilkanMutasi(string $mutasiGiftCard, DaftarGiftCard $daftar): RedirectResponse
    {
        $uuid = $daftar->CariUuidDariMutasi(strtoupper($mutasiGiftCard)) ?? abort(404);

        return redirect()->route('kelola.pelanggan.gift-card.daftar', ['rincian' => $uuid]);
    }

    public function Jual(Request $permintaan, JualGiftCard $jual, TanggalBisnisOutlet $tanggal): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Uuid' => ['required', 'string', 'ulid'],
            'Nilai' => ['required', 'numeric', 'gt:0'],
            'UuidAkun' => ['required', 'string', 'ulid'],
            'NamaPemegang' => ['nullable', 'string', 'max:100'],
            'MasaBerlakuHari' => ['nullable', 'integer', 'min:1', 'max:'.PengaturanGiftCardTenant::MASA_BERLAKU_MAKSIMAL_HARI],
            'Catatan' => ['nullable', 'string', 'max:255'],
        ], attributes: ['Nilai' => 'nilai', 'UuidAkun' => 'akun kas/bank', 'NamaPemegang' => 'nama pemegang', 'MasaBerlakuHari' => 'masa berlaku']);
        $kartu = $jual->Jalankan(
            strtoupper((string) $valid['Uuid']),
            Uang::Dari((string) $valid['Nilai']),
            (string) $valid['UuidAkun'],
            null,
            isset($valid['NamaPemegang']) ? (string) $valid['NamaPemegang'] : null,
            isset($valid['MasaBerlakuHari']) ? (int) $valid['MasaBerlakuHari'] : null,
            isset($valid['Catatan']) ? (string) $valid['Catatan'] : null,
            $this->Pelaku()->Id,
            $tanggal->Hitung(null),
        );

        return redirect()->route('kelola.pelanggan.gift-card.daftar')->with(self::KUNCI_KARTU_BARU, [
            'Uuid' => $kartu->Uuid,
            'Kode' => $kartu->Kode,
            'Nilai' => $kartu->NilaiAwal,
            'BerlakuSampai' => $kartu->BerlakuSampai?->toDateString(),
        ]);
    }

    public function Perpanjang(Request $permintaan, string $giftCard, PerpanjangGiftCard $perpanjang, TanggalBisnisOutlet $tanggal): RedirectResponse
    {
        $valid = $permintaan->validate(['BerlakuSampai' => ['nullable', 'date_format:Y-m-d']], attributes: ['BerlakuSampai' => 'tanggal berlaku']);
        $baru = isset($valid['BerlakuSampai']) ? CarbonImmutable::parse((string) $valid['BerlakuSampai']) : null;
        $kartu = $perpanjang->Jalankan($this->Cari($giftCard), $baru, $this->Pelaku()->Id, $tanggal->Hitung(null));

        return back()->with('Kilat', $kartu->BerlakuSampai === null
            ? 'Gift card '.KodeGiftCard::Samarkan($kartu->Kode).' kini tanpa batas masa berlaku.'
            : 'Masa berlaku gift card '.KodeGiftCard::Samarkan($kartu->Kode).' sampai '.$kartu->BerlakuSampai->toDateString().'.');
    }

    public function Hanguskan(Request $permintaan, string $giftCard, TutupGiftCard $tutup, TanggalBisnisOutlet $tanggal): RedirectResponse
    {
        $valid = $permintaan->validate(['Alasan' => ['required', 'string', 'max:255']], attributes: ['Alasan' => 'alasan']);
        $kartu = $this->Cari($giftCard);
        $tutup->Hanguskan($kartu, (string) $valid['Alasan'], $this->Pelaku()->Id, $tanggal->Hitung(null));

        return back()->with('Kilat', 'Sisa saldo gift card '.KodeGiftCard::Samarkan($kartu->Kode).' dihanguskan.');
    }

    public function Kembalikan(Request $permintaan, string $giftCard, TutupGiftCard $tutup, TanggalBisnisOutlet $tanggal): RedirectResponse
    {
        $valid = $permintaan->validate([
            'UuidAkun' => ['required', 'string', 'ulid'],
            'Alasan' => ['required', 'string', 'max:255'],
        ], attributes: ['UuidAkun' => 'akun kas/bank', 'Alasan' => 'alasan']);
        $kartu = $this->Cari($giftCard);
        $tutup->Kembalikan($kartu, (string) $valid['UuidAkun'], (string) $valid['Alasan'], $this->Pelaku()->Id, $tanggal->Hitung(null));

        return back()->with('Kilat', 'Penjualan gift card '.KodeGiftCard::Samarkan($kartu->Kode).' dibatalkan dan uangnya dikembalikan.');
    }

    private function Cari(string $uuid): GiftCard
    {
        return GiftCard::query()->where('Uuid', strtoupper($uuid))->firstOrFail();
    }
}
