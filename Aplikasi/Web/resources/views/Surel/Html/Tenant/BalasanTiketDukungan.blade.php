@extends('Surel.TataLetak')

@section('Judul', 'Balasan untuk tiket '.$Nomor)
@section('Pratinjau', $NamaPetugas.' membalas tiket Anda: '.$Judul)

@section('Isi')
    <p style="margin:0 0 20px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;"><strong style="color:#1f3335;">{{ $NamaPetugas }}</strong> dari Tim Dukungan membalas tiket Anda.</p>

    @include('Surel.Komponen.Rincian', ['Baris' => [
        'Nomor tiket' => $Nomor,
        'Judul' => $Judul,
        'Status sekarang' => $Status,
    ]])

    @include('Surel.Komponen.Kutipan', ['Teks' => $Isi])

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Buka percakapan lengkap'])
@endsection

@section('CatatanKaki', 'Mohon jangan membalas email ini. Kirim balasan lewat halaman Bantuan agar tercatat di tiket Anda.')
