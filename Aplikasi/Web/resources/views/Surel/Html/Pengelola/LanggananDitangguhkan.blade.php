@extends('Surel.TataLetak')

@section('Judul', 'Akun '.$NamaUsaha.' ditangguhkan')
@section('Pratinjau', 'Anda tetap bisa masuk dan melihat data. Aplikasi kasir terkunci sampai penangguhan dicabut.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo {{ $Nama }},</p>

    <p style="margin:0 0 20px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Akun usaha <strong style="color:#1f3335;">{{ $NamaUsaha }}</strong> kami tangguhkan sementara.</p>

    @include('Surel.Komponen.Rincian', ['Baris' => ['Alasan' => $Kategori]])

    <p style="margin:0 0 8px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; font-weight:bold; color:#1f3335;">Selama ditangguhkan</p>

    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Anda tetap bisa masuk, melihat laporan, mengekspor data, dan membayar tagihan. Aplikasi kasir terkunci sampai penangguhan dicabut. <strong style="color:#1f3335;">Data usaha Anda tidak dihapus.</strong></p>

    <p style="margin:0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Transaksi offline yang dibuat sebelum penangguhan tetap diterima saat aplikasi kasir tersambung kembali.</p>
@endsection

@section('CatatanKaki', 'Jika menurut Anda ini keliru, balas email ini atau hubungi '.$EmailDukungan.' dengan menyebut nama usaha Anda.')
