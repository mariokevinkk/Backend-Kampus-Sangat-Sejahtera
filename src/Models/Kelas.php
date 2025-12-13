<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Kelas extends Model
{
    // Specify the table name if it's not following Laravel's default naming convention
    protected $table = 'kelas';  // Adjust the table name if necessary

    // Define the fields that can be mass-assigned
    protected $fillable = [
        'nama_kelas', 'matakuliah_id', 'dosen_id', 'ruangan', 'jam', 'created_at', 'updated_at'
    ];

    // Define the relationship with Matakuliah (one Kelas belongs to one Matakuliah)
    public function matakuliah()
    {
        return $this->belongsTo(Matakuliah::class, 'matakuliah_id');  // A kelas belongs to a Matakuliah
    }

    // Define the relationship with Dosen (one Kelas belongs to one Dosen)
    public function dosen()
    {
        return $this->belongsTo(Dosen::class, 'dosen_id');  // A kelas belongs to a Dosen
    }

    // If a Kelas has many Mahasiswa (students), define it as a one-to-many relationship
    // public function mahasiswa()
    // {
    //     return $this->hasMany(MahasiswaKelas::class, 'kelas_id');  // Assuming a pivot table MahasiswaKelas
    // }
       // Many-to-many relationship with Mahasiswa through mahasiswa_kelas pivot table
    public function mahasiswa()
    {
        return $this->belongsToMany(Mahasiswa::class, 'mahasiswa_kelas', 'kelas_id', 'mahasiswa_id');
    }

    // You can calculate the number of students using this relationship
    public function jumlahMahasiswa()
    {
        return $this->mahasiswa()->count();  // This returns the number of students in the class
    }
     public function materi() {
        return $this->hasMany(Materi::class, 'kelas_id');
    }
    public function tugas()
    {
        // Relasi One-to-Many: Kelas memiliki banyak Tugas.
        // Tugas dihubungkan melalui field 'kelas_id' di tabel 'tugas'.
        return $this->hasMany(Tugas::class, 'kelas_id');
    }
    public function pengumuman()
    {
        // Relasi One-to-Many: Kelas memiliki banyak Pengumuman.
        // Diikat melalui field 'kelas_id' di tabel 'sangat_sejahtera_pengumuman'.
        return $this->hasMany(Pengumuman::class, 'kelas_id');
    }
    public function presensiSesi()
{
    return $this->hasMany(Presensi::class, 'kelas_id');
}
}
