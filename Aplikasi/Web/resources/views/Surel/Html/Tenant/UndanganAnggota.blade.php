@extends('Surel.TataLetak')

@section('Judul', 'Undangan bergabung ke '.$NamaTenant)
@section('Pratinjau', $NamaPengundang.' mengundang Anda bergabung ke '.$NamaTenant.'.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;"><strong style="color:#1f3335;">{{ $NamaPengundang }}</strong> mengundang Anda bergabung ke <strong style="color:#1f3335;">{{ $NamaTenant }}</strong>.</p>

    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Kalau Anda sudah punya akun, masuk dengan alamat email ini. Kalau belum, Anda akan diminta membuat kata sandi di halaman undangan.</p>

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Terima undangan'])

    @include('Surel.Komponen.TautanCadangan', ['Url' => $Tautan])

    <p style="margin:0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Undangan berlaku sampai {{ $BerlakuSampai }} dan hanya bisa dipakai sekali.</p>
@endsection

@section('CatatanKaki', 'Jika Anda tidak mengenal pengirimnya, abaikan email ini. Undangan akan kedaluwarsa sendiri.')
