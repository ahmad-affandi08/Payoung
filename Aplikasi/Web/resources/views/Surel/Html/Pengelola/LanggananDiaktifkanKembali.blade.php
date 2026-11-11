@extends('Surel.TataLetak')

@section('Judul', 'Akun '.$NamaUsaha.' aktif kembali')
@section('Pratinjau', 'Penangguhan sudah dicabut. Aplikasi kasir dan semua menu kembali bisa dipakai.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo {{ $Nama }},</p>

    <p style="margin:0 0 20px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Penangguhan akun usaha <strong style="color:#1f3335;">{{ $NamaUsaha }}</strong> sudah dicabut.</p>

    @include('Surel.Komponen.Rincian', ['Baris' => ['Status langganan' => $Status]])

    <p style="margin:0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Aplikasi kasir dan semua menu kembali bisa dipakai sesuai paket Anda.</p>
@endsection
