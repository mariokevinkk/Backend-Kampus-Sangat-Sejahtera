<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class MahasiswaKelas extends Pivot
{
    // Specify the table name if it's not following Laravel's default naming convention
    protected $table = 'mahasiswa_kelas';  // This is the pivot table name

    // Define the fields that can be mass-assigned
    protected $fillable = [
        'mahasiswa_id', 'kelas_id', 'created_at', 'updated_at'
    ];

    // Define the relationship with Mahasiswa
    public function mahasiswa()
    {
        return $this->belongsTo(Mahasiswa::class, 'mahasiswa_id');  // A record in MahasiswaKelas belongs to Mahasiswa
    }

    // Define the relationship with Kelas
    public function kelas()
    {
        return $this->belongsTo(Kelas::class, 'kelas_id');  // A record in MahasiswaKelas belongs to Kelas
    }
}
