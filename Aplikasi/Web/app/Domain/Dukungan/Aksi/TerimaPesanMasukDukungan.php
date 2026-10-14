<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Dukungan\Data\DataPesanMasuk;
use App\Domain\Dukungan\Data\DataTiketBaru;
use App\Domain\Dukungan\Data\HasilPesanMasuk;
use App\Domain\Dukungan\Enum\KanalTiketDukungan;
use App\Domain\Dukungan\Enum\KategoriTiketDukungan;
use App\Domain\Dukungan\Enum\PrioritasTiketDukungan;
use App\Domain\Dukungan\Layanan\PembersihPesanMasuk;
use App\Domain\Dukungan\Model\PesanMasukDukungan;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Organisasi\Kueri\CariPenggunaDariKontak;
use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use App\Domain\Tenant\Kueri\ProfilTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

/**
 * Menerima pesan masuk dari email/WhatsApp menjadi tiket baru atau balasan tiket (P-09, PGL-15). Pintu masuknya
 * (`WebhookDukunganMasukKontroler`) sudah memastikan pesan datang dari penyedia kita; di sini yang diputuskan adalah
 * siapa pengirimnya dan tiket mana yang dituju.
 *
 * - **Idempoten**: (`Kanal`, `IdPesan`) yang sudah diproses mengembalikan hasil sebelumnya, tanpa tiket ganda.
 * - **Pengirim harus akun yang dikenal**: email dicocokkan ke `Pengguna.Email` (hanya bila penyedia menyatakan SPF/DKIM
 *   lolos), nomor WhatsApp ke `Pengguna.NoHp`; kecocokan harus tepat satu akun. Pesan dari pihak tak dikenal ditolak,
 *   bukan dijadikan tiket tanpa pemilik.
 * - **Balasan**: nomor tiket (`TKT-2026-000123`, dari payload, subjek, atau awal isi) dicari hanya di tenant tempat
 *   pengirim menjadi anggota aktif; tiket tenant lain tidak pernah terbaca. Aturan buka-ulang/tutup mengikuti
 *   `BalasTiketDukungan`.
 * - **Tiket baru**: hanya bila pengirim anggota tepat satu usaha aktif (tidak ada cara bertanya balik lewat webhook);
 *   anggota beberapa usaha diminta membalas tiket yang ada atau membuat tiket dari aplikasi.
 * - Batas pesan per pengirim per hari (`dukungan.MasukBatasPerPengirimHarian`) mencegah banjir tiket.
 * - Lampiran belum didukung; hanya teks. Prioritas selalu Normal dan kategori Lainnya: tim yang melakukan triase.
 */
