<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Pelanggan;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Integrasi\Enum\FungsiNomorWhatsapp;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pelanggan\Aksi\BatalkanKampanyePesan;
use App\Domain\Pelanggan\Aksi\JalankanKampanyePesan;
use App\Domain\Pelanggan\Aksi\SimpanKampanyePesan;
use App\Domain\Pelanggan\Enum\KanalKampanye;
use App\Domain\Pelanggan\Enum\SegmenRfm;
use App\Domain\Pelanggan\Enum\StatusKampanye;
use App\Domain\Pelanggan\Enum\StatusPenerimaKampanye;
use App\Domain\Pelanggan\Kueri\DaftarKampanyePesan;
use App\Domain\Pelanggan\Kueri\DaftarPelanggan;
use App\Domain\Pelanggan\Kueri\DaftarTierPelanggan;
use App\Domain\Pelanggan\Kueri\PemilihPenerimaKampanye;
use App\Domain\Pelanggan\Layanan\PengirimKampanyePesan;
use App\Domain\Pelanggan\Model\KampanyePesan;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Permintaan\Kelola\Pelanggan\SimpanKampanyePesanPermintaan;
use App\Http\Respons\ResponsTabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRM-07 kampanye pesan WhatsApp/email bersegmen (`/kelola/pelanggan/kampanye`, izin `pelanggan.kelola`: data pribadi &
 * pemasaran). Daftar, formulir draf dengan pratinjau jumlah penerima, rincian dengan progres & penerima, kirim sekarang
 * atau jadwalkan, batalkan. Nomor/email penerima tidak pernah dikirim ke peramban.
 */
