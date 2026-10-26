<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Organisasi\Data\DataPemilikBaru;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pajak\Model\TarifPajak;
use App\Domain\PanduanAwal\Enum\StatusTemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektor;
use App\Domain\Pengelola\Katalog\Aksi\AjukanHargaPaket;
use App\Domain\Pengelola\Katalog\Aksi\TinjauHargaPaket;
use App\Domain\Pengelola\Katalog\Aksi\UbahStatusPaket;
use App\Domain\Pengelola\Konten\Aksi\SimpanDrafDokumenLegal;
use App\Domain\Pengelola\Konten\Aksi\TerbitkanDokumenLegal;
use App\Domain\Pengelola\Konten\Data\DataDokumenLegal;
use App\Domain\Pengelola\Referensi\Aksi\AjukanTarifPajak;
use App\Domain\Pengelola\Referensi\Aksi\TinjauTarifPajak;
use App\Domain\Pengelola\Referensi\Enum\KeputusanTinjauan;
use App\Domain\Pengelola\TemplateSektor\Aksi\TerbitkanTemplate;
use App\Domain\Pengelola\TimInternal\Aksi\BuatSuperAdmin;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Aksi\DaftarkanTenant;
use App\Domain\Tenant\Data\DataPendaftaran;
use App\Domain\Tenant\Enum\JenisDokumenLegal;
use App\Domain\Tenant\Enum\StatusDokumenLegal;
use App\Domain\Tenant\Enum\StatusPaket;
use App\Domain\Tenant\Model\DokumenLegal;
use App\Domain\Tenant\Model\HargaPaket;
use App\Domain\Tenant\Model\Paket;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Data demo lingkungan lokal: dua Super Admin (P-01, BR-P01.1), dokumen legal wajib registrasi (P-06, BR-P06.2),
 * paket Gratis & Pro aktif lewat alur tinjauan harga (P-04, BR-P04.5, BR-P04.6), dan satu tenant trial (F-00).
 *
 * Nama kelas mengikuti konvensi Indonesia; `run()` adalah metode framework (§13.7.4).
 * Dilarang jalan di produksi: isinya akun contoh, bukan data nyata.
 *
 * Jalankan: php artisan db:seed --class=DataDemoLokal
 * Kata sandi diambil dari env DEMO_KATA_SANDI; bila kosong dibuat acak dan dicetak sekali ke layar.
 */
final class DataDemoLokal extends Seeder
{
    private const EMAIL_SUPER_ADMIN_SATU = 'admin@payoung.test';

    private const EMAIL_SUPER_ADMIN_DUA = 'admin2@payoung.test';

    /** Tarif pajak nasional butuh dua penyetuju selain pengaju (TinjauTarifPajak::PENYETUJU_NASIONAL). */
    private const EMAIL_SUPER_ADMIN_TIGA = 'admin3@payoung.test';

    private const EMAIL_PEMILIK = 'owner@payoung.test';

    private const NAMA_USAHA = 'Toko Demo Payoung';

    private const KODE_PAKET = 'PRO';

    /** @var list<string> */
    private array $ringkasan = [];

    public function run(
        BuatSuperAdmin $buatSuperAdmin,
        SimpanDrafDokumenLegal $simpanDraf,
        TerbitkanDokumenLegal $terbitkanDokumen,
        AjukanHargaPaket $ajukanHarga,
        TinjauHargaPaket $tinjauHarga,
        UbahStatusPaket $ubahStatusPaket,
        DaftarkanTenant $daftarkanTenant,
        AjukanTarifPajak $ajukanTarif,
        TinjauTarifPajak $tinjauTarif,
        TerbitkanTemplate $terbitkanTemplate,
    ): void {
        if (app()->isProduction()) {
            throw new RuntimeException('DataDemoLokal hanya untuk lingkungan non-produksi.');
        }

        $kataSandi = $this->AmbilKataSandi();

        $superAdminSatu = $this->SiapkanSuperAdmin($buatSuperAdmin, self::EMAIL_SUPER_ADMIN_SATU, 'Super Admin Payoung', $kataSandi);
        $superAdminDua = $this->SiapkanSuperAdmin($buatSuperAdmin, self::EMAIL_SUPER_ADMIN_DUA, 'Admin Kedua Payoung', $kataSandi);
        $superAdminTiga = $this->SiapkanSuperAdmin($buatSuperAdmin, self::EMAIL_SUPER_ADMIN_TIGA, 'Admin Ketiga Payoung', $kataSandi);

        $this->TerbitkanDokumenWajib($simpanDraf, $terbitkanDokumen, $superAdminSatu);
        $this->AktifkanPaket($ajukanHarga, $tinjauHarga, $ubahStatusPaket, $superAdminSatu, $superAdminDua);
        // Template sektor (P-03) baru bisa terbit bila pajak nasional di dalamnya punya tarif terbit (BR-P03.3).
        $this->TerbitkanTarifPajakDraf($ajukanTarif, $tinjauTarif, $superAdminSatu, [$superAdminDua, $superAdminTiga]);
        $this->TerbitkanTemplateSektor($terbitkanTemplate, $superAdminSatu);
        $this->DaftarkanTenantDemo($daftarkanTenant, $kataSandi);

        foreach ($this->ringkasan as $baris) {
            $this->command?->info($baris);
        }

        $this->command?->warn("Kata sandi semua akun demo: {$kataSandi}");
        $this->command?->warn('Ganti kata sandi ini sebelum dipakai di lingkungan yang bisa diakses orang lain.');
    }

