<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tugas extends Model
{
    // Nama tabel di database
    protected $table = 'tugas'; 

    // Field yang diizinkan untuk diisi (fillable)
    protected $fillable = [
        'kelas_id', 
        'judul_tugas', 
        'deskripsi', 
        'tanggal_pengumpulan', 
        'file_tugas', 
        // Tambahkan field lain jika ada
    ];

    // Kolom untuk tanggal yang harus di-cast ke tipe Date/DateTime (opsional)
    protected $casts = [
        'tanggal_pengumpulan' => 'date', 
    ];

    // Relasi ke Kelas (One-to-Many terbalik)
    public function kelas()
    {
        return $this->belongsTo(Kelas::class, 'kelas_id');
    }

    // Relasi ke Pengumpulan (Submissions) Tugas Mahasiswa (Many-to-Many)
    public function submissions()
    {
        // Mendefinisikan relasi Many-to-Many melalui tabel perantara 'tugas_mahasiswa'
        // Jika Anda menggunakan Model TugasMahasiswa.php, ganti 'TugasMahasiswa::class'
        return $this->hasMany(TugasMahasiswa::class, 'tugas_id');
    }
}