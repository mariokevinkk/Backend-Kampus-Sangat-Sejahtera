<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TugasMahasiswa extends Model
{
    // Nama tabel di database
    protected $table = 'tugas_mahasiswa'; 

    // Field yang diizinkan untuk diisi (fillable)
    protected $fillable = [
        'tugas_id',
        'mahasiswa_id',
        'file_kumpul',
        'tanggal_kumpul',
        'nilai',
        'catatan_dosen',
        'status',
    ];

    // Kolom untuk tanggal yang harus di-cast ke tipe Date/DateTime (opsional)
    protected $casts = [
        'tanggal_kumpul' => 'datetime',
    ];

    // Relasi ke Tugas (One-to-Many terbalik)
    public function tugas()
    {
        return $this->belongsTo(Tugas::class, 'tugas_id');
    }

    // Relasi ke Mahasiswa (One-to-Many terbalik)
    public function mahasiswa()
    {
        // Sesuaikan dengan nama Model Mahasiswa Anda, mungkin Mahasiswas::class
        return $this->belongsTo(Mahasiswa::class, 'mahasiswa_id');
    }
}