final class TerimaPesanMasukDukungan
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly CariPenggunaDariKontak $cariPengguna,
        private readonly KeanggotaanPengguna $keanggotaan,
        private readonly ProfilTenant $profilTenant,
        private readonly BuatTiketDukungan $buatTiket,
        private readonly BalasTiketDukungan $balasTiket,
        private readonly PembersihPesanMasuk $pembersih,
    ) {}

    public function Jalankan(DataPesanMasuk $pesan): HasilPesanMasuk
    {
        $kanal = $pesan->kanal->value;
        $idPesan = mb_substr(trim($pesan->idPesan), 0, 190);

        if (($lama = PesanMasukDukungan::query()->where('Kanal', $kanal)->where('IdPesan', $idPesan)->first()) !== null) {
            return $this->DariCatatan($lama, duplikat: true);
        }

        $hasil = $this->Proses($pesan);

        try {
            PesanMasukDukungan::query()->create([
                'Kanal' => $kanal,
                'IdPesan' => $idPesan,
                'Hasil' => $hasil['Hasil'],
                'KodeTolak' => $hasil['Kode'],
                'NomorTiket' => $hasil['NomorTiket'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dua pengiriman ulang berbarengan: pemenang sudah menulis; tiket yang baru kita buat tidak dihapus karena
            // dokumen tidak pernah dihapus, tetapi kejadian ini sangat jarang (penyedia mengirim ulang setelah timeout).
        }

        return new HasilPesanMasuk($hasil['Hasil'], $hasil['NomorTiket'], $hasil['Kode']);
    }

    /**
     * @return array{Hasil: string, NomorTiket: string|null, Kode: string|null}
     */
    private function Proses(DataPesanMasuk $pesan): array
    {
        $isi = $this->pembersih->RapikanIsi($pesan->isi);

        if (mb_strlen($isi) < 2) {
            return $this->Tolak('PesanKosong');
        }

        if ($pesan->kanal === KanalTiketDukungan::Email && ! $pesan->terverifikasi) {
            return $this->Tolak('PengirimTidakTerverifikasi');
        }

        $pengguna = match ($pesan->kanal) {
            KanalTiketDukungan::Email => $this->cariPengguna->DariEmail($pesan->dari),
            KanalTiketDukungan::WhatsApp => $this->cariPengguna->DariNoHp($pesan->dari),
            default => null,
        };

        if ($pengguna === null) {
            return $this->Tolak('PengirimTidakDikenal');
        }

        if (! $this->MasihDalamBatas($pesan->kanal, $pengguna['Id'])) {
            return $this->Tolak('TerlaluBanyakPesan');
        }

        $tenantAktif = array_values(array_filter(
            $this->keanggotaan->AmbilIdTenant($pengguna['Id']),
            fn (int $idTenant): bool => $this->profilTenant->CekAda($idTenant),
        ));

        if ($tenantAktif === []) {
            return $this->Tolak('PengirimTidakDikenal');
        }

        $nomor = $pesan->nomorTiket ?? $this->pembersih->CariNomorTiket($pesan->subjek, $isi);

        if ($nomor !== null) {
            return $this->Balas($pengguna, $tenantAktif, $nomor, $isi);
        }

        if (count($tenantAktif) > 1) {
            return $this->Tolak('UsahaAmbigu');
        }

        return $this->BuatBaru($pesan, $pengguna, $tenantAktif[0], $isi);
    }

    /**
     * @param  array{Id: int, Nama: string}  $pengguna
     * @param  list<int>  $tenantAktif
     * @return array{Hasil: string, NomorTiket: string|null, Kode: string|null}
     */
    private function Balas(array $pengguna, array $tenantAktif, string $nomor, string $isi): array
    {
        foreach ($tenantAktif as $idTenant) {
            $this->konteks->Atur($idTenant);
            $tiket = TiketDukungan::query()->where('Nomor', $nomor)->first();

            if ($tiket === null) {
                continue;
            }

            try {
                $this->balasTiket->Jalankan($pengguna['Id'], $pengguna['Nama'], $tiket, $isi);
            } catch (PelanggaranAturanBisnis) {
                return $this->Tolak('TiketTidakBisaDibalas', $tiket->Nomor);
            }

            return ['Hasil' => PesanMasukDukungan::HASIL_BALASAN, 'NomorTiket' => $tiket->Nomor, 'Kode' => null];
        }

        return $this->Tolak('TiketTidakDitemukan');
    }

    /**
     * @param  array{Id: int, Nama: string}  $pengguna
     * @return array{Hasil: string, NomorTiket: string|null, Kode: string|null}
     */
    private function BuatBaru(DataPesanMasuk $pesan, array $pengguna, int $idTenant, string $isi): array
    {
        $this->konteks->Atur($idTenant);
        $tiket = $this->buatTiket->Jalankan($pengguna['Id'], $pengguna['Nama'], new DataTiketBaru(
            kategori: KategoriTiketDukungan::Lainnya,
            prioritas: PrioritasTiketDukungan::Normal,
            judul: $this->pembersih->BuatJudul($pesan->subjek, $isi),
            isi: $isi,
            konteks: ['Kanal' => $pesan->kanal->AmbilLabel(), 'PesanMasuk' => true],
            kanal: $pesan->kanal,
        ));

        return ['Hasil' => PesanMasukDukungan::HASIL_TIKET_BARU, 'NomorTiket' => $tiket->Nomor, 'Kode' => null];
    }

    /**
     * @return array{Hasil: string, NomorTiket: string|null, Kode: string|null}
     */
    private function Tolak(string $kode, ?string $nomor = null): array
    {
        return ['Hasil' => PesanMasukDukungan::HASIL_DITOLAK, 'NomorTiket' => $nomor, 'Kode' => $kode];
    }

    private function MasihDalamBatas(KanalTiketDukungan $kanal, int $idPengguna): bool
    {
        $kunci = "dukungan-masuk:{$kanal->value}:{$idPengguna}:".now()->format('Ymd');
        $jumlah = (int) Cache::get($kunci, 0) + 1;
        Cache::put($kunci, $jumlah, now()->addDay());

        return $jumlah <= (int) config('dukungan.MasukBatasPerPengirimHarian');
    }

    private function DariCatatan(PesanMasukDukungan $catatan, bool $duplikat): HasilPesanMasuk
    {
        return new HasilPesanMasuk($catatan->Hasil, $catatan->NomorTiket, $catatan->KodeTolak, $duplikat);
    }
}