    private function AmbilKataSandi(): string
    {
        // getenv(), bukan env(): nilai hanya dipakai saat perintah seeder berjalan dan tidak boleh ikut ter-cache config.
        $dariLingkungan = getenv('DEMO_KATA_SANDI');

        if (is_string($dariLingkungan) && $dariLingkungan !== '') {
            return $dariLingkungan;
        }

        // Minimal 12 karakter, huruf dan angka (syarat BuatSuperAdmin).
        return 'Demo'.bin2hex(random_bytes(5)).random_int(10, 99);
    }

    private function SiapkanSuperAdmin(BuatSuperAdmin $buatSuperAdmin, string $email, string $nama, string $kataSandi): PenggunaPengelola
    {
        $adaSebelumnya = PenggunaPengelola::query()->where('Email', $email)->first();

        if ($adaSebelumnya !== null) {
            $this->ringkasan[] = "Super Admin {$email} sudah ada. Kata sandi tidak diubah.";

            return $adaSebelumnya;
        }

        $pengguna = $buatSuperAdmin->Jalankan($nama, $email, $kataSandi);
        $this->ringkasan[] = "Super Admin {$email} dibuat.";

        return $pengguna;
    }

    private function TerbitkanDokumenWajib(
        SimpanDrafDokumenLegal $simpanDraf,
        TerbitkanDokumenLegal $terbitkanDokumen,
        PenggunaPengelola $pelaku,
    ): void {
        $hariIni = now('Asia/Jakarta')->toDateString();

        foreach (JenisDokumenLegal::AmbilWajibRegistrasi() as $jenis) {
            $sudahBerlaku = DokumenLegal::query()
                ->where('Jenis', $jenis->value)
                ->where('Status', StatusDokumenLegal::Terbit->value)
                ->whereDate('BerlakuMulai', '<=', $hariIni)
                ->exists();

            if ($sudahBerlaku) {
                $this->ringkasan[] = "{$jenis->AmbilLabel()} sudah berlaku.";

                continue;
            }

            $draf = DokumenLegal::query()
                ->where('Jenis', $jenis->value)
                ->where('Status', StatusDokumenLegal::Draf->value)
                ->first();

            $data = new DataDokumenLegal(
                jenis: $jenis,
                judul: $jenis->AmbilLabel(),
                isi: "# {$jenis->AmbilLabel()}\n\nNaskah contoh untuk lingkungan demo lokal Payoung. Bukan dokumen legal final.",
                ringkasanPerubahan: null,
                materiil: false,
                berlakuMulai: $hariIni,
            );

            $draf = $simpanDraf->Jalankan($pelaku, $data, $draf);
            $terbitkanDokumen->Jalankan($pelaku, $draf);
            $this->ringkasan[] = "{$jenis->AmbilLabel()} versi {$draf->Versi} diterbitkan.";
        }
    }

    private function AktifkanPaket(
        AjukanHargaPaket $ajukanHarga,
        TinjauHargaPaket $tinjauHarga,
        UbahStatusPaket $ubahStatusPaket,
        PenggunaPengelola $pengaju,
        PenggunaPengelola $peninjau,
    ): void {
        // Gratis dipakai saat trial berakhir (BR-00.3), Pro adalah paket trial bawaan.
        foreach (['GRATIS', self::KODE_PAKET] as $kode) {
            $paket = Paket::query()->where('Kode', $kode)->first();

            if ($paket === null) {
                $this->ringkasan[] = "Paket {$kode} tidak ada; lewati.";

                continue;
            }

            if ($paket->Status === StatusPaket::Aktif) {
                $this->ringkasan[] = "Paket {$kode} sudah aktif.";

                continue;
            }

            $harga = HargaPaket::query()
                ->where('IdPaket', $paket->Id)
                ->where('Status', StatusDataMaster::Draf->value)
                ->orderByDesc('Id')
                ->first();

            if ($harga !== null) {
                $harga->update(['BerlakuMulai' => now('Asia/Jakarta')->toDateString()]);
                $ajukanHarga->Jalankan($pengaju, $harga);

                // D-34: ajuan Super Admin langsung terbit; peninjau hanya dipakai bila pengaju bukan Super Admin.
                if ($harga->refresh()->Status === StatusDataMaster::MenungguTinjauan) {
                    $tinjauHarga->Jalankan($peninjau, $harga, KeputusanTinjauan::Setuju, 'Disetujui untuk lingkungan demo lokal.');
                }
            }

            $ubahStatusPaket->Jalankan($pengaju, $paket->refresh(), StatusPaket::Aktif);
            $this->ringkasan[] = "Paket {$kode} aktif.";
        }
    }

