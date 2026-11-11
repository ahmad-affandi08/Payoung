@extends('Surel.TataLetak')

@section('Judul', 'Undangan bergabung ke Platform Pengelola')
@section('Pratinjau', $NamaPengundang.' mengundang Anda bergabung ke Platform Pengelola '.config('app.name').'.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;"><strong style="color:#1f3335;">{{ $NamaPengundang }}</strong> mengundang Anda bergabung ke Platform Pengelola.</p>

    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Di halaman undangan Anda akan membuat kata sandi dan mengaktifkan verifikasi dua langkah.</p>

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Aktifkan akun saya'])

    @include('Surel.Komponen.TautanCadangan', ['Url' => $Tautan])

    <p style="margin:0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Undangan berlaku sampai {{ $BerlakuSampai }} dan hanya bisa dipakai sekali.</p>
@endsection

@section('CatatanKaki', 'Jika Anda tidak merasa diundang, abaikan email ini. Undangan akan kedaluwarsa sendiri.')