final class KampanyePesanKontroler extends DasarKelolaKontroler
{
    public function Daftar(Request $permintaan, DaftarKampanyePesan $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarKampanyePesan::KOLOM_URUT, DaftarKampanyePesan::URUT_BAWAAN, DaftarKampanyePesan::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Pelanggan/Kampanye/Daftar', 'Kampanye', fn (): array => $daftar->AmbilTabel($tabel), fn (): array => [
            'OpsiStatus' => array_map(fn (StatusKampanye $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusKampanye::cases()),
            'OpsiKanal' => self::OpsiKanal(),
        ]);
    }

    public function Buat(DaftarPelanggan $pelanggan, DaftarTierPelanggan $tier, PembuatPengirimWhatsapp $whatsapp, PemeriksaFiturTenant $fitur): Response
    {
        return Inertia::render('Kelola/Pelanggan/Kampanye/Form', [
            'Kampanye' => null,
            ...$this->PropsFormulir($pelanggan, $tier, $whatsapp, $fitur),
        ]);
    }

    public function Ubah(string $kampanye, DaftarPelanggan $pelanggan, DaftarTierPelanggan $tier, PembuatPengirimWhatsapp $whatsapp, PemeriksaFiturTenant $fitur): Response|RedirectResponse
    {
        $k = $this->Cari($kampanye);

        if ($k->Status !== StatusKampanye::Draf) {
            return redirect()->route('kelola.pelanggan.kampanye.detail', $k->Uuid);
        }

        return Inertia::render('Kelola/Pelanggan/Kampanye/Form', [
            'Kampanye' => ['Uuid' => $k->Uuid, 'Nama' => $k->Nama, 'Kanal' => $k->Kanal->value, 'Judul' => $k->Judul, 'Isi' => $k->Isi, 'Segmen' => $k->Segmen],
            ...$this->PropsFormulir($pelanggan, $tier, $whatsapp, $fitur),
        ]);
    }

    public function Simpan(SimpanKampanyePesanPermintaan $permintaan, SimpanKampanyePesan $simpan): RedirectResponse
    {
        $k = $simpan->Jalankan($permintaan->AmbilData($this->Pelaku()->Id));

        return redirect()->route('kelola.pelanggan.kampanye.detail', $k->Uuid)->with('Kilat', 'Draf kampanye disimpan. Periksa penerimanya lalu kirim.');
    }

    public function Perbarui(string $kampanye, SimpanKampanyePesanPermintaan $permintaan, SimpanKampanyePesan $simpan): RedirectResponse
    {
        $k = $simpan->Jalankan($permintaan->AmbilData($this->Pelaku()->Id), $this->Cari($kampanye));

        return redirect()->route('kelola.pelanggan.kampanye.detail', $k->Uuid)->with('Kilat', 'Draf kampanye disimpan.');
    }

    /** Pratinjau jumlah penerima untuk saringan & kanal yang sedang diisi (tanpa data pribadi). */
    public function Pratinjau(Request $permintaan, PemilihPenerimaKampanye $pemilih, TanggalBisnisOutlet $tanggal): JsonResponse
    {
        $valid = $permintaan->validate(['Kanal' => ['required', Rule::enum(KanalKampanye::class)], ...SimpanKampanyePesanPermintaan::AturanSegmen()]);
        $hasil = $pemilih->Susun(SimpanKampanyePesanPermintaan::AmbilSegmen($valid), KanalKampanye::from((string) $valid['Kanal']), $tanggal->Hitung(null));

        return response()->json([
            'JumlahPenerima' => count($hasil['Penerima']),
            'TanpaKontak' => $hasil['TanpaKontak'],
            'PerSegmen' => $hasil['PerSegmen'],
            'MaksPenerima' => JalankanKampanyePesan::MAKS_PENERIMA,
        ]);
    }

    public function Detail(Request $permintaan, string $kampanye, DaftarKampanyePesan $daftar, ProfilTenant $profil): Response|JsonResponse
    {
        $k = $this->Cari($kampanye);
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarKampanyePesan::KOLOM_URUT_PENERIMA, 'TerkirimPada', DaftarKampanyePesan::KOLOM_SARING_PENERIMA);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Pelanggan/Kampanye/Detail', 'Penerima', fn (): array => $daftar->AmbilPenerima($k, $tabel), fn (): array => [
            'Kampanye' => [
                ...DaftarKampanyePesan::Ringkas($k),
                'Judul' => $k->Judul,
                'Isi' => $k->Isi,
                'Contoh' => PengirimKampanyePesan::SusunTeks($k->Isi, 'Budi', $profil->Ambil($this->IdTenant())['Nama'], url('/berhenti-langganan/…')),
                'Segmen' => $this->LabelSegmen($k->Segmen),
            ],
            'OpsiStatusPenerima' => array_map(fn (StatusPenerimaKampanye $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusPenerimaKampanye::cases()),
            'Aturan' => [
                'JamMulai' => PengirimKampanyePesan::JAM_MULAI,
                'JamSelesai' => PengirimKampanyePesan::JAM_SELESAI,
                'UkuranGiliran' => PengirimKampanyePesan::UKURAN_GILIRAN,
                'JedaDetik' => PengirimKampanyePesan::JEDA_DETIK,
            ],
        ]);
    }

    public function Jalankan(Request $permintaan, string $kampanye, JalankanKampanyePesan $jalankan): RedirectResponse
    {
        $valid = $permintaan->validate(['DijadwalkanPada' => ['nullable', 'date', 'after:now']], attributes: ['DijadwalkanPada' => 'jadwal kirim']);
        $jadwal = isset($valid['DijadwalkanPada']) ? CarbonImmutable::parse((string) $valid['DijadwalkanPada']) : null;
        $k = $jalankan->Jalankan($this->Cari($kampanye), $jadwal, $this->Pelaku()->Id);

        return back()->with('Kilat', $k->Status === StatusKampanye::Dijadwalkan
            ? 'Kampanye dijadwalkan.'
            : "Kampanye mulai dikirim ke {$k->JumlahPenerima} pelanggan secara bertahap.");
    }

    public function Batal(string $kampanye, BatalkanKampanyePesan $batal): RedirectResponse
    {
        $batal->Jalankan($this->Cari($kampanye), $this->Pelaku()->Id);

        return back()->with('Kilat', 'Kampanye dibatalkan. Pesan yang sudah terkirim tidak bisa ditarik.');
    }

    /**
     * @return array<string, mixed>
     */
    private function PropsFormulir(DaftarPelanggan $pelanggan, DaftarTierPelanggan $tier, PembuatPengirimWhatsapp $whatsapp, PemeriksaFiturTenant $fitur): array
    {
        $pengirim = $whatsapp->AmbilAktif(fungsi: FungsiNomorWhatsapp::Broadcast);

        return [
            'OpsiKanal' => self::OpsiKanal(),
            'OpsiRfm' => array_map(fn (SegmenRfm $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel(), 'Keterangan' => $s->AmbilKeterangan()], SegmenRfm::cases()),
            'OpsiTier' => array_map(fn (array $t): array => ['Nilai' => $t['Uuid'], 'Label' => $t['Label']], $tier->AmbilOpsi()),
            'OpsiTag' => array_map(fn (string $t): array => ['Nilai' => $t, 'Label' => $t], $pelanggan->AmbilSemuaTag()),
            'KanalAktif' => [
                'Whatsapp' => $pengirim !== null && $fitur->CekAktif($this->IdTenant(), PemeriksaFiturTenant::KUNCI_WHATSAPP),
                'Email' => false,
            ],
            'MaksIsi' => SimpanKampanyePesan::MAKS_ISI,
        ];
    }

    /**
     * @param  array{Rfm?: list<string>, UuidTier?: list<string>, Tag?: list<string>, UlangTahunBulanIni?: bool}  $segmen
     * @return list<string>
     */
    private function LabelSegmen(array $segmen): array
    {
        $label = [];
        $rfm = array_values(array_filter(array_map(fn (string $s): ?SegmenRfm => SegmenRfm::tryFrom($s), $segmen['Rfm'] ?? [])));

        if ($rfm !== []) {
            $label[] = 'Segmen: '.implode(', ', array_map(fn (SegmenRfm $s): string => $s->AmbilLabel(), $rfm));
        }

        if (($segmen['UuidTier'] ?? []) !== []) {
            $nama = array_column(array_filter(app(DaftarTierPelanggan::class)->AmbilOpsi(), fn (array $t): bool => in_array($t['Uuid'], $segmen['UuidTier'] ?? [], true)), 'Label');
            $label[] = 'Tier: '.($nama === [] ? '(tier diarsipkan)' : implode(', ', $nama));
        }

        if (($segmen['Tag'] ?? []) !== []) {
            $label[] = 'Tag: '.implode(', ', $segmen['Tag'] ?? []);
        }

        if (($segmen['UlangTahunBulanIni'] ?? false) === true) {
            $label[] = 'Berulang tahun bulan ini';
        }

        return $label === [] ? ['Semua pelanggan yang setuju menerima promosi'] : $label;
    }

    /** @return list<array{Nilai: string, Label: string}> */
    private static function OpsiKanal(): array
    {
        return array_values(array_map(
            fn (KanalKampanye $k): array => ['Nilai' => $k->value, 'Label' => $k->AmbilLabel()],
            array_filter(KanalKampanye::cases(), fn (KanalKampanye $k): bool => $k->CekTersedia()),
        ));
    }

    private function Cari(string $uuid): KampanyePesan
    {
        return KampanyePesan::query()->where('Uuid', strtoupper($uuid))->firstOrFail();
    }
}
