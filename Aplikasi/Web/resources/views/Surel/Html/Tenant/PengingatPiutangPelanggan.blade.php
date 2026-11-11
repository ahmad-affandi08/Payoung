@extends('Surel.TataLetak')

@section('Judul', 'Pengingat tagihan dari '.$NamaUsaha)
@section('Pratinjau', 'Pengingat tagihan dari '.$NamaUsaha.'.')

@section('Isi')
    {{-- Isi pesan disusun penjual di back-office (F-16/D-23 D 4b), jadi ditampilkan apa adanya sebagai kutipan. --}}
    @include('Surel.Komponen.Kutipan', ['Teks' => $Isi])

    <p style="margin:0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Salam,<br /><strong style="color:#1f3335;">{{ $NamaUsaha }}</strong></p>
@endsection

@section('CatatanKaki', 'Email ini dikirim oleh '.$NamaUsaha.'. Balas email ini untuk menghubungi mereka langsung.')
