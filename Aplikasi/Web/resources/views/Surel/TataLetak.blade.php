{{--
    Tata letak email HTML Payoung (§17.6, D-26).

    Kenapa gayanya ditulis inline dan warnanya hex literal, bukan token:
    klien email (Outlook, Gmail, Apple Mail) tidak mendukung variabel CSS, `color-mix()`, maupun kelas
    Tailwind. Nilai hex di sini disalin dari token `Gaya/Aplikasi.css` dan dijaga `tests/Arsitektur/SurelTes.php`.

    Struktur email memakai tabel `role="presentation"` karena klien email tidak bisa diandalkan untuk
    flexbox/grid. Satu-satunya gambar adalah logo merek (D-74) dari `public/surel/logo-payoung.png`, dengan
    `alt` bernama merek dan ukuran tetap supaya tata letak tidak bergeser saat gambar diblokir; tanpa piksel
    pelacak. `SurelTes` menjaga bahwa hanya tata letak ini yang boleh memuat gambar.

    Bagian yang diisi templat anak:
    - `Pratinjau`  : satu baris teks cuplikan di kotak masuk (wajib, kalau kosong klien memakai isi email).
    - `Judul`      : judul di dalam kartu (wajib).
    - `Isi`        : paragraf & komponen (wajib).
    - `CatatanKaki`: catatan tambahan di atas kaki bersama (opsional).
--}}
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    {{-- D-14: tanpa mode gelap. Tanpa dua meta ini, Gmail & Apple Mail membalik warna sendiri dan kontras rusak. --}}
    <meta name="color-scheme" content="light only" />
    <meta name="supported-color-schemes" content="light only" />
    <title>@yield('Judul')</title>
    <!--[if mso]>
    <style type="text/css">
        body, table, td, p, a, h1, h2 { font-family: Arial, sans-serif !important; }
    </style>
    <![endif]-->
    <style type="text/css">
        /* Klien yang mendukung <style> memakai ini; sisanya tetap benar karena semua gaya juga inline. */
        body { margin: 0; padding: 0; width: 100% !important; }
        a { color: #3b5b5d; }
        .Kartu { border-radius: 12px; }
        @media only screen and (max-width: 600px) {
            .Bingkai { width: 100% !important; }
            .Sisi { padding-left: 22px !important; padding-right: 22px !important; }
            .JudulKartu { font-size: 22px !important; line-height: 29px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#ebf0ec; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">
{{-- Cuplikan kotak masuk. Deretan &#8199; mendorong isi email keluar dari cuplikan supaya tidak ikut terbaca. --}}
<div style="display:none; font-size:1px; color:#ebf0ec; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">
    @yield('Pratinjau')
    &#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;&#8199;
</div>

<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#ebf0ec;">
    <tr>
        <td align="center" style="padding:32px 12px 48px 12px;">

            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="560" class="Bingkai" style="width:560px; max-width:560px;">

                {{-- Satu panel putih berbingkai tipis: logo, judul, isi, lalu kaki. Tanpa pita warna dan tanpa garis aksen. --}}
                <tr>
                    <td class="Kartu" style="background-color:#ffffff; border:1px solid #d2dcd6; border-radius:12px;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                            <tr>
                                <td class="Sisi" style="padding:36px 40px 0 40px;">
                                    <img src="{{ asset('surel/logo-payoung.png') }}" width="120" height="28" alt="{{ config('app.name') }}" style="display:block; border:0; outline:none; text-decoration:none; width:120px; height:28px; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:20px; line-height:28px; font-weight:bold; color:#22383a;" />
                                </td>
                            </tr>
                            <tr>
                                <td class="Sisi" style="padding:28px 40px 0 40px;">
                                    <div style="border-top:1px solid #d2dcd6; line-height:1px; font-size:1px;">&nbsp;</div>
                                </td>
                            </tr>
                            <tr>
                                <td class="Sisi" style="padding:20px 40px 36px 40px;">
                                    <h1 class="JudulKartu" style="margin:0 0 20px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:26px; line-height:34px; font-weight:bold; letter-spacing:-0.3px; color:#1f3335;">@yield('Judul')</h1>
                                    @yield('Isi')
                                </td>
                            </tr>
                            @hasSection('CatatanKaki')
                                <tr>
                                    <td class="Sisi" style="padding:0 40px 32px 40px;">
                                        <div style="border-top:1px solid #d2dcd6; line-height:1px; font-size:1px;">&nbsp;</div>
                                        <p style="margin:16px 0 0 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; line-height:20px; color:#4f6567;">@yield('CatatanKaki')</p>
                                    </td>
                                </tr>
                            @endif
                        </table>
                    </td>
                </tr>

                {{-- Kaki bersama, di luar panel --}}
                <tr>
                    <td class="Sisi" style="padding:24px 8px 0 8px; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; line-height:19px; color:#4f6567; text-align:center;">
                        <p style="margin:0 0 6px 0;">
                            <strong style="color:#1f3335;">{{ config('app.name') }}</strong> &nbsp;|&nbsp; kasir, stok, dan pembukuan dalam satu aplikasi
                        </p>
                        <p style="margin:0;">
                            Butuh bantuan? Balas email ini atau hubungi <a href="mailto:{{ config('mail.from.address') }}" style="color:#3b5b5d; text-decoration:underline;">{{ config('mail.from.address') }}</a>
                        </p>
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>
</body>
</html>
