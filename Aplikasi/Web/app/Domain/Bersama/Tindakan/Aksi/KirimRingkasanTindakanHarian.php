<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Tindakan\Aksi;

use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Bersama\Tindakan\Kueri\KotakTindakan;
use App\Domain\Bersama\Tindakan\Model\LanggananRingkasanTindakan;
use App\Domain\Bersama\Web\AlamatDomain;
use App\Domain\Integrasi\Enum\FungsiNomorWhatsapp;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Organisasi\Kueri\KontakAnggotaTenant;
use App\Domain\Organisasi\Kueri\KonteksTindakanPengguna;
use App\Domain\Tenant\Kueri\ProfilTenant;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * D-23 D bagian 4 (D-33: lewat WhatsApp): kirim ringkasan pagi Kotak Tindakan ke anggota tenant aktif yang
 * berlangganan dan punya nomor HP sah (Owner bawaan berlangganan, anggota lain memilih sendiri). Isi = butir Penting &
 * Perhatian menurut izin & outlet akses penerima (hutang/piutang jatuh tempo, shift lupa ditutup, dokumen perlu dicek,
 * dst.). Tidak ada butir, atau penyedia WhatsApp platform belum aktif = tidak dikirim. Paling banyak sekali per tanggal
 * bisnis per penerima (`TerakhirDikirim`); gagal kirim tidak ditandai sehingga dicoba lagi pada putaran berikutnya.
 * Dipanggil dalam konteks tenant.
 */
final class KirimRingkasanTindakanHarian
{
    public const MAKS_BUTIR_TEKS = 8;

    public function __construct(
        private readonly KontakAnggotaTenant $kontak,
        private readonly KonteksTindakanPengguna $konteks,
        private readonly KotakTindakan $kotak,
        private readonly ProfilTenant $profil,
        private readonly PembuatPengirimWhatsapp $whatsapp,
    ) {}

    /** @return int jumlah pesan terkirim */
    public function Jalankan(int $idTenant, CarbonImmutable $hariIni): int
    {
        $pengirim = $this->whatsapp->AmbilAktif(fungsi: FungsiNomorWhatsapp::Karyawan);

        if ($pengirim === null) {
            return 0;
        }

        $langganan = LanggananRingkasanTindakan::query()->get()->keyBy('IdPengguna');
        $namaUsaha = $this->profil->Ambil($idTenant)['Nama'];
        $tanggal = $hariIni->toDateString();
        $tautan = AlamatDomain::BuatUrlAbsolutTenant('/kelola/tindakan');
        $templat = $pengirim->CekResmi() ? $this->whatsapp->AmbilTemplatRingkasanTindakan() : null;
        $terkirim = 0;

        foreach ($this->kontak->AmbilWhatsapp($idTenant) as $anggota) {
            $baris = $langganan->get($anggota['Id']);

            if (! ($baris->Aktif ?? $anggota['Pemilik']) || $baris?->TerakhirDikirim?->toDateString() === $tanggal) {
                continue;
            }

            $butir = array_values(array_filter(
                $this->kotak->Ambil($this->konteks->Buat($idTenant, $anggota['Id'], $hariIni)),
                fn (DataButirTindakan $b): bool => $b->tingkat !== TingkatTindakan::Info,
            ));

            if ($butir === []) {
                continue;
            }

            $ringkas = count($butir).' hal perlu ditindaklanjuti: '.implode(', ', array_map(fn (DataButirTindakan $b): string => $b->judul, array_slice($butir, 0, 3)))
                .(count($butir) > 3 ? ', dan lainnya' : '');

            try {
                $hasil = $pengirim->Kirim(new PesanWhatsapp(
                    $anggota['NoHp'],
                    self::SusunTeks($anggota['Nama'], $namaUsaha, $hariIni->translatedFormat('j F Y'), $butir, $tautan),
                    $templat,
                    $templat === null ? [] : [$anggota['Nama'], $namaUsaha, $ringkas, $tautan],
                ));
            } catch (Throwable $galat) {
                report($galat);

                continue;
            }

            if (! $hasil->berhasil) {
                continue;
            }

            $baris ??= new LanggananRingkasanTindakan(['IdPengguna' => $anggota['Id'], 'Aktif' => true]);
            $baris->setAttribute('TerakhirDikirim', $tanggal);
            $baris->save();
            $terkirim++;
        }

        return $terkirim;
    }

    /**
     * Teks WhatsApp: salam, daftar butir (paling banyak `MAKS_BUTIR_TEKS`), tautan Kotak Tindakan.
     *
     * @param  list<DataButirTindakan>  $butir
     */
    public static function SusunTeks(string $nama, string $namaUsaha, string $tanggal, array $butir, string $tautan): string
    {
        $baris = array_map(
            fn (DataButirTindakan $b): string => '• '.($b->tingkat === TingkatTindakan::Penting ? '[Penting] ' : '').$b->judul.($b->jumlah > 1 ? " ({$b->jumlah})" : ''),
            array_slice($butir, 0, self::MAKS_BUTIR_TEKS),
        );

        if (count($butir) > self::MAKS_BUTIR_TEKS) {
            $baris[] = '• dan '.(count($butir) - self::MAKS_BUTIR_TEKS).' hal lainnya';
        }

        return "Selamat pagi {$nama}, ringkasan {$namaUsaha} hari ini ({$tanggal}):\n".implode("\n", $baris)."\n\nBuka Kotak Tindakan: {$tautan}";
    }
}
