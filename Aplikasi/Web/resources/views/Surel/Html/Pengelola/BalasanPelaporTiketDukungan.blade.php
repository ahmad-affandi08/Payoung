@extends('Surel.TataLetak')

@section('Judul', $DibukaLagi ? 'Tiket '.$Nomor.' dibuka lagi' : 'Balasan pelapor di tiket '.$Nomor)
@section('Pratinjau', ($DibukaLagi ? 'Pelapor membuka lagi tiket ' : 'Pelapor membalas tiket ').$Nomor.': '.$Judul)

@section('Isi')
    <p style="margin:0 0 20px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo, {{ $DibukaLagi ? 'pelapor membuka lagi tiket' : 'pelapor membalas tiket' }} yang Anda tangani.</p>

    @include('Surel.Komponen.Rincian', ['Baris' => ['Nomor' => $Nomor, 'Judul' => $Judul]])

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Baca balasannya'])
@endsection
