<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Konten;

use App\Domain\Situs\Layanan\ValidatorBagianSitus;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * D-21: isian pengaturan situs pemasaran dari konsol.
 */
final class SimpanPengaturanSitusPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $tautan = static function (string $atribut, mixed $nilai, Closure $gagal): void {
            if (is_string($nilai) && $nilai !== '' && ! ValidatorBagianSitus::CekTautanValid($nilai)) {
                $gagal('Tautan harus diawali "/", "#", "https://", "mailto:", "tel:", atau pintasan seperti @daftar.');
            }
        };
        // Butir menu utama boleh `@solusi` (dropdown sektor, D-91); pintasan itu tidak berlaku untuk tautan lain.
        $tautanMenu = static function (string $atribut, mixed $nilai, Closure $gagal): void {
            if (is_string($nilai) && $nilai !== '' && $nilai !== '@solusi' && ! ValidatorBagianSitus::CekTautanValid($nilai)) {
                $gagal('Tautan harus diawali "/", "#", "https://", "mailto:", "tel:", atau pintasan seperti @daftar atau @solusi.');
            }
        };
        $gambar = ['nullable', 'string', 'size:26', Rule::exists('GambarSitus', 'Uuid')];
        $https = ['nullable', 'string', 'max:255', 'url:https'];

        return [
            'NamaSitus' => ['required', 'string', 'max:60'],
            'Slogan' => ['nullable', 'string', 'max:120'],
            'JudulSeo' => ['required', 'string', 'max:70'],
            'DeskripsiSeo' => ['required', 'string', 'max:170'],
            'KataKunci' => ['nullable', 'string', 'max:255'],
            'UuidGambarOg' => $gambar,
            'UuidLogo' => $gambar,
            'VerifikasiGoogle' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'Kontak.WhatsApp' => ['nullable', 'string', 'regex:/^\+?[0-9\- ]{8,20}$/'],
            'Kontak.PesanWhatsApp' => ['nullable', 'string', 'max:300'],
            'Kontak.Email' => ['nullable', 'email', 'max:150'],
            'Kontak.Telepon' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\-() ]{6,30}$/'],
            'Kontak.Alamat' => ['nullable', 'string', 'max:300'],
            'Kontak.JamLayanan' => ['nullable', 'string', 'max:100'],
            'MediaSosial.Instagram' => $https,
            'MediaSosial.Facebook' => $https,
            'MediaSosial.Tiktok' => $https,
            'MediaSosial.Youtube' => $https,
            'MediaSosial.Linkedin' => $https,
            'MediaSosial.X' => $https,
            'Pengumuman.Aktif' => ['boolean'],
            'Pengumuman.Teks' => ['nullable', 'required_if_accepted:Pengumuman.Aktif', 'string', 'max:200'],
            'Pengumuman.Tautan' => ['nullable', 'string', 'max:500', $tautan],
            'Menu' => ['present', 'array', 'max:10'],
            'Menu.*.Label' => ['required', 'string', 'max:30'],
            'Menu.*.Tautan' => ['required', 'string', 'max:500', $tautanMenu],
            'MenuKaki' => ['present', 'array', 'max:5'],
            'MenuKaki.*.Judul' => ['required', 'string', 'max:40'],
            'MenuKaki.*.Tautan' => ['present', 'array', 'max:10'],
            'MenuKaki.*.Tautan.*.Label' => ['required', 'string', 'max:40'],
            'MenuKaki.*.Tautan.*.Tautan' => ['required', 'string', 'max:500', $tautan],
            'TeksKaki' => ['nullable', 'string', 'max:400'],
            'TautanUnduh.Android' => $https,
            'TautanUnduh.Ios' => $https,
            'TautanUnduh.Windows' => $https,
            'TeksTombolDaftar' => ['required', 'string', 'max:30'],
            'TeksTombolMasuk' => ['required', 'string', 'max:30'],
            'TombolWhatsAppMelayang' => ['boolean'],
            'Analitik.IdGoogleAnalytics' => ['nullable', 'string', 'max:20', 'regex:/^G-[A-Z0-9]{4,16}$/i'],
            'Analitik.IdMetaPixel' => ['nullable', 'string', 'regex:/^[0-9]{6,20}$/'],
            'Prospek.EmailNotifikasi' => ['nullable', 'email', 'max:150'],
        ];
    }

    /**
     * Nilai rapi untuk `PengaturanSitus.Nilai` (teks dipangkas, kosong = null).
     *
     * @return array<string, mixed>
     */
    public function AmbilNilai(): array
    {
        $t = fn (string $kunci): ?string => ($nilai = trim((string) $this->input($kunci, ''))) === '' ? null : $nilai;
        $tautan = fn (mixed $daftar): array => array_values(array_map(
            fn (array $baris): array => ['Label' => trim((string) $baris['Label']), 'Tautan' => trim((string) $baris['Tautan'])],
            is_array($daftar) ? $daftar : [],
        ));

        return [
            'NamaSitus' => (string) $t('NamaSitus'),
            'Slogan' => $t('Slogan'),
            'JudulSeo' => (string) $t('JudulSeo'),
            'DeskripsiSeo' => (string) $t('DeskripsiSeo'),
            'KataKunci' => $t('KataKunci'),
            'UuidGambarOg' => ($u = $t('UuidGambarOg')) === null ? null : strtoupper($u),
            'UuidLogo' => ($u = $t('UuidLogo')) === null ? null : strtoupper($u),
            'VerifikasiGoogle' => $t('VerifikasiGoogle'),
            'Kontak' => [
                'WhatsApp' => $t('Kontak.WhatsApp'),
                'PesanWhatsApp' => $t('Kontak.PesanWhatsApp'),
                'Email' => $t('Kontak.Email'),
                'Telepon' => $t('Kontak.Telepon'),
                'Alamat' => $t('Kontak.Alamat'),
                'JamLayanan' => $t('Kontak.JamLayanan'),
            ],
            'MediaSosial' => array_map(fn (string $kunci): ?string => $t("MediaSosial.{$kunci}"), array_combine(
                ['Instagram', 'Facebook', 'Tiktok', 'Youtube', 'Linkedin', 'X'],
                ['Instagram', 'Facebook', 'Tiktok', 'Youtube', 'Linkedin', 'X'],
            )),
            'Pengumuman' => [
                'Aktif' => $this->boolean('Pengumuman.Aktif'),
                'Teks' => $t('Pengumuman.Teks'),
                'Tautan' => $t('Pengumuman.Tautan'),
            ],
            'Menu' => $tautan($this->input('Menu')),
            'MenuKaki' => array_values(array_map(
                fn (array $kolom): array => ['Judul' => trim((string) $kolom['Judul']), 'Tautan' => $tautan($kolom['Tautan'] ?? [])],
                (array) $this->input('MenuKaki', []),
            )),
            'TeksKaki' => $t('TeksKaki'),
            'TautanUnduh' => ['Android' => $t('TautanUnduh.Android'), 'Ios' => $t('TautanUnduh.Ios'), 'Windows' => $t('TautanUnduh.Windows')],
            'TeksTombolDaftar' => (string) $t('TeksTombolDaftar'),
            'TeksTombolMasuk' => (string) $t('TeksTombolMasuk'),
            'TombolWhatsAppMelayang' => $this->boolean('TombolWhatsAppMelayang'),
            'Analitik' => [
                'IdGoogleAnalytics' => ($u = $t('Analitik.IdGoogleAnalytics')) === null ? null : strtoupper($u),
                'IdMetaPixel' => $t('Analitik.IdMetaPixel'),
            ],
            'Prospek' => ['EmailNotifikasi' => ($u = $t('Prospek.EmailNotifikasi')) === null ? null : mb_strtolower($u)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'NamaSitus' => 'nama situs',
            'JudulSeo' => 'judul SEO',
            'DeskripsiSeo' => 'deskripsi SEO',
            'Kontak.WhatsApp' => 'nomor WhatsApp',
            'Kontak.Email' => 'email',
            'Pengumuman.Teks' => 'teks pengumuman',
            'Menu.*.Label' => 'label menu',
            'Menu.*.Tautan' => 'tautan menu',
            'TeksTombolDaftar' => 'teks tombol daftar',
            'TeksTombolMasuk' => 'teks tombol masuk',
            'Analitik.IdGoogleAnalytics' => 'ID Google Analytics',
            'Analitik.IdMetaPixel' => 'ID Meta Pixel',
            'Prospek.EmailNotifikasi' => 'email notifikasi prospek',
        ];
    }
}
