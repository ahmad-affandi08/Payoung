@extends('Surel.TataLetak')

@section('Judul', 'Atur ulang kata sandi Anda')
@section('Pratinjau', 'Tautan atur ulang kata sandi, berlaku '.$MenitBerlaku.' menit.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo {{ $Nama }},</p>

    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Kami menerima permintaan untuk mengatur ulang kata sandi akun Anda. Tekan tombol di bawah untuk membuat kata sandi baru.</p>

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Buat kata sandi baru'])

    @include('Surel.Komponen.TautanCadangan', ['Url' => $Tautan])

    <p style="margin:0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Tautan ini berlaku {{ $MenitBerlaku }} menit dan hanya bisa dipakai sekali. Setelah kata sandi diganti, semua perangkat lain yang masuk dengan akun ini akan keluar otomatis.</p>
@endsection

@section('CatatanKaki', 'Jika Anda tidak meminta atur ulang kata sandi, abaikan email ini. Kata sandi Anda tidak berubah.')
