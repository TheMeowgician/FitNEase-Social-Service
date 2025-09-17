<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Group extends Model
{
    use HasFactory;

    protected $primaryKey = 'group_id';

    protected $fillable = [
        'group_name',
        'description',
        'created_by',
        'max_members',
        'current_member_count',
        'is_private',
        'group_code',
        'group_image',
        'is_active'
    ];

    protected $casts = [
        'is_private' => 'boolean',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function members()
    {
        return $this->hasMany(GroupMember::class, 'group_id', 'group_id');
    }

    public function activeMembers()
    {
        return $this->hasMany(GroupMember::class, 'group_id', 'group_id')->where('is_active', true);
    }

    public function workoutEvaluations()
    {
        return $this->hasMany(GroupWorkoutEvaluation::class, 'group_id', 'group_id');
    }

    // Note: In microservices architecture, we don't establish direct relationships
    // with external service models. Use created_by field to reference user ID
    // and fetch user details via AuthService when needed.

    public function admins()
    {
        return $this->hasMany(GroupMember::class, 'group_id', 'group_id')
            ->where('member_role', 'admin')
            ->where('is_active', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePublic($query)
    {
        return $query->where('is_private', false);
    }

    public function scopeWithAvailableSlots($query)
    {
        return $query->whereRaw('current_member_count < max_members');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($group) {
            if (empty($group->group_code)) {
                $group->group_code = self::generateUniqueGroupCode();
            }
        });
    }

    public static function generateUniqueGroupCode()
    {
        do {
            $code = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8));
        } while (self::where('group_code', $code)->exists());

        return $code;
    }
}
