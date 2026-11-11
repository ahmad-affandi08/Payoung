@extends('Surel.TataLetak')

@section('Judul', 'Verifikasi email Anda')
@section('Pratinjau', 'Satu langkah lagi sebelum akun '.config('app.name').' Anda bisa dipakai.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo {{ $Nama }},</p>

    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Terima kasih sudah mendaftar. Tekan tombol di bawah untuk memastikan alamat email ini benar milik Anda, lalu akun usaha Anda langsung bisa dipakai.</p>

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Verifikasi email saya'])

    @include('Surel.Komponen.TautanCadangan', ['Url' => $Tautan])

    <p style="margin:0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Tautan ini berlaku {{ $JamBerlaku }} jam. Kalau sudah lewat, masuk ke akun Anda lalu minta tautan baru lewat tombol kirim ulang.</p>
@endsection

@section('CatatanKaki', 'Anda menerima email ini karena alamat ini dipakai saat mendaftar. Jika Anda tidak merasa mendaftar, abaikan saja — tanpa verifikasi akun tidak akan aktif.')