    /**
     * Tarif pajak awal dimuat sebagai draf (BR-P02.5). Untuk demo lokal saja: berlaku mulai hari ini, diajukan admin
     * pertama, lalu disetujui admin lain sampai jumlah penyetuju terpenuhi (nasional 2, daerah 1). Tarif yang sudah
     * menunggu tinjauan dilanjutkan. Di lingkungan nyata nilai & dasar hukum wajib diverifikasi dulu.
     *
     * @param  list<PenggunaPengelola>  $daftarPeninjau
     */
    private function TerbitkanTarifPajakDraf(
        AjukanTarifPajak $ajukanTarif,
        TinjauTarifPajak $tinjauTarif,
        PenggunaPengelola $pengaju,
        array $daftarPeninjau,
    ): void {
        $daftarTarif = TarifPajak::query()
            ->whereIn('Status', [StatusDataMaster::Draf->value, StatusDataMaster::MenungguTinjauan->value])
            ->get();

        if ($daftarTarif->isEmpty()) {
            $this->ringkasan[] = 'Tidak ada tarif pajak draf atau menunggu tinjauan.';

            return;
        }

        foreach ($daftarTarif as $tarif) {
            if ($tarif->Status === StatusDataMaster::Draf) {
                $tarif->update(['BerlakuMulai' => now('Asia/Jakarta')->toDateString()]);
                $ajukanTarif->Jalankan($pengaju, $tarif);
            }

            // D-34: ajuan Super Admin langsung terbit; peninjau hanya dipakai untuk tarif yang masih menunggu.
            $status = $tarif->refresh()->Status;

            foreach ($status === StatusDataMaster::Terbit ? [] : $daftarPeninjau as $peninjau) {
                try {
                    $status = $tinjauTarif->Jalankan($peninjau, $tarif->refresh(), KeputusanTinjauan::Setuju, 'Disetujui untuk lingkungan demo lokal.');
                } catch (PelanggaranAturanBisnis) {
                    // Peninjau ini sudah memberi keputusan pada putaran yang sama (seeder dijalankan ulang): lanjut.
                    continue;
                }

                if ($status === StatusDataMaster::Terbit) {
                    break;
                }
            }

            $this->ringkasan[] = $status === StatusDataMaster::Terbit
                ? "Tarif pajak #{$tarif->Id} diterbitkan untuk demo lokal."
                : "Tarif pajak #{$tarif->Id} masih {$status->value}; periksa di Platform Pengelola > Referensi > Tarif pajak.";
        }
    }

    /** Draf versi terakhir tiap template yang belum punya versi terbit diterbitkan, supaya panduan awal F-01 bisa memilihnya. */
    private function TerbitkanTemplateSektor(TerbitkanTemplate $terbitkanTemplate, PenggunaPengelola $pelaku): void
    {
        foreach (TemplateSektor::query()->orderBy('Kode')->get() as $template) {
            if ($template->Versi()->where('Status', StatusTemplateSektor::Terbit->value)->exists()) {
                $this->ringkasan[] = "Template {$template->Kode} sudah terbit.";

                continue;
            }

            $draf = $template->Versi()->where('Status', StatusTemplateSektor::Draf->value)->orderByDesc('Versi')->first();

            if ($draf === null) {
                $this->ringkasan[] = "Template {$template->Kode} tidak punya draf; lewati.";

                continue;
            }

            try {
                $terbitkanTemplate->Jalankan($pelaku, $draf);
                $this->ringkasan[] = "Template {$template->Kode} versi {$draf->Versi} diterbitkan.";
            } catch (PelanggaranAturanBisnis $galat) {
                // Hasil validasi tersimpan di versi; periksa di Platform Pengelola > Template sektor.
                $this->ringkasan[] = "Template {$template->Kode} belum terbit: {$galat->getMessage()}";
            }
        }
    }

    private function DaftarkanTenantDemo(DaftarkanTenant $daftarkanTenant, string $kataSandi): void
    {
        if (Pengguna::query()->where('Email', self::EMAIL_PEMILIK)->exists()) {
            $this->ringkasan[] = 'Tenant demo sudah ada. Lewati pendaftaran.';

            return;
        }

        $hasil = $daftarkanTenant->Jalankan(new DataPendaftaran(
            pemilik: new DataPemilikBaru(
                nama: 'Pemilik Toko Demo',
                email: self::EMAIL_PEMILIK,
                noHp: '081234567890',
                kataSandi: $kataSandi,
            ),
            namaUsaha: self::NAMA_USAHA,
            kodePaket: self::KODE_PAKET,
            ip: '127.0.0.1',
        ));

        $this->ringkasan[] = "Tenant {$hasil['Tenant']->Nama} dibuat dengan Owner ".self::EMAIL_PEMILIK.'.';
    }
}
