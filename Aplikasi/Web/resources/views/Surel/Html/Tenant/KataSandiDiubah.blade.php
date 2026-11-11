@extends('Surel.TataLetak')

@section('Judul', 'Kata sandi akun Anda diubah')
@section('Pratinjau', 'Kalau bukan Anda yang mengubahnya, segera amankan akun Anda.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo {{ $Nama }},</p>

    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Kata sandi akun Anda baru saja diubah lewat tautan atur ulang kata sandi. Semua perangkat lain yang masuk dengan akun ini sudah dikeluarkan.</p>

    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#1f3335; font-weight:bold;">Jika bukan Anda yang mengubahnya, akun Anda mungkin diakses orang lain. Atur ulang kata sandi sekarang, lalu hubungi kami.</p>

    @include('Surel.Komponen.Tombol', ['Url' => $TautanLupa, 'Label' => 'Atur ulang kata sandi'])

    @include('Surel.Komponen.TautanCadangan', ['Url' => $TautanLupa])
@endsection

@section('CatatanKaki', 'Kalau perubahan ini memang Anda lakukan, tidak ada yang perlu dikerjakan lagi.')
