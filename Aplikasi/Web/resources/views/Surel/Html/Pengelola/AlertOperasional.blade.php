@extends('Surel.TataLetak')

@section('Judul', $Label)
@section('Pratinjau', 'Alert '.$Tingkat.' mulai '.$MulaiPada.'.')

@section('Isi')
    <p style="margin:0 0 20px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo Tim Teknis, ada insiden operasional yang terbuka.</p>

    @include('Surel.Komponen.Rincian', ['Baris' => ['Tingkat' => $Tingkat, 'Alert' => $Label, 'Mulai' => $MulaiPada]])

    @include('Surel.Komponen.Kutipan', ['Teks' => $Pesan])

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Periksa dasbor operasional'])
@endsection

@section('CatatanKaki', 'Email ini dikirim sekali per insiden. Alert tertutup otomatis saat kondisi pulih.')
