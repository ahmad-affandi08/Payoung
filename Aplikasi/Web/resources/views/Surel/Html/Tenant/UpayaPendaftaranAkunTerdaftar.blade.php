@extends('Surel.TataLetak')

@section('Judul', 'Ada upaya pendaftaran memakai data Anda')
@section('Pratinjau', 'Pendaftaran itu tidak diproses dan akun Anda tidak berubah.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo {{ $Nama }},</p>

    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Seseorang baru saja mencoba mendaftarkan usaha baru memakai {{ $Identitas }} yang sudah terhubung dengan akun Anda. Pendaftaran itu <strong style="color:#1f3335;">tidak diproses</strong> dan data akun Anda tidak berubah.</p>

    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Kalau itu Anda, cukup masuk dengan akun yang sudah ada:</p>

    @include('Surel.Komponen.Tombol', ['Url' => $TautanMasuk, 'Label' => 'Masuk ke akun saya'])

    <p style="margin:0 0 4px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Lupa kata sandi? Atur ulang di <a href="{{ $TautanLupa }}" style="color:#3b5b5d; text-decoration:underline;">halaman lupa kata sandi</a>.</p>
@endsection

@section('CatatanKaki', 'Jika bukan Anda, abaikan email ini. Kami tidak memberi tahu pendaftar bahwa data ini sudah terdaftar.')
