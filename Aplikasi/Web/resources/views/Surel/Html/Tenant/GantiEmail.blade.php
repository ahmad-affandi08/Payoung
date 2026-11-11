@extends('Surel.TataLetak')

@section('Judul', 'Konfirmasi email baru Anda')
@section('Pratinjau', 'Satu langkah lagi untuk mengganti email akun '.config('app.name').' Anda.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo {{ $Nama }},</p>

    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Ada permintaan menjadikan alamat email ini sebagai email akun Anda. Tekan tombol di bawah untuk mengonfirmasi.</p>

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Konfirmasi email baru'])

    @include('Surel.Komponen.TautanCadangan', ['Url' => $Tautan])

    <p style="margin:0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Tautan ini berlaku {{ $JamBerlaku }} jam. Email akun baru berganti setelah tautan dibuka; sebelum itu email lama Anda tetap dipakai.</p>
@endsection

@section('CatatanKaki', 'Anda menerima email ini karena alamat ini diminta menjadi email akun. Jika Anda tidak merasa meminta ini, abaikan saja — tidak ada yang berubah pada akun.')
