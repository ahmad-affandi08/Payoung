@extends('Surel.TataLetak')

@section('Judul', 'Akses darurat ke akun '.$NamaUsaha.' dibuka')
@section('Pratinjau', 'Kategori: '.$Kategori.'. Anda bisa melihat riwayatnya dan mencabut aksesnya kapan saja.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo {{ $Nama }},</p>

    <p style="margin:0 0 20px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Tim keamanan Payoung membuka <strong style="color:#1f3335;">akses darurat</strong> ke akun usaha <strong style="color:#1f3335;">{{ $NamaUsaha }}</strong> tanpa menunggu izin Anda.</p>

    @include('Surel.Komponen.Rincian', ['Baris' => [
        'Kategori' => $Kategori,
        'Alasan' => $Alasan,
        'Cakupan' => $Cakupan,
        'Berakhir' => $BerakhirPada,
    ]])

    <p style="margin:0 0 16px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Akses darurat hanya dipakai untuk insiden keamanan atau permintaan hukum, hanya bisa dibuka Super Admin, dan setiap tindakan petugas tercatat. Kata sandi, PIN, dan kredensial integrasi Anda tidak pernah terlihat oleh petugas.</p>

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Lihat riwayat & cabut akses'])
@endsection

@section('CatatanKaki', 'Jika menurut Anda ini keliru, balas email ini atau hubungi '.$EmailDukungan.' dengan menyebut nama usaha Anda.')
